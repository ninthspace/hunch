<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Events\PromptingAgent;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Models\StoredSample;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Prompts\Bands;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;

require_once dirname(__DIR__).'/Persistence/helpers.php';

beforeEach(function () {
    persistenceOn($this);
});

function selfReporting(): PendingClassification
{
    return Hunch::of('Refund please.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->question('urgent', new Boolean('Is it urgent?'))
        ->sampling(3);
}

/**
 * A fake answering both questions, with `$bands` stated for `intent` in turn.
 *
 * @param  list<string>  $bands
 */
function banded(array $bands, string $intent = 'refund'): Closure
{
    $call = 0;

    return function () use (&$call, $bands, $intent) {
        $band = $bands[min($call++, count($bands) - 1)];

        return ['intent' => $intent, 'urgent' => true, 'bands' => ['intent' => $band, 'urgent' => 'low']];
    };
}

/**
 * The schema properties of the first prompt sent.
 *
 * @return array<string, mixed>
 */
function schemaOf(PendingClassification $pending): array
{
    $properties = [];
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$properties) {
        $properties = $properties ?: (new ObjectType($event->prompt->agent->schema(new JsonSchemaTypeFactory)))->toArray()['properties'];
    });

    $pending->classify();

    return $properties;
}

it('asks for a band per question from a fixed set, and records each sample s bands', function () {
    ClassifierAgent::fake(banded(['high', 'high', 'medium']));
    $instructions = [];
    Event::listen(PromptingAgent::class, function (PromptingAgent $event) use (&$instructions) {
        $instructions[] = (string) $event->prompt->agent->instructions();
    });

    $result = selfReporting()->withSelfReport()->record(enquiry())->classify();
    $schema = schemaOf(selfReporting()->withSelfReport());

    expect($schema)->toHaveKey('bands')
        ->and(array_keys($schema['bands']['properties'] ?? []))->toBe(['intent', 'urgent'])
        ->and($schema['bands']['properties']['intent']['enum'] ?? null)->toBe(['high', 'medium', 'low'])
        ->and($schema['bands']['required'] ?? null)->toBe(['intent', 'urgent'])
        ->and($instructions[0])->toContain('one band per question')
        ->and($instructions[0])->toContain('high:')
        ->and(array_map(fn ($sample) => $sample->bands, $result->taken))->toBe([
            ['intent' => 'high', 'urgent' => 'low'],
            ['intent' => 'high', 'urgent' => 'low'],
            ['intent' => 'medium', 'urgent' => 'low'],
        ])
        ->and(StoredSample::query()->orderBy('number')->pluck('band')->all())->toBe([
            ['intent' => 'high', 'urgent' => 'low'],
            ['intent' => 'high', 'urgent' => 'low'],
            ['intent' => 'medium', 'urgent' => 'low'],
        ]);
});

it('leaves the band out of the schema without withSelfReport()', function () {
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund', 'urgent' => true];
    });

    expect(schemaOf(selfReporting()))->not->toHaveKey('bands');
});

it('refuses a sample whose bands are missing or not a known band', function (array $output, string $problem) {
    ClassifierAgent::fake(function () use ($output) {
        return $output;
    });
    config()->set('hunch.min_valid_samples', 1);

    $result = selfReporting()->withSelfReport()->sampling(1)->record(enquiry())->classify();
})->with([
    'missing bands' => [['intent' => 'refund', 'urgent' => true], 'bands'],
    'unknown band' => [['intent' => 'refund', 'urgent' => true, 'bands' => ['intent' => 'certain', 'urgent' => 'low']], 'band'],
    'partial bands' => [['intent' => 'refund', 'urgent' => true, 'bands' => ['intent' => 'high']], 'bands'],
])->throws(ClassificationFailed::class);

it('measures accuracy, Brier and ECE per stated band alongside the bucket measures', function () {
    // 4 classifications, all answering refund unanimously: 2 stated high (1 right),
    // 2 stated low (2 right). Band ECE: |0.95 - 0.5| and |0.4 - 1.0|, evenly weighted.
    $labels = ['other', 'refund', 'refund', 'refund'];
    $bands = ['high', 'high', 'low', 'low'];
    $enquiry = enquiry();

    foreach ($labels as $i => $label) {
        ClassifierAgent::fake(banded([$bands[$i]]));
        $id = selfReporting()->withSelfReport()->record($enquiry)->classify()->id;
        Hunch::label($id, ['intent' => $label]);
    }

    Artisan::call('hunch:calibrate');
    $output = Artisan::output();

    expect($output)->toContain('| high | 2 | 0.500    | 1.0000 |')
        ->and($output)->toContain('| low  | 2 | 1.000    | 0.0000 |')
        ->and($output)->toContain('Band ECE '.sprintf('%.4f', (abs(0.95 - 0.5) + abs(0.4 - 1.0)) / 2))
        ->and($output)->toContain('ECE 0.2500');
});

it('lets no stated band change a vote, probability, bucket or answer', function () {
    $run = function (array $bands) {
        Str::createUlidsUsing(fn () => '01J00000000000000000000002');
        ClassifierAgent::fake(banded($bands));
        $result = selfReporting()->withSelfReport()->classify();
        Str::createUlidsNormally();

        return $result;
    };

    $confident = $run(['high', 'high', 'high']);
    $unsure = $run(['low', 'low', 'low']);

    expect(array_map(fn ($sample) => $sample->bands['intent'], $confident->taken))->toBe(['high', 'high', 'high'])
        ->and(array_map(fn ($sample) => $sample->bands['intent'], $unsure->taken))->toBe(['low', 'low', 'low'])
        ->and($confident->answers)->toEqual($unsure->answers)
        ->and($confident['intent']->probabilities)->toBe($unsure['intent']->probabilities)
        ->and($confident['intent']->votes)->toBe($unsure['intent']->votes)
        ->and($confident['intent']->bucket)->toBe($unsure['intent']->bucket)
        ->and($confident['intent']->choice)->toBe($unsure['intent']->choice)
        ->and($confident->meta->questionSetHash)->toBe($unsure->meta->questionSetHash);
});

it('takes the lower band when a classification s samples are split', function () {
    expect(Bands::modal(['high', 'low']))->toBe('low')
        ->and(Bands::modal(['high', 'high', 'low']))->toBe('high')
        ->and(Bands::modal(['medium', 'low', 'medium']))->toBe('medium')
        ->and(Bands::modal([]))->toBeNull();
});

/**
 * Every place under src/ that reads a sample's `->bands`, as file::method.
 *
 * @return list<string>
 */
function bandReaders(): array
{
    $src = dirname(__DIR__, 2).'/src';
    $readers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file->getPathname())),
            fn (PhpToken $token) => ! $token->isIgnorable(),
        ));
        $method = null;

        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? null;

            if ($token->is(T_FUNCTION) && $next?->is(T_STRING)) {
                $method = $next->text;
            }

            if ($token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) && $next?->is(T_STRING) && $next->text === 'bands') {
                $readers[] = substr($file->getPathname(), strlen($src) + 1)."::{$method}";
            }
        }
    }

    sort($readers);

    return $readers;
}

it('reads a sample s bands only where they are stored for comparison', function () {
    expect(bandReaders())->toBe(['Persistence/Recorder.php::samples']);
});
