# Aggregation and answers

**Number**: 03  
**Source epic**: 01-03  
**Status**: complete  

## Observations

- Codebase discovery — The TDD cycle needed a second red. The Choice share of an option with no votes came back as the integer `0` because PHP's `0 / 4` is an int. The same trap sat in the Boolean probability and the Score mean, so all three now cast to float. The criterion's `toBe(0.0)` caught it, where a loose equality would not have. Answer classes were started here, carrying only the aggregate fields (probability, shares, votes, choice, confidence, entropy, score, level), so story 3 grows them rather than replacing them. An invalid `Sample` carries no answers by construction, so the planted-violation control counted invalid samples as votes and was caught three ways.

- Smooth delivery — Smooth, one red and green. `tieBreak()` is a copying method on the readonly Choice and Score, and it validates its key. The Score criterion needed a modal answer to assert on, so `ScoreAnswer` gained `mode` (FR10 doesn't list it) alongside `tied`, while `score` and `level()` stay on the mean. `tieBreak` is deliberately NOT in the question-set hash: FR14 lists what the hash covers and the tie-break isn't among it, which reverses the assumption recorded in retro 2 that it would "have to join the canonical JSON". If calibration later needs tie-break-specific cells, that belongs in a spec change, not a quiet hash bump.

- Testing gap — Two findings. First, the docblock arch test caught my own wording: I wrote the plural "vote-share estimates, not calibrated probabilities", and the criterion requires the exact singular phrase. A test that asserts the literal phrase earns its place. Second, a Pest dataset of `fn () => fn (...)` does not invoke the outer closure, so the first immutability test passed its cases without ever assigning. It was rewritten as property-name data. For the immutability control, stripping `readonly` from just two classes is a fatal error (a readonly child can't extend a non-readonly parent), so a clean control has to strip all four. The bucket for each answer is its leading share: max(p, 1-p) for a Boolean, confidence for a Choice, the mode's share for a Score. The spec doesn't say which share decides the bucket, and this is my reading.
