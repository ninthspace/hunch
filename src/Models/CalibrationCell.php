<?php

namespace Ninthspace\Hunch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Measured accuracy for one calibration cell: question set hash, driver,
 * provider, model, sampling rule, question, answer and bucket.
 *
 * @property int $id
 * @property string $question_set_hash
 * @property string $driver
 * @property string $provider
 * @property string $model
 * @property string $sampling
 * @property string $question
 * @property string $answer
 * @property string $bucket
 * @property int $n
 * @property int $correct
 * @property float $accuracy
 * @property float $lower_bound
 * @property Carbon $computed_at
 */
class CalibrationCell extends Model
{
    protected $table = 'hunch_calibration';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accuracy' => 'float',
            'lower_bound' => 'float',
            'computed_at' => 'datetime',
        ];
    }
}
