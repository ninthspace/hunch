<?php

use Ninthspace\Hunch\Tests\Compat\ResultsTable;

/**
 * @return list<array{provider: string, model: string, items: int, samples: int, invalid: float, accuracy: float, input: int, cached: int, output: int}>
 */
function resultsRows(): array
{
    return [[
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-5',
        'items' => 42,
        'samples' => 126,
        'invalid' => 0.0238,
        'accuracy' => 0.9048,
        'input' => 123456,
        'cached' => 98765,
        'output' => 4321,
    ]];
}

function readmeWith(string $between): string
{
    return "# Hunch\n\nSome prose that must not move.\n\n"
        .ResultsTable::START."\n{$between}\n".ResultsTable::END
        ."\n\n## Development\n\nMore prose that must not move.\n";
}

it('renders a row per provider with percentages and token counts', function () {
    $table = ResultsTable::render(resultsRows(), '2026-09-20');

    expect($table)->toContain('| anthropic | claude-sonnet-4-5 | 42 | 126 | 2.4% | 90.5% | 123,456 | 98,765 | 4,321 |')
        ->toContain('| Provider | Model | Items | Samples | Invalid samples | Accuracy | Input | Cached input | Output |')
        ->toContain('Last run 2026-09-20 by `composer compat`, over 1 provider.')
        ->toContain('not a benchmark');
});

it('changes only what lies between the markers', function () {
    $readme = tempnam(sys_get_temp_dir(), 'readme').'.md';
    file_put_contents($readme, readmeWith('No run recorded yet.'));

    ResultsTable::write(resultsRows(), '2026-09-20', $readme);
    $written = (string) file_get_contents($readme);

    [$before, $rest] = explode(ResultsTable::START, $written, 2);
    [$table, $after] = explode(ResultsTable::END, $rest, 2);

    expect($before)->toBe("# Hunch\n\nSome prose that must not move.\n\n")
        ->and($after)->toBe("\n\n## Development\n\nMore prose that must not move.\n")
        ->and($table)->toContain('| anthropic |')
        ->and($table)->not->toContain('No run recorded yet.');

    unlink($readme);
});

it('replaces an earlier run rather than appending to it', function () {
    $readme = tempnam(sys_get_temp_dir(), 'readme').'.md';
    file_put_contents($readme, readmeWith('No run recorded yet.'));

    ResultsTable::write(resultsRows(), '2026-09-19', $readme);
    ResultsTable::write(resultsRows(), '2026-09-20', $readme);
    $written = (string) file_get_contents($readme);

    expect(substr_count($written, '| anthropic |'))->toBe(1)
        ->and(substr_count($written, ResultsTable::START))->toBe(1)
        ->and($written)->toContain('Last run 2026-09-20')
        ->and($written)->not->toContain('Last run 2026-09-19');

    unlink($readme);
});

it('refuses a README with no markers rather than guessing where the table goes', function () {
    $readme = tempnam(sys_get_temp_dir(), 'readme').'.md';
    file_put_contents($readme, "# Hunch\n\nNo markers here.\n");

    try {
        ResultsTable::write(resultsRows(), '2026-09-20', $readme);

        $this->fail('Writing should have been refused.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('markers')
            ->and((string) file_get_contents($readme))->toBe("# Hunch\n\nNo markers here.\n");
    }

    unlink($readme);
});

it('keeps the markers in the project README, ready for a run', function () {
    $readme = (string) file_get_contents(ResultsTable::README);

    expect($readme)->toContain(ResultsTable::START)
        ->and($readme)->toContain(ResultsTable::END)
        ->and(strpos($readme, ResultsTable::START))->toBeLessThan(strpos($readme, ResultsTable::END));
});
