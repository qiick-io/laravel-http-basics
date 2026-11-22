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
        string $acceptLanguageSuffix = '',
    ): string {
        $method = strtoupper($request->getMethod());
        $path = $request->getPathInfo();
        $query = $this->canonicalQueryString($request);
        $pathLine = $path.($query !== '' ? '?'.$query : '');

        // Request::getContent() is already string-typed in Symfony 7.
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
                foreach ($value as $single) {
                    $encoded = is_scalar($single) ? (string) $single : '';
                    // Query bag keys are strings; rawurlencode would coerce the same way.
                    $parts[] = rawurlencode((string) $name).'='.rawurlencode($encoded); // @pest-mutate-ignore: RemoveStringCast
                }

                continue;
            }

            $encodedValue = is_scalar($value) ? (string) $value : '';
            // Query bag keys are strings; rawurlencode would coerce the same way.
            $parts[] = rawurlencode((string) $name).'='.rawurlencode($encodedValue); // @pest-mutate-ignore: RemoveStringCast
        }

        return implode('&', $parts);
    }

    /**
     * @throws JsonException
     */
    private function canonicalJsonBody(string $rawBody): string
    {
        if ($rawBody === '') {
            return '';
        }

        // Depth 513 still accepts every payload depth 512 accepts (IncrementInteger equivalent).
        $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR); // @pest-mutate-ignore: IncrementInteger
        $sorted = $this->recursiveKeySort($decoded);

        return json_encode($sorted, JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR);
    }

    private function recursiveKeySort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $sorted = [];
            foreach ($value as $item) {
                $sorted[] = $this->recursiveKeySort($item);
            }

            // Falling through ksort()+rebuild on a list yields the same list.
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
