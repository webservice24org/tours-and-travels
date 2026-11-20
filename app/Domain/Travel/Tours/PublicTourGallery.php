<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Models\Travel\TourMedia;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * P3F.3B.1: public, read-only image gallery for one ALREADY PUBLISHED tour.
 * Caller must enforce Tour::publiclyAvailable() before invoking this method.
 * The media table has no publication flag. Public disk + image type + safe
 * path + object existence are therefore mandatory checks, not suggestions.
 */
final class PublicTourGallery
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
    ];

    private const MAX_IMAGES = 12;
    private const MAX_ROWS_TO_SCAN = 160;

    /**
     * @return list<array{src:string,alt:string,width:int,height:int}>
     */
    public function forPublishedTour(int $tourId, string $title): array
    {
        if ($tourId <= 0 || config('filesystems.disks.public.visibility') !== 'public') {
            return [];
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        $rows = TourMedia::query()
            ->where('tour_id', $tourId)
            ->where('disk', 'public')
            ->whereIn('mime_type', array_keys(self::MIME_EXTENSIONS))
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(self::MAX_ROWS_TO_SCAN)
            ->get(['path', 'mime_type', 'file_size', 'width', 'height', 'alt_text']);

        $images = [];
        foreach ($rows as $row) {
            $path = (string) $row->path;
            if (! $this->safeImagePath($path, (string) $row->mime_type)
                || (int) $row->file_size <= 0
                || (int) $row->width <= 0
                || (int) $row->height <= 0) {
                continue;
            }

            try {
                if (! $disk->exists($path)) {
                    continue;
                }
                $url = $disk->url($path);
            } catch (Throwable) {
                // A missing/broken public image should never break the tour page.
                continue;
            }
            if (! $this->safePublicUrl($url)) {
                continue;
            }

            $alt = trim(strip_tags((string) ($row->alt_text ?? '')));
            if ($alt === '') {
                $alt = $title.' tour photograph';
            }

            $images[] = [
                'src' => $url,
                'alt' => $alt,
                'width' => (int) $row->width,
                'height' => (int) $row->height,
            ];
            if (count($images) >= self::MAX_IMAGES) {
                break;
            }
        }

        return $images;
    }

    private function safeImagePath(string $path, string $mime): bool
    {
        $extensions = self::MIME_EXTENSIONS[$mime] ?? null;
        if ($extensions === null || $path === '' || strlen($path) > 255
            || preg_match('~\A[a-zA-Z0-9._/-]+\z~D', $path) !== 1) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true);
    }

    private function safePublicUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
