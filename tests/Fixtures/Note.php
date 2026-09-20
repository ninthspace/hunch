<?php

namespace Ninthspace\Hunch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A subject that cannot give its state back, so `hunch:eval` skips it.
 */
class Note extends Model
{
    protected $table = 'notes';

    protected $guarded = [];
}
