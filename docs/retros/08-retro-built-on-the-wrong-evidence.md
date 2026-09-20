# Built right, on the wrong evidence

**Number**: 08  
**Source epic**: 01-09  
**Status**: complete  

## Observations

- Pattern worth reusing · Testing gap — Changing a delivered behaviour cost far more in test churn than in source: nine lines of `Stopping` and one in the driver, against twenty existing tests across eight files that had encoded the old budget in their expected call counts. Sorting them needed a rule, and the rule that worked was to ask what each test's subject was. A test about validity handling or token summing got `max_resamples: 0` pinned so it kept testing its one thing; a test whose subject actually was the budget was restated. One could be neither: `'ignores invalid samples when judging unanimity'` asserted that an adaptive run stops at three calls holding two valid samples, and no cap setting reaches that state any more, because `min` now means min valid. It was deleted rather than contorted, its claim folded into the replacement test, which still fails if an invalid sample is allowed to vote.

The churn is also the argument for having amended the spec first. Each of those twenty tests was a true statement about FR6, FR7 or FR12 as written, so without the amendment there would have been no way to tell a test that had to change from a defect this work introduced.

Read after epic 01-10, this reads differently. The incident that justified the epic — the compatibility run needing `min_valid_samples` set to 1 — was caused by Hunch truncating its own answers against a 136-token ceiling, not by the provider. The work was built correctly and was kept on re-examination, but on different grounds from the ones that motivated it: that it costs nothing when nothing fails, and that one clean run on one model does not speak for the providers FR3 commits the driver to.

The churn rule is the procedural lesson. The one underneath it is that an incident should have its cause confirmed before it becomes the warrant for a mechanism — here the mechanism was worth having anyway, which is luck rather than method.

Epic 01-09 story 1, 2026-09-20.
