<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\RequestId;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Qiick\HttpBasics\Contracts\IdGenerator;
use Qiick\HttpBasics\Contracts\TrustedProxyDetector;
use Symfony\Component\HttpFoundation\Response;

/** @api */
final class RequestIdResolver
{
    /**
     * @param  list<string>  $hiddenContextKeys
     */
    public function __construct(
        private IdGenerator $ids,
        private TrustedProxyDetector $proxies,
        private RequestIdAcceptFrom $acceptFrom,
        private array $hiddenContextKeys,
        private string $provenanceContextKey = 'http_basics.request_id_source',
    ) {}

    public function bind(Request $request): RequestId
    {
        $id = $this->resolve($request);

        $request->headers->set(RequestId::HEADER, $id->value);

        foreach ($this->hiddenContextKeys as $key) {
            Context::addHidden($key, $id->value);
        }

        Context::addHidden($this->provenanceContextKey, $id->source->value);

        return $id;
    }

    public function echoInto(RequestId $id, Response $response): void
    {
        $response->headers->set(RequestId::HEADER, $id->value);
    }

    public function current(): RequestId
    {
        $value = null;

        foreach ($this->hiddenContextKeys as $key) {
            if (Context::hasHidden($key)) {
                $value = Context::getHidden($key);
                break;
            }
        }

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException('No request id is bound in the current Context.');
        }

        $sourceRaw = Context::hasHidden($this->provenanceContextKey)
            ? Context::getHidden($this->provenanceContextKey)
            : RequestIdSource::Generated->value;

        $source = is_string($sourceRaw)
            ? (RequestIdSource::tryFrom($sourceRaw) ?? RequestIdSource::Generated)
            : RequestIdSource::Generated;

        return new RequestId($value, $source);
    }

    private function resolve(Request $request): RequestId
    {
        if ($this->acceptFrom === RequestIdAcceptFrom::Never) {
            return new RequestId($this->ids->generate(), RequestIdSource::Generated);
        }

        /** @var list<string|null> $values */
        $values = $request->headers->all(RequestId::HEADER);
        // Symfony may return a nested list for multi-value headers.
        $flat = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                foreach ($value as $inner) {
                    $flat[] = is_string($inner) ? $inner : null;
                }
            } else {
                $flat[] = is_string($value) ? $value : null;
            }
        }

        $incoming = RequestId::tryFromHeaderValues($flat);

        if ($incoming === null) {
            return new RequestId($this->ids->generate(), RequestIdSource::Generated);
        }

        if ($this->acceptFrom === RequestIdAcceptFrom::Any) {
            return new RequestId($incoming->value, RequestIdSource::Client);
        }

        // trusted_proxy (default)
        if ($this->proxies->isTrusted($request)) {
            return new RequestId($incoming->value, RequestIdSource::TrustedProxy);
        }

        return new RequestId($this->ids->generate(), RequestIdSource::Generated);
    }
}
