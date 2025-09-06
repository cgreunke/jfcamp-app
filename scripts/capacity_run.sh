#!/usr/bin/env bash
set -euo pipefail
VUS="${1:-20}"
DUR="${2:-60s}"
OUT="docs/capacity/runs/$(date -u +%Y%m%dT%H%M%SZ)_vus${VUS}_dur${DUR}.json"

NET=$(docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{printf "%s\n" $k}}{{end}}' vue-prod | head -n1)
docker run --rm -it --user 0:0 --network "$NET" \
  -v "$PWD":/scripts -w /scripts \
  -e BASE_URL="http://vue-prod" \
  -e CODES_PATH="/scripts/codes.txt" \
  -e WORKSHOPS_PATH="/scripts/workshops.txt" \
  -e MAX_WISHES="3" \
  grafana/k6 run --vus "$VUS" --duration "$DUR" \
  --summary-export "/scripts/${OUT}" \
  --summary-trend-stats "avg,med,min,max,p(90),p(95)" \
  k6-wunsch-simple.js

echo "Wrote $OUT"
