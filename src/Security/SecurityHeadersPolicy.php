<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Security;

/**
 * @api Immutable security-header settings for one app. Built from
 * `http-basics.security` (+ locale machine-door patterns) by the service provider.
 */
final readonly class SecurityHeadersPolicy
{
    /** @var list<string> */
    public array $machineDoorPatterns;

    /**
     * @param  list<string>|null  $machineDoorPatterns  Null uses the default machine routes.
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
        ?array $machineDoorPatterns = null,
        // A per-request CSP nonce, handed to Vite before the handler renders (S2).
        public bool $cspNonce = false,
    ) {
        $this->machineDoorPatterns = $machineDoorPatterns ?? ['api/*', 'mcp', 'mcp/*'];
    }
}
