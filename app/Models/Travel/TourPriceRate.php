<?php

declare(strict_types=1);

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $variant_id
 * @property string $traveler_type
 * @property int $regular_minor
 * @property int|null $sale_minor
 * @property Carbon|null $sale_starts_at
 * @property Carbon|null $sale_ends_at
 */
final class TourPriceRate extends Model
{
    protected $table = 'travel_tour_price_rates';

    /** @var list<string> */
    protected $fillable = [
        'variant_id', 'traveler_type', 'regular_minor', 'sale_minor',
        'sale_starts_at', 'sale_ends_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'variant_id' => 'integer',
            'regular_minor' => 'integer',
            'sale_minor' => 'integer',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TourPriceVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(TourPriceVariant::class, 'variant_id');
    }
}
