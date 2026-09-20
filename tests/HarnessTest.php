<?php

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Ninthspace\Hunch\Tests\TestCase;
use Orchestra\Testbench\TestCase as Testbench;

it('extends the Testbench TestCase', function () {
    expect(is_subclass_of(TestCase::class, Testbench::class))->toBeTrue();
});

it('runs on Pest 4', function () {
    expect(Pest\version())->toStartWith('4.');
});

it('sets SQLite in memory in phpunit.xml', function () {
    $env = [];

    foreach (simplexml_load_file(__DIR__.'/../phpunit.xml')->php->env as $variable) {
        $env[(string) $variable['name']] = (string) $variable['value'];
    }

    expect($env)->toMatchArray([
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
    ]);
});

it('runs against SQLite in memory', function () {
    $connection = config('database.connections.'.config('database.default'));

    expect($connection['driver'])->toBe('sqlite')
        ->and($connection['database'])->toBe(':memory:');
});

it('prevents stray HTTP requests', function () {
    Http::get('https://example.com');
})->throws(StrayRequestException::class);
