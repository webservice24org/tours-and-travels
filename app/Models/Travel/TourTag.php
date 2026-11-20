<?php

declare(strict_types=1);

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $name_key
 */
final class TourTag extends Model
{
    protected $table = 'travel_tour_tags';

    /** @var list<string> */
    protected $fillable = ['name', 'name_key'];

    /** @return BelongsToMany<Tour, $this> */
    public function tours(): BelongsToMany
    {
        return $this->belongsToMany(Tour::class, 'travel_tour_tag', 'tag_id', 'tour_id')
            ->withPivot('sort_order');
    }
}
