<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Http\Request;
use JsonException;

/**
 * @api Canonical request fingerprint for idempotency claims.
 *
 * SHA-256 over method, raw path + canonical query, credential/anonymous bucket,
 * canonical JSON body, and optional Accept-Language suffix when machine-door
 * locale negotiation applies.
 *
 * Query strings are taken from the raw QUERY_STRING when present so repeated keys
 * keep order (`a=1&a=2` ≠ `a=2`), and bracket forms stay distinct (`a[]` ≠ `a`,
 * `a[b]` ≠ `a[c]`). JSON uses `JSON_BIGINT_AS_STRING` and preserves `{}` vs `[]`.
 */
final class Fingerprinter
{
    /**
     * Bump with every change to the material below; records keep the version they
     * were fingerprinted with. 1 = method, path, query, canonical JSON body, bucket
     * and the served locale.
     */
    public const int VERSION = 1;

    /**
     * @throws JsonException When the body is not valid JSON.
     * @throws PayloadTooLargeException When the body exceeds maxBytes.
     */
    public function fingerprint(
        Request $request,
        int $maxBytes,
        string $acceptLanguageSuffix = '',
        string $bucket = '',
    ): string {
        $method = strtoupper($request->getMethod());
        $path = $request->getPathInfo();
        $query = $this->canonicalQueryString($request);
        $pathLine = $path.($query !== '' ? '?'.$query : '');

        $rawBody = $request->getContent();

        if (strlen($rawBody) > $maxBytes) {
            throw new PayloadTooLargeException;
        }

        $canonicalBody = $this->canonicalJsonBody($rawBody);

        $material = $method."\n".$pathLine."\n".$bucket."\n".$canonicalBody."\n".$acceptLanguageSuffix;

        return hash('sha256', $material);
    }

    private function canonicalQueryString(Request $request): string
    {
        $raw = $request->server->get('QUERY_STRING');

        if (is_string($raw) && $raw !== '') {
            return $this->canonicalQueryFromRaw($raw);
        }

        return $this->canonicalQueryFromBag($request->query->all());
    }

    private function canonicalQueryFromRaw(string $raw): string
    {
        $pairs = [];

        foreach (explode('&', $raw) as $segment) {
            // `a=1&&b=2` and a trailing `&` fingerprint like `a=1&b=2`.
            if ($segment === '') {
                continue;
            }

            $equalsPosition = strpos($segment, '=');

            // A bare key (`?flag`) fingerprints like an empty value (`?flag=`).
            if ($equalsPosition === false) {
                $pairs[] = [rawurldecode($segment), ''];

                continue;
            }

            $pairs[] = [
                rawurldecode(substr($segment, 0, $equalsPosition)),
                rawurldecode(substr($segment, $equalsPosition + 1)),
            ];
        }

        return $this->encodeSortedPairs($pairs);
    }

    /**
     * Fallback when QUERY_STRING is absent (synthetic requests in unit tests).
     *
     * @param  array<string, mixed>  $parameters
     */
    private function canonicalQueryFromBag(array $parameters): string
    {
        return $this->encodeSortedPairs($this->flattenQueryPairs($parameters));
    }

    /**
     * Sort by key; keep relative order of equal keys (stable) so `a=1&a=2` ≠ `a=2&a=1`.
     *
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    private function encodeSortedPairs(array $pairs): string
    {
        $indexed = [];

        foreach ($pairs as $index => $pair) {
            $indexed[] = [$pair[0], $index, $pair[1]];
        }

        usort(
            $indexed,
            static function (array $left, array $right): int {
                return $left[0] <=> $right[0] ?: $left[1] <=> $right[1];
            },
        );

        $parts = [];

        foreach ($indexed as [$name, $index, $value]) {
            unset($index);
            $parts[] = rawurlencode($name).'='.rawurlencode($value);
        }

        return implode('&', $parts);
    }

    /**
     * @param  array<array-key, mixed>  $parameters
     * @return list<array{0: string, 1: string}>
     */
    private function flattenQueryPairs(array $parameters, string $prefix = ''): array
    {
        $pairs = [];

        foreach ($parameters as $name => $value) {
            // A numeric query key (`?0=a`) arrives as an int array key.
            $key = $prefix === '' ? (string) $name : $prefix.'['.$name.']';

            if (is_array($value)) {
                if ($value === []) {
                    // Empty array still contributes a key= pair for collision resistance.
                    $pairs[] = [$key, ''];

                    continue;
                }

                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            foreach ($this->flattenQueryPairs($item, $key) as $nested) {
                                $pairs[] = $nested;
                            }

                            continue;
                        }

                        $pairs[] = [$key, is_scalar($item) ? (string) $item : ''];
                    }

                    continue;
                }

                foreach ($this->flattenQueryPairs($value, $key) as $nested) {
                    $pairs[] = $nested;
                }

                continue;
            }

            $pairs[] = [$key, is_scalar($value) ? (string) $value : ''];
        }

        return $pairs;
    }

    /**
     * @throws JsonException
     */
    private function canonicalJsonBody(string $rawBody): string
    {
        if ($rawBody === '') {
            return '';
        }

        // Objects stay stdClass, so `{}` and `[]` keep apart and every PHP array is a list.
        $decoded = json_decode($rawBody, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

        $sorted = $this->canonicalJsonValue($decoded);

        return json_encode(
            $sorted,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR,
        );
    }

    private function canonicalJsonValue(mixed $value): mixed
    {
        if (is_object($value)) {
            $properties = get_object_vars($value);
            ksort($properties);
            $sorted = new \stdClass;

            foreach ($properties as $key => $item) {
                $sorted->{$key} = $this->canonicalJsonValue($item);
            }

            return $sorted;
        }

        return is_array($value) ? array_map($this->canonicalJsonValue(...), $value) : $value;
    }
}
