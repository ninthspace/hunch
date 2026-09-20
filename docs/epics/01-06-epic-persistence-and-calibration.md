# Persistence, labels and calibration

**Number**: 01-06  
**Source spec**: 01  
**Status**: complete  

## Story 1 — Persist classifications without the state

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- With persistence on, `->record($enquiry)->classify()` writes:
- one `hunch_classifications` row, with the subject morph set and `state_hash` equal to sha256 of the rendered state
- one `hunch_samples` row per sample
- a `hunch_question_sets` row, if one did not already exist `[integration]`
- `hunch_samples.reason` is filled only when `withReasons()` was used; otherwise it is null. `[integration]`
- `model:prune` deletes classification rows older than `retention_days`, together with their samples, and keeps newer rows. `[integration]`
- A failed classification is recorded with status `failed`, the error, and the samples taken. `[integration]`
- must NOT — Any column in any Hunch table contains the state text. Checked by embedding a unique marker string in the state and scanning every row. `[integration]`
- must NOT — `record()` succeeds when persistence is off; it throws instead. `[unit]`
- With persistence on, reasons on, and a log spy and cache spy attached, a classification whose state contains a unique marker writes that marker to no database row, log entry or cache entry. `[integration]`
- For a failed classification whose state contains a unique marker, the marker appears in neither the `ClassificationFailed` message nor the recorded `error` column. `[integration]`
- `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`, and a persistence test migrates and passes. `[integration]`
- must NOT — Any prompt, event or persisted row contains the unredacted text when a redactor is set. `[integration]`
- must NOT — `model:prune` deletes a `hunch_labels` row, or a classification that has a current label. `[integration]`

### Task 1 — Write migrations and models for the five Hunch tables

**Status**: complete  

`hunch_question_sets`, `hunch_classifications`, `hunch_samples` (with `reason` and `band`), `hunch_labels` (with `superseded_at`) and `hunch_calibration` keyed by the full cell. `state_hash` only, never the state.

### Task 2 — Implement record() persistence

**Status**: complete  

Classification with subject morph and `state_hash`, one sample row per sample, question set row if new, reasons only when requested, failed runs with the state scrubbed from `error`. Throws when persistence is off.

### Task 3 — Implement pruning with Prunable, sparing labels

**Status**: complete  

Classifications older than `retention_days` go with their samples; labels and classifications with a current label are never pruned.

### Task 4 — Write tests for Persist classifications without the state

**Status**: complete  

Covers the eleven unit- and integration-tagged criteria, including the marker scans across rows, logs and cache, and the redaction check on persisted rows.

### Retro

- Persistence has a single entry point. `PendingClassification::record()` checks persistence and the subject's key before any model call, and `classify()` hands the Result, or the ClassificationFailed, to `Persistence\Recorder` in one transaction. So any driver, TypeSafe included, is recorded the same way. `Result` gained `taken` (every Sample), and drivers gained `name()`.

Three finds went beyond the criteria:
1. Scrubbing stored reasons (Chris chose it over not storing them, or storing them verbatim). A model reason can quote the state, and that conflicted with the marker scans. Stored reasons now have every 8+ character passage found in the sent state replaced with `[state]`; `Result->reasons` stays unscrubbed.
2. The provider's own message was in the `ClassificationFailed` message and could quote the prompt. It is now reachable only through `getPrevious()`.
3. The scratch-app install check exposed data loss. With `retention_days` unset, `prunable()` used `subDays(0)` and would have pruned every row. Now there is nothing to prune without a positive value. `Settings::int` also now accepts integer strings, because env-sourced retention was silently ignored.

`model:prune` only discovers `app/Models`, so the config documents scheduling it with `--model`. 15 plants were checked to apply. One, planting the state into `version`, first missed: `classification()` sets `version` after the spread and overwrote the plant. Re-planted into `model_reported`, it was caught.

## Story 2 — Record human labels

**Status**: complete  
**Blocked by**: Story 1  

### Acceptance Criteria

- `Hunch::label($id, ['intent' => 'refund'], by: $user)` writes one `hunch_labels` row with the labeller morph set, and leaves the classification's other questions unlabelled. `[integration]`
- A second label for the same classification and question sets `superseded_at` on the first. Both rows remain, and exactly one is current. `[integration]`
- A label for an unknown question key, or a Choice value that is not a canonical option, throws and writes nothing. `[integration]`
- Labelling a classification ID that does not exist throws. `[integration]`

### Task 1 — Implement Hunch::label

**Status**: complete  

Validates the classification ID, question keys and canonical values before writing; supersedes the earlier label for the same question without deleting it; dispatches `Labelled`.

### Task 2 — Write tests for Record human labels

**Status**: complete  

Covers the four integration-tagged criteria.

### Retro

- `Hunch::label()` delegates to `Persistence\Labeller`, which validates every answer before writing anything. Answers are checked against the question set *as recorded*: it decodes `hunch_question_sets.canonical`, not a live QuestionSet, so a label is judged by the question that was actually asked. A Boolean needs a bool, and a Choice or Score needs one of its canonical keys, compared case-sensitively. Superseding is an UPDATE of `superseded_at` on the current row inside the same transaction as the insert, so exactly one label per question is current. `Labelled` extends the shared event base (ID, question set hash, state hash) and carries the question keys. The story-6 "no event property could hold the state" control needed an explicit exception for that one list, rather than allowing arrays in general.

The persistence setup and helpers moved to `tests/Persistence/helpers.php`, shared by the record and label tests. 10 plants were checked to apply, and all were caught, including validating while writing (which left a partial write) and deleting the superseded label instead of keeping it.

## Story 3 — Compute calibration with hunch:calibrate

**Status**: complete  
**Blocked by**: Story 2  

### Acceptance Criteria

- The Wilson score 95% lower bound for 27 correct out of 30 matches a reference value to 1e-6. `[tdd]` `[unit]`
- The Brier score for Boolean probabilities [1.0, 0.8, 0.6] against labels [T, F, T] is (0 + 0.64 + 0.16) / 3 to 1e-9. For Choice, it is the multi-class Brier score over the option probability vector. `[tdd]` `[unit]`
- The expected calibration error for a hand-built set across 3 buckets equals the count-weighted mean of |mean stated probability − accuracy| per bucket, to 1e-9. `[tdd]` `[unit]`
- Against a seeded SQLite fixture of 40 labelled classifications across 2 drivers, `hunch:calibrate` writes one `hunch_calibration` row per cell (set hash, driver, provider, model, rule, question, answer, bucket). Each row's n, correct, accuracy and lower bound match the fixture's expected values.

A classification is correct for a question when its winning answer equals the current label. The winning answer is the Choice's `choice`, the Boolean's value at probability ≥ 0.5, or the Score's modal level. `[integration]`
- Only classifications with a current label for the question are counted; unlabelled questions are skipped. `[integration]`
- Running `hunch:calibrate` twice produces identical rows: it is idempotent and replaces previous values. `[integration]`
- must NOT — Classifications from different drivers or models are merged into one calibration cell. `[integration]`
- must NOT — A superseded label is counted by `hunch:calibrate`. `[integration]`
- `Hunch::label()` dispatches `Labelled`, and `hunch:calibrate` dispatches `CalibrationComputed` once per run. `[integration]`

### Task 1 — Write tests for Compute calibration with hunch:calibrate

**Status**: complete  

Test-first: covers the three unit-tagged statistics criteria and the six integration-tagged command criteria against a seeded SQLite fixture.

### Task 2 — Implement the Wilson lower bound, Brier score and ECE

**Status**: complete  

Pure functions shared by `hunch:calibrate` and `hunch:eval`; multi-class Brier for Choice.

### Task 3 — Implement the hunch:calibrate command

**Status**: complete  

Per-cell rows from current labels only, per-question Brier, ECE and reliability table, cells never merged across drivers or models, idempotent replacement, and one `CalibrationComputed` per run.

### Retro

- Test-first, as the tdd tags asked. The statistics tests were red (the class was missing) before `Calibration\Statistics` existed: Wilson (reference from Python's `NormalDist`, not my own arithmetic), Boolean Brier, multi-class Brier, ECE and reliability. The only green-before-fix surprise was PHP's int/int division returning int `1` for accuracy.

Design: `Calibrator` joins current labels to the recorded `answers` JSON and judges correctness by that JSON's `answer` field: Choice `choice`, Boolean `isTrue()`, Score `mode`, which matches the criterion's "modal level". The stated probability is the winning answer's share, which is exactly what the bucket is built from. The cell model is `model_reported ?? model_requested`. Storage is `updateOrCreate` per cell and then deletes stale cells, so row IDs survive and a rerun is identical under frozen time.

Gaps closed beyond the criteria: the Choice-only fixture never exercised Boolean or Score, so a hand-computed test for both was added; and skipping non-applying conditional answers (my own design choice) went untested until a plant missed it. `CalibrationComputed` does not extend the classification event base, because it belongs to no one classification. 14 plants were checked to apply; 13 were caught first time, and the 14th led to the new applies test.

## Story 4 — Read calibration back through buckets

**Status**: complete  
**Blocked by**: Story 1  

### Acceptance Criteria

- With `hunch.buckets` = `[1.0, 0.9, 0.5]`, a probability of 0.95 falls in `[0.9, 1.0)` and 0.4 falls in `[0, 0.5)`. `[unit]`
- With a calibration row where n = 30 for the answer's cell, `$answer->calibration()` returns `{n: 30, accuracy, lowerBound}` equal to that row. `[integration]`
- With n = 29 for the cell (below `calibration.min_n` = 30), `calibration()` returns null. `[integration]`
- With persistence off, `calibration()` returns null and makes no database query. `[integration]`
- must NOT — `calibration()` on a sampling-driver answer returns a row computed for the TypeSafe driver, or for a different model or sampling rule. `[integration]`
- A bucket configuration that is not strictly descending, or has an edge outside (0, 1], throws a configuration exception. `[unit]`

### Task 1 — Validate buckets and implement the calibration() lookup

**Status**: complete  

Strictly descending edges in (0, 1]; lookup by the exact cell, gated by `calibration.min_n`; null with no query when persistence is off.

### Task 2 — Write tests for Read calibration back through buckets

**Status**: complete  

Covers the six unit- and integration-tagged criteria.

### Retro

- Answers are readonly, so the calibration cell's context can't be attached afterwards. A `CalibrationScope` (question set hash, driver, provider, model, sampling rule, question) now travels into every answer at construction: through `Aggregator::answers()`, `aggregate()` and `fromShares()`, from both drivers. `calibration()` returns null before any query when there is no scope or persistence is off; otherwise it looks up the exact cell (scope columns + `calibrationAnswer()` + bucket) and gates on `calibration.min_n`. `CalibrationScope::model()` is the single rule (reported, else requested) that both `Calibrator` and the answers use, so writer and reader can't drift. An end-to-end test proves it: 30 recorded, labelled classifications, then `hunch:calibrate`, then a fresh answer reads n 30, accuracy 0.9 and the Wilson bound. A planted key mismatch in `Calibrator` fails that test.

`Buckets` now validates on use and throws, rather than silently falling back to defaults: a non-empty list, strictly descending, edges in (0, 1]. 13 plants were checked to apply, and all were caught.

## Story 5 — Compare drivers with hunch:eval

**Status**: complete  
**Blocked by**: Story 3  

### Acceptance Criteria

- Against a seeded SQLite set of 20 labelled classifications, `hunch:eval {hash} --driver=sampling:fake:m1 --driver=sampling:fake:m2` (with faked agents) re-classifies all 20 with each driver and prints one row per driver: accuracy, Brier, ECE, mean samples, and input, cached-input and output tokens. `[feature]`
- Accuracy, Brier and ECE are computed by the same functions `hunch:calibrate` uses, and match the fixture's expected values. `[integration]`
- A question set hash with no labelled items exits non-zero with a clear message and makes no model calls. `[feature]`
- A `--driver` value that does not parse as `driver:provider:model` exits non-zero before any model call. `[unit]`
- must NOT — An eval run writes classification or calibration rows that `hunch:calibrate` would count alongside production classifications. `[integration]`

### Task 1 — Implement the hunch:eval command

**Status**: complete  

Parses `driver:provider:model` before any call, re-classifies the labelled items per driver, reuses the calibrate functions, reports token usage but no cost, and writes nothing `hunch:calibrate` would count.

### Task 2 — Write tests for Compare drivers with hunch:eval

**Status**: complete  

Covers the five feature-, integration- and unit-tagged criteria.

### Retro

- This story had two gaps the criteria didn't answer, both put to Chris.

1. Eval re-classifies labelled items, but Hunch never stores the state (NFR2). Chosen: the subject gives it back, through a new `Contracts\ProvidesHunchState`. Items whose subject is gone or doesn't implement it are skipped and counted in the output. Nothing new is stored.
2. `onlyWhen` conditions and tie-breaks are deliberately outside the hash (FR14), so the canonical JSON can't rebuild the set that was asked. Chosen: `hunch_question_sets` gained a `definition` column beside `canonical`, and `QuestionSet::fromStored()` rebuilds from both. The hash is unchanged, which a round-trip test pins.

`Calibration\Scoring` now holds the answer-to-row mapping and the per-answer scoring that `Recorder`, `Calibrator` and `hunch:eval` all use, so the three cannot measure differently. Eval never calls `record()`, so its runs can't reach `hunch:calibrate`.

Two Pest traps cost time: `expectsOutputToContain` consumes one output line per expectation, so several values on one table row never match — asserting on `Artisan::output()` is the way; and `ClassifierAgent::fake()` does not reset recorded prompts, so `assertNeverPrompted` counted the fixture's own prompts. Prompts are now counted with a listener registered after the fixture.

17 plants were checked to apply. Three missed at first because the fixture was too plain (no context, no superseded label, no conditions); the fixture gained all three and a round-trip test, and all were then caught.

## Story 6 — Record self-reported confidence bands

**Status**: complete  
**Blocked by**: Story 3  

### Acceptance Criteria

- With `withSelfReport()`, the schema requires a `band` per question from a fixed set of bands defined in the instructions, and each sample's bands are recorded (in `hunch_samples.band` when persisted). `[integration]`
- Against a seeded fixture with bands recorded, `hunch:calibrate` computes accuracy, Brier score and ECE per stated band alongside the agreement-based measures, over the same classifications. `[integration]`
- must NOT — A self-reported band changes any vote, probability, bucket or answer. `[unit]`
- Without `withSelfReport()`, the schema has no `band` field. `[unit]`

### Task 1 — Implement withSelfReport bands

**Status**: complete  

Band schema field and instruction set, bands recorded per sample, and per-band accuracy, Brier and ECE in `hunch:calibrate`. Bands never affect votes, probabilities, buckets or answers.

### Task 2 — Write tests for Record self-reported confidence bands

**Status**: complete  

Covers the four unit- and integration-tagged criteria.

### Retro

- `withSelfReport()` adds a required `bands` object to the schema, one enum per question, and the instructions define the three bands (high, medium, low) rather than leaving "how confident are you" open. A sample missing a band, or stating an unknown one, is invalid like any other schema breach, so a self-report failure never becomes a silent default.

Two judgement calls the criteria left open, both recorded in `Prompts\Bands`: a classification's stated band is the modal band across its valid samples, and a tie goes to the *lower* confidence, so a split never reads as more certain than it was; and each band stands for a probability (0.95 / 0.75 / 0.4) so stated bands can be measured with the same ECE function as buckets. `hunch:calibrate` prints a per-band table and a band ECE beside the agreement-based ones.

Bands follow the reasons pattern exactly: carried on `Sample` apart from the answers, stored in `hunch_samples.band`, and guarded by a token scan that allows `->bands` only in `Recorder::samples`. The must-NOT is a two-run test with a fixed ULID: all-high and all-low bands give identical votes, probabilities, buckets, answers and hash. 12 plants were checked to apply, and all were caught, including a band quietly truncating the vote list.

## Story 7 — Verify cross-story integration for Persistence, labels and calibration

**Status**: complete  
**Blocked by**: Story 1, Story 2, Story 3, Story 4, Story 5, Story 6  

### Acceptance Criteria

- 30 classifications recorded with `record()`, labelled with `Hunch::label()` and calibrated by `hunch:calibrate` into one cell make a later answer in that cell return the computed row from `calibration()`. `[integration]`
- Relabelling a recorded classification after a calibration run changes that cell's `correct` count on the next `hunch:calibrate` run. `[integration]`

### Task 1 — Write the cross-story integration tests for persistence and calibration

**Status**: complete  

From `record()` through `Hunch::label()` and `hunch:calibrate` to `calibration()`, and relabel-then-recalibrate. No new production code.

### Retro

- The journey test runs the epic end to end with no new production code: 30 classifications recorded against their own subjects, labelled, calibrated, then a 31st classification reads the cell back through `calibration()`. It pins the row counts (31 classifications, 93 samples, 30 current labels) as well as the measures, so a change that quietly stops writing samples fails here even though no criterion mentions sample rows.

The relabel test is the one that ties labels to calibration: relabelling the 28th item and recalibrating moves `correct` from 27 to 28 on the same row id, and leaves two label rows with exactly one current. A plant that stopped superseding, and one that rebuilt the cells from scratch each run, both failed it.

One plant missed, correctly: loosening `calibration.min_n` changes nothing at n = 30, and story 4's lookup tests already hold that boundary. 4 plants were checked to apply, 3 caught here and the 4th covered elsewhere.

## Dependencies

- blocks → 01-08

## Retro Applied

- 04 · codebase-discoveries · applied — Testbench provider registration kept explicit; migration publishing, pruning and the hunch:* commands get an install check in a scratch Laravel app via a path repository.
- 05 · smooth-deliveries · applied — Persistence hangs off one driver-agnostic choke point on the classify path rather than code in each driver.
- 04 · testing-gaps · applied — No closure datasets; try/catch over toThrow(Interface); exception types (SQLite/Eloquent) confirmed before asserting.
- 05 · testing-gaps · applied — Plant-first with an apply check on every plant; DB-backed criteria also get a plant against the written rows; fixtures paired with the criterion's own end-to-end example.
