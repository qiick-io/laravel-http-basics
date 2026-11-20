#!/usr/bin/env bash
# Enforce pest --mutate --min=95 on design §8 security-critical classes.
# One comma-separated --class invocation (pcov required). Empty list = scaffold
# no-op until feature classes land. Never `|| true`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

CLASSES=(
  'Qiick\HttpBasics\RequestId\RequestId'
  'Qiick\HttpBasics\RequestId\RequestIdResolver'
  'Qiick\HttpBasics\RequestId\RequestIdPropagator'
)

MIN="${MUTATE_MIN:-95}"

if [[ "${#CLASSES[@]}" -eq 0 ]]; then
  echo "No mutation target classes yet (scaffold). Skipping."
  exit 0
fi

# Join with commas for a single --class argument (design / fleet rule).
CSV="$(IFS=,; echo "${CLASSES[*]}")"

echo "==> mutate --class=${CSV} (min=${MIN})"
vendor/bin/pest --mutate --covered-only --min="${MIN}" --class="${CSV}"
