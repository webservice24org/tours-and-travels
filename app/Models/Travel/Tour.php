<?php

declare(strict_types=1);

namespace App\Models\Travel;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Canonical tour record. Prices, itinerary days and departures arrive in P3E;
 * builder templates may only reference published records via PublishedTourCatalog.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $summary
 * @property PublicationStatus $status
 * @property int $duration_days
 * @property int|null $duration_nights
 * @property bool $is_featured
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $scheduled_for
 * @property int|null $created_by
 * @property int|null $updated_by
 */
final class Tour extends Model
{
    use SoftDeletes;

    protected $table = 'travel_tours';

    /** @var list<string> */
    protected $fillable = [
        'title', 'slug', 'summary', 'description', 'status',
        'duration_days', 'duration_nights', 'is_featured', 'sort_order',
        'scheduled_for', 'published_at', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'duration_days' => 'integer',
            'duration_nights' => 'integer',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** @return BelongsToMany<TourCategory, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            TourCategory::class,
            'travel_tour_category',
            'tour_id',
            'category_id'
        )->withPivot('sort_order')->orderByPivot('sort_order')->orderByPivot('category_id');
    }

    /** @return BelongsToMany<Destination, $this> */
    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(
            Destination::class,
            'travel_tour_destination',
            'tour_id',
            'destination_id'
        )->withPivot('sort_order')->orderByPivot('sort_order')->orderByPivot('destination_id');
    }

    /** @return BelongsToMany<TourTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TourTag::class, 'travel_tour_tag', 'tour_id', 'tag_id')
            ->withPivot('sort_order');
    }

    /** @return HasMany<TourMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(TourMedia::class, 'tour_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Public selector: never trust the `status` flag alone; a publication must
     * have begun and at least one currently published category and destination.
     *
     * @param Builder<Tour> $query
     * @return Builder<Tour>
     */
    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query
            ->where('status', PublicationStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereHas('categories', static function (Builder $categories): void {
                $categories->where('status', PublicationStatus::Published->value);
            })
            ->whereHas('destinations', static function (Builder $destinations): void {
                $destinations->where('status', PublicationStatus::Published->value);
            });
    }

    /**
     * @param Builder<Tour> $query
     * @return Builder<Tour>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
