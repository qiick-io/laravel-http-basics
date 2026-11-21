<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Qiick\HttpBasics\RequestId\RequestIdAcceptFrom;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Testing\AlwaysTrustedProxyDetector;
use Qiick\HttpBasics\Testing\FixedIdGenerator;
use Qiick\HttpBasics\Tests\Support\CaptureRequestIdJob;

beforeEach(function (): void {
    Context::flush();
    CaptureRequestIdJob::$seen = null;
    config(['queue.default' => 'sync']);
});

it('inherits the visible request_id into a sync queued job', function (): void {
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('job-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
    );
    $resolver->bind(Request::create('/', 'GET'));

    expect(Context::get(RequestIdResolver::VISIBLE_CONTEXT_KEY))->toBe('job-request-id');

    dispatch(new CaptureRequestIdJob);

    expect(CaptureRequestIdJob::$seen)->toBe('job-request-id');
});
