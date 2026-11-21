<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Locale;

/** @api */
enum LocaleSource: string
{
    case Header = 'header';
    case Principal = 'principal';
    case Cookie = 'cookie';
    case Default = 'default';
    case Fallback = 'fallback';
}
