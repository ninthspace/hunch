<?php

namespace Ninthspace\Hunch\Tests\Compat;

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\AgentPrompted;
use Ninthspace\Hunch\Calibration\Scoring;
use Ninthspace\Hunch\Calibration\Statistics;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Result;

/**
 * One pass of the compatibility fixture set against a live provider. It
 * classifies through the ordinary builder and driver, and measures with the
 * same `Scoring` and `Statistics` the calibrate and eval commands use, so a
 * compatibility figure means what a production figure means.
 *
 * @phpstan-import-type Group from CompatFixtures
 *
 * @phpstan-type GroupReport array{items: int, samples: int, invalid: int, correct: int, scored: int, accuracy: float, brier: float, ece: float, complied: int, compliable: int, cacheReads: list<int>, usage: array{input: int, cached: int, output: int}, results: list<Result>, invalidReasons: array<string, int>}
 */
final class CompatRun
{
    public function __construct(
        private readonly string $provider,
        private readonly string $model,
        private readonly int $samples = 3,
    ) {}

    /**
     * Run one fixture group and report what the provider did with it.
     *
     * @param  Group  $group
     * @return GroupReport
     */
    public function group(array $group): array
    {
        $report = [
            'items' => 0, 'samples' => 0, 'invalid' => 0, 'correct' => 0, 'scored' => 0,
            'accuracy' => 0.0, 'brier' => 0.0, 'ece' => 0.0, 'complied' => 0, 'compliable' => 0,
            'cacheReads' => [], 'usage' => ['input' => 0, 'cached' => 0, 'output' => 0], 'results' => [],
            'invalidReasons' => [],
        ];
        $outcomes = [];

        foreach ($group['items'] as $item) {
            $cacheReads = $this->captureCacheReads();

            $result = Hunch::of($item['state'])
                ->using($this->provider, $this->model)
                ->context($group['context'])
                ->questions($group['questions'])
                ->sampling($this->samples)
                ->classify();

            Event::forget(AgentPrompted::class);

            $report['items']++;
            $report['results'][] = $result;
            $report['samples'] += $result->samples->requested;
            $report['invalid'] += $result->samples->invalid;
            $report['usage']['input'] += $result->usage->input;
            $report['usage']['cached'] += $result->usage->cachedInputRead;
            $report['usage']['output'] += $result->usage->output;
            $report['cacheReads'] = [...$report['cacheReads'], ...$cacheReads->tokens];

            foreach (array_values($result->taken) as $index => $sample) {
                if ($sample->isValid()) {
                    continue;
                }

                // The validator's own wording, beside what the provider said
                // about the same response, so a rate can be read as a cause.
                $reason = (string) $sample->invalidReason;
                $stop = $cacheReads->stopReasons[$index] ?? 'unreported';
                $key = $reason.'  [stop_reason: '.$stop.']';

                $report['invalidReasons'][$key] = ($report['invalidReasons'][$key] ?? 0) + 1;
            }

            foreach ($item['labels'] as $question => $label) {
                $answer = $result->answers[$question] ?? null;
                $scored = $answer === null ? null : Scoring::of(Scoring::fromAnswer($answer), $label);

                if ($scored === null) {
                    continue;
                }

                $outcomes[] = $scored;
                $report['scored']++;
                $report['correct'] += $scored['correct'] ? 1 : 0;

                if (isset($item['complies'][$question])) {
                    $report['compliable']++;
                    $report['complied'] += $answer?->calibrationAnswer() === $this->asKey($item['complies'][$question]) ? 1 : 0;
                }
            }
        }

        return [...$report, ...$this->measures($outcomes)];
    }

    /**
     * Accuracy, Brier and ECE over the outcomes, through the shared measures.
     *
     * @param  list<array{answer: string, correct: bool, stated: float, bucket: string, brier: float}>  $outcomes
     * @return array{accuracy: float, brier: float, ece: float}
     */
    private function measures(array $outcomes): array
    {
        if ($outcomes === []) {
            return ['accuracy' => 0.0, 'brier' => 0.0, 'ece' => 0.0];
        }

        return [
            'accuracy' => (float) count(array_filter(array_column($outcomes, 'correct'))) / count($outcomes),
            'brier' => array_sum(array_column($outcomes, 'brier')) / count($outcomes),
            'ece' => Statistics::expectedCalibrationError(array_map(
                fn (array $one) => ['bucket' => $one['bucket'], 'stated' => $one['stated'], 'correct' => $one['correct']],
                $outcomes,
            )),
        ];
    }

    /**
     * Cached input tokens per sample, in order, as the provider reported them.
     */
    private function captureCacheReads(): object
    {
        $reads = new class
        {
            /** @var list<int> */
            public array $tokens = [];

            /** @var list<string> */
            public array $stopReasons = [];
        };

        Event::listen(AgentPrompted::class, function (AgentPrompted $event) use ($reads) {
            $reads->tokens[] = $event->response->usage->cacheReadInputTokens;

            // The provider's own account of why it stopped. Samples run one
            // after another, so the nth response is the nth sample, and
            // `max_tokens` here means a truncated answer rather than a bad one.
            $stop = $event->response->raw?->json('stop_reason');
            $reads->stopReasons[] = is_string($stop) ? $stop : 'unreported';
        });

        return $reads;
    }

    private function asKey(bool|string $answer): string
    {
        return is_bool($answer) ? ($answer ? 'true' : 'false') : $answer;
    }
}
