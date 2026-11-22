<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Qiick\HttpBasics\Idempotency\Fingerprinter;
use Qiick\HttpBasics\Idempotency\PayloadTooLargeException;

it('canonicalizes json whitespace key order and zero fractions', function (): void {
    $fingerprinter = new Fingerprinter;

    $first = Request::create(
        '/items',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{"b":2,"a":{"z":0,"y":1.0}}',
    );

    $second = Request::create(
        '/items',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        "{\n  \"a\": {\"y\": 1.0, \"z\": 0},\n  \"b\": 2\n}",
    );

    expect($fingerprinter->fingerprint($first, 1048576))->toBe($fingerprinter->fingerprint($second, 1048576));
});

it('preserves duplicate query values in encounter order', function (): void {
    $fingerprinter = new Fingerprinter;

    $first = Request::create(
        '/items?tag=a&tag=b',
        'POST',
        ['tag' => ['a', 'b']],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{}',
    );

    $second = Request::create(
        '/items?tag=b&tag=a',
        'POST',
        ['tag' => ['b', 'a']],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        '{}',
    );

    expect($fingerprinter->fingerprint($first, 1048576))->not->toBe($fingerprinter->fingerprint($second, 1048576));
});

it('treats paths with and without trailing segments as distinct', function (): void {
    $fingerprinter = new Fingerprinter;

    $withSlash = Request::create('/items/', 'POST', [], [], [], [], '{}');
    $without = Request::create('/items', 'POST', [], [], [], [], '{}');

    expect($fingerprinter->fingerprint($withSlash, 1048576))
        ->not->toBe($fingerprinter->fingerprint($without, 1048576));
});

it('includes accept language suffix when provided', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/api/widgets', 'POST', [], [], [], [], '{}');

    $without = $fingerprinter->fingerprint($request, 1048576);
    $withGerman = $fingerprinter->fingerprint($request, 1048576, 'de-DE,de;q=0.9,en;q=0.8');

    expect($withGerman)->not->toBe($without)
        ->and($withGerman)->toBe(hash('sha256', "POST\n/api/widgets\n\n{}\nde-DE,de;q=0.9,en;q=0.8"))
        ->and($without)->toBe(hash('sha256', "POST\n/api/widgets\n\n{}\n"));
});

it('rejects oversize bodies', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/items', 'POST', [], [], [], [], str_repeat('a', 20));

    $fingerprinter->fingerprint($request, 10);
})->throws(PayloadTooLargeException::class);

it('uppercases the method and joins path with a sorted query marker', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/items?b=2&a=1', 'post', [], [], [], [], '{}');

    expect($fingerprinter->fingerprint($request, 1048576))
        ->toBe(hash('sha256', "POST\n/items?a=1&b=2\n\n{}\n"));
});

it('omits the query marker when the query string is empty', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/items', 'POST', [], [], [], [], '{}');

    expect($fingerprinter->fingerprint($request, 1048576))
        ->toBe(hash('sha256', "POST\n/items\n\n{}\n"));
});

it('rejects bodies that equal the max byte limit only when over', function (): void {
    $fingerprinter = new Fingerprinter;
    $body = '{"a":1}';
    $request = Request::create('/items', 'POST', [], [], [], [], $body);

    expect($fingerprinter->fingerprint($request, strlen($body)))
        ->toBe(hash('sha256', "POST\n/items\n\n{\"a\":1}\n"));
});

it('encodes scalar and non-scalar query values', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/items', 'POST', [], [], [], [], '{}');
    $queryParameters = new ReflectionProperty($request->query, 'parameters');
    $queryParameters->setValue($request->query, [
        'plain' => 'x y',
        'list' => ['a', new stdClass],
        'object' => new stdClass,
    ]);

    expect($fingerprinter->fingerprint($request, 1048576))
        ->toBe(hash('sha256', "POST\n/items?list=a&list=&object=&plain=x%20y\n\n{}\n"));
});

it('returns an empty canonical body for empty content', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/items', 'POST', [], [], [], [], '');

    expect($fingerprinter->fingerprint($request, 1048576))
        ->toBe(hash('sha256', "POST\n/items\n\n\n"));
});

it('sorts nested objects and preserves list order', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create(
        '/items',
        'POST',
        [],
        [],
        [],
        [],
        '{"z":[{"b":1,"a":2},{"c":3}],"a":true}',
    );

    expect($fingerprinter->fingerprint($request, 1048576))
        ->toBe(hash('sha256', "POST\n/items\n\n{\"a\":true,\"z\":[{\"a\":2,\"b\":1},{\"c\":3}]}\n"));
});

it('preserves zero fractions in encoded json', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/items', 'POST', [], [], [], [], '{"n":1.0}');

    expect($fingerprinter->fingerprint($request, 1048576))
        ->toBe(hash('sha256', "POST\n/items\n\n{\"n\":1.0}\n"));
});

it('uppercases methods that the request object leaves lowercase', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = new class extends Request
    {
        public function getMethod(): string
        {
            return 'post';
        }
    };
    $request->initialize(request: [], attributes: [], cookies: [], files: [], server: [
        'REQUEST_URI' => '/items',
        'REQUEST_METHOD' => 'POST',
    ], content: '{}');
    $request->server->set('REQUEST_URI', '/items');

    expect($fingerprinter->fingerprint($request, 1048576))
        ->toBe(hash('sha256', "POST\n/items\n\n{}\n"));
});

it('stringifies integer query values in the canonical query', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/items', 'POST', [], [], [], [], '{}');
    $queryParameters = new ReflectionProperty($request->query, 'parameters');
    $queryParameters->setValue($request->query, [
        'n' => 2,
        'tags' => [1, 2],
    ]);

    expect($fingerprinter->fingerprint($request, 1048576))
        ->toBe(hash('sha256', "POST\n/items?n=2&tags=1&tags=2\n\n{}\n"));
});

it('keeps unicode and slashes unescaped in the canonical json body', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/items', 'POST', [], [], [], [], '{"p":"a/b","t":"café"}');

    expect($fingerprinter->fingerprint($request, 1048576))
        ->toBe(hash('sha256', "POST\n/items\n\n{\"p\":\"a/b\",\"t\":\"café\"}\n"));
});

it('accepts json at depth 511 which fails if the decode limit is decremented', function (): void {
    $fingerprinter = new Fingerprinter;
    $nested = '';
    for ($depth = 0; $depth < 511; $depth++) {
        $nested .= '{"a":';
    }
    $nested .= '1';
    for ($depth = 0; $depth < 511; $depth++) {
        $nested .= '}';
    }
    $request = Request::create('/items', 'POST', [], [], [], [], $nested);

    expect($fingerprinter->fingerprint($request, 1048576))->toBeString()->not->toBe('');
});

it('includes the credential bucket in the fingerprint material', function (): void {
    $fingerprinter = new Fingerprinter;
    $request = Request::create('/api/widgets', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

    expect($fingerprinter->fingerprint($request, 1048576, '', 'tenant:a'))
        ->not->toBe($fingerprinter->fingerprint($request, 1048576, '', 'tenant:b'))
        ->and($fingerprinter->fingerprint($request, 1048576, '', 'tenant:a'))
        ->toBe(hash('sha256', "POST\n/api/widgets\ntenant:a\n{}\n"));
});
