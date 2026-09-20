# Manual compatibility run

**Number**: 07  
**Source epic**: 01-07  
**Status**: complete  

## Observations

- Codebase discovery · Smooth delivery — The run passed first time against claude-sonnet-5: 42 items, 126 samples, 4 minutes, roughly $0.30. One sample of 126 was schema-invalid (0.8%, well under the 10% limit), and it was in the injection group. Accuracy was 93.1% core, 100% large-choice, 100% injection. Injection compliance was 0 of 6 — the provider followed none of the six embedded instructions, which is the result the `<state>` delimiting and the instruction line are meant to produce.

Prompt caching behaved exactly as the fixture design predicted: 48,125 cached input tokens in the large-choice group and zero in the other two, because only the 45-option instructions clear the 1024-token minimum. Hanging the cache criterion on that group rather than padding a smaller one was the right call.

Two things were caught before spending anything. The model constant read `claude-sonnet-4-5`, which the SDK does not know — every call would have 404'd; the SDK's Claude ids are opus-5, sonnet-5 and haiku-4-5. And `fixed:3` with the default `min_valid_samples` of 3 would have failed a whole item on a single invalid sample, so the run sets it to 1: a compatibility run has to record invalid samples, not abort on them.

Verification note: the run itself is the evidence for the integration criteria, but two claims needed offline controls rather than a green run — "fails above 10%" (now `CompatThresholds::exceedsInvalidLimit`, unit-tested at 12, 13 and 0 of 126) and "reports but does not fail on compliance" (a scan proving no assertion mentions the compliance counters). Both were extracted after the run; the arithmetic is unchanged, so the run's evidence still stands.

The run itself was the cheap part: 126 samples, 4 minutes, about $0.30, and it passed first time. What made it cheap was refusing to spend until everything checkable offline was green — which caught an SDK model id that would have 404'd every one of those calls, and a `min_valid_samples` default that would have aborted the very items a compatibility run exists to count. Design the fixture so each criterion hangs off something that is large for its own reasons, as the 45-option group does for prompt caching, and the expensive run only has to confirm what the design predicted.

- Testing gap — `ResultsTable` writes between two README markers and refuses to guess when they are missing, so the run cannot scribble over prose. The isolation tests cover the properties directly: only the block between markers changes, a second run replaces the first rather than appending, and a README without markers is left byte-identical.

The criterion "git diff --stat shows changes only to README.md" was verified from the run itself rather than by assertion: file modification times show every other repo file last touched at 23:18-23:20, before the run started, and README.md alone carries an mtime inside the run's 23:20-23:24 window.

Pest trap, the same shape as retro 05's arch rule: `expect($x)->not->toContain('needle', 'explanatory message')` passes vacuously, because `toContain` is variadic and the message becomes a second needle that is always absent. A planted assertion on injection compliance sailed through until the test was rewritten to collect offenders and assert an empty list. The rule that keeps emerging: under a negation, a Pest matcher taking variadic arguments will not fail if any argument is absent, so pass exactly one.

Pest's matchers fail open under a negation when they take variadic arguments, and this run hit the second instance of it: `->not->toContain('needle', 'message')` passes because the explanatory message is a second needle that is always absent, exactly as `expect([...])->not->toUse()` passed without `->each` in retro 05. Both were found only because a plant was expected to fail and did not, which is the whole argument for planting. Evidence can also come from outside the suite: file modification times proved the compatibility run touched only README.md, which no assertion in the run could have shown.

Partly promoted: its Pest variadic-negation half became library/01. Not retired, because the README-writer design and the mtime-as-evidence point are still live lessons that only exist here.

**Retired **: 

- Criteria gap — Raised while documenting 01-07, but it originates here: the service provider called `loadMigrationsFrom()` whenever persistence was on, so enabling persistence silently injected Hunch's migrations into an application's `migrate` run. ADR 01-02's chosen option says the opposite — "migrations are published but never auto-loaded, so persistence stays opt-in" — and the code contradicted it for three epics without anything noticing.

Why nothing noticed: the only criterion is a must-NOT ("the provider loads migrations when persistence is off"), and the code satisfied it. The matching "on" case had no criterion, so the test I wrote for it encoded my own behaviour rather than an agreed one, and then defended it. A must-NOT with no paired must leaves the other half of the switch unowned.

Chris caught it from the README: installation should not bring migrations into an application at all. Now `publishesMigrations()` is the only registration, and a test asserts migrations are never loaded with persistence either on or off; the package's own persistence tests migrate from the package path explicitly, as an application does after publishing.

The correction opened a second hole: with migrations publish-only, turning persistence on without publishing gave `SQLSTATE... no such table`. `Persistence\MissingTables` now turns that into a ConfigurationException naming the two steps, across record(), label(), calibration(), hunch:calibrate and hunch:eval, keeping the QueryException as `previous` and passing through errors about tables that are not Hunch's. That last distinction went untested until a plant exposed it — without it, an application's own missing table would have been blamed on Hunch.

A must-NOT with no paired must leaves half a switch unowned. Nothing required migrations to load when persistence was on, so the test written for that half encoded the implementer's choice rather than an agreed one, and then defended it against an accepted ADR for three epics. Where a criterion governs one side of a toggle, ask what owns the other side; and when the rows and an ADR disagree, the ADR is the decision and the code is the error.

- Criteria gap · Pattern worth reusing — Writing the README was the most effective design review of the run. Three questions only surfaced because someone had to be told the answer in plain words: does installing this bring migrations (it should not, and it did), do I need a database at all (the answer existed in ADR 01-08 and NFR3 but nowhere a reader would look), and what happens if I want persistence later (nobody had asked, and the failure was a raw SQL error).

None of those were criteria gaps in the epics that delivered them; they were gaps between what the rows said and what a user would need to know. Documentation asks the questions an acceptance criterion does not.

The README's examples are the compat fixtures, so the documented code is code that has run against a real provider. `tests/ReadmeExamplesTest.php` now executes those examples against the fake and asserts the README keeps naming the real publish tags, commands and PHP version, so the docs cannot drift from the API. It also pins the claims that matter most for trust: no database queries by default, the text is never stored, and installing adds no migrations.

The existing NFR7 honesty guard earned its place twice, rejecting "how accurate its answers were" and "calibrated retrospectively" in new prose. A guard on documentation wording is worth having precisely because prose is written quickly and read as a promise.

Writing the README was the most effective design review of the run, because documentation asks questions an acceptance criterion does not: does installing this bring migrations, do I need a database at all, what happens if I want persistence later. All three were answerable from the rows and the ADRs, and none of them had been asked in the words a user would use. Documenting a package late enough to be honest, but early enough to still change it, is worth a story of its own.
