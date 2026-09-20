<?php

namespace Ninthspace\Hunch\Persistence;

use Closure;
use Illuminate\Database\QueryException;
use Ninthspace\Hunch\Exceptions\ConfigurationException;

/**
 * Turns "no such table" into a usable error. Persistence is a configuration
 * switch and the migrations are published separately, so an application can
 * have one without the other. When it does, this says which step is missing
 * instead of surfacing a driver-specific SQL error.
 */
final class MissingTables
{
    private const ADVICE = 'Hunch cannot find its tables. `hunch.persistence` is on, so publish and run the migrations: php artisan vendor:publish --tag=hunch-migrations && php artisan migrate. Turn `hunch.persistence` off to run without a database.';

    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public static function guard(Closure $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            throw self::missing($e) ? new ConfigurationException(self::ADVICE, previous: $e) : $e;
        }
    }

    /**
     * Whether this query failed because a Hunch table is not there, in any of
     * the wordings the database drivers use for it.
     */
    private static function missing(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        if (! str_contains($message, 'hunch_')) {
            return false;
        }

        foreach (['no such table', 'base table or view not found', 'does not exist', 'invalid object name', 'undefined table'] as $wording) {
            if (str_contains($message, $wording)) {
                return true;
            }
        }

        return false;
    }
}
