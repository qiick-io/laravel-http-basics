#!/usr/bin/env bash
# Enforce pest --mutate --min=95 per design §8 class (no --covered-only).
# One invocation per class so a weak class cannot hide behind a high overall score.
# Pest's --class is a prefix match and --ignore takes comma-separated filesystem
# paths (repeated --ignore flags keep only the last), so we ignore every other
# target file when mutating one class.
# Never `|| true`.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Parallel arrays: class FQCN and its source file.
CLASSES=(
  'Qiick\HttpBasics\RequestId\RequestId'
  'Qiick\HttpBasics\RequestId\RequestIdResolver'
  'Qiick\HttpBasics\RequestId\RequestIdPropagator'
  'Qiick\HttpBasics\RequestId\AssignRequestIdMiddleware'
  'Qiick\HttpBasics\RequestId\ConfigTrustedProxyDetector'
)

CLASS_FILES=(
  'src/RequestId/RequestId.php'
  'src/RequestId/RequestIdResolver.php'
  'src/RequestId/RequestIdPropagator.php'
  'src/RequestId/AssignRequestIdMiddleware.php'
  'src/RequestId/ConfigTrustedProxyDetector.php'
)

# Extra files under the RequestId\ prefix that are not mutation targets.
SIBLING_FILES=(
  'src/RequestId/RequestIdAcceptFrom.php'
  'src/RequestId/RequestIdSource.php'
  'src/RequestId/UuidIdGenerator.php'
)

MIN="${MUTATE_MIN:-95}"

if [[ "${#CLASSES[@]}" -eq 0 ]]; then
  echo "No mutation target classes yet (scaffold). Skipping."
  exit 0
fi

FAILED=0
declare -a TABLE_ROWS=()

for index in "${!CLASSES[@]}"; do
  class="${CLASSES[$index]}"
  target_file="${CLASS_FILES[$index]}"

  echo "::group::mutate ${class}"
  echo "==> mutate --class=${class} (min=${MIN}, no covered-only)"

  ignore_files=()
  for other_index in "${!CLASS_FILES[@]}"; do
    other_file="${CLASS_FILES[$other_index]}"
    if [[ "$other_file" != "$target_file" ]]; then
      ignore_files+=("${other_file}")
    fi
  done
  for sibling in "${SIBLING_FILES[@]}"; do
    ignore_files+=("${sibling}")
  done
  ignore_csv="$(IFS=','; echo "${ignore_files[*]}")"

  output="$(mktemp)"
  set +e
  vendor/bin/pest --mutate --min="${MIN}" --class="${class}" --ignore="${ignore_csv}" | tee "${output}"
  status=${PIPESTATUS[0]}
  set -e

  # Strip ANSI before parsing — pest colours the Score line.
  score="$(sed $'s/\x1b\\[[0-9;]*m//g' "${output}" | rg -o 'Score:[[:space:]]+[0-9.]+%' | tail -1 | rg -o '[0-9.]+' | head -1 || true)"
  if [[ -z "${score}" ]]; then
    score='?'
  fi
  TABLE_ROWS+=("| \`${class}\` | ${score}% |")

  if [[ "${status}" -ne 0 ]]; then
    echo "::error::Mutation score below ${MIN}% for ${class}"
    FAILED=1
  fi
  rm -f "${output}"
  echo "::endgroup::"
done

echo "## Per-class mutation scores"
echo "| Class | Score |"
echo "| --- | --- |"
for row in "${TABLE_ROWS[@]}"; do
  echo "${row}"
done

if [[ "${FAILED}" -ne 0 ]]; then
  echo "One or more classes scored below ${MIN}%."
  exit 1
fi

echo "All ${#CLASSES[@]} classes scored >= ${MIN}%."
