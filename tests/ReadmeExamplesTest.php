<?php

use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Events\SampleInvalid;
use Ninthspace\Hunch\Exceptions\ClassificationFailed;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\Questions\Boolean;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Questions\Score;
use Ninthspace\Hunch\Redactors\ContactDetails;
use Ninthspace\Hunch\Result;

/*
 * The README's examples, run against the fake so the documentation cannot
 * drift away from the API it describes.
 */

const README_INTENTS = [
    'refund' => 'Money back for an order',
    'exchange' => 'A different size, colour or item',
    'delivery' => 'To find out where an order is, or to change delivery',
    'other' => 'Anything else',
];

const README_LEVELS = ['low' => 'It can wait', 'medium' => 'Today or tomorrow', 'high' => 'Within the hour'];

it('runs the usage example and reads the answers it documents', function () {
    ClassifierAgent::fake([
        ['intent' => 'refund', 'urgency' => 'medium', 'complaint' => true],
        ['intent' => 'refund', 'urgency' => 'medium', 'complaint' => true],
        ['intent' => 'delivery', 'urgency' => 'low', 'complaint' => true],
        ['intent' => 'refund', 'urgency' => 'medium', 'complaint' => false],
        ['intent' => 'refund', 'urgency' => 'high', 'complaint' => true],
    ]);

    $result = Hunch::of('The jumper arrived with a hole in the sleeve. I would like my money back please.')
        ->context('A support inbox for a clothing shop.')
        ->questions([
            'intent' => new Choice('What does the customer want?', README_INTENTS),
            'urgency' => new Score('How urgent is this message?', README_LEVELS),
            'complaint' => new Boolean('Is the customer complaining?'),
        ])
        ->using('anthropic', 'claude-sonnet-5')
        ->sampling(5)
        ->classify();

    expect($result['intent']->choice)->toBe('refund')
        // The README's Answers examples, run as documented.
        ->and($result->answers['intent'])->toBe($result->answers->get('intent'))
        ->and($result->answer('intent'))->toBe($result['intent'])
        ->and($result->answers->has('colour'))->toBeFalse()
        ->and($result->answers->keys())->toBe(['complaint', 'intent', 'urgency'])
        ->and($result->answers->applicable()->keys())->toBe(['complaint', 'intent', 'urgency'])
        ->and($result->answers->unanswered())->toBe([])
        ->and(count($result->answers))->toBe(3)
        ->and($result['intent']->confidence)->toEqualWithDelta(0.8, 1e-9)
        ->and($result['intent']->votes)->toBe(['refund' => 4, 'exchange' => 0, 'delivery' => 1, 'other' => 0])
        ->and($result['urgency']->level())->toBe('medium')
        ->and($result['complaint']->isTrue())->toBeTrue()
        ->and($result['intent']->applies)->toBeTrue()
        ->and($result['intent']->bucket)->toBe('[0.8, 1.0)')
        ->and($result['intent']->calibration())->toBeNull()
        ->and($result->samples->requested)->toBe(5)
        ->and($result->samples->valid)->toBe(5)
        ->and($result->id)->not->toBeEmpty();
});

it('runs the adaptive sampling example', function () {
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund'];
    });

    $result = Hunch::of('Refund please.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', README_INTENTS))
        ->sampling(adaptive: [3, 9])
        ->classify();

    expect($result->samples->requested)->toBe(3)
        ->and($result->meta->sampling)->toBe('adaptive:3-9');
});

it('throws rather than guessing when too few samples are valid', function () {
    ClassifierAgent::fake(function () {
        return ['intent' => 'not-an-option'];
    });

    try {
        Hunch::of('Refund please.')
            ->using('anthropic', 'm')
            ->question('intent', new Choice('What does the customer want?', README_INTENTS))
            ->sampling(3)
            ->classify();

        $this->fail('The classification should have failed.');
    } catch (ClassificationFailed $e) {
        // The rule's three, plus the two replacements the default cap allows.
        expect($e->samples)->toHaveCount(5)
            ->and($e->validSamples())->toHaveCount(0);
    }
});

it('replaces a malformed sample so sampling(5) still votes on five, up to the cap', function () {
    $call = 0;
    ClassifierAgent::fake(function () use (&$call) {
        return ++$call <= 2 ? ['intent' => 'not-an-option'] : ['intent' => 'refund'];
    });

    $result = Hunch::of('Refund please.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', README_INTENTS))
        ->sampling(5)
        ->classify();

    expect([$result->samples->requested, $result->samples->valid])->toBe([7, 5])
        ->and($result['intent']->votes['refund'])->toBe(5);
});

it('runs the conditional question and tie-break example', function () {
    $dates = ['saturday' => 'Saturday', 'sunday' => 'Sunday'];
    $questions = [
        'intent' => (new Choice('What do they want?', ['booking' => 'To book', 'other' => 'Anything else']))->tieBreak('other'),
        'group_date' => (new Choice('Which date suits?', $dates))->onlyWhen('intent', 'booking'),
    ];
    ClassifierAgent::fake([
        ['intent' => 'booking', 'group_date' => 'saturday'],
        ['intent' => 'booking', 'group_date' => 'saturday'],
        ['intent' => 'other', 'group_date' => 'sunday'],
    ]);

    $result = Hunch::of('Can we book a table for six on Saturday?')
        ->using('anthropic', 'm')
        ->questions($questions)
        ->sampling(3)
        ->classify();

    expect($result['intent']->choice)->toBe('booking')
        ->and($result['group_date']->applies)->toBeTrue()
        ->and($result['group_date']->choice)->toBe('saturday')
        ->and($questions['intent']->tieBreak)->toBe('other');
});

it('runs the reasons, self-report and redaction example', function () {
    ClassifierAgent::fake(function () {
        return [
            'intent' => 'refund',
            'reason' => 'They ask for money back',
            'bands' => ['intent' => 'high'],
        ];
    });

    $result = Hunch::of('Refund please. Call me on 07700 900123.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', README_INTENTS))
        ->sampling(3)
        ->withReasons(120)
        ->withSelfReport()
        ->redactUsing(ContactDetails::class)
        ->classify();

    expect($result->reasons)->toHaveCount(3)
        ->and($result->reasons[0])->toBe('They ask for money back')
        ->and($result->taken[0]->bands)->toBe(['intent' => 'high'])
        ->and($result->meta->stateHash)->toBe(hash('sha256', 'Refund please. Call me on [phone].'));
});

it('runs the testing examples', function () {
    Hunch::fake(['intent' => ['refund' => 0.8, 'other' => 0.2]]);

    $direct = Hunch::of('Refund please.')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->classify();

    expect($direct['intent']->choice)->toBe('refund')
        ->and($direct['intent']->probabilities)->toBe(['refund' => 0.8, 'other' => 0.2]);

    $fake = Hunch::fake()->samples([['intent' => 'refund'], ['intent' => 'other']])->preventStrayClassifications();

    $scripted = Hunch::of('Refund please.')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->classify();

    expect($scripted['intent']->votes)->toBe(['refund' => 1, 'other' => 1]);
    $fake->assertSampledTimes(2);
});

it('documents the question types, options and commands it actually has', function () {
    $readme = (string) file_get_contents(__DIR__.'/../README.md');
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($readme)->toContain('php artisan vendor:publish --tag=hunch-config')
        ->and($readme)->toContain('php artisan vendor:publish --tag=hunch-migrations')
        ->and($readme)->toContain('hunch:calibrate')
        ->and($readme)->toContain('hunch:eval')
        ->and($readme)->toContain('ninthspace/hunch')
        ->and($readme)->toContain(ltrim($composer['require']['php'], '^'))
        // It is not on Packagist, so the README must not imply a plain require.
        ->and($readme)->toContain('not on Packagist')
        // The repository is private and has no tags, so both must be said.
        ->and($readme)->toContain('repository is private')
        ->and($readme)->toContain('github.com:ninthspace/hunch.git')
        ->and($readme)->toContain('dev-main')
        // The database is optional, and the README has to say so plainly.
        ->and($readme)->toContain('no database queries')
        ->and($readme)->toContain('is never')
        ->and($readme)->toContain('state_hash')
        // Installing the package must not be described as bringing migrations.
        ->and($readme)->toContain('Installing Hunch adds no migrations')
        ->and($readme)->toContain('Adding persistence later');

    // Every configuration key must be documented in the reference table.
    $config = require __DIR__.'/../config/hunch.php';
    $keys = [];

    foreach ($config as $key => $value) {
        $keys = [...$keys, ...(is_array($value) && ! array_is_list($value)
            ? array_map(fn (string $inner) => "{$key}.{$inner}", array_keys($value))
            : [$key])];
    }

    // toContain is variadic, so a message would become a second needle: collect instead.
    $undocumented = array_values(array_filter($keys, fn (string $key) => ! str_contains($readme, "`{$key}`")));

    expect($undocumented)->toBe([])
        ->and($keys)->toHaveCount(15);
});

it('runs the Choice example and returns the documented ChoiceAnswer', function () {
    ClassifierAgent::fake([
        ['intent' => 'refund'], ['intent' => 'refund'], ['intent' => 'delivery'],
        ['intent' => 'refund'], ['intent' => 'refund'],
    ]);

    $result = Hunch::of('The jumper arrived with a hole in the sleeve. I would like my money back please.')
        ->context('A support inbox for a clothing shop.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', README_INTENTS))
        ->sampling(5)
        ->classify();

    $intent = $result->answer('intent');

    expect($intent->choice)->toBe('refund')
        ->and($intent->votes)->toBe(['refund' => 4, 'exchange' => 0, 'delivery' => 1, 'other' => 0])
        ->and($intent->probabilities)->toBe(['refund' => 0.8, 'exchange' => 0.0, 'delivery' => 0.2, 'other' => 0.0])
        ->and($intent->probabilityOf('delivery'))->toBe(0.2)
        ->and(fn () => $intent->probabilityOf('nonsense'))->toThrow(InvalidArgumentException::class)
        ->and($intent->confidence)->toBe(0.8)
        ->and(round($intent->entropy, 2))->toBe(0.36)
        ->and($intent->tied)->toBeFalse()
        ->and($intent->unanimous())->toBeFalse()
        ->and($intent->bucket)->toBe('[0.8, 1.0)')
        ->and($intent->applies)->toBeTrue()
        ->and($intent->calibration())->toBeNull();
});

it('runs the Boolean example and returns the documented BooleanAnswer', function () {
    ClassifierAgent::fake([
        ['complaint' => true], ['complaint' => true], ['complaint' => false],
        ['complaint' => true], ['complaint' => true],
    ]);

    $result = Hunch::of('Nobody has answered me in four days about a missing delivery. This is unacceptable.')
        ->context('A support inbox for a clothing shop.')
        ->using('anthropic', 'm')
        ->question('complaint', new Boolean('Is the customer complaining?', [
            'true' => 'They are unhappy with the product or the service',
            'false' => 'They are asking or telling, without complaint',
        ]))
        ->sampling(5)
        ->classify();

    $complaint = $result->answer('complaint');

    expect($complaint->probability)->toBe(0.8)
        ->and($complaint->isTrue())->toBeTrue()
        ->and($complaint->isTrue(0.9))->toBeFalse()
        ->and($complaint->votes)->toBe(['true' => 4, 'false' => 1])
        ->and($complaint->anyTrue())->toBeTrue()
        ->and($complaint->unanimous())->toBeFalse()
        ->and($complaint->bucket)->toBe('[0.8, 1.0)');
});

it('runs the Score example and returns the documented ScoreAnswer', function () {
    ClassifierAgent::fake([
        ['urgency' => 'high'], ['urgency' => 'high'], ['urgency' => 'medium'],
        ['urgency' => 'high'], ['urgency' => 'medium'],
    ]);

    $result = Hunch::of('My order says delivered but there is nothing here. I need it before the wedding on Saturday.')
        ->context('A support inbox for a clothing shop.')
        ->using('anthropic', 'm')
        ->question('urgency', new Score('How urgent is this message?', [
            'low' => 'It can wait a few days',
            'medium' => 'It should be answered today or tomorrow',
            'high' => 'It needs an answer within the hour',
        ]))
        ->sampling(5)
        ->classify();

    $urgency = $result->answer('urgency');

    expect($urgency->score)->toBe(1.6)
        ->and($urgency->level())->toBe('high')
        ->and($urgency->mode)->toBe('high')
        ->and($urgency->votes)->toBe(['low' => 0, 'medium' => 2, 'high' => 3])
        ->and($urgency->probabilities)->toBe(['low' => 0.0, 'medium' => 0.4, 'high' => 0.6])
        ->and($urgency->tied)->toBeFalse()
        ->and($urgency->unanimous())->toBeFalse()
        ->and($urgency->bucket)->toBe('[0.6, 0.8)');
});

it('scores an even split toward the middle, as the README warns', function () {
    ClassifierAgent::fake([['urgency' => 'low'], ['urgency' => 'high']]);
    config()->set('hunch.min_valid_samples', 2);

    $urgency = Hunch::of('Some text.')
        ->using('anthropic', 'm')
        ->question('urgency', new Score('How urgent?', ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High']))
        ->sampling(2)
        ->classify()
        ->answer('urgency');

    expect($urgency->score)->toBe(1.0)
        ->and($urgency->level())->toBe('medium')
        ->and($urgency->votes['medium'])->toBe(0);
});

/**
 * The README's restaurant inbox: a conditional date, and a tie-broken intent.
 *
 * @param  list<array<string, string>>  $samples
 */
function restaurantEnquiry(string $text, array $samples): Result
{
    ClassifierAgent::fake($samples);

    return Hunch::of($text)
        ->context('The inbox of a restaurant.')
        ->questions([
            'intent' => (new Choice('What does the writer want?', [
                'booking' => 'To book a table',
                'other' => 'Anything else',
            ]))->tieBreak('other'),

            'group_date' => (new Choice('Which date do they want?', [
                'saturday' => 'Saturday',
                'sunday' => 'Sunday',
            ]))->onlyWhen('intent', 'booking'),
        ])
        ->using('anthropic', 'm')
        ->sampling(count($samples))
        ->classify();
}

it('documents a conditional answer that applies', function () {
    $result = restaurantEnquiry('Can we book a table for six on Saturday evening?', array_fill(0, 5, [
        'intent' => 'booking', 'group_date' => 'saturday',
    ]));

    expect($result->answer('intent')->choice)->toBe('booking')
        ->and($result->answer('group_date')->choice)->toBe('saturday')
        ->and($result->answer('group_date')->applies)->toBeTrue()
        ->and($result->answer('group_date')->votes)->toBe(['saturday' => 5, 'sunday' => 0])
        ->and($result->answers->applicable()->keys())->toBe(['group_date', 'intent']);
});

it('documents a conditional answer that was counted but does not apply', function () {
    $result = restaurantEnquiry('Do you do gift vouchers? We might book for Saturday later in the year.', [
        ['intent' => 'booking', 'group_date' => 'saturday'],
        ['intent' => 'booking', 'group_date' => 'saturday'],
        ['intent' => 'other', 'group_date' => 'sunday'],
        ['intent' => 'other', 'group_date' => 'sunday'],
        ['intent' => 'other', 'group_date' => 'sunday'],
    ]);

    expect($result->answer('intent')->choice)->toBe('other')
        ->and($result->answer('group_date')->choice)->toBe('saturday')
        ->and($result->answer('group_date')->applies)->toBeFalse()
        ->and($result->answers->applicable()->keys())->toBe(['intent'])
        ->and($result->answers->unanswered())->toBe([]);
});

it('documents a conditional question too few samples qualified to answer', function () {
    $result = restaurantEnquiry('What are your opening hours on a Sunday?', [
        ['intent' => 'booking', 'group_date' => 'saturday'],
        ['intent' => 'other', 'group_date' => 'sunday'],
        ['intent' => 'other', 'group_date' => 'sunday'],
    ]);

    expect($result->answer('intent')->choice)->toBe('other')
        ->and($result->answer('group_date'))->toBeNull()
        ->and($result->answers->unanswered())->toBe(['group_date'])
        ->and($result->answers->has('group_date'))->toBeTrue();
});

it('documents a tie decided by the tie-break', function () {
    $result = restaurantEnquiry('Table for two on Saturday, or do you just do takeaway now?', [
        ['intent' => 'booking', 'group_date' => 'saturday'],
        ['intent' => 'booking', 'group_date' => 'saturday'],
        ['intent' => 'other', 'group_date' => 'sunday'],
        ['intent' => 'other', 'group_date' => 'sunday'],
    ]);

    expect($result->answer('intent')->choice)->toBe('other')
        ->and($result->answer('intent')->votes)->toBe(['booking' => 2, 'other' => 2])
        ->and($result->answer('intent')->probabilities)->toBe(['booking' => 0.5, 'other' => 0.5])
        ->and($result->answer('intent')->tied)->toBeTrue();
});

it('documents every event it dispatches, and the payload fields it names', function () {
    $readme = (string) file_get_contents(__DIR__.'/../README.md');
    $undocumented = [];

    foreach (glob(__DIR__.'/../src/Events/*.php') ?: [] as $file) {
        $name = basename($file, '.php');

        // ClassificationEvent is the shared base, not something dispatched.
        if ($name !== 'ClassificationEvent' && ! str_contains($readme, "`{$name}`")) {
            $undocumented[] = $name;
        }
    }

    // The listener example reads these off SampleInvalid, so they must exist.
    $fields = ['classificationId', 'questionSetHash', 'stateHash', 'sampleNumber', 'problem', 'requestId'];
    $missing = array_values(array_filter(
        $fields,
        fn (string $field) => ! property_exists(SampleInvalid::class, $field),
    ));

    expect($undocumented)->toBe([])
        ->and($missing)->toBe([])
        ->and($readme)->toContain('PromptingAgent');
});
