<?php

namespace Ninthspace\Hunch\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ninthspace\Hunch\Calibration\Scoring;
use Ninthspace\Hunch\Calibration\Statistics;
use Ninthspace\Hunch\Contracts\ProvidesHunchState;
use Ninthspace\Hunch\Drivers\ClassificationDriver;
use Ninthspace\Hunch\Drivers\DriverResolver;
use Ninthspace\Hunch\Drivers\SamplingDriver;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Models\Classification;
use Ninthspace\Hunch\Models\StoredQuestionSet;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\QuestionSet;
use Ninthspace\Hunch\Sampling\SamplingRule;
use Ninthspace\Hunch\Support\Settings;

/**
 * Re-classifies a question set's labelled items with each named driver and
 * compares them against the labels. It records nothing, so an eval run never
 * reaches `hunch:calibrate`, and it reports tokens rather than money.
 *
 * @phpstan-type Item array{state: string|array<string, string>, labels: array<string, bool|string>, sampling: string}
 */
class EvalCommand extends Command
{
    protected $signature = 'hunch:eval {set : The question set hash} {--driver=* : driver:provider:model, once per driver}';

    protected $description = 'Compare drivers on a question set\'s labelled items';

    /**
     * Labelled items whose subject could not give its state back.
     */
    private int $skipped = 0;

    public function handle(): int
    {
        if (! Settings::get('persistence', false)) {
            $this->error('hunch:eval needs `hunch.persistence` to be on.');

            return self::FAILURE;
        }

        $specs = [];

        foreach ($this->option('driver') as $spec) {
            $parts = explode(':', (string) $spec);

            if (count($parts) !== 3 || in_array('', $parts, true)) {
                $this->error("`{$spec}` is not a driver: give it as driver:provider:model.");

                return self::FAILURE;
            }

            $specs[] = $parts;
        }

        if ($specs === []) {
            $this->error('Name at least one --driver as driver:provider:model.');

            return self::FAILURE;
        }

        $hash = (string) $this->argument('set');

        try {
            $set = $this->questionSet($hash);
            $items = $set === null ? [] : $this->items($hash);
        } catch (ConfigurationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($items === []) {
            $this->error($this->skipped > 0
                ? "Every labelled classification of `{$hash}` was skipped: their subjects do not provide their state."
                : "No labelled classifications recorded for `{$hash}`.");

            return self::FAILURE;
        }

        $rows = [];

        foreach ($specs as [$driver, $provider, $model]) {
            try {
                $rows[] = $this->evaluate($set, $items, $driver, $provider, $model);
            } catch (ConfigurationException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $this->line(count($items).' labelled items');
        $this->table(
            ['Driver', 'Accuracy', 'Brier', 'ECE', 'Mean samples', 'Input', 'Cached input', 'Output'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * The question set as it was asked, or null when it was never recorded.
     */
    private function questionSet(string $hash): ?QuestionSet
    {
        $stored = StoredQuestionSet::query()->where('hash', $hash)->first();

        return $stored === null ? null : QuestionSet::fromStored($stored->canonical, $stored->definition, $stored->version);
    }

    /**
     * Every labelled item of this question set whose subject can still give
     * back its state. Others are reported as skipped.
     *
     * @return list<Item>
     */
    private function items(string $hash): array
    {
        $items = [];

        $classifications = Classification::query()
            ->where('question_set_hash', $hash)
            ->where('status', Classification::COMPLETED)
            ->whereHas('labels', fn (Builder $query) => $query->whereNull('superseded_at'))
            ->with(['labels', 'subject'])
            ->orderBy('id')
            ->get();

        foreach ($classifications as $classification) {
            $subject = $classification->subject;

            if (! $subject instanceof Model || ! $subject instanceof ProvidesHunchState) {
                $this->skipped++;

                continue;
            }

            $labels = [];

            foreach ($classification->labels->whereNull('superseded_at') as $label) {
                $labels[$label->question] = $label->answer;
            }

            $items[] = ['state' => $subject->hunchState(), 'labels' => $labels, 'sampling' => $classification->sampling];
        }

        if ($this->skipped > 0) {
            $this->line("{$this->skipped} item(s) skipped: their subject does not provide its state.");
        }

        return $items;
    }

    /**
     * One driver's row: accuracy, Brier, ECE, mean samples and tokens.
     *
     * @param  list<Item>  $items
     * @return array{string, string, string, string, string, int, int, int}
     */
    private function evaluate(QuestionSet $set, array $items, string $driver, string $provider, string $model): array
    {
        $scored = [];
        $samples = 0;
        $tokens = ['input' => 0, 'cached' => 0, 'output' => 0];

        $failed = 0;

        foreach ($items as $item) {
            $rule = SamplingRule::parse($item['sampling']);

            try {
                $result = new PendingClassification($item['state'], $this->driver($driver))
                    ->using($provider, $model)
                    ->questions($set->questions)
                    ->context($set->context)
                    ->sampling($rule->adaptive ? null : $rule->min, $rule->adaptive ? [$rule->min, $rule->max] : null)
                    ->classify();
            } catch (ClassificationFailed) {
                $failed++;

                continue;
            }

            $samples += $result->samples->requested;
            $tokens['input'] += $result->usage->input;
            $tokens['cached'] += $result->usage->cachedInputRead;
            $tokens['output'] += $result->usage->output;

            foreach ($item['labels'] as $question => $label) {
                $answer = $result->answers->has($question) ? $result->answers[$question] : null;
                $one = $answer === null ? null : Scoring::of(Scoring::fromAnswer($answer), $label);

                if ($one !== null) {
                    $scored[] = $one;
                }
            }
        }

        if ($failed > 0) {
            $this->line("{$failed} item(s) failed to classify with {$driver}:{$provider}:{$model}.");
        }

        $correct = count(array_filter(array_column($scored, 'correct')));

        return [
            "{$driver}:{$provider}:{$model}",
            sprintf('%.4f', $scored === [] ? 0.0 : $correct / count($scored)),
            sprintf('%.4f', $scored === [] ? 0.0 : array_sum(array_column($scored, 'brier')) / count($scored)),
            sprintf('%.4f', Statistics::expectedCalibrationError(array_map(
                fn (array $one) => ['bucket' => $one['bucket'], 'stated' => $one['stated'], 'correct' => $one['correct']],
                $scored,
            ))),
            sprintf('%.2f', $items === [] ? 0.0 : $samples / count($items)),
            $tokens['input'],
            $tokens['cached'],
            $tokens['output'],
        ];
    }

    /**
     * The driver to run this pass with, by name.
     */
    private function driver(string $driver): ClassificationDriver
    {
        return match ($driver) {
            'sampling' => new SamplingDriver,
            'typesafe' => DriverResolver::for('typesafe'),
            default => throw new ConfigurationException("`{$driver}` is not a Hunch driver."),
        };
    }
}
