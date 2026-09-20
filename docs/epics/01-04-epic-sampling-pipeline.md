# Sampling driver and classify pipeline

**Number**: 01-04  
**Source spec**: 01  
**Status**: complete  

## Amendment: story 4 blocks story 3

Added 2026-09-19 during the dpm:do run, with Chris's approval. Story 3's integration criterion "After 5 samples, 1 of them invalid, `samples` reports requested 5, valid 4, invalid 1" can only be exercised through the driver once story 4's schema validation exists ("A sample whose output fails schema validation … is counted as invalid and excluded from every vote"). The two stories had no edge between them, so readiness offered story 3 first. A `blocks` edge from story 4 to story 3 now records the dependency. No criterion text changed.

## Story 1 — Publish and read the configuration

**Status**: complete  
**Blocked by**: —  

### Acceptance Criteria

- `vendor:publish --tag=hunch-config` writes `config/hunch.php` containing every key listed in FR16, each with its documented default. `[integration]`
- `hunch.sampling` = `adaptive:3-7` parses into an adaptive rule [3, 7]. A malformed value (such as `adaptive:7-3` or `foo:5`) throws a configuration exception when first used. `[unit]`
- `hunch.persistence` true with `hunch.retention_days` null throws a configuration exception at boot. `[integration]`
- must NOT — The service provider loads Hunch migrations when persistence is off. `[integration]`

### Task 1 — Write config/hunch.php and register publishing

**Status**: complete  

Every FR16 key with its default, published under the `hunch-config` tag. Migrations are loaded only when persistence is on.

### Task 2 — Parse and validate the sampling rule and persistence settings

**Status**: complete  

`fixed:N` and `adaptive:MIN-MAX` parsing with malformed values rejected on first use; persistence without `retention_days` rejected at boot.

### Task 3 — Write tests for Publish and read the configuration

**Status**: complete  

Covers the four unit- and integration-tagged criteria.

### Retro

- Smooth. A guarded `Settings` reader now fronts every `hunch.*` read, and `Choice` and `Buckets` moved onto it (retro 2's config lesson, applied). `SamplingRule::configured()` is the "first use" point, so a malformed `hunch.sampling` fails at the classification rather than at boot. The FR16 default `agent` names `ClassifierAgent::class` before story 2 builds it, so an empty stub class was added to keep PHPStan and the published config honest. Boot checks were exercised by re-registering the provider with `force: true` after changing config, which runs the real `packageBooted()`. The migration check looks for the package's `database/migrations` path in `migrator->paths()`; that directory is empty until the persistence epic.

- Raised while documenting 01-07, but it originates here: the service provider called `loadMigrationsFrom()` whenever persistence was on, so enabling persistence silently injected Hunch's migrations into an application's `migrate` run. ADR 01-02's chosen option says the opposite — "migrations are published but never auto-loaded, so persistence stays opt-in" — and the code contradicted it for three epics without anything noticing.

Why nothing noticed: the only criterion is a must-NOT ("the provider loads migrations when persistence is off"), and the code satisfied it. The matching "on" case had no criterion, so the test I wrote for it encoded my own behaviour rather than an agreed one, and then defended it. A must-NOT with no paired must leaves the other half of the switch unowned.

Chris caught it from the README: installation should not bring migrations into an application at all. Now `publishesMigrations()` is the only registration, and a test asserts migrations are never loaded with persistence either on or off; the package's own persistence tests migrate from the package path explicitly, as an application does after publishing.

The correction opened a second hole: with migrations publish-only, turning persistence on without publishing gave `SQLSTATE... no such table`. `Persistence\MissingTables` now turns that into a ConfigurationException naming the two steps, across record(), label(), calibration(), hunch:calibrate and hunch:eval, keeping the QueryException as `previous` and passing through errors about tables that are not Hunch's. That last distinction went untested until a plant exposed it — without it, an application's own missing table would have been blamed on Hunch.

## Story 2 — Classify through the sampling driver with fixed sampling

**Status**: complete  
**Blocked by**: Story 1  

### Acceptance Criteria

- `Hunch::of([...])->questions([...])->using('anthropic', 'm')->sampling(3)->classify()` returns a `Result` whose answers are keyed by the question keys. `[feature]`
- `classify()` with no `using()` and no `hunch.provider` configured throws a configuration exception before any model call. `[unit]`
- must NOT — `using('openai', ...)` routes to a driver other than sampling, or calls any provider other than openai. `[integration]`
- `classify()` with an empty question set throws before any model call. `[unit]`
- With `ClassifierAgent::fake()` returning valid structured output, `classify()` with fixed 3 sampling prompts the agent 3 times, each with the provider, model and timeout given to the builder. `[integration]`
- Reflection on `ClassifierAgent` shows `Temperature(1.0)`, `MaxTokens` and `CacheInstructions` attributes, and no `TopP`. `[unit]`
- ~~The computed max tokens grows with the number of questions, and grows further when reasons are enabled. `[unit]`~~ **Superseded 2026-09-20T08:33:00Z**: The computed max-tokens formula was removed on 2026-09-20. It bought nothing — billing follows generated tokens, not the ceiling — while being the only thing that could truncate a good sample, and a truncated sample now costs replacement calls. Epic 01-10 carries the criteria for the generous ceiling that replaced it.
- Setting `hunch.agent` to a subclass of `ClassifierAgent` makes the sampling driver prompt that subclass. `[integration]`
- must NOT — `ClassifierAgent` implements `Conversational`, or SDK conversation storage is written during a classification. `[unit]`
- `sampling(5)` prompts the agent exactly 5 times, even when the first 3 samples are unanimous. `[integration]`
- With no `sampling()` call, the `hunch.sampling` config applies (default `fixed:5`). `[integration]`
- `sampling(0)` or a negative number throws before any model call. `[unit]`
- must NOT — A later sample starts before the previous `prompt()` call has returned. `[integration]`
- Builder calls override config: with `hunch.timeout` = 20, `->timeout(5)` passes 5 to `prompt()`. `[integration]`

### Task 1 — Define the ClassificationDriver contract and resolve the driver from the provider

**Status**: complete  

`classify(State, QuestionSet, Options): Result` per ADR 01-02; any provider other than `typesafe` resolves the sampling driver. TypeSafe resolution itself belongs to the TypeSafe epic.

### Task 2 — Implement the fluent builder with its pre-call checks

**Status**: complete  

`of`, `context`, `question(s)`, `using`, `sampling`, `timeout`, `classify`; builder values override config. Rejects a missing provider, an empty question set and a non-positive sample count before any model call.

### Task 3 — Implement ClassifierAgent

**Status**: complete  

`Agent` + `HasStructuredOutput` with Temperature 1.0, schema-sized MaxTokens, CacheInstructions and no TopP; not conversational; class replaceable through `hunch.agent`.

### Task 4 — Implement SamplingDriver fixed sampling

**Status**: complete  

N sequential prompts with provider, model and timeout, each using the split prompt and seeded shuffles, feeding the aggregator. The happy path only; validation, retries and the full Result are later stories.

### Task 5 — Write tests for Classify through the sampling driver with fixed sampling

**Status**: complete  

Covers the fourteen unit-, integration- and feature-tagged criteria.

### Retro

- Delivered to plan once the SDK was understood. The facts worth keeping:
- `prompt()` with a provider string (not an array) means one provider and no failover.
- The SDK prefers an agent's `maxTokens()` method over the `#[MaxTokens]` attribute, so the attribute can be a ceiling while the method sizes the value from the schema.
- The `PromptingAgent` and `AgentPrompted` events give a real start and end for each prompt, which made the sequential check observable.
- Testbench does not register `laravel/ai`'s service provider unless the test case lists it (package discovery does that in a real app). Without it, every classify test died with `MultipleInstanceManager` "Unresolvable dependency $app".

Every test was shown failing against a planted defect (11 plants). One arch rule I first wrote for no-failover (banning `array_merge`/`Lab`) proved nothing and was dropped; the runtime provider assertion is the real control there. A throwaway Laravel 13 app in the scratchpad installed Hunch from a path repository, discovered both providers, published the config and resolved the facade.

## Story 3 — Report the Result

**Status**: complete  
**Blocked by**: Story 2, Story 4  

### Acceptance Criteria

- `$result['intent']` returns the same object as `$result->answers['intent']`; an unknown key throws. `[unit]`
- After 5 samples, 1 of them invalid, `samples` reports requested 5, valid 4, invalid 1. `[integration]`
- `usage` equals the sum over all samples of input, cached-input (read and write) and output tokens, as reported by the faked agent responses. `[integration]`
- `meta` contains driver, provider, model requested, model reported, sampling rule, question set hash, version name, seeds and request IDs, each filled from the faked responses. `[integration]`
- `id` is the classification's ULID, whether or not the classification is recorded. `[unit]`
- must NOT — `$result['intent'] = ...` or `unset($result['intent'])` succeeds. `[unit]`
- `Result->meta` lists the seed for every sample taken. `[integration]`

### Task 1 — Implement Result

**Status**: complete  

Read-only `ArrayAccess` by question key, sample counts, summed usage including cache reads and writes, `meta` with seeds and request IDs, and the classification ULID `id`. `reasons` stays null until reasons land.

### Task 2 — Write tests for Report the Result

**Status**: complete  

Covers the seven unit- and integration-tagged criteria.

### Retro

- Chris's critique of the delivered shape: `Result` was an object with an array of objects hanging off it, which smelled. It was also two behaviours for one mistake — `$result['nope']` threw a useful error while `$result->answers['nope']` returned null — and the two cases conditional questions create (an answer that did not apply, an answer too few samples qualified for) had no home, so `Recorder`, `EvalCommand` and the compat runner each hand-rolled their own null check.

`Answers` is now a readonly collection: ArrayAccess, Countable, IteratorAggregate, plus `get()`, `has()`, `keys()`, `applicable()`, `unanswered()` and `all()`.

Chris then spotted that the README still led with `$result['intent']`, which teaches the array-flavoured API first. Bracket access stays, because two accepted criteria pin it and removing it would need a pivot, but it is now documented as the shorthand it is. `Result::answer()` was added as the object-first route, so there are three ways in — `$result->answers->get()`, `$result->answer()` and `$result['…']` — all delegating to one `get()`, so all return the same object and all throw for a question never asked. One implementation, three spellings, and the docs lead with the object.

The criterion pinned here — "`$result['intent']` returns the same object as `$result->answers['intent']`; an unknown key throws" — is satisfied verbatim by an object with ArrayAccess, so no pivot was needed and the coverage row stands.

The line we drew: objects where behaviour attaches, plain arrays where the data is scalar. `votes`, `probabilities`, `meta->seeds` and `meta->requestIds` stay arrays, because a wrapper would cost ergonomics and buy nothing. `taken` was considered and left alone: a `Samples` object would overlap `SampleCounts`, which FR11 names.

10 plants were checked to apply, and all were caught, including `answer()` returning null instead of throwing and `answer()` returning a clone rather than the same object. Two mistakes of mine, both in tests rather than code: I asserted `applies === false` for a question no sample qualified for, but that state is null (`applies === false` needs at least two qualifying samples and a controlling winner that does not match); and a fixture whose samples answered a question the set did not ask made every sample invalid. Also worth knowing: Pest shares one global function namespace across test files, so a helper named `answered()` collided with the calibrate tests and fatally broke the suite until renamed.

- I repeated retro 3's own lesson within one run of applying it. The set/unset test used a Pest dataset of closures returning closures, the outer one was never invoked, and the test failed for the wrong reason. It was rewritten with plain string data. The applied lens caught it only because every new test must be seen failing and passing for the right reason; a test that happens to pass would have hidden it. Meta records `modelReported` from the SDK response `Meta` (distinct from the requested model), seeds and request IDs keyed by sample number, and the question-set hash. Six planted defects plus a readonly arch rule were all caught.

## Story 4 — Validate samples and fail loudly

**Status**: complete  
**Blocked by**: Story 2  

### Acceptance Criteria

- A sample whose output fails schema validation (a missing field, or an enum value that is not a canonical key) is counted as invalid and excluded from every vote. `[integration]`
- With `max_resamples` = 0, 5 samples, 3 of them invalid, and `min_valid_samples` = 3, `classify()` throws `ClassificationFailed` carrying the 2 valid and 3 invalid samples, and the usage summed across all 5. `[integration]`
- A provider error that persists after the configured retries throws `ClassificationFailed` carrying the samples taken so far. `[integration]`
- must NOT — `classify()` returns a `Result` when fewer than `min_valid_samples` valid samples were obtained. `[integration]`
- must NOT — Any answer carries a default value, rather than one computed from counted samples, when a failure occurred. `[unit]`
- `sampling(2)` or `adaptive: [2, 5]` with `min_valid_samples` = 3 throws a configuration exception before any model call. `sampling(3)` with the same setting proceeds. `[unit]`
- A faked sample that returns an extra field, or free text instead of the schema, is counted as invalid. `[integration]`
- must NOT — A model response with a value outside the schema's enums contributes a vote. `[integration]`

### Task 1 — Validate each sample against the schema

**Status**: complete  

Missing fields, extra fields, non-canonical enum values and free text mark a sample invalid; invalid samples are recorded and excluded from every vote.

### Task 2 — Throw ClassificationFailed with partial samples and usage

**Status**: complete  

Too few valid samples or a persistent provider error throws, never a defaulted answer. `min_valid_samples` is checked against the rule's fewest samples before any call and never scaled.

### Task 3 — Write tests for Validate samples and fail loudly

**Status**: complete  

Covers the eight unit- and integration-tagged criteria.

### Retro

- This story had to come before story 3, whose invalid-sample criterion needs validation to exist. A missing edge was added (story 4 blocks story 3) with approval, and recorded on the epic. The new `min_valid_samples` pre-check correctly broke two story 2 tests that took 2 samples against the default of 3; the tests, not the rule, were adjusted.

Provider errors are recognised narrowly: SDK `AiException` and Laravel's `HttpClientException`. Anything else propagates unwrapped, so a programming error is never disguised as a failed classification. The faked SDK accepts a full `StructuredTextResponse` with its own `Usage`, which is how the summed-usage criterion was driven. All 7 planted defects were caught.

## Story 5 — Retry rate limits against the same provider

**Status**: complete  
**Blocked by**: Story 2  

### Acceptance Criteria

- A faked rate-limit error on the first 2 attempts, then success, with `retries` = 2, gives a successful sample against the same provider after 3 attempts, with backoff sleeps between them (asserted via `Sleep::fake()`). `[integration]`
- A rate-limit error on 3 attempts with `retries` = 2 throws `ClassificationFailed`. `[integration]`
- A non-retryable provider error (such as authentication) is not retried. `[integration]`
- must NOT — With `failover` false, a sample is sent to any provider other than the configured one, even after retries are exhausted. `[integration]`
- With `failover` true, the SDK's failover configuration is passed through to the prompt call. `[integration]`

### Task 1 — Retry rate-limit and overload errors against the same provider

**Status**: complete  

Backoff through `Sleep`, up to `retries`; non-retryable errors fail at once; SDK failover passed through only when `failover` is true.

### Task 2 — Write tests for Retry rate limits against the same provider

**Status**: complete  

Covers the five integration-tagged criteria.

### Retro

- Criterion 5 ("the SDK's failover configuration is passed through") was ambiguous, because the SDK only fails over when `prompt()` receives a provider list. Chris chose to use the agent's own SDK configuration: with `hunch.failover` on, the requested provider leads, followed by the agent's `provider()` or `#[Provider]` list (supplied through a `hunch.agent` subclass). No new config key was added, and FR16's `failover` stays a boolean.

Only `RateLimitedException` and `ProviderOverloadedException` are retried, with backoff of 500 ms × 2^n through `Sleep`. `Sleep::fake()` is now suite-wide in `TestCase`, after story 4's provider-error test began taking real 0.5 s and 1 s sleeps once retries existed. All six planted defects were caught.

## Story 6 — Provide testing fakes

**Status**: complete  
**Blocked by**: Story 2  

### Acceptance Criteria

- `Hunch::fake(['intent' => ['refund' => 0.8, 'other' => 0.2], 'reply_today' => 0.6])` makes `classify()` return those probabilities, with no agent call. `[feature]`
- `Hunch::fake()->samples([...])` with 3 scripted samples (refund, refund, other) returns answers computed by the real aggregator: `intent` refund with probability 2/3. `[feature]`
- `assertClassified(fn)` passes when a matching classification happened, and fails with a readable message when none did. `[feature]`
- `assertSampledTimes(3)` passes after 3 scripted samples and fails after 2. `[feature]`
- Under `preventStrayClassifications()`, a classification with no matching fake throws. `[feature]`
- Two runs of the same classification under the fake produce identical user messages (seeds are fixed). `[integration]`
- A contract test runs the same scripted input through the sampling driver and the fake driver, and asserts that each answer class exposes the same public properties and methods from both. `[integration]`
- For the same votes a,a,a,b, `ChoiceAnswer::confidence` is 0.75 from both the sampling driver and the fake driver. `[integration]`

### Task 1 — Implement Hunch::fake with direct answers and scripted samples

**Status**: complete  

Direct answers skip the agent; scripted samples run the real aggregator; seeds are fixed so user messages are deterministic. Returns the same answer classes as the sampling driver.

### Task 2 — Implement the fake's assertions and preventStrayClassifications

**Status**: complete  

`assertClassified` with readable failures, `assertSampledTimes`, and throwing on unmatched classifications when stray prevention is on.

### Task 3 — Write tests for Provide testing fakes

**Status**: complete  

Covers the eight feature- and integration-tagged criteria, including the sampling-versus-fake driver contract test.

### Retro

- The fake is a manager swapped behind the facade (`Hunch::fake()` → `HunchFake`), whose builders run on a `FakeDriver` through a new optional driver on `PendingClassification`. Direct answers go through a new `Aggregator::fromShares()`, so they are real answer objects with the real tie rule and entropy, and the contract test holds by construction. The fake uses a fixed classification ID (`01J0000000000000000000FAKE`), which fixes seeds and so user messages. An unmatched classification falls through to the real driver unless `preventStrayClassifications()` is on, mirroring Laravel's `Http::fake`.

One design cost: `HunchFake` uses PHPUnit's `Assert` from `src/`, as Laravel's own fakes do, so it assumes PHPUnit is present when it is used. Six planted defects were caught.

## Story 7 — Verify cross-story integration for Sampling driver and classify pipeline

**Status**: complete  
**Blocked by**: Story 1, Story 2, Story 3, Story 4, Story 5, Story 6  

### Acceptance Criteria

- At least one test drives `SamplingDriver` end to end through `ClassifierAgent::fake()`: builder, then prompts, then aggregation, then `Result`. `[feature]`
- Two classifications with the same question set hash and different states send byte-identical instructions, compared by sha256 of the captured prompts. `[integration]`
- With persistence off and `DB::listen` attached, a full classification, including calls to each answer's `calibration()`, runs zero queries. `[integration]`
- With persistence off and the default database connection pointing to a non-existent driver, `classify()` succeeds. `[integration]`

### Task 1 — Write the cross-story integration tests for the sampling pipeline

**Status**: complete  

End to end through `ClassifierAgent::fake()`, identical instructions across classifications, zero queries with persistence off, and a missing database driver. Spans config, driver, Result and fakes; no new production code.

### Retro

- Smooth, with two test-writing slips caught by the planted controls. First, a broken database driver raises `ErrorException` ("Undefined array key database"), not `InvalidArgumentException`. Second, Pest's `toThrow(Throwable::class)` treats an interface name as a message to match, so the control that proves the database is unusable is an explicit try/catch. The identical-instructions check listens to the SDK's `PromptingAgent` event and hashes `$event->prompt->agent->instructions()`: 6 prompts across two states gave 1 distinct hash. No production code changed in this story.

## Story 8 — Check the runtime requirements in the package suite

**Status**: complete  
**Blocked by**: Story 2  

### Acceptance Criteria

- ~~`composer.json` requires `laravel/ai`, and in the package suite a `classify()` through the SDK's fake returns a `Result`. `[integration]` `[target]`~~ **Superseded 2026-09-19T19:15:00Z**: Replaced by an identical criterion tagged `integration` only. The original still carried the `target` tag left from the production-host framing, and dpm has no tool to remove an approach tag.
- ~~On the host, with no queue worker running, a `classify()` called from a web request or tinker returns a `Result`. `[target]`~~ **Superseded 2026-09-19T18:15:00Z**: Pivot on spec 01: Hunch is a package with no host, and the spec no longer refers to queues. Criterion 5 covers synchronous `classify()` in the package suite.
- ~~In the package suite, with persistence off and no database connection configured, a `classify()` returns a `Result`. `[integration]` `[target]`~~ **Superseded 2026-09-19T19:15:00Z**: Replaced by an identical criterion tagged `integration` only. The original still carried the `target` tag left from the production-host framing, and dpm has no tool to remove an approach tag.
- ~~On a host whose outbound network is limited to the local model endpoint, a `classify()` against Ollama succeeds. `[target]`~~ **Superseded 2026-09-19T18:15:00Z**: Pivot on spec 01: Hunch is a package with no host, and Ollama is not part of the plan. ENVX4 is covered by criterion 6's architecture test (no `Http` facade or raw HTTP client under `src/`).
- In the package suite, `classify()` returns a `Result` directly to its caller. `[integration]`
- An architecture test shows no class under `src/` uses the `Http` facade or a raw HTTP client, so every model call goes through the SDK agent. `[unit]`
- `composer.json` requires `laravel/ai`, and in the package suite a `classify()` through the SDK's fake returns a `Result`. `[integration]`
- In the package suite, with persistence off and no database connection configured, a `classify()` returns a `Result`. `[integration]`

### Task 1 — Document the host smoke-check procedure

**Status**: withdrawn — Pivot on spec 01: Hunch is a package with no production host, so there is no host smoke-check procedure to document. The runtime checks run in the package suite.  

How to run the four target checks on a production host: SDK version, no queue worker, no database, local-model-only network. Verification happens on the host, not here.

### Task 2 — Write tests for the runtime requirements

**Status**: complete  

Covers all four criteria: the SDK-fake smoke `classify()`, `classify()` with no database, synchronous `classify()`, and the HTTP-client architecture test.

### Retro

- Smooth. The pivot that turned this story from host smoke checks into package-suite checks paid off: all four criteria ran in the suite with no machine to borrow. The HTTP arch rule deliberately allows catching `Illuminate\Http\Client\HttpClientException` (the driver wraps provider errors) while forbidding anything that sends a request: the `Http` facade, `Factory`/`PendingRequest`, Guzzle, PSR clients, `curl`, sockets and `file_get_contents`. A planted `Http::get()` tripped it, and a planted `DB::select` broke the no-database classification.

## Dependencies

- blocks → 01-05
- blocks → 01-06
- blocks → 01-07

## Retro Applied

- 02 · Codebase Discoveries · applied — Story 1's config layer gives one guarded reader; existing direct reads in Choice and Buckets move onto it.
- 01 · Criteria Gaps · applied — Each criterion's place to run is decided before it is built against; install-level checks use a throwaway Laravel app in the scratchpad.
- 02 · Patterns Worth Reusing · applied — Each 01-04 must-NOT gets a planted-violation control, plus an arch rule where the thing to rule out is a dependency (raw HTTP, failover).
- 03 · Testing Gaps · applied — Every new test is shown to fail against a planted defect before it counts; no nested closures in Pest datasets.
