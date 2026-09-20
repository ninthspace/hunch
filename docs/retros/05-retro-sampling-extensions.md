# Sampling extensions

**Number**: 05  
**Source epic**: 01-05  
**Status**: complete  

## Observations

- Testing gap — The stopping rule is a pure `Stopping::nextBatch()`, and the driver loops on it, so fixed and adaptive sampling share one loop.

Two findings:
- One test first failed for my reason, not the rule's. A, invalid, A is unanimous among the valid samples, so it correctly stops at `min`. The test was split so each clause of the criterion has its own sequence.
- Pint silently rewrote `$votes[0] - $votes[1] <= $remaining` as `$remaining >= $votes[0] - $votes[1]`. A planted defect written against the pre-Pint text failed to apply, which I first misread as the test passing. Plants must now be checked to have applied (the assert-on-missing guard caught one; a sed edit did not). All six rule plants were then caught, including the tie boundary.

A green test isn't evidence until you have seen it fail. Three checks this epic were blind without anyone noticing: Pint rewrote a comparison, so a planted defect never applied; Pest's arch expect([...])->not->toUse() can't fail without ->each; and the redactor fixture missed a phone followed by a comma, which the spec's own example caught. Plant-first caught all three. Keep checking that plants apply, and pair fixtures with the criterion's own end-to-end example.

- Smooth delivery — Conditions are a small `Condition` value carried on every question type through `onlyWhen()`, which returns a copy (the types are readonly). `tieBreak()` copies preserve it. `Aggregator::answers($set, $samples)` is now the single entry point both drivers use: conditional questions count only qualifying samples, `applies` follows the controlling winner, and the answer is null under two qualifying samples, so `Result` answers are nullable.

FR14 check (retro lens applied): FR14 lists question text, options, levels, descriptions, context and instruction-changing prompt options. `onlyWhen` changes none of those, so it stays out of the question-set hash, consistent with `tieBreak`; a test pins that. One bulk-edit script aborted on a nested-parenthesis regex before writing anything, so it was redone as exact replacements. All 7 plants were checked to apply, and all were caught.

Putting cross-cutting behaviour at one choke point made both drivers correct by construction: conditions in Aggregator::answers, redaction in PendingClassification::classify. The plants stayed cheap too: one place to break, one place to test.

- Codebase discovery · Testing gap — Reasons are carried under a reserved `reason` output key (a question can't use that key while reasons are on). `SampleValidator::split` separates the reason from the answers and cuts it to `maxChars` with `mb_substr`. `Sample` holds it apart from its answers, so the aggregator never sees it. The Hunch::fake scripted samples take the same path.

Discovery: in Pest 4, `arch()->expect([...])->not->toUse(X)` without `->each` passes vacuously. A planted `use Sample` in Prompts sailed through until `->each` was added. The existing array-form rules (`toBeReadonly`, `not->toBeUsed`) were probed and do work.

The "no code path reads a reason" criterion is enforced by a token scan: `->reason` may be read only inside the `reasons()` methods that build `Result->reasons`. That caught plants feeding the reason into the prompt, the vote and the hash. The two-run must-NOT uses `Str::createUlidsUsing` to fix the classification ID, so seeds and prompts are comparable across runs. 13 plants were checked to apply, and all were caught.

A green test isn't evidence until you have seen it fail. Three checks this epic were blind without anyone noticing: Pint rewrote a comparison, so a planted defect never applied; Pest's arch expect([...])->not->toUse() can't fail without ->each; and the redactor fixture missed a phone followed by a comma, which the spec's own example caught. Plant-first caught all three. Keep checking that plants apply, and pair fixtures with the criterion's own end-to-end example.

Partly promoted: the arch-rule instance of the Pest variadic-negation trap became library/01, alongside retro 07's `toContain` instance. Not retired, because the reasons design and the FR14 judgement are still live lessons that only exist here.

- Smooth delivery — Redaction lives in the builder: `PendingClassification::classify()` redacts once, after the pre-call checks and before handing the state to any driver. So the sampling driver, the fake, and the fake's stray fall-through all receive the same redacted text, and no driver can redact per sample. Array state is redacted part by part, with section names untouched.

`state_hash` is on `ClassificationMeta` and is computed by `UserMessage::stateHash()` over exactly the text rendered between the `<state>` tags. That is the one rendering both drivers send, so it is sha256 of what was sent rather than of the input.

A redactor may be a callable or an invokable class name resolved from the container. A non-string return, or a non-invokable class, is a ConfigurationException before any call. 7 plants were checked to apply, and all were caught (no redaction, array parts skipped, redacted twice, hash over raw JSON, hash over unredacted text, non-string accepted, callable check removed).

Putting cross-cutting behaviour at one choke point made both drivers correct by construction: conditions in Aggregator::answers, redaction in PendingClassification::classify. The plants stayed cheap too: one place to break, one place to test.

- Testing gap — The ContactDetails phone rule is "10–15 digits with at most one space, dot or hyphen between them, an optional + country code and a bracketed area code". It is bounded by lookarounds, so dates, prices, times and BK-style references can't be picked up. The first version missed `+44 7700 900123,` because a bare comma in the lookahead (meant for thousands separators) also stopped at a phone followed by a comma. The rule was narrowed to "separator followed by a digit" on both sides. That was found by the criterion's own end-to-end example, not the fixture, so the fixture now isn't the only guard.

The must-NOT has two controls: the ten labelled negatives, and 300 seeded generated strings built from dates, times, prices, references and short numbers. The generator skips any string that does contain a 10-digit run. 5 plants were checked to apply, and all were caught (email off, 7-digit threshold, no bracket form, on by default, touching other text).

A green test isn't evidence until you have seen it fail. Three checks this epic were blind without anyone noticing: Pint rewrote a comparison, so a planted defect never applied; Pest's arch expect([...])->not->toUse() can't fail without ->each; and the redactor fixture missed a phone followed by a comma, which the spec's own example caught. Plant-first caught all three. Keep checking that plants apply, and pair fixtures with the criterion's own end-to-end example.

- Pattern worth reusing — Events share a readonly `ClassificationEvent` base (`classificationId`, `questionSetHash`, `stateHash`) with Laravel's `Dispatchable` trait, and SamplingDriver dispatches them around the sampling loop. The failure event is dispatched from one `catch (ClassificationFailed)` that rethrows, so both provider errors and too few valid samples go through the same path. Deliberately, `ClassificationFailed` carries the cause's class and not its message: a provider error message can quote the prompt. `Classified` likewise carries counts and usage, not the Result, whose reasons can quote the state.

Test note: `Event::fake()` groups recorded events by class, so it can't prove order. Order is asserted with a wildcard listener, and counts under `Event::fake()`. `Event::assertNotDispatched` only exists when faking. Hunch::fake's FakeDriver dispatches no events; that's left for when a consumer needs it. 9 plants were checked to apply, and all were caught, including the state on an event, the cause message, and prompting twice per sample.
