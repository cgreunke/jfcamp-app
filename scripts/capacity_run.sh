#!/usr/bin/env bash
set -euo pipefail
VUS="${1:-20}"
DUR="${2:-60s}"
BASE_URL="${BASE_URL:-https://example.org}"   # öffentlich!
OUT="docs/capacity/runs/$(date -u +%Y%m%dT%H%M%SZ)_vus${VUS}_dur${DUR}.json"
mkdir -p docs/capacity/runs
docker run --rm \
  -v "$PWD":/scripts -w /scripts \
  -e BASE_URL="$BASE_URL" \
  -e CODES_PATH="/scripts/codes.txt" \
  -e WORKSHOPS_PATH="/scripts/workshops.txt" \
  -e MAX_WISHES="3" \
  grafana/k6 run --vus "$VUS" --duration "$DUR" \
  --summary-export "/scripts/${OUT}" \
  --summary-trend-stats "avg,med,min,max,p(90),p(95)" \
  k6-wunsch-simple.js
echo "Wrote $OUT"
