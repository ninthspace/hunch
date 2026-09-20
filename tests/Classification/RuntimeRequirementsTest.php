<?php

use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Result;

const RUNTIME_SAMPLES = [['intent' => 'refund'], ['intent' => 'refund'], ['intent' => 'other']];

function runtimeCheck(): PendingClassification
{
    return Hunch::of('Please refund my order.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->sampling(3);
}

it('returns a Result directly to the caller of classify()', function () {
    ClassifierAgent::fake(RUNTIME_SAMPLES);

    $returnType = (new ReflectionMethod(PendingClassification::class, 'classify'))->getReturnType();
    $result = runtimeCheck()->classify();

    expect((string) $returnType)->toBe(Result::class)
        ->and($result)->toBeInstanceOf(Result::class)
        ->and($result['intent']->choice)->toBe('refund');
});

arch('no class under src/ makes HTTP calls itself: every model call goes through the SDK agent')
    ->expect('Ninthspace\Hunch')
    ->not->toUse([
        'Illuminate\Support\Facades\Http',
        'Illuminate\Http\Client\Factory',
        'Illuminate\Http\Client\PendingRequest',
        'GuzzleHttp',
        'Psr\Http\Client\ClientInterface',
        'curl_init',
        'curl_exec',
        'fsockopen',
        'stream_socket_client',
        'file_get_contents',
    ]);

it('requires laravel/ai and classifies through the SDK fake', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);
    ClassifierAgent::fake(RUNTIME_SAMPLES);

    expect($composer['require'])->toHaveKey('laravel/ai')
        ->and(runtimeCheck()->classify())->toBeInstanceOf(Result::class);

    ClassifierAgent::assertPromptedTimes(3);
});

it('classifies with persistence off and no database connection configured', function () {
    config()->set('hunch.persistence', false);
    config()->set('database.default', null);
    config()->set('database.connections', []);
    ClassifierAgent::fake(RUNTIME_SAMPLES);

    expect(runtimeCheck()->classify())->toBeInstanceOf(Result::class);
});
