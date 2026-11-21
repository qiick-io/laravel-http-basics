<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Qiick\HttpBasics\RequestId\RequestIdResolver;

final class CaptureRequestIdJob implements ShouldQueue
{
    use Queueable;

    public static ?string $seen = null;

    public function handle(): void
    {
        $value = Context::get(RequestIdResolver::VISIBLE_CONTEXT_KEY);
        self::$seen = is_string($value) ? $value : null;
    }
}
