# Coverage: TypeSafe driver

**Number**: 01-08  
**Source epic**: 01-08  
**Status**: pending  

## Coverage

| # | Requirement | Spec Text | Story Criterion | Covered by | Test Approach | Verified |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | FR1 | The driver is inferred from the provider: `typesafe` gives the TypeSafe driver, anything else the sampling driver. | `using('typesafe', ...)` resolves the TypeSafe driver; any other provider resolves the sampling driver. | Story 1 | `[unit]` |  |
| 2 | FR25 | `TypeSafeDriver` delegates to the SDK's `Classification` once that is in a tagged release. | With the SDK's `Classification` faked (or its HTTP layer faked with `Http::fake()` if the SDK offers no fake), the same question set run through `TypeSafeDriver` returns the same answer classes as the sampling driver and passes the driver contract test. | Story 1 | `[integration]` |  |
| 3 | FR25 | Conditional questions are asked unconditionally, and `applies` is set from the controlling Choice's result. | A conditional question is sent to TypeSafe unconditionally, and its `applies` is set from the controlling Choice's result. | Story 1 | `[integration]` |  |
| 4 | FR25 | It never returns reasons. | `Result->reasons` is null even when `withReasons()` was called, and `meta` records that reasons are unsupported by this driver. | Story 1 | `[integration]` |  |
| 5 | FR25 | `ChoiceAnswer::confidence` stays the top share. If TypeSafe's native confidence is defined differently, the native value goes into `meta`. | `ChoiceAnswer::confidence` is the top share of TypeSafe's probabilities. If TypeSafe returns its own confidence value, that value appears in `meta`. | Story 1 | `[integration]` |  |
| 6 | FR25 | Its answers use the same buckets, but calibration cells stay separate per driver. | must NOT — A calibration row computed from TypeSafe classifications is returned by `calibration()` on a sampling-driver answer, or the other way round. | Story 1 | `[integration]` |  |
| 7 | FR25 | `TypeSafeDriver` delegates to the SDK's `Classification` once that is in a tagged release. | `composer compat` also runs the fixture set through TypeSafe when `TYPESAFE_API_KEY` is set in the local environment, and skips that provider with a visible notice when it is absent. | Story 2 | `[integration]` |  |
| 8 | ENV9 | plus an optional `TYPESAFE_API_KEY` once TypeSafe access is granted. | `composer compat` also runs the fixture set through TypeSafe when `TYPESAFE_API_KEY` is set in the local environment, and skips that provider with a visible notice when it is absent. | Story 2 | `[integration]` |  |
