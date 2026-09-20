<?php

beforeEach(function () {
    $this->composer = json_decode(file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
});

it('is named ninthspace/hunch', function () {
    expect($this->composer['name'])->toBe('ninthspace/hunch');
});

it('requires php ^8.3', function () {
    expect($this->composer['require']['php'])->toBe('^8.3');
});

it('constrains every illuminate requirement to ^13.0', function () {
    $illuminate = array_filter(
        $this->composer['require'],
        fn (string $package) => str_starts_with($package, 'illuminate/'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($illuminate)->not->toBeEmpty()
        ->each->toBe('^13.0');
});

it('requires laravel/ai', function () {
    expect($this->composer['require'])->toHaveKey('laravel/ai');
});

it('declares no ext requirements of its own', function () {
    $extensions = array_filter(
        array_keys($this->composer['require']),
        fn (string $package) => str_starts_with($package, 'ext-'),
    );

    expect($extensions)->toBeEmpty();
});

it('requires orchestra/testbench for development', function () {
    expect($this->composer['require-dev'])->toHaveKey('orchestra/testbench');
});

it('runs pest through composer test', function () {
    expect($this->composer['scripts']['test'])->toBe('vendor/bin/pest');
});

it('runs pest with outbound network denied through composer test:offline', function () {
    $commands = $this->composer['scripts']['test:offline'];

    expect($commands)->toHaveCount(2)
        ->each->toContain('deny network-outbound')
        ->and($commands[0])->toEndWith('vendor/bin/pest')
        ->and($commands[1])->toEndWith('vendor/bin/pest --group=network-probe');
});

it('runs phpstan and pint --test through composer check', function () {
    expect($this->composer['scripts']['check'])->toBe([
        'vendor/bin/phpstan analyse --memory-limit=1G',
        'vendor/bin/pint --test',
    ]);
});
