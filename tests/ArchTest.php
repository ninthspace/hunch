<?php

use Symfony\Component\Finder\Finder;

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

it('declares every class under src in the Ninthspace\Hunch namespace', function () {
    $files = Finder::create()->files()->in(__DIR__.'/../src')->name('*.php');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        preg_match('/^namespace\s+([^;]+);/m', $file->getContents(), $matches);

        expect($matches[1] ?? null)
            ->toStartWith('Ninthspace\\Hunch', $file->getRelativePathname().' is outside Ninthspace\\Hunch');
    }
});
