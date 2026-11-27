<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\HttpBasics\RequestId\RequestIdAcceptFrom;
use Qiick\HttpBasics\RequestId\RequestIdResolver;
use Qiick\HttpBasics\Testing\AlwaysTrustedProxyDetector;
use Qiick\HttpBasics\Testing\FixedIdGenerator;
use Qiick\HttpBasics\Tests\Support\CaptureRequestIdJob;

beforeEach(function (): void {
    Context::flush();
    CaptureRequestIdJob::$seen = null;

    config([
        'database.default' => 'testing',
        'database.connections.testing' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],
    ]);

    // Reconnect so the in-memory sqlite schema is visible to the queue worker.
    DB::purge('testing');
    DB::reconnect('testing');

    Schema::connection('testing')->dropIfExists('jobs');
    Schema::connection('testing')->create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
});

it('inherits the visible request_id into a database-queued job after serialisation', function (): void {
    $resolver = new RequestIdResolver(
        new FixedIdGenerator('job-request-id'),
        new AlwaysTrustedProxyDetector,
        RequestIdAcceptFrom::Never,
    );
    $resolver->bind(Request::create('/', 'GET'));

    expect(Context::get(RequestIdResolver::VISIBLE_CONTEXT_KEY))->toBe('job-request-id');

    dispatch(new CaptureRequestIdJob);

    expect(CaptureRequestIdJob::$seen)->toBeNull()
        ->and(DB::connection('testing')->table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', [
        '--once' => true,
        '--queue' => 'default',
        '--stop-when-empty' => true,
    ])->assertSuccessful();

    expect(CaptureRequestIdJob::$seen)->toBe('job-request-id');
});
