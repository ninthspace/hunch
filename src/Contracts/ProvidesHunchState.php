<?php

namespace Ninthspace\Hunch\Contracts;

/**
 * A model that can give back the text a classification was made from.
 * `hunch:eval` re-classifies labelled items by asking their subject for it,
 * because Hunch never stores the state itself.
 */
interface ProvidesHunchState
{
    /**
     * @return string|array<string, string>
     */
    public function hunchState(): string|array;
}
