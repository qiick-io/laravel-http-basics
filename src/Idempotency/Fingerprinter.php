<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Idempotency;

use Illuminate\Http\Request;
use JsonException;

/**
 * @api Canonical request fingerprint for idempotency claims.
 *
 * SHA-256 over method, raw path + canonical query, canonical JSON body, and optional
 * Accept-Language suffix when machine-door locale negotiation applies.
 *
 * Query strings are taken from the raw QUERY_STRING when present so repeated keys
 * keep order (`a=1&a=2` ≠ `a=2`), and bracket forms stay distinct (`a[]` ≠ `a`,
 * `a[b]` ≠ `a[c]`). JSON uses `JSON_BIGINT_AS_STRING` and preserves `{}` vs `[]`.
 */
final class Fingerprinter
{
    /**
     * @throws JsonException When the body is not valid JSON.
     * @throws PayloadTooLargeException When the body exceeds maxBytes.
     */
    public function fingerprint(
        Request $request,
        int $maxBytes,
        string $acceptLanguageSuffix = '',
    ): string {
        $method = strtoupper($request->getMethod());
        $path = $request->getPathInfo();
        $query = $this->canonicalQueryString($request);
        $pathLine = $path.($query !== '' ? '?'.$query : '');

        $rawBody = (string) $request->getContent();

        if (strlen($rawBody) > $maxBytes) {
            throw new PayloadTooLargeException;
        }

        $canonicalBody = $this->canonicalJsonBody($rawBody);

        $material = $method."\n".$pathLine."\n".$canonicalBody."\n".$acceptLanguageSuffix;

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
            if ($segment === '') {
                continue;
            }

            $equalsPosition = strpos($segment, '=');

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
            $key = $prefix === '' ? (string) $name : $prefix.'['.$name.']';

            if (is_array($value)) {
                if ($value === []) {
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

        $decoded = json_decode(
            $rawBody,
            false,
            512,
            JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
        );

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

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $sorted = [];

            foreach ($value as $item) {
                $sorted[] = $this->canonicalJsonValue($item);
            }

            return $sorted;
        }

        ksort($value);
        $sorted = [];

        foreach ($value as $key => $item) {
            $sorted[$key] = $this->canonicalJsonValue($item);
        }

        return $sorted;
    }
}
