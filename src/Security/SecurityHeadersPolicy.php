<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

/**
 * @api Immutable security-header settings for one app. Built from
 * `http-basics.security` (+ locale machine-door patterns) by the service provider.
 */
final readonly class SecurityHeadersPolicy
{
    /**
     * @param  list<string>  $machineDoorPatterns
     */
    public function __construct(
        public bool $enabled = true,
        public string $xFrameOptions = 'DENY',
        public string $referrerPolicy = 'strict-origin-when-cross-origin',
        public string $permissionsPolicy = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        public CspMode $cspMode = CspMode::Enforce,
        public ?string $csp = null,
        public ?string $cspReportOnly = null,
        public ?string $cspReportUri = null,
        public int $hstsMaxAge = 0,
        public bool $hstsIncludeSubdomains = false,
        public bool $hstsPreload = false,
        public bool $baselineOnMachineDoors = true,
        public array $machineDoorPatterns = ['api/*', 'mcp', 'mcp/*'],
    ) {}
}
