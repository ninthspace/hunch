<?php

namespace Ninthspace\Hunch\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Ninthspace\Hunch\Prompts\Instructions;
use Ninthspace\Hunch\Prompts\OutputSchema;
use Ninthspace\Hunch\QuestionSet;
use Stringable;

/**
 * The SDK agent the sampling driver prompts once per sample.
 *
 * Temperature is 1.0 so samples vary, the instructions are cached across
 * samples, and TopP is left to the provider. It is not conversational: each
 * sample is an independent single-turn prompt. The class can be replaced
 * through `hunch.agent` with a subclass.
 *
 * The max-tokens ceiling is far above any answer the schema can produce.
 * Providers bill for the tokens they generate, not for the ceiling, so a tight
 * one saves nothing and risks truncating a good answer into an invalid sample.
 */
#[Temperature(1.0)]
#[MaxTokens(4096)]
#[CacheInstructions]
class ClassifierAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(
        public readonly QuestionSet $set,
    ) {}

    public function instructions(): Stringable|string
    {
        return Instructions::render($this->set);
    }

    public function schema(JsonSchema $schema): array
    {
        return OutputSchema::for($this->set, $schema);
    }
}
