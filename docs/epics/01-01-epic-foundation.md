# Package foundation and CI

**Number**: 01-01  
**Source spec**: 01  
**Status**: complete  

## Amendment: offline test run uses an OS-level network sandbox, not a container

Story 2, criterion 4 originally required `composer test:offline` to run Pest "inside a container with networking disabled (for example `docker run --network none`)". The development machine has no Docker. The requirement it serves, ENV10 in 01, asks only that the default suite can run locally with outbound network access disabled; the container was the criterion's chosen mechanism, not the requirement's.

Amended (2026-09-19, with Chris's approval) to: `composer test:offline` runs Pest with outbound network denied at the OS level (on macOS, `sandbox-exec` with a `deny network-outbound` profile), and a deliberate outbound request in a probe test fails there.

The guarantee is unchanged: the operating system, not the suite's own `Http::preventStrayRequests()`, blocks the network. Trade-off accepted: `sandbox-exec` is macOS-only and deprecated by Apple, so `test:offline` does not run on Linux. The spec's ENV10 criterion text still names a container and was not edited (the source spec is read, not written, by this run); a later `/dpm:pivot` on the spec can bring its wording into line if wanted.

## Amendment: production-host criteria superseded

Story 1's three `[target]` criteria (`php -v` on the production host, `composer show laravel/framework` in the host application, and `composer check-platform-reqs` on the production host) were superseded on 2026-09-19 at Chris's direction: Hunch is a package and has no production host. Composer enforces each check in any application that installs it: the `php` `^8.3` constraint, the `illuminate/*` `^13.0` constraints, and the absence of any `ext-*` requirement of Hunch's own. Story 1's composer.json unit tests (criteria 3, 5 and 8) verify those constraints.

Their coverage bindings to ENV1, ENV2 and ENVX3 in 01 were retired, and those requirements now rest on the composer.json bindings. The spec's own requirement text still refers to "the host" and was not edited by this run. A `/dpm:pivot` on the spec can reword it and review similar host-based `[target]` criteria in later epics.

## Amendment: ENVX3's runtime half gets its own test

Added on 2026-09-19 during the coverage-claim pass. ENVX3 in 01 has two obligations. The declared half ("its composer.json declares no additional `ext-*` requirements") was bound to criterion 8. The runtime half ("Hunch must not require any PHP extension beyond those Laravel itself requires") was bound to nothing, so code could have called an undeclared extension's function unnoticed.

Story 1 gained a criterion for it, covered by `tests/ExtensionsTest.php`. The test tokenises every file under `src/`, resolves each function called and each class imported to its extension, and fails on anything outside PHP's core and `laravel/framework`'s own `ext-*` requirements. A planted `grapheme_strlen()` (intl) and an `Imagick` import both made it fail.

## Story 1 — Scaffold ninthspace/hunch from the Spatie skeleton

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- An architecture test confirms every class under `src/` lives in the `Ninthspace\Hunch\` namespace, and `composer.json` declares the name `ninthspace/hunch`. `[unit]`
- ~~must NOT — The package is submitted to Packagist, the repository is made public, or a release is tagged before ownership of the code has been confirmed in writing. `[manual]`~~ **Superseded 2026-09-20T10:55:00Z**: Superseded on 2026-09-20 as mistaken rather than as satisfied. NFR8's release gate presumed a question over ownership of the code that never existed; the work is the author's own in invention and origin. The criterion is withdrawn with the clause it guarded, and publication is not conditional on anything.
- `composer.json` requires `php` `^8.3`. `[unit]`
- ~~On the production host, `php -v` reports 8.3 or later. `[target]`~~ **Superseded 2026-09-19T17:50:00Z**: Hunch is a package with no production host. Composer enforces `php` `^8.3` in any installing app, and criterion 3's composer.json test covers it.
- `composer.json` constrains every `illuminate/*` requirement to `^13.0`. `[unit]`
- ~~In the host application, `composer show laravel/framework` reports 13.x, and installing Hunch reports no dependency conflict. `[target]`~~ **Superseded 2026-09-19T17:50:00Z**: Hunch is a package with no production host. Composer refuses to resolve Hunch against a framework outside `illuminate/*` `^13.0`, and criterion 5's composer.json test covers it.
- `composer.json` requires `laravel/ai`. `[unit]`
- Hunch's `composer.json` declares no `ext-*` requirement beyond those Laravel requires. `[unit]`
- ~~`composer check-platform-reqs` passes on the production host with Hunch installed. `[target]`~~ **Superseded 2026-09-19T17:50:00Z**: Hunch is a package with no production host. It declares no `ext-*` requirement of its own, so it adds nothing to an installing app's platform check; criterion 8's composer.json test covers it.
- `composer.json` `require-dev` includes `orchestra/testbench`, and the base `TestCase` extends Testbench's `TestCase`. `[unit]`
- `vendor/bin/pest --version` reports 4.x, and `tests/ArchTest.php` exists and runs. `[integration]`
- `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. `[integration]`
- `Http::preventStrayRequests()` is enabled globally in the test bootstrap. `[unit]`
- A test confirms that no function called and no class imported under `src/` comes from a PHP extension outside PHP's core and those `laravel/framework` requires. `[unit]`

### Task 1 — Generate the package from the Spatie skeleton

**Status**: complete  

Composer name `ninthspace/hunch`, root namespace `Ninthspace\Hunch\`, and the `php` `^8.3`, `illuminate/*` `^13.0` and `laravel/ai` constraints, with no `ext-*` requirements. Delete the skeleton's `.github` workflows. Nothing is published.

### Task 2 — Configure the test harness

**Status**: complete  

Testbench base `TestCase`, Pest 4 with `tests/ArchTest.php`, SQLite in memory in `phpunit.xml`, and `Http::preventStrayRequests()` in the bootstrap. Addresses the harness criteria, not CI.

### Task 3 — Write tests for Scaffold ninthspace/hunch from the Spatie skeleton

**Status**: complete  

Covers the criteria tagged unit or integration: the namespace architecture test and the composer.json assertions. The manual and target criteria are verified outside the suite.

### Retro

- Three of this story's criteria were `[target]` checks "on the production host" (`php -v`, `composer show laravel/framework`, `check-platform-reqs`). Hunch is a package and has no host, so they could never run. Chris caught it after the epic closed. Composer already enforces all three in any installing app, so they were superseded, and the spec was pivoted to "Installation:" and "Runtime:" framing. Separately, the claim pass found that ENVX3's runtime half ("must not require any PHP extension") had no binding at all. It is now covered by `tests/ExtensionsTest.php`.

Recorded after the fact, during the 2026-09-19 revisit of the epic summaries.

- Testbench rewrites `database.default` from `sqlite` to its own `testing` connection whenever the SQLite database is not a file (`:memory:` is not), so a test asserting `config('database.default') === 'sqlite'` fails even though phpunit.xml is correct. Assert the effective connection's driver and database instead. Also: the Spatie skeleton's .gitignore ignores `/docs` and `phpunit.xml`, both of which this repo needs tracked.

Skeleton files copied by hand from spatie/package-skeleton-laravel main; the facade, command, migration stub, factory, views, laravel-ray and workbench autoload were dropped, not adapted.

## Story 2 — Run the checks locally through composer scripts

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- `composer test` runs `vendor/bin/pest` against Laravel 13, and passes. `[integration]`
- `composer check` runs `phpstan analyse` at level max with Larastan, and `pint --test`. A deliberate violation of either makes it exit non-zero. `[integration]`
- `composer test` passes with no provider API keys set in the environment. `[integration]`
- `composer test:offline` runs Pest with outbound network denied at the OS level (on macOS, `sandbox-exec` with a `deny network-outbound` profile), and a deliberate outbound request in a probe test fails there. `[integration]`
- `composer test:offline` runs `vendor/bin/pest` with no provider API keys set and outbound network blocked, and passes. `[integration]`
- must NOT — Any test in the default suite is skipped because an API key or the network is missing. Such tests live only in the nightly suite. `[integration]`
- ~~must NOT — `tests.yml` declares any permission beyond `contents: read`. `[integration]`~~ **Superseded 2026-09-19T15:50:00Z**: Spec 01 amendment removing all GitHub Actions (ENVX6): there is no tests.yml whose permissions could be constrained.
- must NOT — The repository contains a `.github/workflows` directory or any file that triggers a GitHub Actions run. `[unit]`

### Task 1 — Add the composer test and test:offline scripts

**Status**: complete  

`composer test` runs Pest against Laravel 13 with no provider keys; `composer test:offline` runs it with outbound network denied at the OS level (macOS `sandbox-exec`), per the amended criterion 4. Addresses criteria 1, 3, 4 and 5.

### Task 2 — Add the composer check script

**Status**: complete  

PHPStan with Larastan at level max and `pint --test`, exiting non-zero on any error. Addresses criterion 2 only.

### Task 3 — Write tests for Run the checks locally through composer scripts

**Status**: complete  

Covers the automated criteria: the outbound-request probe that must fail under `composer test:offline`, a check that no default-suite test skips on a missing key or network, and an architecture test that no `.github/workflows` exists.

### Retro

- The offline criterion assumed Docker, which the development machine does not have. macOS `sandbox-exec` with `(deny network-outbound (remote ip))` gives the same OS-level guarantee with no install, and a raw-socket probe (excluded from the default suite by group) proves it bites. Criterion amended; the spec's ENV10 criterion text still names a container. Separately, Pest's `toContain` is variadic, so a second argument is another needle, not a failure message — a check written that way passed on its own source. Every must-NOT in this story now has a planted-violation control.

Change moment resolved by amending story 2 criterion 4; section recorded on the epic.

## Dependencies

- blocks → 01-02
- blocks → 01-03
