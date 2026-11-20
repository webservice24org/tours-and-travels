<?php

declare(strict_types=1);

namespace App\Models\Travel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tour_id
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $file_size
 * @property int $width
 * @property int $height
 * @property string|null $alt_text
 * @property int $sort_order
 * @property bool $is_featured
 */
final class TourMedia extends Model
{
    protected $table = 'travel_tour_media';

    /** @var list<string> */
    protected $fillable = [
        'tour_id', 'disk', 'path', 'mime_type', 'file_size', 'width', 'height',
        'alt_text', 'sort_order', 'is_featured', 'uploaded_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_featured' => 'boolean', 'sort_order' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    /** @return BelongsTo<Tour, $this> */
    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class, 'tour_id');
    }
}
