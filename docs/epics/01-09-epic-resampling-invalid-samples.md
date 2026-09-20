# Replacing invalid samples

**Number**: 01-09  
**Source spec**: 01  
**Status**: complete  

## Why this epic exists

Epics 01-04 and 01-05 delivered a sampling loop in which an invalid sample consumes the budget and is never replaced. `Stopping::nextBatch()` counts every sample taken, valid or not, so `fixed:5` with two invalid samples aggregates a three-sample vote, and `fixed:3` with the default `min_valid_samples: 3` — a configuration the builder accepts — fails the whole classification on a single invalid sample. The compatibility run of 01-07 hit exactly that and had to set `min_valid_samples` to 1 to proceed.

The decision taken on 2026-09-20 is to top up: the sampling rule's counts become counts of *valid* samples, and further samples are taken to make up a shortfall, capped at `max_resamples` beyond the rule's maximum. The cap is what keeps the cost bounded and stated — a classification makes at most `rule max + max_resamples` model calls — and `max_resamples: 0` restores the delivered behaviour exactly.

FR6, FR7, FR12 and FR16 were amended for this on 2026-09-20. Every coverage fragment bound to them survived the amendment verbatim, so no binding was retired.

Seeds are unaffected in kind: a sample's seed is derived from the classification id and the sample number, and top-up samples keep taking the next number, so each replacement has its own seed and its own recorded row.

The `min_valid_samples` guard in `PendingClassification` needs no change. Top-up never takes more than the rule's maximum *valid* samples, so a `min_valid_samples` above the fewest the rule can take stays impossible and stays refused before any model call.

## Revisited after the truncation fix, and kept

Epic 01-10, later the same day, found that the invalid samples which motivated this epic were Hunch truncating its own answers against a 136-token ceiling. With the ceiling removed the live invalid rate went from 3.2% to 0.0% over 126 samples, which put the obvious question: was the replacement mechanism built for a problem that no longer exists?

It was revisited on 2026-09-20 and kept, at the default cap of 2, for two reasons.

The first is that it costs nothing when nothing fails. With every sample valid, the valid count equals the calls spent, the remaining figure reduces to `max - valid`, and the loop takes exactly the path it took before — which is why a test asserting `sampling(5)` prompts exactly five times passes unchanged at the default cap. It is contingent, not a tax.

The second is that 0.0% is one run, on one model, one provider, and 42 synthetic items, while FR3 commits the driver to any SDK text provider supporting structured output. A local or cheaper model, or a question set large enough to strain one, will not hold that rate.

There is also a flaw this removes that has nothing to do with truncation: `fixed:N` with `min_valid_samples` equal to N is a configuration the builder accepts in which a single invalid sample, from any cause, discards the whole classification.

The honest qualification, which the README now carries, is that on a capable model this will almost never fire. It is insurance, and it should not be read as something an application will see in its token bill.

## Story 1 — Top up invalid samples within a stated cap

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- A fixed rule replaces its invalid samples: with `fixed:5` and `max_resamples: 2`, a run whose 2nd and 4th samples are invalid makes 7 model calls and aggregates 5 valid ones, and `Result->samples` reports 7 taken, 5 valid, 2 invalid. `[feature]` `[tdd]`
- An adaptive rule counts valid samples for both ends of its range: with `adaptive:3-9`, an invalid sample among the first three is replaced before the settle check can stop the run, so the run does not settle on a two-sample vote. `[feature]`
- The cap bounds the cost: with `fixed:5`, `max_resamples: 2` and every sample invalid, exactly 7 model calls are made and `classify()` then throws `ClassificationFailed` carrying all 7 samples and their usage. `[feature]`
- `max_resamples: 0` takes the rule's count once and replaces nothing: `fixed:5` with 2 invalid samples makes exactly 5 calls and aggregates 3 valid ones, as the delivered behaviour did. `[feature]`
- `max_resamples` is published in `config/hunch.php` with a default of 2, is read through `Settings`, and a negative value is treated as 0 rather than shrinking the rule. The README's configuration table documents it, including what 0 means. `[integration]`
- must NOT — A replacement sample must not reuse the seed or the sample number of the invalid sample it replaces: across a topped-up run every sample number is distinct and consecutive from 1, and `meta->seeds` holds a distinct seed for each. `[feature]`
- must NOT — Topping up must not make an invalid sample count towards an answer: the aggregated shares over a topped-up run equal those over the same valid samples taken alone, and every invalid sample is still recorded, still dispatches `SampleInvalid`, and is still persisted with its reason. `[feature]`

### Task 1 — Add `max_resamples` to the config and Settings

**Status**: complete  

Publish `max_resamples` in `config/hunch.php` with a default of 2 and a comment saying what 0 means, and read it through `Settings::int` clamped at 0 so a negative value cannot shrink the rule. Serves criterion 5.

### Task 2 — Count valid samples in Stopping, bounded by the call ceiling

**Status**: complete  

`nextBatch()` takes the resampling allowance and works from two counts: the valid samples so far, which the rule's min and max are measured against, and the calls spent, which the ceiling of `rule max + allowance` bounds. The samples still to come is the smaller of the two headrooms, and that figure is what the adaptive settle check is given, so the cap can settle a run the rule alone would continue. Serves criteria 1, 2, 3 and 4.

### Task 3 — Pass the allowance through the sampling driver

**Status**: complete — The clamp on a negative allowance ended up in Stopping rather than the driver, where the default argument needs it anyway; the driver passes the setting through unchanged.  

`SamplingDriver` reads the allowance once per classification and passes it to each `nextBatch()` call. Sample numbering, seeds, events, aggregation and the `min_valid_samples` check are left exactly as they are — numbering already increments per call, which is what keeps a replacement's seed its own. Serves criteria 6 and 7.

### Task 4 — Tests, each shown failing against a confirmed plant

**Status**: complete  

`tests/Sampling/ResamplingTest.php` covers the seven criteria: a fixed top-up, an adaptive top-up that does not settle short, the cap and its `ClassificationFailed`, `max_resamples: 0`, the config key, distinct seeds and numbers, and aggregation unchanged with the invalid samples still recorded and still dispatching `SampleInvalid`. Each must-NOT carries a control that would catch the rejected thing. Every test is shown failing against a planted defect, and each plant is confirmed to have applied.

### Task 5 — Document the replacement in the README

**Status**: complete  

Add `max_resamples` to the configuration reference table and rewrite "Sampling, and what happens when it fails" so it says an invalid sample is replaced, what the cap costs at worst, and that 0 turns replacement off. Serves criterion 5.

### Retro

- Changing a delivered behaviour cost far more in test churn than in source: nine lines of `Stopping` and one in the driver, against twenty existing tests across eight files that had encoded the old budget in their expected call counts. Sorting them needed a rule, and the rule that worked was to ask what each test's subject was. A test about validity handling or token summing got `max_resamples: 0` pinned so it kept testing its one thing; a test whose subject actually was the budget was restated. One could be neither: `'ignores invalid samples when judging unanimity'` asserted that an adaptive run stops at three calls holding two valid samples, and no cap setting reaches that state any more, because `min` now means min valid. It was deleted rather than contorted, its claim folded into the replacement test, which still fails if an invalid sample is allowed to vote.

The churn is also the argument for having amended the spec first. Each of those twenty tests was a true statement about FR6, FR7 or FR12 as written, so without the amendment there would have been no way to tell a test that had to change from a defect this work introduced.

Epic 01-09 story 1, 2026-09-20.
