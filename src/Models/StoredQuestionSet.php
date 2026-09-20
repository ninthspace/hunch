<?php

namespace Ninthspace\Hunch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A question set as it was asked, identified by its hash.
 *
 * @property int $id
 * @property string $hash
 * @property string|null $version
 * @property string $canonical
 * @property array{conditions: array<string, array{string, list<string>}>, tie_breaks: array<string, string>}|null $definition
 */
class StoredQuestionSet extends Model
{
    protected $table = 'hunch_question_sets';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['definition' => 'array'];
    }
}
