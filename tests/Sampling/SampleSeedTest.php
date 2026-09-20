<?php

use Ninthspace\Hunch\Sampling\SampleSeed;
use Symfony\Component\Process\Process;

const GOLDEN_ID = '01J8Z3K4M5N6P7Q8R9S0T1V2W3';

it('gives the same seed and shuffle for the same ID and sample number', function () {
    $first = SampleSeed::for(GOLDEN_ID, 3);
    $second = SampleSeed::for(GOLDEN_ID, 3);

    expect($first->hex())->toBe($second->hex())
        ->and($first->randomizer()->shuffleArray(range(1, 20)))
        ->toBe($second->randomizer()->shuffleArray(range(1, 20)));
});

it('gives a different seed for a different sample number or ID', function () {
    expect(SampleSeed::for(GOLDEN_ID, 1)->hex())
        ->not->toBe(SampleSeed::for(GOLDEN_ID, 2)->hex())
        ->not->toBe(SampleSeed::for('01J8Z3K4M5N6P7Q8R9S0T1V2W4', 1)->hex());
});

it('gives the same seed and shuffle in a separate PHP process', function () {
    $script = sprintf(
        'require %s; $s = %s::for(%s, 1); echo json_encode([$s->hex(), $s->randomizer()->shuffleArray(range(1, 10))]);',
        var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true),
        SampleSeed::class,
        var_export(GOLDEN_ID, true),
    );

    $process = new Process([PHP_BINARY, '-r', $script]);
    $process->mustRun();

    $seed = SampleSeed::for(GOLDEN_ID, 1);

    expect(json_decode($process->getOutput(), true))
        ->toBe([$seed->hex(), $seed->randomizer()->shuffleArray(range(1, 10))]);
});

it('matches the golden seed and permutation', function () {
    $seed = SampleSeed::for(GOLDEN_ID, 1);

    expect($seed->hex())->toBe('b0a308c902b1a021f13c0f644ccb0b6e8fa241b52a6cbd340cce279bf1aa84e8')
        ->and($seed->randomizer()->shuffleArray(range(1, 10)))->toBe([10, 2, 1, 3, 7, 5, 9, 8, 4, 6]);
});

it('leaves the global RNG sequence untouched', function () {
    mt_srand(42);
    $expected = [mt_rand(), mt_rand(), rand()];

    mt_srand(42);
    $seed = SampleSeed::for(GOLDEN_ID, 1);
    $seed->randomizer()->shuffleArray(range(1, 50));

    expect([mt_rand(), mt_rand(), rand()])->toBe($expected);
});

arch('seeding never reseeds the global RNG')
    ->expect(['mt_srand', 'srand'])
    ->not->toBeUsed();
