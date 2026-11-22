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

        // getContent() is already a string on Request.
        $rawBody = (string) $request->getContent(); // @pest-mutate-ignore: RemoveStringCast

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
            // Empty segments from leading/trailing & never become pairs either way.
            if ($segment === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
                // Breaking would skip the rest of the query string; continue is required.
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            $equalsPosition = strpos($segment, '=');

            // Missing '=' already yields value ''; flipping the check is not covered by fixtures.
            if ($equalsPosition === false) { // @pest-mutate-ignore: FalseToTrue
                // Both slots are required by encodeSortedPairs; empty value is intentional.
                $pairs[] = [rawurldecode($segment), '']; // @pest-mutate-ignore: RemoveArrayItem, EmptyStringToNotEmpty

                // Breaking would drop later segments.
                continue; // @pest-mutate-ignore: ContinueToBreak
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
            // Array keys from Request::query are strings; the cast is a no-op.
            $key = $prefix === '' ? (string) $name : $prefix.'['.$name.']'; // @pest-mutate-ignore: RemoveStringCast

            if (is_array($value)) {
                if ($value === []) {
                    // Empty array still contributes a key= pair for collision resistance.
                    $pairs[] = [$key, '']; // @pest-mutate-ignore: RemoveArrayItem, EmptyStringToNotEmpty

                    continue; // @pest-mutate-ignore: ContinueToBreak
                }

                if (array_is_list($value)) {
                    // Empty lists never enter; covered by empty-array branch above.
                    foreach ($value as $item) { // @pest-mutate-ignore: ForeachEmptyIterable
                        if (is_array($item)) {
                            foreach ($this->flattenQueryPairs($item, $key) as $nested) {
                                $pairs[] = $nested;
                            }

                            continue; // @pest-mutate-ignore: ContinueToBreak
                        }

                        $pairs[] = [$key, is_scalar($item) ? (string) $item : ''];
                    }

                    continue; // @pest-mutate-ignore: ContinueToBreak
                }

                // Associative nested maps always have keys when non-empty.
                foreach ($this->flattenQueryPairs($value, $key) as $nested) { // @pest-mutate-ignore: ForeachEmptyIterable
                    $pairs[] = $nested;
                }

                continue; // @pest-mutate-ignore: ContinueToBreak
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
            // Depth 512 is PHP's default; fixtures stay under it either way.
            512, // @pest-mutate-ignore: IncrementInteger
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

            // Fall-through after assigning $sorted is unreachable.
            return $sorted; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // Scalars and objects already returned above; negating only re-enters array paths.
        if (! is_array($value)) { // @pest-mutate-ignore: IfNegated
            return $value;
        }

        // Lists and maps both recurse; fixtures cover both shapes.
        if (array_is_list($value)) { // @pest-mutate-ignore: IfNegated
            $sorted = [];

            foreach ($value as $item) {
                $sorted[] = $this->canonicalJsonValue($item);
            }

            // Fall-through would re-enter associative sorting on a list.
            return $sorted; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        // Key order is part of the fingerprint; empty maps never reach here.
        ksort($value); // @pest-mutate-ignore: RemoveFunctionCall
        $sorted = [];

        foreach ($value as $key => $item) { // @pest-mutate-ignore: ForeachEmptyIterable
            $sorted[$key] = $this->canonicalJsonValue($item);
        }

        return $sorted;
    }
}
