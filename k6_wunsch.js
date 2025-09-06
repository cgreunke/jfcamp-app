// k6-wunsch.js
import http from 'k6/http'
import { check, sleep } from 'k6'
import { SharedArray } from 'k6/data'
import { Trend, Rate, Counter } from 'k6/metrics'

// Dateien: codes.txt (eine Zeile pro Code: TEST-001...), workshops.txt (Titel ODER UUIDs)
const codes = new SharedArray('codes', () =>
  open(__ENV.CODES_PATH || 'codes.txt').trim().split(/\r?\n/).filter(Boolean)
)
const workshops = new SharedArray('workshops', () =>
  open(__ENV.WORKSHOPS_PATH || 'workshops.txt').trim().split(/\r?\n/).filter(Boolean)
)

const MAX_WISHES = parseInt(__ENV.MAX_WISHES || '3', 10)
const BASE = __ENV.BASE_URL || 'http://localhost:3000' // via vue-prod (Same-Origin)

export const options = {
  scenarios: {
    ramp: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '20s', target: 20 },
        { duration: '40s', target: 60 },
        { duration: '60s', target: 120 },
        { duration: '20s', target: 0 },
      ],
      gracefulRampDown: '10s',
    },
  },
  thresholds: {
    // 429 sind okay (Backpressure). 5xx sind nicht okay:
    'status_5xx': ['rate<0.01'],
    'check_ok_or_throttled': ['rate>0.95'],
    'http_req_duration': ['p(95)<2000'], // p95 < 2s als Ziel
  },
  discardResponseBodies: true,
}

const t_total = new Trend('latency_total_ms')
const r_200 = new Rate('status_200')
const r_429 = new Rate('status_429')
const r_5xx = new Rate('status_5xx')
const okOrThrottled = new Rate('check_ok_or_throttled')

function shuffledSlice(arr, n) {
  const a = arr.slice()
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    ;[a[i], a[j]] = [a[j], a[i]]
  }
  return a.slice(0, Math.max(1, Math.min(n, a.length)))
}

export default function () {
  // Code rotierend wählen (verteilt per-code Flood)
  const i = (__VU * 100000 + __ITER)
  const code = codes[i % codes.length]

  // Wunschliste: zufällige Titel/UUIDs, auf MAX_WISHES begrenzt
  const wuensche = shuffledSlice(workshops, MAX_WISHES)

  const res = http.post(`${BASE}/api/wunsch`, JSON.stringify({ code, wuensche }), {
    headers: { 'Content-Type': 'application/json' },
    timeout: '60s',
  })

  t_total.add(res.timings.duration)
  r_200.add(res.status === 200)
  r_429.add(res.status === 429)
  r_5xx.add(res.status >= 500 && res.status <= 599)
  okOrThrottled.add(res.status === 200 || res.status === 429)

  check(res, { 'ok or throttled': (r) => r.status === 200 || r.status === 429 })
  sleep(0.2)
}

export function handleSummary(data) {
  return { 'summary.json': JSON.stringify(data, null, 2) }
}
