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
    public const string VISIBLE_CONTEXT_KEY = 'request_id';

    /**
     * @param  list<string>  $hiddenContextKeys  Optional dual-write aliases (e.g. externa).
     */
    public function __construct(
        private IdGenerator $ids,
        private TrustedProxyDetector $proxies,
        private RequestIdAcceptFrom $acceptFrom,
        private array $hiddenContextKeys = [],
        private string $provenanceContextKey = 'http_basics.request_id_source',
        private string $acceptPattern = RequestId::PATTERN,
    ) {}

    public function bind(Request $request): RequestId
    {
        $id = $this->resolve($request);

        $request->headers->set(RequestId::HEADER, $id->value);

        // Visible so Laravel's context processor logs it and queued jobs inherit it.
        Context::add(self::VISIBLE_CONTEXT_KEY, $id->value);

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

    /**
     * Bound id, or null when nothing is bound (CLI / jobs without a prior HTTP bind).
     * Never throws: outbound callers treat null as "send no header".
     */
    public function current(): ?RequestId
    {
        $value = null;

        if (Context::has(self::VISIBLE_CONTEXT_KEY)) {
            $value = Context::get(self::VISIBLE_CONTEXT_KEY);
        }

        if (! is_string($value) || $value === '') {
            foreach ($this->hiddenContextKeys as $key) {
                if (Context::hasHidden($key)) {
                    $value = Context::getHidden($key);
                    break;
                }
            }
        }

        if (! is_string($value) || $value === '') {
            return null;
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

        /** @var list<string|null> $flat */
        $flat = [];
        foreach ($request->headers->all(RequestId::HEADER) as $value) {
            $flat[] = is_string($value) ? $value : null;
        }

        $incoming = RequestId::tryFromHeaderValues($flat, $this->acceptPattern);

        if ($incoming === null) {
            return new RequestId($this->ids->generate(), RequestIdSource::Generated);
        }

        if ($this->acceptFrom === RequestIdAcceptFrom::Any) {
            return new RequestId($incoming->value, RequestIdSource::Client);
        }

        if ($this->proxies->isTrusted($request)) {
            return new RequestId($incoming->value, RequestIdSource::TrustedProxy);
        }

        return new RequestId($this->ids->generate(), RequestIdSource::Generated);
    }
}
