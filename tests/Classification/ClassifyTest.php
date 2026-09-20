<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\TopP;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\RemembersConversations;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Prompts\AgentPrompt;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Answers\BooleanAnswer;
use Ninthspace\Hunch\Answers\ChoiceAnswer;
use Ninthspace\Hunch\Drivers\DriverResolver;
use Ninthspace\Hunch\Drivers\SamplingDriver;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\PromptOptions;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Result;
use Ninthspace\Hunch\Tests\Fixtures\TestAgent;

const SAMPLE = ['intent' => 'refund', 'reply_today' => true];

function supportTicket(): PendingClassification
{
    return Hunch::of(['subject' => 'Wrong size', 'message' => 'Please refund my order.'])
        ->questions([
            'intent' => new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']),
            'reply_today' => new Boolean('Should we reply today?'),
        ]);
}

/**
 * @return list<array<string, bool|string>>
 */
function samples(int $count): array
{
    return array_fill(0, $count, SAMPLE);
}

it('classifies through the builder into answers keyed by question', function () {
    ClassifierAgent::fake(samples(3));

    $result = supportTicket()->using('anthropic', 'm')->sampling(3)->classify();

    expect($result)->toBeInstanceOf(Result::class)
        ->and($result->answers->keys())->toEqualCanonicalizing(['intent', 'reply_today'])
        ->and($result['intent'])->toBeInstanceOf(ChoiceAnswer::class)
        ->and($result['intent']->choice)->toBe('refund')
        ->and($result['reply_today'])->toBeInstanceOf(BooleanAnswer::class);
});

it('refuses to classify with no provider, before any model call', function () {
    ClassifierAgent::fake(samples(5));
    config()->set('hunch.provider', null);

    expect(fn () => supportTicket()->classify())->toThrow(ConfigurationException::class);

    ClassifierAgent::assertNeverPrompted();
});

it('sends every sample through the sampling driver to the named provider only', function () {
    ClassifierAgent::fake(samples(3));

    supportTicket()->using('openai', 'gpt')->sampling(3)->classify();

    expect(DriverResolver::for('openai'))->toBeInstanceOf(SamplingDriver::class);
    ClassifierAgent::assertPromptedTimes(3);
    ClassifierAgent::assertNotPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() !== 'openai');
});

it('refuses an empty question set, before any model call', function () {
    ClassifierAgent::fake(samples(5));

    expect(fn () => Hunch::of('text')->using('anthropic')->classify())->toThrow(InvalidArgumentException::class);

    ClassifierAgent::assertNeverPrompted();
});

it('prompts once per sample with the provider, model and timeout given to the builder', function () {
    ClassifierAgent::fake(samples(3));

    supportTicket()->using('anthropic', 'claude-test')->sampling(3)->timeout(12)->classify();

    ClassifierAgent::assertPromptedTimes(3);
    ClassifierAgent::assertNotPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() !== 'anthropic'
        || $prompt->model !== 'claude-test'
        || $prompt->timeout !== 12);
});

it('declares temperature 1.0, a max-tokens ceiling and cached instructions, and no TopP', function () {
    $class = new ReflectionClass(ClassifierAgent::class);

    expect($class->getAttributes(Temperature::class)[0]->newInstance()->value)->toBe(1.0)
        ->and($class->getAttributes(MaxTokens::class))->toHaveCount(1)
        ->and($class->getAttributes(CacheInstructions::class))->toHaveCount(1)
        ->and($class->getAttributes(TopP::class))->toBeEmpty();
});

it('leaves the max-tokens ceiling to the attribute, whatever the question set', function () {
    $one = ['a' => new Boolean('A?')];
    $three = ['a' => new Boolean('A?'), 'b' => new Boolean('B?'), 'c' => new Boolean('C?')];

    // A computed ceiling would override the attribute, because the SDK prefers
    // a maxTokens() method over it. There is no such method.
    expect(method_exists(ClassifierAgent::class, 'maxTokens'))->toBeFalse();

    $ceiling = fn (QuestionSet $set) => TextGenerationOptions::forAgent(new ClassifierAgent($set))->maxTokens;

    expect($ceiling(new QuestionSet($one)))->toBe(4096)
        ->and($ceiling(new QuestionSet($three)))->toBe(4096)
        ->and($ceiling(new QuestionSet($three, options: new PromptOptions(reasons: 200))))->toBe(4096);
});

it('never lets the ceiling come within reach of the largest answer the schema allows', function () {
    // The widest question set the package permits: a 50-option Choice, one of
    // every other type, and reasons at their maximum.
    $options = [];

    foreach (range(1, 50) as $n) {
        $options["department_{$n}"] = "Department number {$n}, which handles a particular kind of enquiry";
    }

    $set = new QuestionSet([
        'department' => new Choice('Which department?', $options),
        'urgent' => new Boolean('Is it urgent?'),
        'severity' => new Score('How severe?', ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High']),
    ], options: new PromptOptions(reasons: 200));

    // The answer is one key per question plus the reason, never the option list.
    $answer = (string) json_encode([
        'department' => 'department_50',
        'urgent' => true,
        'severity' => 'high',
        'reason' => str_repeat('x', 200),
    ]);

    // Four characters per token is a deliberate over-estimate of the cost.
    $tokens = (int) ceil(mb_strlen($answer) / 4);

    expect($tokens * 10)->toBeLessThan(4096)
        ->and(TextGenerationOptions::forAgent(new ClassifierAgent($set))->maxTokens)->toBe(4096);
});

it('prompts the agent class configured in hunch.agent', function () {
    config()->set('hunch.agent', TestAgent::class);
    TestAgent::fake(samples(3));
    ClassifierAgent::fake(samples(3));

    supportTicket()->using('anthropic')->sampling(3)->classify();

    TestAgent::assertPromptedTimes(3);
    ClassifierAgent::assertNeverPrompted();
});

it('refuses a hunch.agent that is not a ClassifierAgent', function () {
    config()->set('hunch.agent', stdClass::class);

    supportTicket()->using('anthropic')->sampling(1)->classify();
})->throws(ConfigurationException::class);

arch('the classifier agent keeps no conversation')
    ->expect(ClassifierAgent::class)
    ->not->toImplement(Conversational::class)
    ->not->toImplement(RemembersConversations::class);

it('writes nothing to the database while classifying', function () {
    ClassifierAgent::fake(samples(3));
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    supportTicket()->using('anthropic')->sampling(3)->classify();

    expect($queries)->toBe(0);
});

it('takes every fixed sample even when the first ones agree', function () {
    ClassifierAgent::fake(samples(5));

    supportTicket()->using('anthropic')->sampling(5)->classify();

    ClassifierAgent::assertPromptedTimes(5);
});

it('uses hunch.sampling when sampling() is not called', function (?string $rule, int $expected) {
    if ($rule !== null) {
        config()->set('hunch.sampling', $rule);
        config()->set('hunch.min_valid_samples', 1);
    }

    ClassifierAgent::fake(samples($expected));

    supportTicket()->using('anthropic')->classify();

    ClassifierAgent::assertPromptedTimes($expected);
})->with([
    'default fixed:5' => [null, 5],
    'fixed:2' => ['fixed:2', 2],
]);

it('refuses a non-positive sample count, before any model call', function (int $samples) {
    ClassifierAgent::fake(samples(5));

    expect(fn () => supportTicket()->using('anthropic')->sampling($samples)->classify())->toThrow(ConfigurationException::class);

    ClassifierAgent::assertNeverPrompted();
})->with([0, -1]);

it('finishes each prompt before starting the next', function () {
    ClassifierAgent::fake(samples(4));
    $timeline = [];
    Event::listen(PromptingAgent::class, function () use (&$timeline) {
        $timeline[] = 'start';
    });
    Event::listen(AgentPrompted::class, function () use (&$timeline) {
        $timeline[] = 'end';
    });

    supportTicket()->using('anthropic')->sampling(4)->classify();

    expect($timeline)->toBe(['start', 'end', 'start', 'end', 'start', 'end', 'start', 'end']);
});

arch('drivers never run samples concurrently')
    ->expect('Ninthspace\Hunch\Drivers')
    ->not->toUse([
        'Illuminate\Support\Facades\Concurrency',
        'Illuminate\Support\Facades\Process',
        'Illuminate\Support\Facades\Queue',
        'Symfony\Component\Process\Process',
        'pcntl_fork',
    ]);

it('lets builder values override configuration', function () {
    config()->set('hunch.timeout', 20);
    config()->set('hunch.min_valid_samples', 1);
    ClassifierAgent::fake(samples(2));

    supportTicket()->using('anthropic')->sampling(2)->timeout(5)->classify();

    ClassifierAgent::assertNotPrompted(fn (AgentPrompt $prompt) => $prompt->timeout !== 5);
});
