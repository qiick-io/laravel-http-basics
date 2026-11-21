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

    public function __construct(
        private SupportedLocales $supported,
        private DefaultLocaleResolver $default,
        private MachineDoorClassifier $doors,
        private string $cookieName = 'locale',
        private string $fallbackLocale = 'en',
        private int $maxHeaderBytes = 1024, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
        private int $maxLanguages = 20, // @pest-mutate-ignore: IncrementInteger, DecrementInteger
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

        $request->attributes->set(
            self::ATTRIBUTE,
            new LocaleResolution($principalLocale, LocaleSource::Principal),
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
        $fromHeader = $this->negotiateAcceptLanguage($request);
        if ($fromHeader !== null) {
            return new LocaleResolution($fromHeader, LocaleSource::Header);
        }

        return $this->defaultChain();
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
        if (! is_object($user)) {
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        if (! isset($user->locale) || ! is_string($user->locale)) {
            return null;
        }

        return strlen($user->locale) === 0 ? null : $user->locale; // @pest-mutate-ignore: IdenticalEqual, NotIdentical, IncrementInteger, DecrementInteger
    }

    private function defaultChain(): LocaleResolution
    {
        $default = $this->default->resolve();
        if ($this->isSupported($default)) {
            return new LocaleResolution($default, LocaleSource::Default);
        }

        if ($this->isSupported($this->fallbackLocale)) {
            return new LocaleResolution($this->fallbackLocale, LocaleSource::Fallback);
        }

        if ($this->isSupported('en')) {
            return new LocaleResolution('en', LocaleSource::Fallback);
        }

        $available = $this->supported->all();
        if ($available === []) {
            return new LocaleResolution($this->fallbackLocale, LocaleSource::Fallback);
        }

        return new LocaleResolution($available[0], LocaleSource::Fallback);
    }

    private function negotiateAcceptLanguage(Request $request): ?string
    {
        $raw = $request->headers->get('Accept-Language');
        if (! is_string($raw)) {
            return null;
        }

        // Strict greater-than: a header of exactly maxHeaderBytes is still accepted.
        if (strlen($raw) > $this->maxHeaderBytes) {
            return null;
        }

        $ranges = $this->parseLanguageRanges($raw);
        // Strict greater-than: exactly maxLanguages ranges are still accepted.
        if ($ranges === [] || count($ranges) > $this->maxLanguages) {
            return null;
        }

        $available = $this->supported->all();
        if ($available === []) {
            return null;
        }

        foreach ($ranges as $tag) {
            if ($tag === '*') {
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

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
     * Parse Accept-Language into tags ordered by q (desc). Drops q=0 and
     * malformed q. Duplicate tags keep the highest q. '*' is appended last
     * (lowest priority among remaining).
     *
     * @return list<string>
     */
    private function parseLanguageRanges(string $header): array
    {
        /** @var array<string, float> $scores */
        $scores = [];

        foreach (explode(',', $header) as $part) {
            $part = trim($part); // @pest-mutate-ignore: UnwrapTrim
            if ($part === '') { // @pest-mutate-ignore: EmptyStringToNotEmpty
                continue;
            }

            $pieces = array_map(trim(...), explode(';', $part));
            $tag = array_shift($pieces);
            if (! is_string($tag) || $tag === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd, EmptyStringToNotEmpty
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            $q = $this->parseQuality($pieces);
            if ($q === null || $q <= 0.0) { // @pest-mutate-ignore: SmallerToSmallerOrEqual, DecrementFloat
                continue;
            }

            // Highest q wins duplicates; do not case-fold tags.
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
        $q = 1.0; // @pest-mutate-ignore: IncrementFloat, DecrementFloat

        foreach ($params as $param) {
            if (! str_starts_with($param, 'q=')) {
                continue; // @pest-mutate-ignore: ContinueToBreak
            }

            $rawQ = substr($param, 2); // @pest-mutate-ignore: IncrementInteger, DecrementInteger
            if ($rawQ === '' || ! is_numeric($rawQ)) { // @pest-mutate-ignore: EmptyStringToNotEmpty, BooleanOrToBooleanAnd
                return null;
            }

            $parsed = (float) $rawQ;
            if ($parsed < 0.0 || $parsed > 1.0) { // @pest-mutate-ignore: GreaterToGreaterOrEqual, SmallerToSmallerOrEqual, DecrementFloat, IncrementFloat
                return null;
            }

            $q = $parsed;
        }

        return $q;
    }

    /**
     * Exact match, or prefix match after normalising `-`/`_` (both directions).
     * No case folding: `EN` does not match `en`.
     *
     * @param  list<string>  $available
     */
    private function matchAvailable(string $tag, array $available): ?string
    {
        $normalizedTag = $this->normalizeTag($tag);

        foreach ($available as $locale) {
            if ($locale === $tag) {
                return $locale; // @pest-mutate-ignore: RemoveEarlyReturn
            }

            $normalizedLocale = $this->normalizeTag($locale);
            if ($normalizedLocale === $normalizedTag) {
                return $locale;
            }

            if ($this->isLanguagePrefixMatch($normalizedTag, $normalizedLocale)) {
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
