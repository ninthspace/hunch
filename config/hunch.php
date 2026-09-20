<?php

use Ninthspace\Hunch\Agents\ClassifierAgent;

// config for Ninthspace/Hunch
return [

    // `sampling` for the sampling driver. The TypeSafe driver is chosen by
    // the provider, not here: `using('typesafe', ...)` selects it.
    'driver' => 'sampling',

    // Required when `using()` isn't called on the builder.
    'provider' => null,
    'model' => null,

    // `fixed:N` takes N samples; `adaptive:MIN-MAX` stops early once the
    // answers are settled.
    'sampling' => 'fixed:5',

    // A classification with fewer valid samples than this fails.
    'min_valid_samples' => 3,

    // Extra samples taken to replace ones whose output failed the schema, so
    // `sampling` counts valid samples. At worst a classification makes the
    // rule's own count plus this many calls. Set it to 0 to replace nothing.
    'max_resamples' => 2,

    // Seconds per model call.
    'timeout' => 20,

    // Retries on rate-limit and overload errors, against the same provider.
    'retries' => 2,

    // Whether the SDK may fail over to another provider.
    'failover' => false,

    'agent' => ClassifierAgent::class,

    'choice' => [
        'max_options' => 50,
    ],

    // Probability bucket edges, highest first. An edge of 1.0 is a bucket of
    // its own; the rest run [edge, next edge up), and below the last is [0, edge).
    'buckets' => [1.0, 0.8, 0.6],

    'calibration' => [
        // Labelled classifications needed before calibration() reports a cell.
        'min_n' => 30,
    ],

    // Record classifications to the database. Needs the published migrations.
    'persistence' => false,

    // Required when persistence is on: recorded rows older than this are
    // pruned, except labelled ones. `model:prune` finds only app models, so
    // schedule it with Hunch's model named:
    // model:prune --model="Ninthspace\Hunch\Models\Classification"
    'retention_days' => null,

];
