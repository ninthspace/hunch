# Package foundation and CI

**Number**: 01  
**Source epic**: 01-01  
**Status**: complete  

## Observations

- Criteria gap — The offline criterion assumed Docker, which the development machine does not have. macOS `sandbox-exec` with `(deny network-outbound (remote ip))` gives the same OS-level guarantee with no install, and a raw-socket probe (excluded from the default suite by group) proves it bites. Criterion amended; the spec's ENV10 criterion text still names a container. Separately, Pest's `toContain` is variadic, so a second argument is another needle, not a failure message — a check written that way passed on its own source. Every must-NOT in this story now has a planted-violation control.

Both gaps have one root. The spec named checks to run on machines this project doesn't have, a Docker container and a production host, instead of somewhere a check can actually run. Separately, one requirement's obligation (ENVX3's runtime half) had no binding at all. For the remaining epics: every criterion runs in the package suite, under the OS network sandbox, or in a throwaway Laravel app in the scratchpad, and every obligation in a requirement gets its own binding.

Change moment resolved by amending story 2 criterion 4; section recorded on the epic.

- Criteria gap — Three of this story's criteria were `[target]` checks "on the production host" (`php -v`, `composer show laravel/framework`, `check-platform-reqs`). Hunch is a package and has no host, so they could never run. Chris caught it after the epic closed. Composer already enforces all three in any installing app, so they were superseded, and the spec was pivoted to "Installation:" and "Runtime:" framing. Separately, the claim pass found that ENVX3's runtime half ("must not require any PHP extension") had no binding at all. It is now covered by `tests/ExtensionsTest.php`.

Both gaps have one root. The spec named checks to run on machines this project doesn't have, a Docker container and a production host, instead of somewhere a check can actually run. Separately, one requirement's obligation (ENVX3's runtime half) had no binding at all. For the remaining epics: every criterion runs in the package suite, under the OS network sandbox, or in a throwaway Laravel app in the scratchpad, and every obligation in a requirement gets its own binding.

Recorded after the fact, during the 2026-09-19 revisit of the epic summaries.

- Codebase discovery — Testbench rewrites `database.default` from `sqlite` to its own `testing` connection whenever the SQLite database is not a file (`:memory:` is not), so a test asserting `config('database.default') === 'sqlite'` fails even though phpunit.xml is correct. Assert the effective connection's driver and database instead. Also: the Spatie skeleton's .gitignore ignores `/docs` and `phpunit.xml`, both of which this repo needs tracked.

Skeleton files copied by hand from spatie/package-skeleton-laravel main; the facade, command, migration stub, factory, views, laravel-ray and workbench autoload were dropped, not adapted.
