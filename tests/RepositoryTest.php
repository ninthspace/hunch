<?php

use Symfony\Component\Finder\Finder;

it('contains no GitHub Actions workflows', function () {
    expect(__DIR__.'/../.github/workflows')->not->toBeDirectory();
});

it('skips no test in the default suite', function () {
    // A file may skip only if it belongs to a group phpunit.xml excludes, so
    // the skip cannot hide inside the suite `composer test` runs.
    $excluded = [];
    $xml = simplexml_load_file(__DIR__.'/../phpunit.xml');

    foreach ($xml->groups->exclude->group ?? [] as $group) {
        $excluded[] = (string) $group;
    }

    expect($excluded)->not->toBeEmpty();

    $tests = Finder::create()->files()->in(__DIR__)->name('*.php')->exclude('Nightly');

    foreach ($tests as $test) {
        $contents = (string) $test->getContents();
        $skips = preg_match('/->skip\(|markTest'.'Skipped/', $contents) === 1;
        $isExcluded = false;

        foreach ($excluded as $group) {
            $isExcluded = $isExcluded || preg_match('/group\(\s*[\'"]'.preg_quote($group, '/').'[\'"]\s*\)/', $contents) === 1;
        }

        expect($skips && ! $isExcluded)->toBeFalse($test->getRelativePathname().' skips a test in the default suite');
    }
});
