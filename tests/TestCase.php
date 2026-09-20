<?php

namespace Ninthspace\Hunch\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\AiServiceProvider;
use Ninthspace\Hunch\HunchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Retry backoff sleeps are recorded, never slept.
        Sleep::fake();
    }

    protected function defineEnvironment($app): void
    {
        // Providers the classify tests name. Keys are placeholders: every
        // test fakes the agent, and stray HTTP is prevented regardless.
        $app['config']->set('ai.providers.anthropic', ['driver' => 'anthropic', 'key' => 'test-key']);
        $app['config']->set('ai.providers.openai', ['driver' => 'openai', 'key' => 'test-key']);
    }

    protected function getPackageProviders($app)
    {
        return [
            AiServiceProvider::class,
            HunchServiceProvider::class,
        ];
    }
}
