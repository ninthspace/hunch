<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\HunchServiceProvider;
use Ninthspace\Hunch\Sampling\SamplingRule;

it('publishes config/hunch.php with every FR16 key and its default', function () {
    $path = config_path('hunch.php');
    File::delete($path);

    try {
        expect(Artisan::call('vendor:publish', ['--tag' => 'hunch-config']))->toBe(0)
            ->and($path)->toBeFile();

        $published = require $path;

        expect($published)->toBe([
            'driver' => 'sampling',
            'provider' => null,
            'model' => null,
            'sampling' => 'fixed:5',
            'min_valid_samples' => 3,
            'max_resamples' => 2,
            'timeout' => 20,
            'retries' => 2,
            'failover' => false,
            'agent' => ClassifierAgent::class,
            'choice' => ['max_options' => 50],
            'buckets' => [1.0, 0.8, 0.6],
            'calibration' => ['min_n' => 30],
            'persistence' => false,
            'retention_days' => null,
        ]);
    } finally {
        File::delete($path);
    }
});

it('parses an adaptive sampling rule', function () {
    config()->set('hunch.sampling', 'adaptive:3-7');

    $rule = SamplingRule::configured();

    expect($rule->adaptive)->toBeTrue()
        ->and([$rule->min, $rule->max])->toBe([3, 7]);
});

it('parses a fixed sampling rule', function () {
    $rule = SamplingRule::parse('fixed:5');

    expect($rule->adaptive)->toBeFalse()
        ->and([$rule->min, $rule->max])->toBe([5, 5]);
});

it('rejects a malformed sampling rule when it is first used, not at boot', function (string $value) {
    config()->set('hunch.sampling', $value);

    expect(config('hunch.sampling'))->toBe($value)
        ->and(fn () => SamplingRule::configured())->toThrow(ConfigurationException::class);
})->with(['adaptive:7-3', 'foo:5', 'fixed:0', 'fixed:', 'adaptive:3']);

it('refuses to boot with persistence on and no retention_days', function () {
    config()->set('hunch.persistence', true);
    config()->set('hunch.retention_days', null);

    app()->register(HunchServiceProvider::class, force: true);
})->throws(ConfigurationException::class, 'retention_days');

it('loads no Hunch migrations when persistence is off', function () {
    config()->set('hunch.persistence', false);
    app()->register(HunchServiceProvider::class, force: true);

    expect(app('migrator')->paths())->not->toContain(realpath(dirname(__DIR__, 2).'/src/../database/migrations'))
        ->and(collect(app('migrator')->paths())->filter(fn (string $path) => str_contains($path, 'hunch')))->toBeEmpty();
});

it('never loads Hunch migrations, whether persistence is on or off', function (bool $persistence) {
    config()->set('hunch.persistence', $persistence);
    config()->set('hunch.retention_days', 90);
    app()->register(HunchServiceProvider::class, force: true);

    // Installing the package must not put migrations into an application:
    // they are published on request and run by the application itself.
    expect(collect(app('migrator')->paths())->map(fn (string $path) => realpath($path)))
        ->not->toContain(realpath(dirname(__DIR__, 2).'/database/migrations'));
})->with([true, false]);

it('offers its migrations for publishing under the hunch-migrations tag', function () {
    app()->register(HunchServiceProvider::class, force: true);

    $published = ServiceProvider::pathsToPublish(HunchServiceProvider::class, 'hunch-migrations');

    expect(array_map(realpath(...), array_keys($published)))->toBe([realpath(dirname(__DIR__, 2).'/database/migrations')])
        ->and(array_values($published)[0])->toEndWith('database/migrations');
});
