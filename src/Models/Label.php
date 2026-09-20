<?php

namespace Ninthspace\Hunch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * A human-confirmed answer to one question of a classification. A later
 * label for the same question supersedes it; superseded labels are kept.
 *
 * @property int $id
 * @property string $classification_id
 * @property string $question
 * @property bool|string $answer
 * @property string|null $labelled_by_type
 * @property string|null $labelled_by_id
 * @property Carbon|null $superseded_at
 */
class Label extends Model
{
    protected $table = 'hunch_labels';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'answer' => 'json',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'classification_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function labelledBy(): MorphTo
    {
        return $this->morphTo('labelled_by');
    }
}
