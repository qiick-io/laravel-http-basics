<?php

declare(strict_types=1);

/*
 * Standards review S2: a strict CSP with a per-request nonce, handed to Vite, and
 * optional script hashes; 'strict-dynamic' only alongside a nonce.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Vite;
use Qiick\HttpBasics\Laravel\HttpBasicsServiceProvider;
use Qiick\HttpBasics\Security\CspNonceProvider;
use Qiick\HttpBasics\Security\CspPolicyBuilder;
use Qiick\HttpBasics\Security\RequestCspNonceProvider;
use Qiick\HttpBasics\Security\SecurityHeaderBuilder;
use Qiick\HttpBasics\Security\SecurityHeadersPolicy;
use Qiick\HttpBasics\Security\SetSecurityHeadersMiddleware;
use Qiick\HttpBasics\Security\ViteFriendlyCspPolicyBuilder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stand-in for Laravel's Vite: records the nonce it was handed.
 */
final class NonceRecordingVite
{
    public ?string $nonce = null;

    public function useCspNonce(?string $nonce = null): string
    {
        return $this->nonce = $nonce ?? 'generated';
    }
}

/**
 * A provider that always answers the same nonce, to pin exact policies.
 */
function fixedNonceProvider(string $nonce): CspNonceProvider
{
    return new class($nonce) implements CspNonceProvider
    {
        public function __construct(private string $nonce) {}

        public function nonceFor(Request $request): string
        {
            return $this->nonce;
        }
    };
}

function scriptSourceOf(string $policy): string
{
    preg_match('/(?:^|; )script-src ([^;]*)/', $policy, $match);

    return $match[1] ?? '';
}

it('gives one request one nonce of 128 random bits, and another request another', function (): void {
    $provider = new RequestCspNonceProvider;
    $first = Request::create('/');
    $second = Request::create('/');

    $nonce = $provider->nonceFor($first);

    expect($provider->nonceFor($first))->toBe($nonce)
        ->and(strlen((string) base64_decode($nonce, true)))->toBe(16)
        ->and($provider->nonceFor($second))->not->toBe($nonce);
});

it('builds a strict script-src around the nonce, without unsafe-eval', function (): void {
    $builder = new ViteFriendlyCspPolicyBuilder(fixedNonceProvider('abc123=='));

    expect(scriptSourceOf($builder->build(Request::create('/'))))
        ->toBe("'self' 'nonce-abc123==' 'strict-dynamic' 'unsafe-inline'");
});

it('adds configured script hashes, with strict-dynamic only next to a nonce', function (): void {
    $hashes = ['sha256-AbC+/9=', 'sha384-xyz'];

    expect(scriptSourceOf((new ViteFriendlyCspPolicyBuilder(null, $hashes))->build(Request::create('/'))))
        ->toBe("'self' 'unsafe-inline' 'unsafe-eval' 'sha256-AbC+/9=' 'sha384-xyz'")
        ->and(scriptSourceOf((new ViteFriendlyCspPolicyBuilder(fixedNonceProvider('n0nce'), $hashes))->build(Request::create('/'))))
        ->toBe("'self' 'nonce-n0nce' 'strict-dynamic' 'sha256-AbC+/9=' 'sha384-xyz' 'unsafe-inline'");
});

it('keeps the policy without a nonce or hashes exactly as before', function (): void {
    expect(scriptSourceOf((new ViteFriendlyCspPolicyBuilder)->build(Request::create('/'))))
        ->toBe("'self' 'unsafe-inline' 'unsafe-eval'");
});

it('hands the request nonce to Vite and sends it in the CSP when nonces are on', function (): void {
    Facade::setFacadeApplication(app());
    $vite = new NonceRecordingVite;
    Vite::swap($vite);
    $nonces = new RequestCspNonceProvider;
    $middleware = new SetSecurityHeadersMiddleware(
        new SecurityHeaderBuilder(new ViteFriendlyCspPolicyBuilder($nonces)),
        new SecurityHeadersPolicy(cspNonce: true),
        $nonces,
    );
    $request = Request::create('/dashboard');
    $seenByHandler = null;

    $response = $middleware->handle($request, function () use ($vite, &$seenByHandler): Response {
        // Templates render inside the handler: Vite must know the nonce by then.
        $seenByHandler = $vite->nonce;

        return new Response('page');
    });

    expect($seenByHandler)->toBe($nonces->nonceFor($request))
        ->and($response->headers->get('Content-Security-Policy'))->toContain("'nonce-".$nonces->nonceFor($request)."'");

    Vite::clearResolvedInstance();
});

it('leaves Vite alone when nonces are off', function (): void {
    Facade::setFacadeApplication(app());
    $vite = new NonceRecordingVite;
    Vite::swap($vite);
    $middleware = new SetSecurityHeadersMiddleware(
        new SecurityHeaderBuilder(new ViteFriendlyCspPolicyBuilder),
        new SecurityHeadersPolicy,
        new RequestCspNonceProvider,
    );

    $middleware->handle(Request::create('/dashboard'), static fn (): Response => new Response('page'));

    expect($vite->nonce)->toBeNull();

    Vite::clearResolvedInstance();
});

it('still answers when no Vite is bound', function (): void {
    Facade::setFacadeApplication(app());
    Vite::clearResolvedInstance();
    app()->offsetUnset(Illuminate\Foundation\Vite::class);
    app()->bind(Illuminate\Foundation\Vite::class, static fn () => throw new RuntimeException('no vite'));
    $nonces = new RequestCspNonceProvider;
    $middleware = new SetSecurityHeadersMiddleware(
        new SecurityHeaderBuilder(new ViteFriendlyCspPolicyBuilder($nonces)),
        new SecurityHeadersPolicy(cspNonce: true),
        $nonces,
    );

    expect($middleware->handle(Request::create('/dashboard'), static fn (): Response => new Response('page'))->getStatusCode())->toBe(200);
});

it('wires nonces and script hashes from config', function (): void {
    config([
        'http-basics.security.csp.nonce' => true,
        'http-basics.security.csp.script_hashes' => ['sha256-AbC+/9='],
    ]);
    $request = Request::create('/');

    expect(app(SecurityHeadersPolicy::class)->cspNonce)->toBeTrue()
        ->and(scriptSourceOf(app(CspPolicyBuilder::class)->build($request)))
        ->toBe("'self' 'nonce-".app(CspNonceProvider::class)->nonceFor($request)."' 'strict-dynamic' 'sha256-AbC+/9=' 'unsafe-inline'");
});

it('refuses a script hash that is not a CSP hash source, at boot', function (string $hash): void {
    config(['http-basics.security.csp.script_hashes' => [$hash]]);

    /** @var HttpBasicsServiceProvider $provider */
    $provider = app()->getProvider(HttpBasicsServiceProvider::class);

    expect(fn () => $provider->boot())
        ->toThrow(InvalidArgumentException::class, 'http-basics.security.csp.script_hashes must hold sha256-, sha384- or sha512- base64 hashes.');
})->with(['md5-abc', "sha256-abc' 'unsafe-eval", 'sha256-']);

it('refuses a handler that does not return a Symfony response', function (): void {
    $middleware = new SetSecurityHeadersMiddleware(new SecurityHeaderBuilder(new ViteFriendlyCspPolicyBuilder), new SecurityHeadersPolicy);

    expect(fn () => $middleware->handle(Request::create('/'), static fn (): string => 'not a response'))
        ->toThrow(UnexpectedValueException::class);
});

it('keeps the Vite dev server origin in a strict script-src', function (): void {
    Facade::setFacadeApplication(app());
    Vite::swap(new class
    {
        public function devServerUrl(): string
        {
            return 'http://vite.test:5173';
        }
    });

    try {
        $policy = (new ViteFriendlyCspPolicyBuilder(fixedNonceProvider('n0nce')))->build(Request::create('/'));
    } finally {
        Vite::clearResolvedInstance();
    }

    expect(scriptSourceOf($policy))->toBe("'self' 'nonce-n0nce' 'strict-dynamic' http://vite.test:5173 'unsafe-inline'");
});

it('refuses script hashes that are not a list, at boot', function (): void {
    config([
        'http-basics.security.csp.nonce' => true,
        'http-basics.security.csp.script_hashes' => 'sha256-AbC+/9=',
    ]);

    expect(fn () => bootProvider())
        ->toThrow(InvalidArgumentException::class, 'http-basics.security.csp.script_hashes must be a list of hashes.');
});

it('refuses script hashes without the nonce, at boot', function (): void {
    // A hash makes CSP2+ browsers ignore 'unsafe-inline', so every other inline script would stop running.
    config([
        'http-basics.security.csp.nonce' => false,
        'http-basics.security.csp.script_hashes' => ['sha256-AbC+/9='],
    ]);

    expect(fn () => bootProvider())
        ->toThrow(InvalidArgumentException::class, 'http-basics.security.csp.script_hashes needs http-basics.security.csp.nonce: without it a hash turns off \'unsafe-inline\' for every other inline script.');
});

it('refuses a script hash that is not a CSP hash source when the builder is made', function (): void {
    // The boot check misses a config()->set() after boot and a builder made by hand.
    expect(fn () => new ViteFriendlyCspPolicyBuilder(fixedNonceProvider('n0nce'), ["sha256-abc' 'unsafe-eval"]))
        ->toThrow(InvalidArgumentException::class, 'http-basics.security.csp.script_hashes must hold sha256-, sha384- or sha512- base64 hashes.');
});
