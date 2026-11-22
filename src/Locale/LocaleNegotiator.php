<?php

declare(strict_types=1);

namespace Qiick\HttpBasics\Locale;

use Illuminate\Http\Request;
use Qiick\HttpBasics\Contracts\DefaultLocaleResolver;
use Qiick\HttpBasics\Contracts\SupportedLocales;

/**
 * @api Negotiates the request locale for web vs machine doors.
 *
 * Machine doors: Accept-Language (capped) → principal → default → fallback.
 * Web: principal/user → cookie → default → fallback → en. Never Accept-Language.
 */
final class LocaleNegotiator
{
    public const string ATTRIBUTE = 'http_basics.locale';

    /**
     * RFC 7231 qvalue: 0[.0-3 digits] or 1[.0-3 zeros]. Rejects scientific
     * notation (`1e-1`) and leading-dot forms (`.5`).
     */
    private const string Q_VALUE_PATTERN = '/^(?:0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/D';

    public function __construct(
        private SupportedLocales $supported,
        private DefaultLocaleResolver $default,
        private MachineDoorClassifier $doors,
        private string $cookieName = 'locale',
        private string $fallbackLocale = 'en',
        private int $maxHeaderBytes = 1024,
        private int $maxLanguages = 20,
    ) {}

    public function resolve(Request $request): LocaleResolution
    {
        $resolution = $this->doors->isMachineDoor($request)
            ? $this->resolveMachineDoor($request)
            : $this->resolveWeb($request);

        $request->attributes->set(self::ATTRIBUTE, $resolution);

        return $resolution;
    }

    /**
     * Called from product auth middleware after the global locale middleware has
     * entered and before it writes Content-Language (i.e. inside `$next`).
     * Only upgrades the locale when Accept-Language did not already win.
     * Preserves whether Accept-Language was read (for Vary).
     */
    public function applyPrincipalLocale(Request $request, ?string $principalLocale): void
    {
        if ($principalLocale === null || ! $this->isSupported($principalLocale)) {
            return;
        }

        $current = $request->attributes->get(self::ATTRIBUTE);
        if ($this->isHeaderResolution($current)) {
            return;
        }

        $didReadAcceptLanguage = $current instanceof LocaleResolution
            && $current->didReadAcceptLanguage;

        $request->attributes->set(
            self::ATTRIBUTE,
            new LocaleResolution($principalLocale, LocaleSource::Principal, $didReadAcceptLanguage),
        );
    }

    public function current(Request $request): LocaleResolution
    {
        $current = $request->attributes->get(self::ATTRIBUTE);
        if ($current instanceof LocaleResolution) {
            return $current;
        }

        return $this->resolve($request);
    }

    private function isHeaderResolution(mixed $current): bool
    {
        return $current instanceof LocaleResolution
            && $current->source === LocaleSource::Header;
    }

    private function resolveMachineDoor(Request $request): LocaleResolution
    {
        $raw = $request->headers->get('Accept-Language');
        $didReadAcceptLanguage = is_string($raw);

        $fromHeader = $this->negotiateAcceptLanguage($raw);
        if ($fromHeader !== null) {
            return new LocaleResolution($fromHeader, LocaleSource::Header, true);
        }

        return $this->defaultChain($didReadAcceptLanguage);
    }

    private function resolveWeb(Request $request): LocaleResolution
    {
        $userLocale = $this->userLocale($request->user());
        if ($userLocale !== null && $this->isSupported($userLocale)) {
            return new LocaleResolution($userLocale, LocaleSource::Principal);
        }

        $cookie = $request->cookie($this->cookieName);
        if (is_string($cookie) && $this->isSupported($cookie)) {
            return new LocaleResolution($cookie, LocaleSource::Cookie);
        }

        return $this->defaultChain();
    }

    private function userLocale(mixed $user): ?string
    {
        // Empty locale fails isSupported the same as null (EmptyStringToNotEmpty equivalent).
        // BooleanOrToBooleanAnd is equivalent: non-objects never expose a string locale via isset.
        if (! is_object($user) || ! isset($user->locale) || ! is_string($user->locale) || $user->locale === '') {
            return null;
        }

        return $user->locale;
    }

    private function defaultChain(bool $didReadAcceptLanguage = false): LocaleResolution
    {
        $default = $this->default->resolve();
        if ($this->isSupported($default)) {
            return new LocaleResolution($default, LocaleSource::Default, $didReadAcceptLanguage);
        }

        if ($this->isSupported($this->fallbackLocale)) {
            return new LocaleResolution($this->fallbackLocale, LocaleSource::Fallback, $didReadAcceptLanguage);
        }

        if ($this->isSupported('en')) {
            return new LocaleResolution('en', LocaleSource::Fallback, $didReadAcceptLanguage);
        }

        $available = $this->supported->all();
        if ($available === []) {
            return new LocaleResolution($this->fallbackLocale, LocaleSource::Fallback, $didReadAcceptLanguage);
        }

        return new LocaleResolution($available[0], LocaleSource::Fallback, $didReadAcceptLanguage);
    }

    /**
     * Negotiate a supported locale from a raw Accept-Language value, or null
     * when the header is absent, oversize, over-cap, empty after parse, or
     * matches nothing (including when only `*` remains but nothing is supported).
     */
    private function negotiateAcceptLanguage(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        // Strict greater-than: a header of exactly maxHeaderBytes is still accepted.
        if (strlen($raw) > $this->maxHeaderBytes) {
            return null;
        }

        $rangeParts = $this->splitLanguageRangeParts($raw);
        // Cap counts raw ranges before q-parse / dedupe.
        if ($rangeParts === [] || count($rangeParts) > $this->maxLanguages) {
            return null;
        }

        $ranges = $this->orderLanguageRanges($rangeParts);
        // Empty ordered ranges still fall through to null after both match loops.
        if ($ranges === []) {
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $available = $this->supported->all();
        if ($available === []) {
            return null;
        }

        foreach ($ranges as $tag) {
            // '*' never matches via matchAvailable; handled in the pass below.
            $match = $this->matchAvailable($tag, $available);
            if ($match !== null) {
                return $match;
            }
        }

        // '*' only wins when nothing else matched and it was present.
        foreach ($ranges as $tag) {
            if ($tag === '*') {
                return $available[0];
            }
        }

        return null;
    }

    /**
     * Non-empty comma-separated Accept-Language parts (before q-parse / dedupe).
     *
     * @return list<string>
     */
    private function splitLanguageRangeParts(string $header): array
    {
        $parts = [];

        foreach (explode(',', $header) as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }

            $parts[] = $trimmed;
        }

        return $parts;
    }

    /**
     * Parse Accept-Language parts into tags ordered by q (desc). Drops q=0 and
     * malformed q. Duplicate tags keep the highest q. '*' is appended last
     * (lowest priority among remaining).
     *
     * @param  list<string>  $rangeParts
     * @return list<string>
     */
    private function orderLanguageRanges(array $rangeParts): array
    {
        /** @var array<string, float> $scores */
        $scores = [];

        foreach ($rangeParts as $part) {
            $pieces = array_map(trim(...), explode(';', $part));
            $tag = array_shift($pieces);
            if (! is_string($tag) || $tag === '') {
                continue;
            }

            $q = $this->parseQuality($pieces);
            if ($q === null || $q <= 0.0) {
                continue;
            }

            // Highest q wins duplicate tags; do not case-fold tags.
            // Equal q keeps the same score for the same tag (GreaterToGreaterOrEqual equivalent).
            if (! isset($scores[$tag]) || $q > $scores[$tag]) { // @pest-mutate-ignore: GreaterToGreaterOrEqual
                $scores[$tag] = $q;
            }
        }

        $star = array_key_exists('*', $scores);
        unset($scores['*']);

        arsort($scores, SORT_NUMERIC);

        /** @var list<string> $ordered */
        $ordered = array_keys($scores);
        if ($star) {
            $ordered[] = '*';
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $params
     */
    private function parseQuality(array $params): ?float
    {
        $q = 1.0;
        $hasQuality = false;

        foreach ($params as $param) {
            if (! str_starts_with(strtolower($param), 'q=')) {
                continue;
            }

            // Duplicate q params: first wins.
            // Remaining params are only further q= values we already ignore (ContinueToBreak equivalent).
            if ($hasQuality) {
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            $rawQ = substr($param, 2);
            // Empty rawQ already fails Q_VALUE_PATTERN (EmptyStringToNotEmpty equivalent).
            if ($rawQ === '' || preg_match(self::Q_VALUE_PATTERN, $rawQ) !== 1) { // @pest-mutate-ignore: EmptyStringToNotEmpty
                return null;
            }

            $q = (float) $rawQ;
            $hasQuality = true;
        }

        return $q;
    }

    /**
     * Exact (and normalised exact) match across the whole available list first,
     * then language-prefix match. No case folding: `EN` does not match `en`.
     *
     * @param  list<string>  $available
     */
    private function matchAvailable(string $tag, array $available): ?string
    {
        $normalizedTag = $this->normalizeTag($tag);

        foreach ($available as $locale) {
            // Identical tags also match on the normalised equality below (RemoveEarlyReturn equivalent).
            if ($locale === $tag) {
                return $locale; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            if ($this->normalizeTag($locale) === $normalizedTag) {
                return $locale;
            }
        }

        foreach ($available as $locale) {
            if ($this->isLanguagePrefixMatch($normalizedTag, $this->normalizeTag($locale))) {
                return $locale;
            }
        }

        return null;
    }

    private function normalizeTag(string $tag): string
    {
        return str_replace('_', '-', $tag);
    }

    /**
     * True when one BCP-47-like tag is a hyphen-prefix of the other
     * (`de` ↔ `de-DE`), after `_`→`-` normalisation.
     */
    private function isLanguagePrefixMatch(string $left, string $right): bool
    {
        return str_starts_with($left, $right.'-')
            || str_starts_with($right, $left.'-');
    }

    private function isSupported(string $locale): bool
    {
        return in_array($locale, $this->supported->all(), true);
    }
}
