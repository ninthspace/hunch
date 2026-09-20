# Persistence, labels and calibration

**Number**: 06  
**Source epic**: 01-06  
**Status**: complete  

## Observations

- Scope surprise · Testing gap — Persistence has a single entry point. `PendingClassification::record()` checks persistence and the subject's key before any model call, and `classify()` hands the Result, or the ClassificationFailed, to `Persistence\Recorder` in one transaction. So any driver, TypeSafe included, is recorded the same way. `Result` gained `taken` (every Sample), and drivers gained `name()`.

Three finds went beyond the criteria:
1. Scrubbing stored reasons (Chris chose it over not storing them, or storing them verbatim). A model reason can quote the state, and that conflicted with the marker scans. Stored reasons now have every 8+ character passage found in the sent state replaced with `[state]`; `Result->reasons` stays unscrubbed.
2. The provider's own message was in the `ClassificationFailed` message and could quote the prompt. It is now reachable only through `getPrevious()`.
3. The scratch-app install check exposed data loss. With `retention_days` unset, `prunable()` used `subDays(0)` and would have pruned every row. Now there is nothing to prune without a positive value. `Settings::int` also now accepts integer strings, because env-sourced retention was silently ignored.

`model:prune` only discovers `app/Models`, so the config documents scheduling it with `--model`. 15 plants were checked to apply. One, planting the state into `version`, first missed: `classification()` sets `version` after the spread and overwrote the plant. Re-planted into `model_reported`, it was caught.

NFR2 ("never store the state") was settled once in the spec, but it kept resurfacing as a design constraint rather than a check: a model's reason can quote the state, and eval has to re-classify items whose text Hunch does not hold. Both needed a decision from Chris, and both were answered without weakening the rule — scrub quoted runs from stored reasons; let the subject provide its own state. Expect a privacy rule to shape features long after it is written.

- Smooth delivery — `Hunch::label()` delegates to `Persistence\Labeller`, which validates every answer before writing anything. Answers are checked against the question set *as recorded*: it decodes `hunch_question_sets.canonical`, not a live QuestionSet, so a label is judged by the question that was actually asked. A Boolean needs a bool, and a Choice or Score needs one of its canonical keys, compared case-sensitively. Superseding is an UPDATE of `superseded_at` on the current row inside the same transaction as the insert, so exactly one label per question is current. `Labelled` extends the shared event base (ID, question set hash, state hash) and carries the question keys. The story-6 "no event property could hold the state" control needed an explicit exception for that one list, rather than allowing arrays in general.

The persistence setup and helpers moved to `tests/Persistence/helpers.php`, shared by the record and label tests. 10 plants were checked to apply, and all were caught, including validating while writing (which left a partial write) and deleting the superseded label instead of keeping it.

One choke point per concern kept this epic honest: recording in the builder, `Scoring` shared by the recorder, calibrate and eval, and `CalibrationScope::model` as the single rule for a cell's model. Writer and reader are then pinned together by an end-to-end test, so a key that drifts fails loudly instead of quietly measuring nothing.

- Testing gap — Test-first, as the tdd tags asked. The statistics tests were red (the class was missing) before `Calibration\Statistics` existed: Wilson (reference from Python's `NormalDist`, not my own arithmetic), Boolean Brier, multi-class Brier, ECE and reliability. The only green-before-fix surprise was PHP's int/int division returning int `1` for accuracy.

Design: `Calibrator` joins current labels to the recorded `answers` JSON and judges correctness by that JSON's `answer` field: Choice `choice`, Boolean `isTrue()`, Score `mode`, which matches the criterion's "modal level". The stated probability is the winning answer's share, which is exactly what the bucket is built from. The cell model is `model_reported ?? model_requested`. Storage is `updateOrCreate` per cell and then deletes stale cells, so row IDs survive and a rerun is identical under frozen time.

Gaps closed beyond the criteria: the Choice-only fixture never exercised Boolean or Score, so a hand-computed test for both was added; and skipping non-applying conditional answers (my own design choice) went untested until a plant missed it. `CalibrationComputed` does not extend the classification event base, because it belongs to no one classification. 14 plants were checked to apply; 13 were caught first time, and the 14th led to the new applies test.

A fixture only tests what it contains. Every plant that missed this epic missed because the fixture was too plain — a Choice but no Boolean or Score, no context, no superseded label, no conditional question. Each miss was turned into a richer fixture rather than just a fix, and the plants were then caught. Pest's own traps cost time again: `expectsOutputToContain` consumes one output line per expectation, and `ClassifierAgent::fake()` does not reset recorded prompts.

- Pattern worth reusing — Answers are readonly, so the calibration cell's context can't be attached afterwards. A `CalibrationScope` (question set hash, driver, provider, model, sampling rule, question) now travels into every answer at construction: through `Aggregator::answers()`, `aggregate()` and `fromShares()`, from both drivers. `calibration()` returns null before any query when there is no scope or persistence is off; otherwise it looks up the exact cell (scope columns + `calibrationAnswer()` + bucket) and gates on `calibration.min_n`. `CalibrationScope::model()` is the single rule (reported, else requested) that both `Calibrator` and the answers use, so writer and reader can't drift. An end-to-end test proves it: 30 recorded, labelled classifications, then `hunch:calibrate`, then a fresh answer reads n 30, accuracy 0.9 and the Wilson bound. A planted key mismatch in `Calibrator` fails that test.

`Buckets` now validates on use and throws, rather than silently falling back to defaults: a non-empty list, strictly descending, edges in (0, 1]. 13 plants were checked to apply, and all were caught.

One choke point per concern kept this epic honest: recording in the builder, `Scoring` shared by the recorder, calibrate and eval, and `CalibrationScope::model` as the single rule for a cell's model. Writer and reader are then pinned together by an end-to-end test, so a key that drifts fails loudly instead of quietly measuring nothing.

- Scope surprise · Testing gap — This story had two gaps the criteria didn't answer, both put to Chris.

1. Eval re-classifies labelled items, but Hunch never stores the state (NFR2). Chosen: the subject gives it back, through a new `Contracts\ProvidesHunchState`. Items whose subject is gone or doesn't implement it are skipped and counted in the output. Nothing new is stored.
2. `onlyWhen` conditions and tie-breaks are deliberately outside the hash (FR14), so the canonical JSON can't rebuild the set that was asked. Chosen: `hunch_question_sets` gained a `definition` column beside `canonical`, and `QuestionSet::fromStored()` rebuilds from both. The hash is unchanged, which a round-trip test pins.

`Calibration\Scoring` now holds the answer-to-row mapping and the per-answer scoring that `Recorder`, `Calibrator` and `hunch:eval` all use, so the three cannot measure differently. Eval never calls `record()`, so its runs can't reach `hunch:calibrate`.

Two Pest traps cost time: `expectsOutputToContain` consumes one output line per expectation, so several values on one table row never match — asserting on `Artisan::output()` is the way; and `ClassifierAgent::fake()` does not reset recorded prompts, so `assertNeverPrompted` counted the fixture's own prompts. Prompts are now counted with a listener registered after the fixture.

17 plants were checked to apply. Three missed at first because the fixture was too plain (no context, no superseded label, no conditions); the fixture gained all three and a round-trip test, and all were then caught.

NFR2 ("never store the state") was settled once in the spec, but it kept resurfacing as a design constraint rather than a check: a model's reason can quote the state, and eval has to re-classify items whose text Hunch does not hold. Both needed a decision from Chris, and both were answered without weakening the rule — scrub quoted runs from stored reasons; let the subject provide its own state. Expect a privacy rule to shape features long after it is written.

- Pattern worth reusing · Smooth delivery — `withSelfReport()` adds a required `bands` object to the schema, one enum per question, and the instructions define the three bands (high, medium, low) rather than leaving "how confident are you" open. A sample missing a band, or stating an unknown one, is invalid like any other schema breach, so a self-report failure never becomes a silent default.

Two judgement calls the criteria left open, both recorded in `Prompts\Bands`: a classification's stated band is the modal band across its valid samples, and a tie goes to the *lower* confidence, so a split never reads as more certain than it was; and each band stands for a probability (0.95 / 0.75 / 0.4) so stated bands can be measured with the same ECE function as buckets. `hunch:calibrate` prints a per-band table and a band ECE beside the agreement-based ones.

Bands follow the reasons pattern exactly: carried on `Sample` apart from the answers, stored in `hunch_samples.band`, and guarded by a token scan that allows `->bands` only in `Recorder::samples`. The must-NOT is a two-run test with a fixed ULID: all-high and all-low bands give identical votes, probabilities, buckets, answers and hash. 12 plants were checked to apply, and all were caught, including a band quietly truncating the vote list.

One choke point per concern kept this epic honest: recording in the builder, `Scoring` shared by the recorder, calibrate and eval, and `CalibrationScope::model` as the single rule for a cell's model. Writer and reader are then pinned together by an end-to-end test, so a key that drifts fails loudly instead of quietly measuring nothing.

- Smooth delivery — The journey test runs the epic end to end with no new production code: 30 classifications recorded against their own subjects, labelled, calibrated, then a 31st classification reads the cell back through `calibration()`. It pins the row counts (31 classifications, 93 samples, 30 current labels) as well as the measures, so a change that quietly stops writing samples fails here even though no criterion mentions sample rows.

The relabel test is the one that ties labels to calibration: relabelling the 28th item and recalibrating moves `correct` from 27 to 28 on the same row id, and leaves two label rows with exactly one current. A plant that stopped superseding, and one that rebuilt the cells from scratch each run, both failed it.

One plant missed, correctly: loosening `calibration.min_n` changes nothing at n = 30, and story 4's lookup tests already hold that boundary. 4 plants were checked to apply, 3 caught here and the 4th covered elsewhere.

The throwaway Laravel app earned its keep twice this epic, catching what the package suite could not: pruning that would have deleted every row when `retention_days` was unset, and an eval message that told a consumer nothing was labelled when in truth everything was skipped. Both became tests. An install-level check is worth running once per epic that adds migrations or commands.
