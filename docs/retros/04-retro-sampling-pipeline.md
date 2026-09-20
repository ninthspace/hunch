# Sampling driver and classify pipeline

**Number**: 04  
**Source epic**: 01-04  
**Status**: complete  

## Observations

- Testing gap — I repeated retro 3's own lesson within one run of applying it. The set/unset test used a Pest dataset of closures returning closures, the outer one was never invoked, and the test failed for the wrong reason. It was rewritten with plain string data. The applied lens caught it only because every new test must be seen failing and passing for the right reason; a test that happens to pass would have hidden it. Meta records `modelReported` from the SDK response `Meta` (distinct from the requested model), seeds and request IDs keyed by sample number, and the question-set hash. Six planted defects plus a readonly arch rule were all caught.

My Pest test code fails in Pest-specific ways: closure datasets, interface names passed to toThrow, and guessed exception types. Only the planted controls caught them. Write the planted failure first and watch it fail for the right reason; a test that is merely green is not evidence.

- Testing gap — Smooth, with two test-writing slips caught by the planted controls. First, a broken database driver raises `ErrorException` ("Undefined array key database"), not `InvalidArgumentException`. Second, Pest's `toThrow(Throwable::class)` treats an interface name as a message to match, so the control that proves the database is unusable is an explicit try/catch. The identical-instructions check listens to the SDK's `PromptingAgent` event and hashes `$event->prompt->agent->instructions()`: 6 prompts across two states gave 1 distinct hash. No production code changed in this story.

My Pest test code fails in Pest-specific ways: closure datasets, interface names passed to toThrow, and guessed exception types. Only the planted controls caught them. Write the planted failure first and watch it fail for the right reason; a test that is merely green is not evidence.

- Smooth delivery — Smooth. A guarded `Settings` reader now fronts every `hunch.*` read, and `Choice` and `Buckets` moved onto it (retro 2's config lesson, applied). `SamplingRule::configured()` is the "first use" point, so a malformed `hunch.sampling` fails at the classification rather than at boot. The FR16 default `agent` names `ClassifierAgent::class` before story 2 builds it, so an empty stub class was added to keep PHPStan and the published config honest. Boot checks were exercised by re-registering the provider with `force: true` after changing config, which runs the real `packageBooted()`. The migration check looks for the package's `database/migrations` path in `migrator->paths()`; that directory is empty until the persistence epic.

The stories that went cleanly were the ones whose checks already had a home in the package suite. The earlier pivot away from "production host" checks is what made story 8 straightforward.

- Smooth delivery — Smooth. The pivot that turned this story from host smoke checks into package-suite checks paid off: all four criteria ran in the suite with no machine to borrow. The HTTP arch rule deliberately allows catching `Illuminate\Http\Client\HttpClientException` (the driver wraps provider errors) while forbidding anything that sends a request: the `Http` facade, `Factory`/`PendingRequest`, Guzzle, PSR clients, `curl`, sockets and `file_get_contents`. A planted `Http::get()` tripped it, and a planted `DB::select` broke the no-database classification.

The stories that went cleanly were the ones whose checks already had a home in the package suite. The earlier pivot away from "production host" checks is what made story 8 straightforward.

- Codebase discovery — Delivered to plan once the SDK was understood. The facts worth keeping:
- `prompt()` with a provider string (not an array) means one provider and no failover.
- The SDK prefers an agent's `maxTokens()` method over the `#[MaxTokens]` attribute, so the attribute can be a ceiling while the method sizes the value from the schema.
- The `PromptingAgent` and `AgentPrompted` events give a real start and end for each prompt, which made the sequential check observable.
- Testbench does not register `laravel/ai`'s service provider unless the test case lists it (package discovery does that in a real app). Without it, every classify test died with `MultipleInstanceManager` "Unresolvable dependency $app".

Every test was shown failing against a planted defect (11 plants). One arch rule I first wrote for no-failover (banning `array_merge`/`Lab`) proved nothing and was dropped; the runtime provider assertion is the real control there. A throwaway Laravel 13 app in the scratchpad installed Hunch from a path repository, discovered both providers, published the config and resolved the facade.

- Scope surprise — This story had to come before story 3, whose invalid-sample criterion needs validation to exist. A missing edge was added (story 4 blocks story 3) with approval, and recorded on the epic. The new `min_valid_samples` pre-check correctly broke two story 2 tests that took 2 samples against the default of 3; the tests, not the rule, were adjusted.

Provider errors are recognised narrowly: SDK `AiException` and Laravel's `HttpClientException`. Anything else propagates unwrapped, so a programming error is never disguised as a failed classification. The faked SDK accepts a full `StructuredTextResponse` with its own `Usage`, which is how the summed-usage criterion was driven. All 7 planted defects were caught.

- Criteria gap — Criterion 5 ("the SDK's failover configuration is passed through") was ambiguous, because the SDK only fails over when `prompt()` receives a provider list. Chris chose to use the agent's own SDK configuration: with `hunch.failover` on, the requested provider leads, followed by the agent's `provider()` or `#[Provider]` list (supplied through a `hunch.agent` subclass). No new config key was added, and FR16's `failover` stays a boolean.

Only `RateLimitedException` and `ProviderOverloadedException` are retried, with backoff of 500 ms × 2^n through `Sleep`. `Sleep::fake()` is now suite-wide in `TestCase`, after story 4's provider-error test began taking real 0.5 s and 1 s sleeps once retries existed. All six planted defects were caught.

- Pattern worth reusing — The fake is a manager swapped behind the facade (`Hunch::fake()` → `HunchFake`), whose builders run on a `FakeDriver` through a new optional driver on `PendingClassification`. Direct answers go through a new `Aggregator::fromShares()`, so they are real answer objects with the real tie rule and entropy, and the contract test holds by construction. The fake uses a fixed classification ID (`01J0000000000000000000FAKE`), which fixes seeds and so user messages. An unmatched classification falls through to the real driver unless `preventStrayClassifications()` is on, mirroring Laravel's `Http::fake`.

One design cost: `HunchFake` uses PHPUnit's `Assert` from `src/`, as Laravel's own fakes do, so it assumes PHPUnit is present when it is used. Six planted defects were caught.
