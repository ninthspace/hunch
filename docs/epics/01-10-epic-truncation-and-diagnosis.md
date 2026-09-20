# Stop truncating good samples, and say why a sample failed

**Number**: 01-10  
**Source spec**: 01  
**Status**: complete  

## Why this epic exists

The compatibility run of 2026-09-20 measured a 3.2% schema-invalid rate against Anthropic, and the invalid-reason capture added that morning showed every one of the four failures was a missing-field failure: three missing every field, one missing a single field. No enum violation, no unexpected key. So the schema was being enforced; something was cutting the answers off.

The suspect is Hunch's own output ceiling. `ClassifierAgent::maxTokens()` returned `64 + 24 * questions` — 136 tokens for the three-question core group — and the SDK prefers that method over the `#[MaxTokens(4096)]` attribute on the same class, which was therefore never in effect. Across the three fixture groups the headroom ratio tracked the invalid rate: large-choice had 4.9x headroom and no failures, while core at 2.8x and injection at 3.0x had all four.

The formula bought nothing. A provider bills for the tokens it generates, not for the ceiling, so a tight cap and a generous one cost the same on a well-behaved answer. All a tight cap can do is truncate a good sample — and since epic 01-09 an invalid sample costs replacement calls, so the ceiling had gone from harmless to a cause of spend.

FR3 was amended on 2026-09-20 from "max tokens are sized from the schema" to a ceiling set well above any valid answer. Two coverage bindings quoted the old clause and were retired; the criterion about the computed ceiling growing with the question count was superseded with the formula it described.

The second half of this epic is why the diagnosis took a live run at all. `SampleValidator` reports an output carrying none of the expected keys as "Missing a, b, c", which reads as a model that answered badly rather than one whose answer never arrived. The validator should distinguish the two, and the compatibility run should record the provider's own `stop_reason` so truncation is visible as truncation.

## Story 1 — A ceiling that cannot truncate a good answer

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- `ClassifierAgent` declares no `maxTokens()` method, so the `#[MaxTokens(4096)]` attribute is what the SDK resolves. The ceiling sent to the provider is 4096 whatever the question set, and does not change with the number of questions or with reasons.
- must NOT — The ceiling must not sit within reach of a valid answer: for the largest question set the package allows — 50 Choice options, every question type, and reasons at their 200-character maximum — the rendered answer is an order of magnitude below 4096 tokens.
- Temperature 1.0, cached instructions and no TopP are unchanged by the removal: reflection on `ClassifierAgent` still shows `Temperature(1.0)`, `MaxTokens` and `CacheInstructions`, and no `TopP`.

### Task 1 — Delete the computed ceiling and let the attribute stand

**Status**: complete  

Remove `ClassifierAgent::maxTokens()`, leaving `#[MaxTokens(4096)]` as what the SDK resolves, and say in the class docblock why a tight ceiling is not worth having. Replace the test that asserted the formula with one asserting the ceiling is the attribute's whatever the question set, and one showing the largest answer the schema allows is an order of magnitude below it.

### Retro

- A schema-derived max-tokens ceiling of 136 tokens was silently costing accuracy for three epics, and the package's own `#[MaxTokens(4096)]` attribute was dead the whole time because the SDK prefers a `maxTokens()` method over it. The invalid rate fell from 3.2% to 0.0% across 126 live samples when the formula was deleted.

Two things made it invisible. The first is that the formula looked like thrift and was not: a provider bills for tokens generated, not for the ceiling, so the tight cap saved nothing and could only truncate. The reasoning was never written down next to the code, so nobody re-derived it. The second is that `SampleValidator` reported a truncated response as "Missing intent, urgency, complaint" — the wording of a model answering badly, not of an answer that never arrived — so three runs of compatibility data read as provider flakiness.

What found it was measuring instead of guessing. The first hypothesis was `additionalProperties: false` against extra keys, which would have been shipped on reasoning alone and fixed nothing: the live run showed zero extra-key failures and zero enum failures. The cost of being wrong was one four-minute run.

Epic 01-10 story 1, 2026-09-20. Rates across four runs on identical fixtures: 0.8%, 2.4%, 3.2%, then 0.0% after the fix.

## Story 2 — Say why a sample failed, and record what the provider said

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- An output carrying none of the expected keys is reported as having no structured data, not as missing every field: an empty object gives `The output has no structured data.`, and an object whose keys are all unexpected says it answers none of the questions. An output missing some but not all of its fields still names them.
- The compatibility run reports the provider's own `stop_reason` beside each invalid sample's reason, read from the raw response and paired with the sample by order, so a truncated answer reads as truncation rather than as a bad answer.
- must NOT — A sample's invalid reason must not carry a string the model chose. Unexpected fields are counted, never named, because a field name is the model's to choose and can quote the state — and unlike `reason`, `invalid_reason` is written to the sample row unscrubbed and carried on `SampleInvalid`. A marker placed in the state, echoed back by the model as a field name, reaches no reason, no event and no recorded row.

### Task 1 — Distinguish an answer that never arrived from a bad one

**Status**: complete  

`SampleValidator` reports an output carrying none of the expected keys as having no structured data, or as answering none of the questions when it carries keys of its own, rather than listing every field as missing.

### Task 2 — Count unexpected fields rather than naming them

**Status**: complete  

A field name is the model's to choose and can quote the state, and `invalid_reason` is written to the sample row unscrubbed and carried on `SampleInvalid` — unlike `reason`, which the recorder scrubs. Report the count instead, and prove it with a fake that names a field after a marker in the state.

### Task 3 — Record the provider's stop reason in the compatibility run

**Status**: complete  

Read `stop_reason` from the raw response on `AgentPrompted`, pair it with its sample by order, and print it beside each invalid reason, so a truncated answer reads as truncation.

### Retro

- Writing the must-NOT for story 2 found a live privacy hole rather than confirming one was absent. `SampleValidator` named unexpected fields in the reason it produced — `Unexpected <key>.` — and a field name is the model's to choose, so a model echoing state text as a key put that text into `hunch_samples.invalid_reason` and onto the `SampleInvalid` event. `Recorder` scrubs `reason` on the line above and writes `invalid_reason` untouched, so the column beside the protected one was the leak.

The control proves it: restoring the old wording puts the state marker into both the stored row and the event, and the marker scans of epic 01-06 never caught it because none of them made a sample invalid. A must-NOT is worth writing even when you expect it to pass on the first run.

Epic 01-10 story 2, 2026-09-20. Fixed by counting unexpected fields rather than naming them.
