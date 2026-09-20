<?php

namespace Ninthspace\Hunch\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Ninthspace\Hunch\Support\Settings;

/**
 * One recorded classification. It holds the hash of the state, never the
 * state. Older than `hunch.retention_days` it is pruned with its samples,
 * unless it has been labelled.
 *
 * @property string $id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string $state_hash
 * @property string $question_set_hash
 * @property string|null $version
 * @property string $driver
 * @property string $provider
 * @property string|null $model_requested
 * @property string|null $model_reported
 * @property string $sampling
 * @property string $status
 * @property string|null $error
 * @property int $samples_requested
 * @property int $samples_valid
 * @property int $samples_invalid
 * @property array<string, int> $usage
 * @property array<string, array<string, mixed>|null>|null $answers
 * @property Carbon $created_at
 */
class Classification extends Model
{
    use HasUlids;
    use Prunable;

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    protected $table = 'hunch_classifications';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'usage' => 'array',
            'answers' => 'array',
        ];
    }

    /**
     * @return HasMany<StoredSample, $this>
     */
    public function samples(): HasMany
    {
        return $this->hasMany(StoredSample::class, 'classification_id');
    }

    /**
     * @return HasMany<Label, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class, 'classification_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Unlabelled classifications older than the retention period. With no
     * positive `retention_days` set, nothing is prunable.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = Settings::int('retention_days', 0);

        return static::query()
            ->when($days < 1, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('created_at', '<', now()->subDays($days))
            ->whereDoesntHave('labels');
    }

    protected function pruning(): void
    {
        $this->samples()->delete();
    }
}
