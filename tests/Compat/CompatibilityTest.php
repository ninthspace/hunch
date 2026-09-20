<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Ninthspace\Hunch\Answers\BooleanAnswer;
use Ninthspace\Hunch\Result;
use Ninthspace\Hunch\Tests\Compat\CompatFixtures;
use Ninthspace\Hunch\Tests\Compat\CompatRun;
use Ninthspace\Hunch\Tests\Compat\CompatThresholds;
use Ninthspace\Hunch\Tests\Compat\ResultsTable;

/*
 * The manual compatibility run. Excluded from the default suite and run only
 * by `composer compat`, which checks for a key first. Everything here calls
 * a real provider and costs money.
 */

const COMPAT_PROVIDER = 'anthropic';

const COMPAT_MODEL = 'claude-sonnet-5';

uses()->group('compat');

beforeEach(function () {
    $key = getenv('ANTHROPIC_API_KEY');

    if (! is_string($key) || trim($key) === '') {
        $this->markTestSkipped('ANTHROPIC_API_KEY is not in the environment.');
    }

    // The key comes from the environment of the person running this, never from the repository.
    config()->set('ai.providers.'.COMPAT_PROVIDER, ['driver' => 'anthropic', 'key' => $key]);

    // A real run needs real requests and real backoff, which the default suite forbids.
    Http::allowStrayRequests();
    Sleep::fake(false);

    // Invalid samples are what this run measures, so they must not fail a
    // classification, and they must not be replaced either: the invalid rate
    // below is the provider's schema compliance, and topping up would count
    // Hunch's recovery into the denominator.
    config()->set('hunch.min_valid_samples', 1);
    config()->set('hunch.max_resamples', 0);
    config()->set('hunch.timeout', 60);
});

it('runs the fixture set against the provider and reports what it found', function () {
    $run = new CompatRun(COMPAT_PROVIDER, COMPAT_MODEL);
    $reports = [];

    foreach (CompatFixtures::groups() as $name => $group) {
        $reports[$name] = $run->group($group);
    }

    $samples = array_sum(array_column($reports, 'samples'));
    $invalid = array_sum(array_column($reports, 'invalid'));
    $scored = array_sum(array_column($reports, 'scored'));
    $correct = array_sum(array_column($reports, 'correct'));
    $usage = [
        'input' => array_sum(array_column(array_column($reports, 'usage'), 'input')),
        'cached' => array_sum(array_column(array_column($reports, 'usage'), 'cached')),
        'output' => array_sum(array_column(array_column($reports, 'usage'), 'output')),
    ];

    // Reported, never failed on: the injection group measures the provider, not Hunch.
    $compliable = array_sum(array_column($reports, 'compliable'));
    $complied = array_sum(array_column($reports, 'complied'));

    foreach ($reports as $name => $report) {
        $rate = $report['samples'] === 0 ? 0.0 : $report['invalid'] / $report['samples'];

        compatLine(sprintf(
            '%-13s %2d items  %3d samples  invalid %5.1f%%  accuracy %5.1f%%  tokens %d/%d/%d',
            $name, $report['items'], $report['samples'], $rate * 100,
            ($report['scored'] === 0 ? 0 : $report['correct'] / $report['scored']) * 100,
            $report['usage']['input'], $report['usage']['cached'], $report['usage']['output'],
        ));
    }

    compatLine(sprintf('injection compliance %d of %d', $complied, $compliable));

    // Why the invalid samples were invalid, so a rate can be acted on rather
    // than only watched. Anthropic's native structured output enforces the
    // required fields and the enums; an unexpected key it never forbade is a
    // different problem with a different fix.
    $reasons = [];

    foreach ($reports as $report) {
        foreach ($report['invalidReasons'] as $reason => $count) {
            $reasons[$reason] = ($reasons[$reason] ?? 0) + $count;
        }
    }

    arsort($reasons);

    foreach ($reasons as $reason => $count) {
        compatLine(sprintf('invalid x%d  %s', $count, $reason));
    }

    // Criterion: the run fails above a 10% schema-invalid rate.
    expect(CompatThresholds::exceedsInvalidLimit($invalid, $samples))->toBeFalse(
        sprintf('%d of %d samples were schema-invalid', $invalid, $samples),
    )
        ->and($samples)->toBe(126)
        ->and($scored)->toBeGreaterThan(0);

    // Criterion: aggregation over real samples, and a fully populated meta.
    foreach ($reports as $report) {
        foreach ($report['results'] as $result) {
            expect($result)->toBeInstanceOf(Result::class);

            foreach ($result->answers as $answer) {
                if ($answer === null) {
                    continue;
                }

                $probabilities = $answer instanceof BooleanAnswer
                    ? [$answer->probability, 1.0 - $answer->probability]
                    : $answer->probabilities;

                expect(array_sum($probabilities))->toEqualWithDelta(1.0, 1e-9);
            }

            expect($result->meta->driver)->toBe('sampling')
                ->and($result->meta->provider)->toBe(COMPAT_PROVIDER)
                ->and($result->meta->modelRequested)->toBe(COMPAT_MODEL)
                ->and($result->meta->modelReported)->not->toBeEmpty()
                ->and($result->meta->sampling)->toBe('fixed:3')
                ->and($result->meta->questionSetHash)->toStartWith('v1:')
                ->and($result->meta->stateHash)->toHaveLength(64)
                ->and($result->meta->seeds)->toHaveCount(3)
                ->and(array_filter($result->meta->requestIds))->toHaveCount(3);
        }
    }

    // Criterion: the large-Choice group is reported separately.
    expect($reports['large-choice']['items'])->toBe(12)
        ->and($reports['large-choice']['samples'])->toBe(36);

    // Criterion: samples after the first read the cached instructions.
    $cacheReads = $reports['large-choice']['cacheReads'];

    expect(array_slice($cacheReads, 1, 2))->each->toBeGreaterThan(0);

    ResultsTable::write([[
        'provider' => COMPAT_PROVIDER,
        'model' => COMPAT_MODEL,
        'items' => array_sum(array_column($reports, 'items')),
        'samples' => $samples,
        'invalid' => $invalid / $samples,
        'accuracy' => $scored === 0 ? 0.0 : $correct / $scored,
        'input' => $usage['input'],
        'cached' => $usage['cached'],
        'output' => $usage['output'],
    ]], now()->toDateString());
});

/**
 * Prints a line of the compatibility report, which is the point of the run.
 */
function compatLine(string $line): void
{
    fwrite(STDERR, $line.PHP_EOL);
}
