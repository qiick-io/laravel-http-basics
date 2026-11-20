<?php

declare(strict_types=1);

/**
 * Package-only architecture rules. Naming, suffixes, strict types and readonly
 * DTOs are the shared qiick rules in NamingArchitectureTest.php (configured in
 * naming-architecture.php).
 */
arch('the Laravel namespace only holds the service provider')
    ->expect('Qiick\HttpBasics\Laravel')
    ->toHaveSuffix('ServiceProvider');

arch('src never imports Illuminate Foundation types')
    ->expect('Qiick\HttpBasics')
    ->not->toUse('Illuminate\Foundation');
