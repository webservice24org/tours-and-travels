<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Models\Travel\Tour;
use App\Models\Travel\TourMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Tour-scoped public promotional images. Re-encoding removes EXIF and metadata. */
final class TourMediaService
{
    public function __construct(private readonly CmsMutationAudit $audit)
    {
    }

    public function upload(Tour $tour, User $actor, UploadedFile $file, bool $featured): TourMedia
    {
        $realPath = $file->getRealPath();
        $bytes = is_string($realPath) ? file_get_contents($realPath) : false;
        if (!is_string($bytes) || strlen($bytes) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['image' => 'Image must be at most 5 MB.']);
        }

        $info = @getimagesizefromstring($bytes);

        if (
            $info === false ||
            $info[0] < 1 ||
            $info[1] < 1 ||
            $info[0] > 4000 ||
            $info[1] > 4000 ||
            ($info[0] * $info[1]) > 12000000
        ) {
            throw ValidationException::withMessages([
                'image' => 'Image dimensions must be within 4,000 pixels and 12 megapixels.',
            ]);
        }

        $mime = (string) $info['mime'];
        $ext = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            default => null,
        };
        if ($ext === null || !extension_loaded('gd')) {
            throw ValidationException::withMessages(['image' => 'Only JPEG, PNG, or WebP images supported by PHP GD are allowed.']);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw ValidationException::withMessages(['image' => 'Could not decode this image.']);
        }
        $path = 'tours/' . (int) $tour->getKey() . '/' . (string) Str::uuid() . '.' . $ext;
        ob_start();
        try {
            imagesavealpha($image, true);
            $encoded = match ($mime) {
                'image/jpeg' => imagejpeg($image, null, 85),
                'image/png' => imagepng($image, null, 7),
                default => imagewebp($image, null, 85),
            };
            $clean = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (!$encoded || !is_string($clean) || $clean === '') {
            throw ValidationException::withMessages(['image' => 'Unable to process image.']);
        }
        if (strlen($clean) > 8 * 1024 * 1024) {
            throw ValidationException::withMessages(['image' => 'Processed image is too large.']);
        }
        $disk = Storage::disk('public');
        if (!$disk->put($path, $clean)) {
            throw ValidationException::withMessages(['image' => 'Unable to store image.']);
        }
        try {
            return DB::transaction(function () use ($tour, $actor, $info, $mime, $path, $clean, $featured): TourMedia {
                $locked = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();
                $this->assertActorMayModify($actor, $locked);
                $count = TourMedia::query()->where('tour_id', $locked->getKey())->count();
                if ($count >= 24) {
                    throw ValidationException::withMessages(['image' => 'A tour may contain at most 24 images.']);
                }
                $makeFeatured = $featured || $count === 0;
                if ($makeFeatured) {
                    TourMedia::query()->where('tour_id', $locked->getKey())->update(['is_featured' => false]);
                }
                $media = TourMedia::query()->create([
                    'tour_id' => (int) $locked->getKey(),
                    'disk' => 'public',
                    'path' => $path,
                    'mime_type' => $mime,
                    'file_size' => strlen($clean),
                    'width' => (int) $info[0],
                    'height' => (int) $info[1],
                    'alt_text' => null,
                    'sort_order' => $count,
                    'is_featured' => $makeFeatured,
                    'uploaded_by' => (int) $actor->getKey(),
                ]);
                $this->audit->record('tour.media.uploaded', $actor, 'tour', $locked, [
                    'media_id' => (int) $media->getKey(),
                    'featured' => $makeFeatured,
                ]);
                return $media;
            }, 3);
        } catch (\Throwable $error) {
            $disk->delete($path);
            throw $error;
        }
    }

    /** @param array<string, mixed> $data */
    public function update(Tour $tour, TourMedia $media, User $actor, array $data): TourMedia
    {
        return DB::transaction(function () use ($tour, $media, $actor, $data): TourMedia {
            $locked = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();
            $this->assertActorMayModify($actor, $locked);
            $item = TourMedia::query()->where('tour_id', $locked->getKey())->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            if (!empty($data['is_featured'])) {
                TourMedia::query()->where('tour_id', $locked->getKey())->where('id', '!=', $item->getKey())
                    ->update(['is_featured' => false]);
                $item->is_featured = true;
            }
            if (array_key_exists('alt_text', $data))
                $item->alt_text = $data['alt_text'];
            if (array_key_exists('sort_order', $data))
                $item->sort_order = $data['sort_order'];
            $item->save();
            $this->audit->record('tour.media.updated', $actor, 'tour', $locked, ['media_id' => (int) $item->getKey()]);
            return $item->refresh();
        }, 3);
    }

    private function assertActorMayModify(User $actor, Tour $tour): void
    {
        abort_unless(Gate::forUser($actor)->allows('tours.edit'), 403);
        if (in_array($tour->status->value, ['published', 'scheduled'], true)) {
            abort_unless(Gate::forUser($actor)->allows('tours.publish'), 403);
        }
    }

    public function delete(Tour $tour, TourMedia $media, User $actor): void
    {
        $path = DB::transaction(function () use ($tour, $media, $actor): string {
            $locked = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();
            $this->assertActorMayModify($actor, $locked);
            $item = TourMedia::query()->where('tour_id', $locked->getKey())->whereKey($media->getKey())->lockForUpdate()->firstOrFail();
            if (str_contains((string) $locked->description, '/storage/' . $item->path)) {
                throw ValidationException::withMessages(['image' => 'Remove this image from the tour description and save before deleting.']);
            }
            $wasFeatured = (bool) $item->is_featured;
            $path = $item->path;
            $item->delete();
            if ($wasFeatured) {
                $next = TourMedia::query()->where('tour_id', $locked->getKey())->orderBy('sort_order')->orderBy('id')->first();
                if ($next instanceof TourMedia) {
                    $next->is_featured = true;
                    $next->save();
                }
            }
            $this->audit->record('tour.media.deleted', $actor, 'tour', $locked, ['media_id' => (int) $media->getKey()]);
            return $path;
        }, 3);
        // Filesystem cleanup after database commit; reconcile orphans during general media QA.
        Storage::disk('public')->delete($path);
    }
}
