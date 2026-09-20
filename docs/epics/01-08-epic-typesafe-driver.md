# TypeSafe driver

**Number**: 01-08  
**Source spec**: 01  
**Status**: pending  

## External block

This epic cannot start until `laravel/ai` tags the `Classification` API in a release, as the scope section of 01 states. That block is on an external release rather than a document, so no dependency edge carries it; check the SDK's tagged releases before picking up any story here.

## Story 1 — Classify through TypeSafe

**Status**: pending  
**Blocked by**: —  

### Acceptance Criteria

- `using('typesafe', ...)` resolves the TypeSafe driver; any other provider resolves the sampling driver. `[unit]`
- With the SDK's `Classification` faked (or its HTTP layer faked with `Http::fake()` if the SDK offers no fake), the same question set run through `TypeSafeDriver` returns the same answer classes as the sampling driver and passes the driver contract test. `[integration]`
- A conditional question is sent to TypeSafe unconditionally, and its `applies` is set from the controlling Choice's result. `[integration]`
- `Result->reasons` is null even when `withReasons()` was called, and `meta` records that reasons are unsupported by this driver. `[integration]`
- `ChoiceAnswer::confidence` is the top share of TypeSafe's probabilities. If TypeSafe returns its own confidence value, that value appears in `meta`. `[integration]`
- must NOT — A calibration row computed from TypeSafe classifications is returned by `calibration()` on a sampling-driver answer, or the other way round. `[integration]`
- must NOT — The TypeSafe API key appears in `Result->meta`, an event payload, an exception message or a persisted row. `[integration]`

### Task 1 — Implement TypeSafeDriver over the SDK's Classification

**Status**: pending  

Delegates to `Classification` and maps results onto Hunch's answer classes: conditional questions asked unconditionally with `applies` from the controlling Choice, no reasons (recorded in `meta`), top-share confidence with any native value in `meta`.

### Task 2 — Resolve typesafe to the TypeSafe driver and keep its cells and key separate

**Status**: pending  

`using('typesafe')` resolves this driver; calibration cells are keyed by driver; the API key never reaches `meta`, events, exceptions or persisted rows.

### Task 3 — Write tests for Classify through TypeSafe

**Status**: pending  

Covers the seven unit- and integration-tagged criteria, including the driver contract test against the sampling driver.

## Story 2 — Add TypeSafe to the compatibility run

**Status**: pending  
**Blocked by**: Story 1, 01-07 Story 1  

### Acceptance Criteria

- `composer compat` also runs the fixture set through TypeSafe when `TYPESAFE_API_KEY` is set in the local environment, and skips that provider with a visible notice when it is absent. `[integration]`

### Task 1 — Add TypeSafe to composer compat, gated on its key

**Status**: pending  

Runs the fixture set through TypeSafe when `TYPESAFE_API_KEY` is set in the local environment, and skips with a visible notice when it is absent.

### Task 2 — Write tests for Add TypeSafe to the compatibility run

**Status**: pending  

Covers the integration-tagged criterion through a test that TypeSafe runs only when `TYPESAFE_API_KEY` is set and otherwise prints the skip notice.
