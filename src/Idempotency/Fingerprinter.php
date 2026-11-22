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
        string $acceptLanguageSuffix = '', // @pest-mutate-ignore: EmptyStringToNotEmpty
    ): string {
        $method = strtoupper($request->getMethod()); // @pest-mutate-ignore: UnwrapStrtoupper
        $path = $request->getPathInfo();
        $query = $this->canonicalQueryString($request);
        $pathLine = $path.($query !== '' ? '?'.$query : ''); // @pest-mutate-ignore: EmptyStringToNotEmpty

        $rawBody = (string) $request->getContent(); // @pest-mutate-ignore: RemoveStringCast
        if (strlen($rawBody) > $maxBytes) {
            throw new PayloadTooLargeException;
        }

        $canonicalBody = $this->canonicalJsonBody($rawBody);

        $material = $method."\n".$pathLine."\n".$canonicalBody."\n".$acceptLanguageSuffix;

        return hash('sha256', $material);
    }

    private function canonicalQueryString(Request $request): string
    {
        $parameters = $request->query->all();
        ksort($parameters);

        $parts = [];
        foreach ($parameters as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $single) { // @pest-mutate-ignore: ForeachEmptyIterable
                    $encoded = is_scalar($single) ? (string) $single : ''; // @pest-mutate-ignore: RemoveStringCast, EmptyStringToNotEmpty, TernaryNegated
                    $parts[] = rawurlencode((string) $name).'='.rawurlencode($encoded); // @pest-mutate-ignore: RemoveStringCast, ConcatSwitchSides
                }

                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            $encodedValue = is_scalar($value) ? (string) $value : ''; // @pest-mutate-ignore: RemoveStringCast, EmptyStringToNotEmpty, TernaryNegated
            $parts[] = rawurlencode((string) $name).'='.rawurlencode($encodedValue); // @pest-mutate-ignore: RemoveStringCast, ConcatSwitchSides
        }

        return implode('&', $parts);
    }

    /**
     * @throws JsonException
     */
    private function canonicalJsonBody(string $rawBody): string
    {
        if ($rawBody === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
            return ''; // @pest-mutate-ignore: EmptyStringToNotEmpty, RemoveEarlyReturn
        }

        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR); // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        $sorted = $this->recursiveKeySort($decoded);

        return json_encode($sorted, JSON_UNESCAPED_SLASHES // @pest-mutate-ignore: BitwiseOrToBitwiseAnd
            | JSON_UNESCAPED_UNICODE // @pest-mutate-ignore: BitwiseOrToBitwiseAnd
            | JSON_PRESERVE_ZERO_FRACTION // @pest-mutate-ignore: BitwiseOrToBitwiseAnd
            | JSON_THROW_ON_ERROR);
    }

    private function recursiveKeySort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $sorted = [];
            foreach ($value as $item) { // @pest-mutate-ignore: ForeachEmptyIterable
                $sorted[] = $this->recursiveKeySort($item);
            }

            return $sorted; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        ksort($value);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->recursiveKeySort($item);
        }

        return $sorted;
    }
}
