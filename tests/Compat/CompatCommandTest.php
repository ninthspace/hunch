<?php

use Ninthspace\Hunch\Tests\Compat\CompatThresholds;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Runs the key check as `composer compat` runs it, with `$environment`
 * replacing the inherited environment.
 *
 * @param  array<string, string|false>  $environment
 */
function runKeyCheck(array $environment): Process
{
    $process = new Process(['php', 'tests/Compat/check-key.php'], dirname(__DIR__, 2), $environment);
    $process->run();

    return $process;
}

it('runs the key check before pest, through composer compat', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['compat'])->toBe([
        '@php tests/Compat/check-key.php',
        'vendor/bin/pest --group=compat',
    ]);
});

it('keeps the compatibility group out of the default suite', function () {
    $xml = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
    $excluded = [];

    foreach ($xml->groups->exclude->group as $group) {
        $excluded[] = (string) $group;
    }

    expect($excluded)->toContain('compat');
});

it('exits non-zero with a clear message when the key is absent', function () {
    $process = runKeyCheck(['ANTHROPIC_API_KEY' => false, 'PATH' => getenv('PATH')]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('ANTHROPIC_API_KEY')
        ->and($process->getErrorOutput())->toContain('composer compat')
        ->and($process->getOutput())->toBe('');
});

it('exits zero when the key is in the environment', function () {
    $process = runKeyCheck(['ANTHROPIC_API_KEY' => 'sk-ant-not-a-real-key', 'PATH' => getenv('PATH')]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toBe('');
});

it('treats a blank key as absent', function () {
    $process = runKeyCheck(['ANTHROPIC_API_KEY' => '   ', 'PATH' => getenv('PATH')]);

    expect($process->getExitCode())->toBe(1);
});

it('reads the key from the environment and nowhere else', function () {
    $sources = [];

    foreach (Finder::create()->files()->in(__DIR__)->name('*.php') as $file) {
        $contents = (string) $file->getContents();

        preg_match_all('/(getenv|\$_ENV|\$_SERVER|env\(|config\()\s*\(?\s*[\'"]?([A-Za-z0-9_.\-]*)/', $contents, $matches, PREG_SET_ORDER);

        foreach ($matches as [$whole, $source, $name]) {
            if (str_contains(strtolower($whole.$name), 'key')) {
                $sources[] = $source.':'.$name;
            }
        }
    }

    // The key is read only from the process environment, never from config or a file.
    expect(array_values(array_unique($sources)))->toBe(['getenv:ANTHROPIC_API_KEY']);
});

it('writes no provider key anywhere in the repository', function () {
    $files = Finder::create()->files()
        ->in(dirname(__DIR__, 2))
        ->exclude(['vendor', 'node_modules', 'build', '.git'])
        ->notName('*.lock');

    $offenders = [];

    foreach ($files as $file) {
        $contents = (string) $file->getContents();

        // A real Anthropic key is `sk-ant-` followed by a long opaque string.
        if (preg_match('/sk-ant-[A-Za-z0-9_\-]{20,}/', $contents) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('ignores env files, so a local key file cannot be committed', function () {
    $ignore = (string) file_get_contents(dirname(__DIR__, 2).'/.gitignore');

    expect($ignore)->toContain('.env')
        ->and($ignore)->toContain('*.env');
});

it('fails a run whose samples break the schema more than a tenth of the time', function () {
    expect(CompatThresholds::exceedsInvalidLimit(13, 126))->toBeTrue()
        ->and(CompatThresholds::exceedsInvalidLimit(12, 126))->toBeFalse()
        ->and(CompatThresholds::exceedsInvalidLimit(1, 126))->toBeFalse()
        ->and(CompatThresholds::exceedsInvalidLimit(0, 0))->toBeFalse()
        ->and(CompatThresholds::invalidRate(1, 126))->toEqualWithDelta(0.00794, 1e-5)
        ->and(CompatThresholds::INVALID_LIMIT)->toBe(0.10);
});

it('reports injection compliance without ever failing on it', function () {
    $run = (string) file_get_contents(__DIR__.'/CompatibilityTest.php');

    preg_match_all('/expect\(([^;]*)/s', $run, $assertions);

    // Compliance measures the provider, not Hunch, so it is printed and never asserted on.
    $offenders = array_values(array_filter(
        $assertions[1],
        fn (string $assertion) => str_contains($assertion, 'complied') || str_contains($assertion, 'compliable'),
    ));

    expect($offenders)->toBe([])
        ->and($run)->toContain('injection compliance');
});
