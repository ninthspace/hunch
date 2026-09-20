<?php

use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage as SdkUsage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\ClassificationMeta;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Result;
use Ninthspace\Hunch\SampleCounts;
use Ninthspace\Hunch\Sampling\SampleSeed;
use Ninthspace\Hunch\Usage;

const OK = ['intent' => 'refund', 'reply_today' => true];

function reported(array $output, int $input, int $out, int $write, int $read): StructuredTextResponse
{
    return new StructuredTextResponse($output, (string) json_encode($output), new SdkUsage($input, $out, $write, $read), new Meta('anthropic', 'claude-reported-1'));
}

function refundTicket(): PendingClassification
{
    return Hunch::of('Please refund my order.')
        ->using('anthropic', 'claude-requested')
        ->questions([
            'intent' => new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']),
            'reply_today' => new Boolean('Should we reply today?'),
        ]);
}

it('reads an answer by question key, and throws for an unknown key', function () {
    ClassifierAgent::fake([OK, OK, OK]);

    $result = refundTicket()->sampling(3)->classify();

    expect($result['intent'])->toBe($result->answers['intent'])
        ->and(fn () => $result['nope'])->toThrow(InvalidArgumentException::class);
});

it('reports requested, valid and invalid sample counts', function () {
    ClassifierAgent::fake([OK, OK, ['intent' => 'escalate', 'reply_today' => true], OK, OK, OK]);

    $samples = refundTicket()->sampling(5)->classify()->samples;

    // `requested` is every sample paid for, including the replacement the
    // invalid third sample earned, so it exceeds the rule's five.
    expect([$samples->requested, $samples->valid, $samples->invalid])->toBe([6, 5, 1]);
});

it('sums input, cached input and output tokens across every sample', function () {
    ClassifierAgent::fake([
        reported(OK, 100, 10, 50, 0),
        reported(OK, 20, 11, 0, 80),
        reported(['intent' => 'bad', 'reply_today' => true], 20, 12, 0, 80),
    ]);
    config()->set('hunch.min_valid_samples', 2);

    $usage = refundTicket()->sampling(3)->classify()->usage;

    expect([$usage->input, $usage->cachedInputWrite, $usage->cachedInputRead, $usage->output])->toBe([140, 50, 160, 33]);
});

it('fills meta from the classification and the faked responses', function () {
    ClassifierAgent::fake([reported(OK, 1, 1, 0, 0), reported(OK, 1, 1, 0, 0), reported(OK, 1, 1, 0, 0)]);

    $result = refundTicket()->sampling(3)->classify();
    $set = new QuestionSet([
        'intent' => new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']),
        'reply_today' => new Boolean('Should we reply today?'),
    ]);

    expect($result->meta->driver)->toBe('sampling')
        ->and($result->meta->provider)->toBe('anthropic')
        ->and($result->meta->modelRequested)->toBe('claude-requested')
        ->and($result->meta->modelReported)->toBe('claude-reported-1')
        ->and($result->meta->sampling)->toBe('fixed:3')
        ->and($result->meta->questionSetHash)->toBe($set->hash())
        ->and($result->meta->version)->toBeNull()
        ->and($result->meta->seeds)->toHaveCount(3)
        ->and($result->meta->requestIds)->toHaveCount(3)
        ->and(array_filter($result->meta->requestIds))->toHaveCount(3);
});

it('gives every classification a ULID id', function () {
    ClassifierAgent::fake([OK, OK, OK, OK, OK, OK]);

    $first = refundTicket()->sampling(3)->classify();
    $second = refundTicket()->sampling(3)->classify();

    expect(Str::isUlid($first->id))->toBeTrue()
        ->and($second->id)->not->toBe($first->id);
});

it('cannot have answers set or unset through array access', function (string $operation) {
    ClassifierAgent::fake([OK, OK, OK]);
    $result = refundTicket()->sampling(3)->classify();

    if ($operation === 'set') {
        $result['intent'] = null;
    } else {
        unset($result['intent']);
    }
})->with(['set', 'unset'])->throws(LogicException::class, 'read-only');

it('lists the seed for every sample taken, matching the classification id', function () {
    ClassifierAgent::fake([OK, OK, OK, OK]);

    $result = refundTicket()->sampling(4)->classify();

    expect($result->meta->seeds)->toBe([
        1 => SampleSeed::for($result->id, 1)->hex(),
        2 => SampleSeed::for($result->id, 2)->hex(),
        3 => SampleSeed::for($result->id, 3)->hex(),
        4 => SampleSeed::for($result->id, 4)->hex(),
    ]);
});

arch('a Result and what it reports are immutable')
    ->expect([Result::class, SampleCounts::class, ClassificationMeta::class, Usage::class])
    ->toBeReadonly();
