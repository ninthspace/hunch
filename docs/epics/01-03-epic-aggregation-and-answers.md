# Aggregation and answers

**Number**: 01-03  
**Source spec**: 01  
**Status**: complete  

## Story 1 — Aggregate valid votes into shares

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- Boolean votes T,T,T,F,F give `probability` 0.6. `[tdd]` `[unit]`
- Choice votes a,a,a,b over options {a,b,c} give `probabilities` {a: 0.75, b: 0.25, c: 0}, `choice` a, `confidence` 0.75, and `entropy` equal to H(0.75, 0.25) / log(3) within 1e-9. `[tdd]` `[unit]`
- A unanimous Choice has `entropy` 0. Equal votes across all options give `entropy` 1. `[tdd]` `[unit]`
- Score levels [L,M,H] with votes L,M,H,H give `score` 1.25 and `level()` M. Votes M,H give `score` 1.5 and `level()` H (halves round up). `[tdd]` `[unit]`
- The shares in `probabilities` for Choice and Score sum to 1 within 1e-9. `[unit]`
- must NOT — An invalid sample changes any probability or vote count. `[unit]`

### Task 1 — Write tests for Aggregate valid votes into shares

**Status**: complete  

Test-first: covers the six unit-tagged criteria and fails until the aggregator exists.

### Task 2 — Implement aggregation for Boolean, Choice and Score over valid samples

**Status**: complete  

Shares, choice, confidence, entropy, mean score and nearest level. Pure with no I/O; invalid samples excluded. Tie resolution is the next story's.

### Retro

- The TDD cycle needed a second red. The Choice share of an option with no votes came back as the integer `0` because PHP's `0 / 4` is an int. The same trap sat in the Boolean probability and the Score mean, so all three now cast to float. The criterion's `toBe(0.0)` caught it, where a loose equality would not have. Answer classes were started here, carrying only the aggregate fields (probability, shares, votes, choice, confidence, entropy, score, level), so story 3 grows them rather than replacing them. An invalid `Sample` carries no answers by construction, so the planted-violation control counted invalid samples as votes and was caught three ways.

## Story 2 — Resolve ties between leading answers

**Status**: complete  
**Blocked by**: Story 1  

### Acceptance Criteria

- Choice [a,b,c] with votes b,b,c,c and `tieBreak('c')` gives `choice` c and `tied` true. `[tdd]` `[unit]`
- Choice [a,b,c] with votes b,b,c,c and `tieBreak('a')` (not among the tied answers) gives `choice` b, the earliest tied answer in canonical order, and `tied` true. `[tdd]` `[unit]`
- A Score tie resolves the modal answer by the same tie rule. `score` and `level()` still come from the mean level index and are unaffected by the tie. `[tdd]` `[unit]`
- Boolean votes T,F give `probability` 0.5, and no tie rule is applied. `[unit]`
- must NOT — `tied` is true when there is a single leading answer. `[unit]`

### Task 1 — Write tests for Resolve ties between leading answers

**Status**: complete  

Test-first: covers the five unit-tagged criteria.

### Task 2 — Implement tie resolution with tieBreak and canonical order

**Status**: complete  

A `tieBreak` value among the tied answers wins, otherwise the earliest in canonical order; sets `tied`. Choice and Score only; Booleans are untouched.

### Retro

- Smooth, one red and green. `tieBreak()` is a copying method on the readonly Choice and Score, and it validates its key. The Score criterion needed a modal answer to assert on, so `ScoreAnswer` gained `mode` (FR10 doesn't list it) alongside `tied`, while `score` and `level()` stay on the mean. `tieBreak` is deliberately NOT in the question-set hash: FR14 lists what the hash covers and the tie-break isn't among it, which reverses the assumption recorded in retro 2 that it would "have to join the canonical JSON". If calibration later needs tie-break-specific cells, that belongs in a spec change, not a quiet hash bump.

## Story 3 — Expose SDK-shaped answer objects

**Status**: complete  
**Blocked by**: Story 1  

### Acceptance Criteria

- A `BooleanAnswer` with votes T,T,F: `isTrue(0.6)` is true, `isTrue(0.7)` is false, `anyTrue()` is true, `unanimous()` is false, and `votes` is `['true' => 2, 'false' => 1]`. `[unit]`
- `ChoiceAnswer::probabilityOf('x')` for a key that is not an option throws; for an option with no votes it returns 0.0. `[unit]`
- With the default buckets, probability 1.0 falls in bucket `1.0`, 0.8 in `[0.8, 1.0)`, 0.79 in `[0.6, 0.8)`, and 0.2 in `[0, 0.6)`. `[unit]`
- must NOT — An answer's properties can be changed after construction. `[unit]`
- A `ScoreAnswer` with votes L,M,H,H has `votes` of L 1, M 1 and H 2, and `unanimous()` false. A `ChoiceAnswer` with votes a,a,a has `unanimous()` true. `[unit]`
- The docblocks of `probability`, `probabilities` and `confidence` on every answer class include the phrase "vote-share estimate, not a calibrated probability" and reference `calibration()`. An architecture test checks the docblocks. `[unit]`
- The README has a "Probabilities are estimates" section, placed before the first code example that reads a probability. `[manual]`
- must NOT — The README or docblocks describe vote shares as "calibrated", "accurate" or "confidence level" without qualification. `[manual]`

### Task 1 — Implement immutable BooleanAnswer, ChoiceAnswer and ScoreAnswer

**Status**: complete  

SDK-shaped properties plus Hunch's additions, and `bucket` from the configured buckets. `applies` defaults to true until conditional questions land; `calibration()` returns null until persistence lands.

### Task 2 — Write the honesty docblocks and the README "Probabilities are estimates" section

**Status**: complete  

Addresses the docblock and README criteria only: vote-share estimates, not calibrated probabilities, pointing to `calibration()`.

### Task 3 — Write tests for Expose SDK-shaped answer objects

**Status**: complete  

Covers the six unit-tagged criteria, including the docblock architecture test. The README criteria are manual.

### Retro

- Two findings. First, the docblock arch test caught my own wording: I wrote the plural "vote-share estimates, not calibrated probabilities", and the criterion requires the exact singular phrase. A test that asserts the literal phrase earns its place. Second, a Pest dataset of `fn () => fn (...)` does not invoke the outer closure, so the first immutability test passed its cases without ever assigning. It was rewritten as property-name data. For the immutability control, stripping `readonly` from just two classes is a fatal error (a readonly child can't extend a non-readonly parent), so a clean control has to strip all four. The bucket for each answer is its leading share: max(p, 1-p) for a Boolean, confidence for a Choice, the mode's share for a Score. The spec doesn't say which share decides the bucket, and this is my reading.

## Dependencies

- blocks → 01-04

## Retro Applied

- 01 · Codebase Discoveries · not_applicable — 01-03 is pure aggregation with no database access.
- 02 · Codebase Discoveries · applied — Bucket code reads hunch.buckets through the same guarded pattern Choice uses, falling back to the defaults when no config is bound.
- 01 · Criteria Gaps · applied — Every criterion is checked for a place it can run before it is built against. The README criteria are [manual] and are self-assessed against README.md.
- 02 · Patterns Worth Reusing · applied — Each 01-03 must-NOT (invalid samples never count, tied never set with a single leader, answers immutable) gets a planted-violation control, plus an arch rule where a dependency is what must be absent.
