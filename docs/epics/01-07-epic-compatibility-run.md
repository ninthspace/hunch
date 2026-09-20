# Manual compatibility run

**Number**: 01-07  
**Source spec**: 01  
**Status**: complete  

## Fixture set size

Agreed with Chris before the epic was started: 42 items in three groups, every item classified at `fixed:3`, for 126 samples per run.

| Group | Items | Samples | Carries |
|---|---|---|---|
| Core | 24 | 72 | Boolean, small Choice and Score in one set; the accuracy figure |
| Large Choice | 12 | 36 | The 40–50 option question, reported separately; also the prompt-cache check |
| Injection | 6 | 18 | Compliance rate, reported and never failed on |
| **Total** | **42** | **126** | |

**Why 126 samples.** The 10% schema-invalid threshold is a proportion test, so its stability depends on the sample count rather than the item count. At 126 samples the threshold trips at 13 invalid; against a true invalid rate of 5% that is about 2.7 standard deviations out, so a false failure lands under 1%. At 30 samples the same threshold trips at 4, and a 5% true rate would fail roughly one run in twelve. Below about 100 samples the criterion measures noise.

**Accuracy is deliberately coarse.** Twenty-four core items give roughly ±10–15 points on accuracy. That is the right precision for a compatibility check, and the README table should say so, because measured accuracy comes from `hunch:calibrate` over labelled classifications, which needs `calibration.min_n` per cell.

**The large-Choice group carries the cache check.** Forty-five options with descriptions clear Anthropic's minimum cacheable prefix comfortably, so the `cacheReadInputTokens` criterion hangs off a fixture that is large for its own reasons rather than padded to trip caching.

**Labels must be unambiguous.** An arguable item measures the fixture rather than the provider. Twenty-four clean core items beat forty with a few contested ones.

**Cost is not the constraint; wall clock is.** With instructions cached per question set, a run is on the order of $0.30–$1. Samples run sequentially by design (ADR 01-06), so 126 calls is roughly 4–8 minutes.

## Story 1 — Run the compatibility command locally against Anthropic

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- `composer compat` reads `ANTHROPIC_API_KEY` from the local environment, exits non-zero with a clear message when it is absent, and completes a run when it is present. `[integration]`
- `composer compat` runs the public synthetic fixture set against Anthropic. It fails if Anthropic's samples are schema-invalid more than 10% of the time. `[integration]`
- For every provider, aggregation over real samples produces probabilities that sum to 1 and a `Result` with a fully populated `meta`. `[integration]`
- The fixture set is committed under `tests/Fixtures/compat/`, contains no real personal data, and every item carries a label. `[manual]` `[unit]`
- The fixture set includes a Choice question with 40–50 options, and the compatibility report shows its invalid-sample rate and accuracy per provider separately. `[integration]`
- The compatibility run includes an injection fixture whose state instructs the model to answer a fixed option. It records, and reports but does not fail on, each provider's rate of compliance. `[integration]`
- In the compatibility run against Anthropic, with a question set above the model's minimum cacheable length, the second and later samples report `cacheReadInputTokens` > 0. `[integration]`
- must NOT — A provider key is read from anywhere other than the local environment, or written to the repository. `[integration]`

### Task 1 — Build the synthetic labelled compatibility fixture set

**Status**: complete  

Under `tests/Fixtures/compat/`: no real personal data, every item labelled unambiguously, and three groups totalling 42 items — 24 core (Boolean, small Choice and Score in one set), 12 for the 40–50 option Choice, and 6 injection items whose state asks for a fixed option. See the epic's "Fixture set size" section for why.

### Task 2 — Write the compatibility suite

**Status**: complete  

Runs against Anthropic at `fixed:3` per item, so a run is 126 samples: schema-invalid rate threshold of 10% (which trips at 13 of 126), aggregation and full `meta` checks, the cache-read check on the large-Choice group, and reported-not-failed injection compliance and large-Choice results. Lives outside the default suite.

### Task 3 — Add the composer compat script

**Status**: complete  

Manual and local only: reads `ANTHROPIC_API_KEY` from the environment, exits non-zero with a clear message when it is absent, and never stores a key in the repository.

### Task 4 — Write tests for Run the compatibility command locally against Anthropic

**Status**: complete  

Covers the unit-tagged fixture labelling check and tests of `composer compat`'s key source and absent-key exit; the integration criteria are verified by a completed manual run.

### Retro

- The run passed first time against claude-sonnet-5: 42 items, 126 samples, 4 minutes, roughly $0.30. One sample of 126 was schema-invalid (0.8%, well under the 10% limit), and it was in the injection group. Accuracy was 93.1% core, 100% large-choice, 100% injection. Injection compliance was 0 of 6 — the provider followed none of the six embedded instructions, which is the result the `<state>` delimiting and the instruction line are meant to produce.

Prompt caching behaved exactly as the fixture design predicted: 48,125 cached input tokens in the large-choice group and zero in the other two, because only the 45-option instructions clear the 1024-token minimum. Hanging the cache criterion on that group rather than padding a smaller one was the right call.

Two things were caught before spending anything. The model constant read `claude-sonnet-4-5`, which the SDK does not know — every call would have 404'd; the SDK's Claude ids are opus-5, sonnet-5 and haiku-4-5. And `fixed:3` with the default `min_valid_samples` of 3 would have failed a whole item on a single invalid sample, so the run sets it to 1: a compatibility run has to record invalid samples, not abort on them.

Verification note: the run itself is the evidence for the integration criteria, but two claims needed offline controls rather than a green run — "fails above 10%" (now `CompatThresholds::exceedsInvalidLimit`, unit-tested at 12, 13 and 0 of 126) and "reports but does not fail on compliance" (a scan proving no assertion mentions the compliance counters). Both were extracted after the run; the arithmetic is unchanged, so the run's evidence still stands.

## Story 2 — Write provider results to the README locally

**Status**: complete  
**Blocked by**: Story 1  

### Acceptance Criteria

- `composer compat` writes accuracy and token usage per provider into the README results table and changes nothing else; it does not commit, push or open a pull request. `[integration]`
- After `composer compat`, `git diff --stat` shows changes only to `README.md`, confined to the results table. `[integration]`

### Task 1 — Write the results table into the README locally

**Status**: complete  

Accuracy and token usage per provider written into the README results table and nothing else; no commit, push or pull request.

### Task 2 — Write tests for Write provider results to the README locally

**Status**: complete  

Covers the table writer changing only the results section of `README.md`.

### Retro

- `ResultsTable` writes between two README markers and refuses to guess when they are missing, so the run cannot scribble over prose. The isolation tests cover the properties directly: only the block between markers changes, a second run replaces the first rather than appending, and a README without markers is left byte-identical.

The criterion "git diff --stat shows changes only to README.md" was verified from the run itself rather than by assertion: file modification times show every other repo file last touched at 23:18-23:20, before the run started, and README.md alone carries an mtime inside the run's 23:20-23:24 window.

Pest trap, the same shape as retro 05's arch rule: `expect($x)->not->toContain('needle', 'explanatory message')` passes vacuously, because `toContain` is variadic and the message becomes a second needle that is always absent. A planted assertion on injection compliance sailed through until the test was rewritten to collect offenders and assert an empty list. The rule that keeps emerging: under a negation, a Pest matcher taking variadic arguments will not fail if any argument is absent, so pass exactly one.

Partly promoted: its Pest variadic-negation half became library/01. Not retired, because the README-writer design and the mtime-as-evidence point are still live lessons that only exist here.

- Writing the README was the most effective design review of the run. Three questions only surfaced because someone had to be told the answer in plain words: does installing this bring migrations (it should not, and it did), do I need a database at all (the answer existed in ADR 01-08 and NFR3 but nowhere a reader would look), and what happens if I want persistence later (nobody had asked, and the failure was a raw SQL error).

None of those were criteria gaps in the epics that delivered them; they were gaps between what the rows said and what a user would need to know. Documentation asks the questions an acceptance criterion does not.

The README's examples are the compat fixtures, so the documented code is code that has run against a real provider. `tests/ReadmeExamplesTest.php` now executes those examples against the fake and asserts the README keeps naming the real publish tags, commands and PHP version, so the docs cannot drift from the API. It also pins the claims that matter most for trust: no database queries by default, the text is never stored, and installing adds no migrations.

The existing NFR7 honesty guard earned its place twice, rejecting "how accurate its answers were" and "calibrated retrospectively" in new prose. A guard on documentation wording is worth having precisely because prose is written quickly and read as a promise.

## Retro Applied

- 06 · scope-surprises · applied — The injection fixture's compliance rate is recorded and reported, never failed on, and no state text reaches the README table or any committed artefact.
- 06 · smooth-deliveries · applied — The compat runner reuses Scoring, Statistics and SamplingDriver rather than reimplementing accuracy or sampling, so compat measures what production measures.
- 06 · testing-gaps · applied — The fixture exercises Boolean, Choice, Score, the 40-50 option Choice and injection from the start; plants run against everything verifiable offline.
