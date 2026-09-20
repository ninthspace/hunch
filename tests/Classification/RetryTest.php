<?php

use Illuminate\Support\Sleep;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Tests\Fixtures\FailoverAgent;

const YES = ['urgent' => true];

function urgent(): PendingClassification
{
    config()->set('hunch.agent', FailoverAgent::class);
    config()->set('hunch.min_valid_samples', 1);

    return Hunch::of('The server is down.')->using('anthropic', 'm')->question('urgent', new Boolean('Is it urgent?'));
}

/**
 * A fake that throws `$error` on the first `$failures` calls, then answers.
 */
function failingFirst(int $failures, Throwable $error, array &$providers): Closure
{
    $calls = 0;

    return function ($prompt, $attachments, $provider) use (&$calls, $failures, $error, &$providers) {
        $providers[] = $provider->name();

        if (++$calls <= $failures) {
            throw $error;
        }

        return YES;
    };
}

it('retries a rate limit against the same provider with backoff, then succeeds', function () {
    config()->set('hunch.retries', 2);
    $providers = [];
    FailoverAgent::fake(failingFirst(2, new RateLimitedException('Slow down'), $providers));

    $result = urgent()->sampling(1)->classify();

    expect($result['urgent']->probability)->toBe(1.0)
        ->and($providers)->toBe(['anthropic', 'anthropic', 'anthropic']);
    Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1000)->milliseconds()]);
});

it('fails once the retries are used up', function () {
    config()->set('hunch.retries', 2);
    $providers = [];
    FailoverAgent::fake(failingFirst(3, new RateLimitedException('Slow down'), $providers));

    expect(fn () => urgent()->sampling(1)->classify())->toThrow(ClassificationFailed::class)
        ->and($providers)->toHaveCount(3);
});

it('does not retry an error that retrying cannot fix', function () {
    config()->set('hunch.retries', 2);
    $providers = [];
    FailoverAgent::fake(failingFirst(1, new AiException('401 Unauthorized'), $providers));

    expect(fn () => urgent()->sampling(1)->classify())->toThrow(ClassificationFailed::class)
        ->and($providers)->toBe(['anthropic']);
    Sleep::assertNeverSlept();
});

it('never sends a sample to another provider when failover is off, even after retries run out', function () {
    config()->set('hunch.failover', false);
    config()->set('hunch.retries', 2);
    $providers = [];
    FailoverAgent::fake(failingFirst(99, new RateLimitedException('Slow down'), $providers));

    expect(fn () => urgent()->sampling(1)->classify())->toThrow(ClassificationFailed::class);
    expect(array_unique($providers))->toBe(['anthropic']);
    FailoverAgent::assertNotPrompted(fn (AgentPrompt $prompt) => $prompt->provider->name() !== 'anthropic');
});

it('passes the agent\'s own failover providers through when failover is on', function () {
    config()->set('hunch.failover', true);
    config()->set('hunch.retries', 0);
    $providers = [];
    FailoverAgent::fake(function ($prompt, $attachments, $provider) use (&$providers) {
        $providers[] = $provider->name();

        if ($provider->name() === 'anthropic') {
            throw new RateLimitedException('Slow down');
        }

        return YES;
    });

    $result = urgent()->sampling(1)->classify();

    expect($providers)->toBe(['anthropic', 'openai'])
        ->and($result['urgent']->probability)->toBe(1.0);
});
