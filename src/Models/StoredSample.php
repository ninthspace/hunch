<?php

namespace Ninthspace\Hunch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded sample of a classification. `reason` is filled only when
 * reasons were requested.
 *
 * @property int $id
 * @property string $classification_id
 * @property int $number
 * @property bool $valid
 * @property array<string, bool|string>|null $answers
 * @property string|null $invalid_reason
 * @property string|null $reason
 * @property array<string, string>|null $band
 * @property string|null $seed
 * @property string|null $request_id
 * @property string|null $model
 * @property array<string, int> $usage
 */
class StoredSample extends Model
{
    protected $table = 'hunch_samples';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid' => 'boolean',
            'answers' => 'array',
            'band' => 'array',
            'usage' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'classification_id');
    }
}
