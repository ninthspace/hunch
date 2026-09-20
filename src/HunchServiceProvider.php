<?php

namespace Ninthspace\Hunch;

use Ninthspace\Hunch\Commands\CalibrateCommand;
use Ninthspace\Hunch\Commands\EvalCommand;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class HunchServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('hunch')
            ->hasConfigFile()
            ->hasCommands(CalibrateCommand::class, EvalCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(HunchManager::class);
    }

    public function packageBooted(): void
    {
        // Offered, never loaded: installing Hunch adds no migrations to an
        // application, and nothing runs until someone publishes them. An
        // application with persistence off has no Hunch tables at all.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'hunch-migrations');

        if (config('hunch.persistence') && config('hunch.retention_days') === null) {
            throw new ConfigurationException('`hunch.retention_days` must be set when `hunch.persistence` is on.');
        }
    }
}
