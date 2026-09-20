# Question sets and prompts

**Number**: 02  
**Source epic**: 01-02  
**Status**: complete  

## Observations

- Pattern worth reusing — Smooth. `SampleSeed` hands out a fresh `Randomizer` per call, so successive shuffles in one sample are deterministic only in call order — the prompt story must fix the order in which it shuffles questions, options and levels. Pest's `arch()->not->toBeUsed()` scans `src` only, so a test file may call `mt_srand` to set up the global-RNG check without tripping the arch rule; the mutation control confirmed both the runtime check and the arch rule catch an injected `mt_srand`.

Must-NOTs about what code can see or touch need two controls. A runtime check proves the behaviour, and an arch rule proves the dependency is absent. In story 4, a constant injected seed passed the runtime comparison, and only the arch rule caught it. Use the same pair for 01-03's must-NOTs, for example that answers are immutable and invalid samples never count.

- Pattern worth reusing — Delivered to plan. The output schema had to match the SDK's `HasStructuredOutput::schema(JsonSchema): array<string, Type>`, so it is built with the `Illuminate\JsonSchema` factory rather than as a raw array. Injection defence neutralises only `<state`, `</state`, `<section` and `</section` in state text (the `<` becomes `&lt;`) so ordinary markup in the text is untouched. For must-NOT criteria about what a renderer can see, a Pest arch rule (`Instructions` may not use `UserMessage`, `SampleSeed`, `Carbon` or clock functions) caught a constant injected seed that a runtime comparison could not. The reasons and self-report lines in the instructions are placeholders for FR22 and FR27, whose epics own the final wording and the `reason`/`band` schema fields.

Must-NOTs about what code can see or touch need two controls. A runtime check proves the behaviour, and an arch rule proves the dependency is absent. In story 4, a constant injected seed passed the runtime comparison, and only the arch rule caught it. Use the same pair for 01-03's must-NOTs, for example that answers are immutable and invalid samples never count.

- Codebase discovery — Delivered to plan. One surprise: `Choice` read `config()` unconditionally, so building a question set outside a booted Laravel app (the separate-process determinism test) crashed with "Target class [config] does not exist". It now falls back to the default of 50 when no config is bound. Options and levels are canonicalised as lists of `[key, description]` pairs so declared order is explicit in the bytes. `tieBreak` (FR9) and `onlyWhen` (FR21) will have to join the canonical JSON when their epics land; since nothing is released, that can stay `v1`, but any change after release must bump `QuestionSet::HASH_FORMAT` and the golden fixture.

Corrected by 01-03 story 2: FR14 doesn't list `tieBreak` among what the hash covers, so it stays out of the canonical JSON. `onlyWhen` (FR21) is still to be judged against FR14 when its epic lands.

- Smooth delivery — Smooth. Two things worth carrying forward: PHP silently turns numeric-string array keys into integers, so a Choice option key like '1' is rejected as a non-string key (acceptable, and the must-NOT holds); and PHPStan at level max needs input arrays typed loosely (`array<int|string, string>`) with a narrowing `@var` after validation, otherwise a runtime key check reads as dead code. The mutation control for the must-NOT was run and caught the removed check.
