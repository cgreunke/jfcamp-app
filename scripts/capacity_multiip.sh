#!/usr/bin/env bash
set -euo pipefail
PAR="${1:-5}"        # Anzahl paralleler k6-Container (≈ Anzahl Client-IPs)
VUS_TOTAL="${2:-100}" # Gesamt-VUs über alle Container
DUR="${3:-60s}"
VUS_PER=$(( (VUS_TOTAL + PAR - 1) / PAR ))

NET=$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{printf "%s\n" $k}}{{end}}' vue-prod | head -n1)
echo "Running $PAR containers x $VUS_PER VUs = ~$VUS_TOTAL VUs, duration $DUR"

run_one () {
  i="$1"
  TS=$(date -u +%Y%m%dT%H%M%SZ)
  OUT="docs/capacity/runs/${TS}_k6${i}_par${PAR}_vus${VUS_PER}_dur${DUR}.json"
  docker run --rm --cpus 0.20 --network "$NET" \
    -v "$PWD":/scripts -w /scripts \
    -e BASE_URL="http://vue-prod" \
    -e CODES_PATH="/scripts/codes.txt" \
    -e WORKSHOPS_PATH="/scripts/workshops.txt" \
    -e MAX_WISHES="3" \
    grafana/k6 run --vus "$VUS_PER" --duration "$DUR" \
    --summary-export "/scripts/${OUT}" \
    --summary-trend-stats "avg,med,min,max,p(90),p(95)" \
    k6-wunsch-simple.js >/dev/null 2>&1 &
}

mkdir -p docs/capacity/runs
for i in $(seq 1 "$PAR"); do run_one "$i"; done
wait
echo "DONE. Summaries:"
./scripts/capacity_summarize.sh
