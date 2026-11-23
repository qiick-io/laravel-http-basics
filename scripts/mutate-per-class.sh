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
  'Qiick\HttpBasics\Locale\LocaleNegotiator'
  'Qiick\HttpBasics\Security\SecurityHeaderBuilder'
  'Qiick\HttpBasics\Idempotency\Fingerprinter'
  'Qiick\HttpBasics\Idempotency\AnonymousBucketResolver'
  'Qiick\HttpBasics\Idempotency\CacheIdempotencyStore'
  'Qiick\HttpBasics\Idempotency\DatabaseIdempotencyStore'
  'Qiick\HttpBasics\Idempotency\HandleIdempotencyMiddleware'
  'Qiick\HttpBasics\Idempotency\IdempotencyRejection'
  'Qiick\HttpBasics\Idempotency\IdempotencyContext'
  'Qiick\HttpBasics\Uploads\HardFloor'
  'Qiick\HttpBasics\Uploads\FileNameSanitizer'
  'Qiick\HttpBasics\Uploads\UploadValidator'
  'Qiick\HttpBasics\Uploads\ConfigUploadPolicyResolver'
  'Qiick\HttpBasics\Uploads\DetectedMimeExtension'
  'Qiick\HttpBasics\Uploads\ZipLayoutVerifier'
  'Qiick\HttpBasics\Errors\ProblemDetailsErrorRenderer'
  'Qiick\HttpBasics\Errors\ExceptionErrorCodeAugmenter'
  'Qiick\HttpBasics\Cors\CorsConfigValidator'
  'Qiick\HttpBasics\Cors\ConfigureCorsMiddleware'
  'Qiick\HttpBasics\Cors\OriginNormalizer'
  'Qiick\HttpBasics\Errors\ErrorCodeAugmentor'
  'Qiick\HttpBasics\Errors\FrameworkErrorCodeResolver'
)

CLASS_FILES=(
  'src/RequestId/RequestId.php'
  'src/RequestId/RequestIdResolver.php'
  'src/RequestId/RequestIdPropagator.php'
  'src/RequestId/AssignRequestIdMiddleware.php'
  'src/RequestId/ConfigTrustedProxyDetector.php'
  'src/Locale/LocaleNegotiator.php'
  'src/Security/SecurityHeaderBuilder.php'
  'src/Idempotency/Fingerprinter.php'
  'src/Idempotency/AnonymousBucketResolver.php'
  'src/Idempotency/CacheIdempotencyStore.php'
  'src/Idempotency/DatabaseIdempotencyStore.php'
  'src/Idempotency/HandleIdempotencyMiddleware.php'
  'src/Idempotency/IdempotencyRejection.php'
  'src/Idempotency/IdempotencyContext.php'
  'src/Uploads/HardFloor.php'
  'src/Uploads/FileNameSanitizer.php'
  'src/Uploads/UploadValidator.php'
  'src/Uploads/ConfigUploadPolicyResolver.php'
  'src/Uploads/DetectedMimeExtension.php'
  'src/Uploads/ZipLayoutVerifier.php'
  'src/Errors/ProblemDetailsErrorRenderer.php'
  'src/Errors/ExceptionErrorCodeAugmenter.php'
  'src/Cors/CorsConfigValidator.php'
  'src/Cors/ConfigureCorsMiddleware.php'
  'src/Cors/OriginNormalizer.php'
  'src/Errors/ErrorCodeAugmentor.php'
  'src/Errors/FrameworkErrorCodeResolver.php'
)

# Extra files under RequestId\ / Locale\ / Security\ / Idempotency\ / Cors\ / Uploads\ prefixes that are not mutation targets.
SIBLING_FILES=(
  'src/RequestId/RequestIdAcceptFrom.php'
  'src/RequestId/RequestIdSource.php'
  'src/RequestId/UuidIdGenerator.php'
  'src/Locale/ConfigDefaultLocaleResolver.php'
  'src/Locale/ConfigSupportedLocales.php'
  'src/Locale/MachineDoorClassifier.php'
  'src/Locale/SetLocaleMiddleware.php'
  'src/Security/CspMode.php'
  'src/Security/SecurityHeadersPolicy.php'
  'src/Security/CspPolicyBuilder.php'
  'src/Security/ViteFriendlyCspPolicyBuilder.php'
  'src/Security/SetSecurityHeadersMiddleware.php'
  'src/Idempotency/ClaimOutcome.php'
  'src/Idempotency/IdempotencyClaim.php'
  'src/Idempotency/IdempotencyScope.php'
  'src/Idempotency/ReplayableResponse.php'
  'src/Errors/ErrorCode.php'
  'src/Idempotency/FleetErrorRenderer.php'
  'src/Idempotency/IdempotencyKeyValidator.php'
  'src/Idempotency/IdempotencyKeyFormat.php'
  'src/Idempotency/IdempotencyStoreException.php'
  'src/Idempotency/PayloadTooLargeException.php'
  'src/Idempotency/ReplayHeaderSanitizer.php'
  'src/Idempotency/NullCredentialBucketResolver.php'
  'src/Idempotency/IdempotencyRecord.php'
  'src/Uploads/ArrayUploadPolicy.php'
  'src/Uploads/MismatchMode.php'
  'src/Uploads/MalwareScanner.php'
  'src/Uploads/NullMalwareScanner.php'
  'src/Uploads/SafeUpload.php'
  'src/Uploads/ScanResult.php'
  'src/Uploads/UploadPolicy.php'
  'src/Uploads/UploadPolicyResolver.php'
  'src/Uploads/UploadRejectedException.php'
  'src/Uploads/ValidatedUpload.php'
  'src/Cors/AllowedOriginsResolver.php'
  'src/Cors/NullAllowedOriginsResolver.php'
  'src/Cors/CorsHeaderCatalog.php'
  'src/Contracts/CredentialBucketResolver.php'
  'src/Contracts/IdempotencyStore.php'
  'src/Contracts/ErrorRenderer.php'
  'src/Errors/ErrorCodeResolver.php'
  'src/Errors/ExceptionRespondBinder.php'
  'src/Errors/MachineDoorJson.php'
  'src/Testing/InMemoryIdempotencyStore.php'
  'src/Testing/FixedClock.php'
  'src/Testing/FakeCredentialBucketResolver.php'
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
  # Prefer grep/sed (available on GitHub-hosted runners); rg is not installed there.
  score="$(sed $'s/\x1b\\[[0-9;]*m//g' "${output}" \
    | grep -Eo 'Score:[[:space:]]+[0-9.]+%' \
    | tail -1 \
    | grep -Eo '[0-9.]+' \
    | head -1 || true)"
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
