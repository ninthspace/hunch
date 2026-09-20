<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage as SdkUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Events\SampleInvalid;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Models\StoredSample;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Sampling\Sample;

require_once __DIR__.'/../Persistence/helpers.php';

/*
 * An invalid sample is replaced rather than absorbed: the sampling rule's
 * counts are counts of valid samples, and `max_resamples` caps how many extra
 * calls that may cost.
 */

function resampledTicket(): PendingClassification
{
    return Hunch::of('Please refund my order.')
        ->using('anthropic', 'm')
        ->questions([
            'intent' => new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']),
            'reply_today' => new Boolean('Should we reply today?'),
        ]);
}

/**
 * Output for each sample in turn. The sample numbers in `$invalid` answer with
 * a value outside the schema's enum; the rest answer `$answers[$number]`, or
 * refund when nothing is given for that number.
 *
 * @param  list<int>  $invalid
 * @param  array<int, array<string, bool|string>>  $answers
 * @return Closure(): StructuredTextResponse
 */
function samplesFailingAt(array $invalid, array $answers = []): Closure
{
    $number = 0;

    return function () use (&$number, $invalid, $answers): StructuredTextResponse {
        $number++;

        if (in_array($number, $invalid, true)) {
            $output = ['intent' => 'not an option', 'reply_today' => true];
        } else {
            $output = $answers[$number] ?? ['intent' => 'refund', 'reply_today' => true];
        }

        return new StructuredTextResponse(
            $output,
            (string) json_encode($output),
            new SdkUsage(100, 10, 0, 0),
            new Meta('anthropic', 'm-reported'),
        );
    };
}

it('replaces an invalid sample so a fixed rule still votes on N valid ones', function () {
    config()->set('hunch.max_resamples', 2);
    ClassifierAgent::fake(samplesFailingAt([2, 4]));

    $result = resampledTicket()->sampling(5)->classify();

    ClassifierAgent::assertPromptedTimes(7);
    expect([$result->samples->requested, $result->samples->valid, $result->samples->invalid])->toBe([7, 5, 2])
        ->and(array_sum($result['intent']->votes))->toBe(5);
});

it('replaces an invalid sample before an adaptive rule can settle on a short vote', function () {
    config()->set('hunch.max_resamples', 2);
    ClassifierAgent::fake(samplesFailingAt([2]));

    $result = resampledTicket()->sampling(adaptive: [3, 9])->classify();

    // Without the replacement the three calls hold two valid samples, which are
    // unanimous, and the run would settle on a two-sample vote.
    ClassifierAgent::assertPromptedTimes(4);
    expect([$result->samples->valid, $result->samples->invalid])->toBe([3, 1])
        ->and(array_sum($result['intent']->votes))->toBe(3);
});

it('stops at the cap and fails, carrying every sample it paid for', function () {
    config()->set('hunch.max_resamples', 2);
    ClassifierAgent::fake(samplesFailingAt([1, 2, 3, 4, 5, 6, 7, 8, 9]));

    try {
        resampledTicket()->sampling(5)->classify();
        $this->fail('Expected ClassificationFailed.');
    } catch (ClassificationFailed $e) {
        ClassifierAgent::assertPromptedTimes(7);
        expect($e->samples)->toHaveCount(7)
            ->and($e->validSamples())->toHaveCount(0)
            ->and($e->usage->input)->toBe(700);
    }
});

it('replaces nothing when the cap is zero, taking the rule count once', function () {
    config()->set('hunch.max_resamples', 0);
    ClassifierAgent::fake(samplesFailingAt([2, 4]));

    $result = resampledTicket()->sampling(5)->classify();

    ClassifierAgent::assertPromptedTimes(5);
    expect([$result->samples->requested, $result->samples->valid, $result->samples->invalid])->toBe([5, 3, 2])
        ->and(array_sum($result['intent']->votes))->toBe(3);
});

it('defaults max_resamples to 2, and reads a negative value as none', function () {
    // The published file is ConfigurationTest's business; this is the default
    // a classification runs under when nothing sets it.
    expect(config('hunch.max_resamples'))->toBe(2);

    // A negative cap cannot shrink the rule: it replaces nothing, as 0 does.
    config()->set('hunch.max_resamples', -5);
    ClassifierAgent::fake(samplesFailingAt([2, 4]));

    $result = resampledTicket()->sampling(5)->classify();

    ClassifierAgent::assertPromptedTimes(5);
    expect($result->samples->valid)->toBe(3);
});

it('never reuses the number or the seed of the sample it replaces', function () {
    config()->set('hunch.max_resamples', 2);
    ClassifierAgent::fake(samplesFailingAt([2, 4]));

    $result = resampledTicket()->sampling(5)->classify();
    $seeds = $result->meta->seeds;

    expect(array_keys($seeds))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(array_unique(array_values($seeds)))->toHaveCount(7)
        ->and(array_map(fn (Sample $sample) => $sample->number, $result->taken))->toBe([1, 2, 3, 4, 5, 6, 7]);
});

it('keeps a replaced sample out of the vote while still recording it', function () {
    // The same five valid answers, once reached through two replacements and
    // once taken straight, aggregate identically.
    $answers = [
        1 => ['intent' => 'refund', 'reply_today' => true],
        3 => ['intent' => 'other', 'reply_today' => true],
        5 => ['intent' => 'refund', 'reply_today' => false],
        6 => ['intent' => 'refund', 'reply_today' => true],
        7 => ['intent' => 'other', 'reply_today' => false],
    ];

    Event::fake([SampleInvalid::class]);
    config()->set('hunch.max_resamples', 2);
    ClassifierAgent::fake(samplesFailingAt([2, 4], $answers));
    $toppedUp = resampledTicket()->sampling(5)->classify();

    config()->set('hunch.max_resamples', 0);
    ClassifierAgent::fake(samplesFailingAt([], array_combine([1, 2, 3, 4, 5], array_values($answers))));
    $straight = resampledTicket()->sampling(5)->classify();

    Event::assertDispatchedTimes(SampleInvalid::class, 2);
    expect($toppedUp['intent']->probabilities)->toBe($straight['intent']->probabilities)
        ->and($toppedUp['reply_today']->probability)->toBe($straight['reply_today']->probability)
        ->and($toppedUp['intent']->votes)->toBe($straight['intent']->votes);
});

it('records every replaced sample with its reason when persistence is on', function () {
    persistenceOn($this);
    config()->set('hunch.max_resamples', 2);
    ClassifierAgent::fake(recordedAnswers(['refund', '!', 'refund', 'refund']));

    $result = recording(enquiry())->classify();

    $invalid = StoredSample::query()->where('classification_id', $result->id)->where('valid', false)->get();

    expect(StoredSample::query()->where('classification_id', $result->id)->count())->toBe(4)
        ->and($invalid)->toHaveCount(1)
        ->and($invalid->first()?->number)->toBe(2)
        ->and($invalid->first()?->invalid_reason)->toContain('intent');
});
