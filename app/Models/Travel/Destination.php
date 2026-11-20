<?php

declare(strict_types=1);

namespace App\Models\Travel;

use App\Domain\Cms\Enums\PublicationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $country_code
 * @property string|null $region_name
 * @property string|null $city_name
 * @property string|null $summary
 * @property PublicationStatus $status
 * @property int $sort_order
 */
final class Destination extends Model
{
    use SoftDeletes;

    protected $table = 'travel_destinations';

    /** @var list<string> */
    protected $fillable = [
        'name', 'slug', 'country_code', 'region_name', 'city_name',
        'summary', 'description', 'latitude', 'longitude', 'status', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'status' => PublicationStatus::class,
        ];
    }

    /**
     * @param Builder<Destination> $query
     * @return Builder<Destination>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PublicationStatus::Published->value);
    }

    /**
     * @param Builder<Destination> $query
     * @return Builder<Destination>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
