<?php

use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage as SdkUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Sampling\Sample;

const VALID = ['intent' => 'refund', 'reply_today' => true];

function ticket(): PendingClassification
{
    return Hunch::of('Please refund my order.')
        ->using('anthropic', 'm')
        ->questions([
            'intent' => new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']),
            'reply_today' => new Boolean('Should we reply today?'),
        ]);
}

/**
 * A faked structured response with its own token counts.
 *
 * @param  array<string, mixed>  $output
 */
function costing(array $output, int $input = 100, int $outputTokens = 10): StructuredTextResponse
{
    return new StructuredTextResponse($output, (string) json_encode($output), new SdkUsage($input, $outputTokens, 5, 20), new Meta('anthropic', 'm-reported'));
}

it('counts a sample that fails the schema as invalid and keeps it out of every vote', function (array $bad) {
    // The cap is off here so the vote is exactly the four valid samples of the
    // five taken; replacement has its own tests.
    config()->set('hunch.max_resamples', 0);
    ClassifierAgent::fake([VALID, VALID, $bad, VALID, ['intent' => 'other', 'reply_today' => false]]);

    $result = ticket()->sampling(5)->classify();

    expect($result['intent']->votes)->toBe(['refund' => 3, 'other' => 1])
        ->and($result['reply_today']->votes)->toBe(['true' => 3, 'false' => 1]);
})->with([
    'missing field' => [['intent' => 'refund']],
    'non-canonical enum' => [['intent' => 'Refund', 'reply_today' => true]],
    'wrong type' => [['intent' => 'refund', 'reply_today' => 'yes']],
]);

it('fails with the valid and invalid samples and the usage of all five when too few are valid', function () {
    config()->set('hunch.max_resamples', 0);
    ClassifierAgent::fake([
        costing(VALID),
        costing(['intent' => 'maybe', 'reply_today' => true]),
        costing(VALID),
        costing(['reply_today' => true]),
        costing(['intent' => 'refund', 'reply_today' => true, 'extra' => 1]),
    ]);

    try {
        ticket()->sampling(5)->classify();
        $this->fail('Expected ClassificationFailed.');
    } catch (ClassificationFailed $e) {
        expect($e->validSamples())->toHaveCount(2)
            ->and($e->invalidSamples())->toHaveCount(3)
            ->and([$e->usage->input, $e->usage->output, $e->usage->cachedInputWrite, $e->usage->cachedInputRead])->toBe([500, 50, 25, 100]);
    }
});

it('fails with the samples taken so far when the provider keeps erroring', function () {
    $calls = 0;
    ClassifierAgent::fake(function () use (&$calls) {
        if (++$calls > 2) {
            throw new RateLimitedException('Too many requests');
        }

        return VALID;
    });

    try {
        ticket()->sampling(5)->classify();
        $this->fail('Expected ClassificationFailed.');
    } catch (ClassificationFailed $e) {
        expect($e->samples)->toHaveCount(2)
            ->and($e->getPrevious())->toBeInstanceOf(RateLimitedException::class);
    }
});

it('never returns a Result when fewer than min_valid_samples are valid', function () {
    ClassifierAgent::fake(function () {
        static $call = 0;

        return ++$call <= 2 ? VALID : ['bad' => 1];
    });

    // Seven calls: five the rule allows and two replacements, still only two valid.
    expect(fn () => ticket()->sampling(5)->classify())->toThrow(ClassificationFailed::class);
    ClassifierAgent::assertPromptedTimes(7);
});

it('never fills in a default answer when a classification fails', function () {
    ClassifierAgent::fake([VALID, 'free text', 'free text', 'free text', 'free text']);
    $result = null;

    try {
        $result = ticket()->sampling(3)->classify();
    } catch (ClassificationFailed $e) {
        expect($e->samples)->each->toBeInstanceOf(Sample::class);
    }

    expect($result)->toBeNull();
});

it('refuses a sampling rule that can take fewer samples than min_valid_samples, before any call', function (Closure $configure) {
    config()->set('hunch.min_valid_samples', 3);
    ClassifierAgent::fake([VALID, VALID, VALID, VALID, VALID]);

    expect(fn () => $configure(ticket())->classify())->toThrow(ConfigurationException::class);

    ClassifierAgent::assertNeverPrompted();
})->with([
    'sampling(2)' => [function (PendingClassification $pending) {
        return $pending->sampling(2);
    }],
    'adaptive:2-5' => [function (PendingClassification $pending) {
        config()->set('hunch.sampling', 'adaptive:2-5');

        return $pending;
    }],
]);

it('proceeds when the sample count meets min_valid_samples', function () {
    config()->set('hunch.min_valid_samples', 3);
    ClassifierAgent::fake([VALID, VALID, VALID]);

    expect(ticket()->sampling(3)->classify()['intent']->choice)->toBe('refund');
});

it('counts an extra field or free text as invalid', function (mixed $bad) {
    config()->set('hunch.max_resamples', 0);
    ClassifierAgent::fake([VALID, VALID, VALID, $bad]);

    $result = ticket()->sampling(4)->classify();

    expect(array_sum($result['intent']->votes))->toBe(3);
})->with([
    'extra field' => [['intent' => 'refund', 'reply_today' => true, 'why' => 'because']],
    'free text' => ['The customer wants a refund.'],
]);

it('never lets a value outside the schema enums vote', function () {
    config()->set('hunch.max_resamples', 0);
    ClassifierAgent::fake([VALID, VALID, VALID, ['intent' => 'escalate', 'reply_today' => true]]);

    $result = ticket()->sampling(4)->classify();

    expect($result['intent']->votes)->toBe(['refund' => 3, 'other' => 0])
        ->and($result['intent']->votes)->not->toHaveKey('escalate');
});
