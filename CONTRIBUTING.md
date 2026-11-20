# Contributing to laravel-http-basics

Thanks for helping. A few rules keep the codebase consistent:

1. Open or pick an issue first. One cohesive area per pull request.
2. Branch from `develop` as `<type>/<kebab-slug>` (types: feat, fix, refactor,
   chore, docs, test, perf, ci, build, security).
3. Use Conventional Commits; PR titles follow the same format. Body lines ≤ 100
   characters.
4. Open the PR against `develop`. PRs are squash-merged. Never push to
   `develop` or `main` directly; never tag from a contributor branch; never
   touch Packagist.
5. Before pushing run `composer test` (Pest), `composer lint` (Pint) and
   `composer analyse` (PHPStan level max). Security-critical classes also need
   `pest --mutate` coverage (design §8 mutation targets; per-class MSI ≥ 95%).
6. Red-first: add a failing test for the property, then the implementation.
   Tests are deterministic (injectable clock, fake store; no flaky wall clock).
   At least one CI job runs the middleware stack through real HTTP against a
   local `php -S` Testbench app (Laravel 12 and 13).
7. Code style: strict types, `final` by default, English docblocks that explain
   *why*, no global helpers, no `Illuminate\Foundation\` in `src/`. Only `@api`
   types are SemVer BC; Arch tests guard accidental public-surface growth.
8. Security checks are never weakened. Changes to request-id trust, locale
   caps, CSP/HSTS clobber rules, fingerprinting, leases, or replay filters need
   a test that proves the bad value is refused. Fixtures represent real formats
   and are never edited to make code pass.
9. Do not commit `composer.lock`, secrets, or `graphify-out/`.
10. Releases and Packagist: maintainer only (see SECURITY.md and the qiick
    release standard).
