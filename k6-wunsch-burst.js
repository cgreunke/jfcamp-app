import http from 'k6/http';
import { Trend, Rate } from 'k6/metrics';

export const options = {
  scenarios: {
    burst: {
      executor: 'constant-arrival-rate',
      rate: Number(__ENV.RATE || 220), // Anfragen pro Sekunde gesamt
      timeUnit: '1s',
      duration: __ENV.DUR || '60s',
      preAllocatedVUs: Number(__ENV.VUS || 200),
      maxVUs: Number(__ENV.MAXVUS || 400),
    },
  },
  thresholds: {},
};

const latency200 = new Trend('latency_200_ms');
const status200  = new Rate('status_200');
const status429  = new Rate('status_429');
const status5xx  = new Rate('status_5xx');

const BASE_URL = __ENV.BASE_URL;
const CODES = open(__ENV.CODES_PATH || 'codes.txt').trim().split('\n');
const WSHS  = open(__ENV.WORKSHOPS_PATH || 'workshops.txt').trim().split('\n');

function payloadFor(code) {
  const shuffled = WSHS.slice().sort(() => 0.5 - Math.random());
  const wishes = shuffled.slice(0, Math.min(3, WSHS.length));
  return JSON.stringify({ code, wishes });
}

export default function () {
  const code = CODES[Math.floor(Math.random() * CODES.length)];
  const res  = http.post(`${BASE_URL}/api/wunsch`, payloadFor(code), { headers: { 'Content-Type': 'application/json' }, timeout: '60s' });
  if (res.status === 200) latency200.add(res.timings.duration);
  status200.add(res.status === 200);
  status429.add(res.status === 429);
  status5xx.add(res.status >= 500);
}
