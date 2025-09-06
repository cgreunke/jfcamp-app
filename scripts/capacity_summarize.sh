#!/usr/bin/env bash
set -euo pipefail
for f in docs/capacity/runs/*.json; do
  printf "%-60s " "$(basename "$f")"
  docker run --rm -v "$PWD":/w ghcr.io/jqlang/jq -r '
    def p95_200:
      (.metrics["latency_200_ms"].values["p(95)"] // .metrics["latency_200_ms"]["p(95)"] // "n/a");
    def pct(name):
      (((.metrics[name].value // ((.metrics[name].passes // 0) / (.metrics.iterations.count // 1)) // 0) * 100) | round);
    "p95(200)=\(p95_200) ms | 200=\(pct("status_200"))% | 429=\(pct("status_429"))% | 5xx=\(pct("status_5xx"))%"
  ' "/w/$f"
done | sort
