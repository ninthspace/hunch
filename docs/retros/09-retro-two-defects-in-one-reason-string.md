# Two defects in one reason string

**Number**: 09  
**Source epic**: 01-10  
**Status**: complete  

## Observations

- Codebase discovery · Testing gap — A schema-derived max-tokens ceiling of 136 tokens was silently costing accuracy for three epics, and the package's own `#[MaxTokens(4096)]` attribute was dead the whole time because the SDK prefers a `maxTokens()` method over it. The invalid rate fell from 3.2% to 0.0% across 126 live samples when the formula was deleted.

Two things made it invisible. The first is that the formula looked like thrift and was not: a provider bills for tokens generated, not for the ceiling, so the tight cap saved nothing and could only truncate. The reasoning was never written down next to the code, so nobody re-derived it. The second is that `SampleValidator` reported a truncated response as "Missing intent, urgency, complaint" — the wording of a model answering badly, not of an answer that never arrived — so three runs of compatibility data read as provider flakiness.

What found it was measuring instead of guessing. The first hypothesis was `additionalProperties: false` against extra keys, which would have been shipped on reasoning alone and fixed nothing: the live run showed zero extra-key failures and zero enum failures. The cost of being wrong was one four-minute run.

Both of this epic's findings are the same file and the same mistake in two guises. `SampleValidator`'s reason strings were written to describe a schema mismatch, and were quietly doing two other jobs: they are the only account of *why* a live run failed, and they are stored on the sample row and dispatched on an event, so they are a privacy surface.

As diagnosis they were wrong. A truncated answer reported as "Missing intent, urgency, complaint" reads as a model answering badly rather than as an answer that never arrived, which is why three runs of compatibility data were filed as provider flakiness. As a privacy surface they were leaking, because an unexpected field was named and the name is the model's to choose.

Neither was found by reading the code. The first needed a live run that printed the reasons; the second needed a must-NOT with a control. A string that only appears once something has already gone wrong gets no scrutiny from a passing suite, and both defects had survived every green run since epic 01-04.

Epic 01-10 story 1, 2026-09-20. Rates across four runs on identical fixtures: 0.8%, 2.4%, 3.2%, then 0.0% after the fix.

- Codebase discovery · Testing gap — Writing the must-NOT for story 2 found a live privacy hole rather than confirming one was absent. `SampleValidator` named unexpected fields in the reason it produced — `Unexpected <key>.` — and a field name is the model's to choose, so a model echoing state text as a key put that text into `hunch_samples.invalid_reason` and onto the `SampleInvalid` event. `Recorder` scrubs `reason` on the line above and writes `invalid_reason` untouched, so the column beside the protected one was the leak.

The control proves it: restoring the old wording puts the state marker into both the stored row and the event, and the marker scans of epic 01-06 never caught it because none of them made a sample invalid. A must-NOT is worth writing even when you expect it to pass on the first run.

Both of this epic's findings are the same file and the same mistake in two guises. `SampleValidator`'s reason strings were written to describe a schema mismatch, and were quietly doing two other jobs: they are the only account of *why* a live run failed, and they are stored on the sample row and dispatched on an event, so they are a privacy surface.

As diagnosis they were wrong. A truncated answer reported as "Missing intent, urgency, complaint" reads as a model answering badly rather than as an answer that never arrived, which is why three runs of compatibility data were filed as provider flakiness. As a privacy surface they were leaking, because an unexpected field was named and the name is the model's to choose.

Neither was found by reading the code. The first needed a live run that printed the reasons; the second needed a must-NOT with a control. A string that only appears once something has already gone wrong gets no scrutiny from a passing suite, and both defects had survived every green run since epic 01-04.

Epic 01-10 story 2, 2026-09-20. Fixed by counting unexpected fields rather than naming them.
