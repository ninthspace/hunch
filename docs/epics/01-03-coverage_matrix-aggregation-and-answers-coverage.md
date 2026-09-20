# Coverage: Aggregation and answers

**Number**: 01-03  
**Source epic**: 01-03  
**Status**: pending  

## Coverage

| # | Requirement | Spec Text | Story Criterion | Covered by | Test Approach | Verified |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | FR8 | `probability` is true votes ÷ counted samples. | Boolean votes T,T,T,F,F give `probability` 0.6. | Story 1 | `[tdd]` `[unit]` | ✓ |
| 2 | FR8 | `probabilities` is the share per option; `choice` is the option with the most votes; `confidence` is the top share | Choice votes a,a,a,b over options {a,b,c} give `probabilities` {a: 0.75, b: 0.25, c: 0}, `choice` a, `confidence` 0.75, and `entropy` equal to H(0.75, 0.25) / log(3) within 1e-9. | Story 1 | `[tdd]` `[unit]` | ✓ |
| 3 | FR8 | `entropy` is the normalised Shannon entropy of the shares. | A unanimous Choice has `entropy` 0. Equal votes across all options give `entropy` 1. | Story 1 | `[tdd]` `[unit]` | ✓ |
| 4 | FR8 | `probabilities` is the share per level; `score` is the mean level index; `level()` is the label of the nearest level, with halves rounded up. | Score levels [L,M,H] with votes L,M,H,H give `score` 1.25 and `level()` M. Votes M,H give `score` 1.5 and `level()` H (halves round up). | Story 1 | `[tdd]` `[unit]` | ✓ |
| 5 | FR8 | `probabilities` is the share per option | The shares in `probabilities` for Choice and Score sum to 1 within 1e-9. | Story 1 | `[unit]` | ✓ |
| 6 | FR8 | `probabilities` is the share per level | The shares in `probabilities` for Choice and Score sum to 1 within 1e-9. | Story 1 | `[unit]` | ✓ |
| 7 | FR8 | Aggregation over the valid samples in which a question applies. | must NOT — An invalid sample changes any probability or vote count. | Story 1 | `[unit]` | ✓ |
| 8 | FR9 | If the question's `tieBreak` value is among the tied answers, it wins. | Choice [a,b,c] with votes b,b,c,c and `tieBreak('c')` gives `choice` c and `tied` true. | Story 2 | `[tdd]` `[unit]` | ✓ |
| 9 | FR9 | The answer records `tied = true`. | Choice [a,b,c] with votes b,b,c,c and `tieBreak('c')` gives `choice` c and `tied` true. | Story 2 | `[tdd]` `[unit]` | ✓ |
| 10 | FR9 | Otherwise the tied answer earliest in the question's canonical order wins. | Choice [a,b,c] with votes b,b,c,c and `tieBreak('a')` (not among the tied answers) gives `choice` b, the earliest tied answer in canonical order, and `tied` true. | Story 2 | `[tdd]` `[unit]` | ✓ |
| 11 | FR9 | Ties between the leading Choice or Score answers | A Score tie resolves the modal answer by the same tie rule. `score` and `level()` still come from the mean level index and are unaffected by the tie. | Story 2 | `[tdd]` `[unit]` | ✓ |
| 12 | FR9 | A Boolean tie needs no rule: its probability is exactly 0.5. | Boolean votes T,F give `probability` 0.5, and no tie rule is applied. | Story 2 | `[unit]` | ✓ |
| 13 | FR9 | The answer records `tied = true`. | must NOT — `tied` is true when there is a single leading answer. | Story 2 | `[unit]` | ✓ |
| 14 | FR10 | `probability` and `isTrue($threshold)`; adds `votes`, `anyTrue()` and `unanimous()`. | A `BooleanAnswer` with votes T,T,F: `isTrue(0.6)` is true, `isTrue(0.7)` is false, `anyTrue()` is true, `unanimous()` is false, and `votes` is `['true' => 2, 'false' => 1]`. | Story 3 | `[unit]` | ✓ |
| 15 | FR10 | `choice`, `probabilityOf($key)`, `probabilities` and `confidence` | `ChoiceAnswer::probabilityOf('x')` for a key that is not an option throws; for an option with no votes it returns 0.0. | Story 3 | `[unit]` | ✓ |
| 16 | FR10 | also has `applies`, `bucket` and `calibration()`. | With the default buckets, probability 1.0 falls in bucket `1.0`, 0.8 in `[0.8, 1.0)`, 0.79 in `[0.6, 0.8)`, and 0.2 in `[0, 0.6)`. | Story 3 | `[unit]` | ✓ |
| 17 | FR10 | Answer objects with the same shape as the SDK's answers, plus Hunch's additions. | must NOT — An answer's properties can be changed after construction. | Story 3 | `[unit]` | ✓ |
| 18 | FR10 | `score`, `level()` and `probabilities`; adds `votes` and `unanimous()`. | A `ScoreAnswer` with votes L,M,H,H has `votes` of L 1, M 1 and H 2, and `unanimous()` false. A `ChoiceAnswer` with votes a,a,a has `unanimous()` true. | Story 3 | `[unit]` | ✓ |
| 19 | FR10 | adds `votes`, `unanimous()` and `entropy` | A `ScoreAnswer` with votes L,M,H,H has `votes` of L 1, M 1 and H 2, and `unanimous()` false. A `ChoiceAnswer` with votes a,a,a has `unanimous()` true. | Story 3 | `[unit]` | ✓ |
| 20 | FR20 | The defaults are `1.0`, `[0.8, 1.0)`, `[0.6, 0.8)` and `[0, 0.6)`. | With the default buckets, probability 1.0 falls in bucket `1.0`, 0.8 in `[0.8, 1.0)`, 0.79 in `[0.6, 0.8)`, and 0.2 in `[0, 0.6)`. | Story 3 | `[unit]` | ✓ |
| 21 | NFR7 | the README and docblocks describe `probability`, `probabilities` and `confidence` as vote-share estimates, not calibrated probabilities, and point to `calibration()`. | The docblocks of `probability`, `probabilities` and `confidence` on every answer class include the phrase "vote-share estimate, not a calibrated probability" and reference `calibration()`. An architecture test checks the docblocks. | Story 3 | `[unit]` | ✓ |
| 22 | NFR7 | the README and docblocks describe `probability`, `probabilities` and `confidence` as vote-share estimates, not calibrated probabilities, and point to `calibration()`. | The README has a "Probabilities are estimates" section, placed before the first code example that reads a probability. | Story 3 | `[manual]` | ✓ |
| 23 | NFR7 | the README and docblocks describe `probability`, `probabilities` and `confidence` as vote-share estimates, not calibrated probabilities, and point to `calibration()`. | must NOT — The README or docblocks describe vote shares as "calibrated", "accurate" or "confidence level" without qualification. | Story 3 | `[manual]` | ✓ |
