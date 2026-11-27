<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('blocks unfaked HTTP requests and processes by default', function (): void {
    expect(Http::preventingStrayRequests())->toBeTrue()
        ->and(Process::preventingStrayProcesses())->toBeTrue();
});
