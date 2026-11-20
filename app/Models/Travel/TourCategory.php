<?php

declare(strict_types=1);

namespace App\Models\Travel;

use App\Domain\Cms\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $summary
 * @property PublicationStatus $status
 * @property int $sort_order
 */
final class TourCategory extends Model
{
    use SoftDeletes;

    protected $table = 'travel_tour_categories';

    /** @var list<string> */
    protected $fillable = [
        'parent_id', 'name', 'slug', 'summary', 'description', 'status', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'sort_order' => 'integer',
            'status' => PublicationStatus::class,
        ];
    }

    /** @return BelongsTo<TourCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<TourCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Only genuinely published categories, excluding soft-deleted rows.
     * @param Builder<TourCategory> $query
     * @return Builder<TourCategory>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublicationStatus::Published->value);
    }

    /**
     * @param Builder<TourCategory> $query
     * @return Builder<TourCategory>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
