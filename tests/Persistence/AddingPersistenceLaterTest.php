<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ninthspace\Hunch\Agents\ClassifierAgent;
use Ninthspace\Hunch\Exceptions\ConfigurationException;
use Ninthspace\Hunch\Hunch;
use Ninthspace\Hunch\HunchServiceProvider;
use Ninthspace\Hunch\Models\Classification;
use Ninthspace\Hunch\PendingClassification;
use Ninthspace\Hunch\Persistence\MissingTables;
use Ninthspace\Hunch\Questions\Choice;
use Ninthspace\Hunch\Tests\Fixtures\Enquiry;

/*
 * An application that installed Hunch without persistence, and later wants it.
 * Turning the switch on is not enough: the migrations are published, not
 * loaded, so until they are run Hunch says which step is missing.
 */

function persistenceOnWithoutTables(): Enquiry
{
    config()->set('hunch.persistence', true);
    config()->set('hunch.retention_days', 30);
    app()->register(HunchServiceProvider::class, force: true);

    Schema::create('enquiries', function (Blueprint $table) {
        $table->id();
        $table->text('body');
        $table->timestamps();
    });

    ClassifierAgent::fake(function () {
        return ['intent' => 'refund'];
    });

    return Enquiry::query()->create(['body' => 'Refund please.']);
}

function asking(Enquiry $enquiry): PendingClassification
{
    return Hunch::of('Refund please.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->sampling(3)
        ->record($enquiry);
}

it('classifies as normal while persistence is off, with no tables in sight', function () {
    config()->set('hunch.persistence', false);
    app()->register(HunchServiceProvider::class, force: true);
    ClassifierAgent::fake(function () {
        return ['intent' => 'refund'];
    });

    $result = Hunch::of('Refund please.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->sampling(3)
        ->classify();

    expect($result['intent']->choice)->toBe('refund')
        ->and($result['intent']->calibration())->toBeNull()
        ->and(Schema::hasTable('hunch_classifications'))->toBeFalse();
});

it('says which step is missing when persistence is switched on before migrating', function () {
    $enquiry = persistenceOnWithoutTables();

    try {
        asking($enquiry)->classify();

        $this->fail('Recording should have been refused.');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain('vendor:publish --tag=hunch-migrations')
            ->and($e->getMessage())->toContain('php artisan migrate')
            ->and($e->getPrevious())->toBeInstanceOf(QueryException::class);
    }
});

it('says the same for labelling, calibration lookups and hunch:calibrate', function () {
    persistenceOnWithoutTables();

    expect(fn () => Hunch::label('01J0000000000000000000FAKE', ['intent' => 'refund']))
        ->toThrow(ConfigurationException::class, 'vendor:publish --tag=hunch-migrations');

    $result = Hunch::of('Refund please.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->sampling(3)
        ->classify();

    expect(fn () => $result['intent']->calibration())
        ->toThrow(ConfigurationException::class, 'vendor:publish --tag=hunch-migrations');

    Artisan::call('hunch:calibrate');

    expect(Artisan::output())->toContain('vendor:publish --tag=hunch-migrations');
});

it('records from the moment the migrations are run, and leaves earlier classifications behind', function () {
    $enquiry = persistenceOnWithoutTables();

    // Before: classifying still works, it just cannot be recorded.
    $before = Hunch::of('Refund please.')
        ->using('anthropic', 'm')
        ->question('intent', new Choice('What does the customer want?', ['refund' => 'Money back', 'other' => 'Anything else']))
        ->sampling(3)
        ->classify();

    // The upgrade: publish and migrate. Nothing else changes.
    Artisan::call('migrate', ['--path' => dirname(__DIR__, 2).'/database/migrations', '--realpath' => true]);

    $after = asking($enquiry)->classify();

    expect(Classification::query()->pluck('id')->all())->toBe([$after->id])
        ->and(Classification::query()->find($before->id))->toBeNull()
        ->and($before['intent']->choice)->toBe('refund');
});

it('passes through a database error that is not about a Hunch table', function () {
    persistenceOnWithoutTables();

    // An application's own missing table is the application's problem, and a
    // broken query is not a migration problem at all.
    expect(fn () => MissingTables::guard(fn () => DB::table('orders_that_do_not_exist')->count()))
        ->toThrow(QueryException::class)
        ->and(fn () => MissingTables::guard(fn () => DB::select('select * from enquiries where nonsense_column = 1')))
        ->toThrow(QueryException::class);

    try {
        MissingTables::guard(fn () => DB::table('orders_that_do_not_exist')->count());
    } catch (QueryException $e) {
        expect($e->getMessage())->not->toContain('vendor:publish');
    }
});
