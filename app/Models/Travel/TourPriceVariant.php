<?php

declare(strict_types=1);

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tour_id
 * @property string $code
 * @property string $name
 * @property string $currency
 * @property string $pricing_model
 * @property int $min_travelers
 * @property int|null $max_travelers
 * @property bool $is_active
 * @property int $sort_order
 */
final class TourPriceVariant extends Model
{
    protected $table = 'travel_tour_price_variants';

    /** @var list<string> */
    protected $fillable = [
        'tour_id', 'code', 'name', 'currency', 'pricing_model',
        'min_travelers', 'max_travelers', 'is_active', 'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tour_id' => 'integer',
            'min_travelers' => 'integer',
            'max_travelers' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Tour, $this> */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class, 'tour_id');
    }

    /** @return HasMany<TourPriceRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(TourPriceRate::class, 'variant_id');
    }
}
