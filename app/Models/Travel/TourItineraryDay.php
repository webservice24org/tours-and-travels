<?php

declare(strict_types=1);

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single day of a tour, ordered by day_number within that tour.
 * Text fields are intentionally plain text in P3E.1, not executable HTML.
 *
 * @property int $id
 * @property int $tour_id
 * @property int $day_number
 * @property string $title
 * @property string|null $location_label
 * @property string|null $description
 * @property string|null $overnight_location
 * @property list<string> $meals
 * @property list<string> $highlights
 */
final class TourItineraryDay extends Model
{
    protected $table = 'travel_tour_itinerary_days';

    /** @var list<string> */
    protected $fillable = [
        'tour_id', 'day_number', 'title', 'location_label',
        'description', 'overnight_location', 'meals', 'highlights',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tour_id' => 'integer',
            'day_number' => 'integer',
            'meals' => 'array',
            'highlights' => 'array',
        ];
    }

    /** @return BelongsTo<Tour, $this> */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class, 'tour_id');
    }
}
