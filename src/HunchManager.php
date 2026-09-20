<?php

namespace Ninthspace\Hunch;

use Illuminate\Database\Eloquent\Model;
use Ninthspace\Hunch\Models\Label;
use Ninthspace\Hunch\Persistence\Labeller;

/**
 * The service behind the `Hunch` facade: where every classification starts.
 */
class HunchManager
{
    /**
     * @param  string|array<string, mixed>  $state  The text to classify, or named sections of it.
     */
    public function of(string|array $state): PendingClassification
    {
        return new PendingClassification($state);
    }

    /**
     * Record human-confirmed answers for a recorded classification. A later
     * label for the same question supersedes the earlier one; both are kept.
     *
     * @param  array<string, bool|string>  $answers  Question key => the confirmed answer.
     * @return list<Label>
     */
    public function label(string $classificationId, array $answers, ?Model $by = null): array
    {
        return Labeller::label($classificationId, $answers, $by);
    }
}
