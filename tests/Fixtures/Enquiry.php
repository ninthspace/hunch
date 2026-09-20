<?php

namespace Ninthspace\Hunch\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Ninthspace\Hunch\Contracts\ProvidesHunchState;

/**
 * An application model a classification is recorded against. It can give its
 * text back, so `hunch:eval` can re-classify it.
 */
class Enquiry extends Model implements ProvidesHunchState
{
    protected $table = 'enquiries';

    protected $guarded = [];

    public function hunchState(): string
    {
        return (string) $this->body;
    }
}
