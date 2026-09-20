# Sampling extensions

**Number**: 01-05  
**Source spec**: 01  
**Status**: complete  

## Story 1 — Sample adaptively

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- `adaptive: [3, 7]` with the first 3 samples unanimous on every applicable question takes exactly 3 samples. `[tdd]` `[unit]`
- `adaptive: [3, 7]` with votes A,A,B takes 5 samples next (a step of 2). With votes A,A,B,A,A it then stops, because 4–1 with 2 samples left cannot be overtaken or tied. `[tdd]` `[unit]`
- `adaptive: [3, 7]` with votes still splitting 3–3 after 6 samples reaches exactly 7 samples and stops. `[tdd]` `[unit]`
- A step never exceeds `max`: `adaptive: [3, 4]` goes from 3 samples to 4, not 5. `[tdd]` `[unit]`
- Invalid samples are excluded when judging unanimity. They count towards `max`, and are replaced within the resampling cap, so the number of model calls never exceeds `max` plus `max_resamples`. `[unit]`
- must NOT — Sampling stops while a trailing answer could still tie the leader with the samples remaining before `max`. `[tdd]` `[unit]`

### Task 1 — Write tests for Sample adaptively

**Status**: complete  

Test-first: covers the six unit-tagged criteria and fails until the stopping rule exists.

### Task 2 — Implement the adaptive stopping rule

**Status**: complete  

Take `min`, then add 2 at a time capped at `max`; stop on unanimity or when the leader cannot be overtaken or tied. Invalid samples are excluded from unanimity but count toward `max`.

### Retro

- The stopping rule is a pure `Stopping::nextBatch()`, and the driver loops on it, so fixed and adaptive sampling share one loop.

Two findings:
- One test first failed for my reason, not the rule's. A, invalid, A is unanimous among the valid samples, so it correctly stops at `min`. The test was split so each clause of the criterion has its own sequence.
- Pint silently rewrote `$votes[0] - $votes[1] <= $remaining` as `$remaining >= $votes[0] - $votes[1]`. A planted defect written against the pre-Pint text failed to apply, which I first misread as the test passing. Plants must now be checked to have applied (the assert-on-missing guard caught one; a sed edit did not). All six rule plants were then caught, including the tie boundary.

## Story 2 — Ask conditional questions

**Status**: complete  
**Blocked by**: Story 1  

### Acceptance Criteria

- With `group_date` set to `onlyWhen('intent', 'group')` and samples whose intent is group, group, group, refund, group, `group_date` is computed over the 4 matching samples only. `[tdd]` `[unit]`
- `applies` is true when the winning `intent` is `group`, and false when the winner is something else, even if some samples answered `group`. `[unit]`
- With only 1 matching sample, the conditional answer is null. `[unit]`
- `onlyWhen('intent', ['group', 'refund'])` counts samples matching either listed value. `[unit]`
- Building a question set throws when `onlyWhen` references a key that does not exist, a question that is not a Choice, or a value that is not one of that Choice's options. `[unit]`
- must NOT — Adaptive sampling requires unanimity on a conditional question that does not apply in the current samples. `[tdd]` `[unit]`

### Task 1 — Write tests for Ask conditional questions

**Status**: complete  

Test-first: covers the six unit-tagged criteria.

### Task 2 — Implement onlyWhen conditional questions

**Status**: complete  

Build-time validation of the controlling key and values; aggregation over qualifying samples only; `applies` from the winning controlling answer; null under 2 qualifying samples; non-applying questions left out of adaptive unanimity.

### Retro

- Conditions are a small `Condition` value carried on every question type through `onlyWhen()`, which returns a copy (the types are readonly). `tieBreak()` copies preserve it. `Aggregator::answers($set, $samples)` is now the single entry point both drivers use: conditional questions count only qualifying samples, `applies` follows the controlling winner, and the answer is null under two qualifying samples, so `Result` answers are nullable.

FR14 check (retro lens applied): FR14 lists question text, options, levels, descriptions, context and instruction-changing prompt options. `onlyWhen` changes none of those, so it stays out of the question-set hash, consistent with `tieBreak`; a test pins that. One bulk-edit script aborted on a nested-parenthesis regex before writing anything, so it was redone as exact replacements. All 7 plants were checked to apply, and all were caught.

## Story 3 — Ask for short reasons

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- Without `withReasons()`, the schema has no `reason` field and `Result->reasons` is null. `[unit]`
- With `withReasons(120)`, the schema has a `reason` field, the instructions ask for at most 120 characters, and `Result->reasons` lists one reason per valid sample. `[integration]`
- A reason longer than `maxChars` is truncated to `maxChars`; the sample stays valid. `[unit]`
- must NOT — A reason's content changes any vote, probability or later prompt. Checked by two runs with identical answers and different reasons producing identical answers and identical subsequent prompts. `[integration]`
- An architecture test shows that no code path reads a sample's `reason` into a prompt, a hash, a vote or a label. `[unit]`

### Task 1 — Implement withReasons

**Status**: complete  

Schema `reason` field, an instruction line with the character limit, truncation that keeps the sample valid, and `Result->reasons`. Reasons are display-only and never read back into anything.

### Task 2 — Write tests for Ask for short reasons

**Status**: complete  

Covers the five unit- and integration-tagged criteria, including the architecture test on reason reads.

### Retro

- Reasons are carried under a reserved `reason` output key (a question can't use that key while reasons are on). `SampleValidator::split` separates the reason from the answers and cuts it to `maxChars` with `mb_substr`. `Sample` holds it apart from its answers, so the aggregator never sees it. The Hunch::fake scripted samples take the same path.

Discovery: in Pest 4, `arch()->expect([...])->not->toUse(X)` without `->each` passes vacuously. A planted `use Sample` in Prompts sailed through until `->each` was added. The existing array-form rules (`toBeReadonly`, `not->toBeUsed`) were probed and do work.

The "no code path reads a reason" criterion is enforced by a token scan: `->reason` may be read only inside the `reasons()` methods that build `Result->reasons`. That caught plants feeding the reason into the prompt, the vote and the hash. The two-run must-NOT uses `Str::createUlidsUsing` to fix the classification ID, so seeds and prompts are comparable across runs. 13 plants were checked to apply, and all were caught.

Partly promoted: the arch-rule instance of the Pest variadic-negation trap became library/01, alongside retro 07's `toContain` instance. Not retired, because the reasons design and the FR14 judgement are still live lessons that only exist here.

## Story 4 — Redact the state before sampling

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- With `redactUsing(fn ($s) => str_replace('Alice', '[name]', $s))`, every captured user message contains `[name]` and none contains `Alice`. `[integration]`
- For array state, the redactor receives each named part, and the section names are preserved in the user message. `[unit]`
- The redactor runs exactly once per classification, before the first sample, and every sample receives the same redacted text. `[integration]`
- `state_hash` equals sha256 of the redacted, rendered state that was sent. `[unit]`

### Task 1 — Implement redactUsing

**Status**: complete  

Runs once per classification before the first model call, per named part for array state, and `state_hash` is sha256 of the redacted rendered state. The persisted-row check belongs to the persistence epic.

### Task 2 — Write tests for Redact the state before sampling

**Status**: complete  

Covers the four unit- and integration-tagged criteria.

### Retro

- Redaction lives in the builder: `PendingClassification::classify()` redacts once, after the pre-call checks and before handing the state to any driver. So the sampling driver, the fake, and the fake's stray fall-through all receive the same redacted text, and no driver can redact per sample. Array state is redacted part by part, with section names untouched.

`state_hash` is on `ClassificationMeta` and is computed by `UserMessage::stateHash()` over exactly the text rendered between the `<state>` tags. That is the one rendering both drivers send, so it is sha256 of what was sent rather than of the input.

A redactor may be a callable or an invokable class name resolved from the container. A non-string return, or a non-invokable class, is a ConfigurationException before any call. 7 plants were checked to apply, and all were caught (no redaction, array parts skipped, redacted twice, hash over raw JSON, hash over unredacted text, non-string accepted, callable check removed).

## Story 5 — Provide the contact-details redactor

**Status**: complete  
**Blocked by**: Story 4  

### Acceptance Criteria

- `redactUsing(Hunch\Redactors\ContactDetails::class)` replaces `alice@example.com` with `[email]`, and `+44 7700 900123`, `07700 900123` and `(555) 123-4567` with `[phone]`. `[unit]`
- A fixture of 20 labelled strings (10 containing contact details, 10 not) is redacted with no misses on the positives and no changes to the negatives, which include dates, prices, booking references such as `BK-2026-0415`, and times. `[unit]`
- With no redactor configured, the state reaches the prompt unchanged: the default redactor is off unless explicitly set. `[integration]`
- must NOT — The default redactor changes text that contains no email address or phone number. `[unit]`

### Task 1 — Implement the ContactDetails redactor and its fixture

**Status**: complete  

Emails to `[email]` and phone numbers to `[phone]`, with a 20-string labelled fixture of positives and negatives. Off unless explicitly set.

### Task 2 — Write tests for Provide the contact-details redactor

**Status**: complete  

Covers the four unit- and integration-tagged criteria.

### Retro

- The ContactDetails phone rule is "10–15 digits with at most one space, dot or hyphen between them, an optional + country code and a bracketed area code". It is bounded by lookarounds, so dates, prices, times and BK-style references can't be picked up. The first version missed `+44 7700 900123,` because a bare comma in the lookahead (meant for thousands separators) also stopped at a phone followed by a comma. The rule was narrowed to "separator followed by a digit" on both sides. That was found by the criterion's own end-to-end example, not the fixture, so the fixture now isn't the only guard.

The must-NOT has two controls: the ten labelled negatives, and 300 seeded generated strings built from dates, times, prices, references and short numbers. The generator skips any string that does contain a 10-digit run. 5 plants were checked to apply, and all were caught (email off, 7-digit threshold, no bracket form, on by default, touching other text).

## Story 6 — Dispatch classification events

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- Under `Event::fake()`, a successful classification with 3 samples dispatches, in order, `Classifying`, `SampleTaken` 3 times, and `Classified`. `[integration]`
- An invalid sample dispatches `SampleInvalid` instead of `SampleTaken`. A failed classification dispatches the `ClassificationFailed` event before the exception is thrown. `[integration]`
- The SDK's `PromptingAgent` and `AgentPrompted` events fire once per sample. `[integration]`
- Every classification, sample and label event payload carries the classification ID and the question set hash. `[unit]`
- must NOT — Any Hunch event carries the state text. Events carry `state_hash` only. `[integration]`

### Task 1 — Define and dispatch the classification events

**Status**: complete  

`Classifying`, `SampleTaken`, `SampleInvalid`, `Classified` and `ClassificationFailed`, with payloads of IDs, hashes and counts, never the state. `Labelled` and `CalibrationComputed` belong to the persistence epic.

### Task 2 — Write tests for Dispatch classification events

**Status**: complete  

Covers the five unit- and integration-tagged criteria.

### Retro

- Events share a readonly `ClassificationEvent` base (`classificationId`, `questionSetHash`, `stateHash`) with Laravel's `Dispatchable` trait, and SamplingDriver dispatches them around the sampling loop. The failure event is dispatched from one `catch (ClassificationFailed)` that rethrows, so both provider errors and too few valid samples go through the same path. Deliberately, `ClassificationFailed` carries the cause's class and not its message: a provider error message can quote the prompt. `Classified` likewise carries counts and usage, not the Result, whose reasons can quote the state.

Test note: `Event::fake()` groups recorded events by class, so it can't prove order. Order is asserted with a wildcard listener, and counts under `Event::fake()`. `Event::assertNotDispatched` only exists when faking. Hunch::fake's FakeDriver dispatches no events; that's left for when a consumer needs it. 9 plants were checked to apply, and all were caught, including the state on an event, the cause message, and prompting twice per sample.

## Dependencies

- blocks → 01-06

## Retro Applied

- 02 · Codebase Discoveries · applied — Story 2 judges onlyWhen against FR14's wording before touching the canonical JSON, and records the decision.
- 04 · Codebase Discoveries · applied — Story 6's events coexist with the SDK's per-sample PromptingAgent/AgentPrompted; reasons grow maxTokens(), which already accounts for them.
- 02 · Patterns Worth Reusing · applied — Each must-NOT gets a runtime control plus an arch rule where a dependency is what must be absent (for example, the aggregator never reads reasons).
- 04 · Testing Gaps · applied — The planted defect for each criterion is written and seen failing before its test is trusted; no closure datasets.
