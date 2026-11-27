#!/usr/bin/env bash
# Keep one Pest score per source file so a high aggregate cannot hide a weak class.
# Target exact paths because Pest matches --class by prefix.
set -euo pipefail

cd "$(dirname "$0")/.."

scope="${MUTATE_SCOPE:-all}"
shard="${MUTATE_SHARD:-1}"
shard_count="${MUTATE_SHARDS:-1}"
minimum_score="${MUTATE_MIN:-95}"

if ! [[ "$shard" =~ ^[1-9][0-9]*$ && "$shard_count" =~ ^[1-9][0-9]*$ ]] || (( shard > shard_count )); then
  echo 'Invalid mutation shard.'
  exit 1
fi

case "$scope" in
  all)
    candidates="$(find src -name '*.php' -type f | LC_ALL=C sort)"
    if [[ -z "$candidates" ]]; then
      echo 'No PHP source files found for the full mutation run.'
      exit 1
    fi
    ;;
  changed)
    candidates="$(php -r '
      $paths = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
      if (! is_array($paths) || ! array_is_list($paths)) {
          throw new InvalidArgumentException("Mutation targets must be a JSON list.");
      }
      foreach ($paths as $path) {
          if (! is_string($path) || preg_match("~\Asrc/(?:[A-Za-z0-9_]+/)*[A-Za-z0-9_]+\.php\z~", $path) !== 1 || ! is_file($path)) {
              throw new InvalidArgumentException("A mutation target is outside the current source tree.");
          }
          echo $path, PHP_EOL;
      }
    ' "${MUTATE_CLASSES:-[]}")"
    ;;
  *)
    echo 'MUTATE_SCOPE must be all or changed.'
    exit 1
    ;;
esac

if [[ -z "$candidates" ]]; then
  echo 'No changed PHP source files to mutate.'
  exit 0
fi

report="$(mktemp)"
result="$(mktemp)"
trap 'rm -f "$report" "$result"' EXIT

file_number=0
failed=0

while IFS= read -r source_file; do
  file_number=$((file_number + 1))
  if (( (file_number - 1) % shard_count != shard - 1 )); then
    continue
  fi

  echo "::group::Mutate ${source_file}"
  if ! vendor/bin/pest --exclude-group=http-server \
    --mutate --path="$source_file" --min="$minimum_score" \
    --ignore-min-score-on-zero-mutations --parallel --processes=2 \
    --no-progress --colors=never | tee "$result"; then
    failed=1
  fi

  # No-mutation files (such as interfaces) still appear in the inventory.
  score="$(awk '/Score:/ { score = $2 } /No mutations created[.]/ { score = "0 mutants" } END { print score }' "$result")"
  if [[ -z "$score" ]]; then
    failed=1
    score='missing result'
  fi

  printf '| %s | %s |\n' "$source_file" "$score" >> "$report"
  echo '::endgroup::'
done <<< "$candidates"

printf '%s\n' '## Per-class mutation scores' '| Source | Score |' '| --- | --- |'
cat "$report"
exit "$failed"
