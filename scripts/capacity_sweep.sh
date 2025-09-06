#!/usr/bin/env bash
set -euo pipefail
DUR="${1:-60s}"
for V in 20 40 60 80 100 120; do
  ./scripts/capacity_run.sh "$V" "$DUR"
done
./scripts/capacity_summarize.sh
