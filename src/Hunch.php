<?php

namespace Ninthspace\Hunch;

use Illuminate\Support\Facades\Facade;
use Ninthspace\Hunch\Testing\HunchFake;

/**
 * @method static PendingClassification of(string|array<string, mixed> $state)
 * @method static list<\Ninthspace\Hunch\Models\Label> label(string $classificationId, array<string, bool|string> $answers, ?\Illuminate\Database\Eloquent\Model $by = null)
 *
 * @see HunchManager
 */
class Hunch extends Facade
{
    /**
     * Replace Hunch with a fake that answers from `$answers` (question key =>
     * probability of true, or option => share) without calling a model.
     *
     * @param  array<string, float|array<string, float>>  $answers
     */
    public static function fake(array $answers = []): HunchFake
    {
        static::swap($fake = new HunchFake($answers));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return HunchManager::class;
    }
}
