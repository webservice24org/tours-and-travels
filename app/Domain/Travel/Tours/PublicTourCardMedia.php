<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Models\Travel\TourMedia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Filesystem\FilesystemAdapter;
use Throwable;

/**
 * Read-side public media allowlist for *already published* tour page results.
 * Only configured PUBLIC disk files may become browser URLs. No raw storage
 * paths, uploader IDs, disk metadata, or other media records enter Inertia.
 *
 * This class does not determine tour publication: the caller MUST first use
 * Tour::publiclyAvailable(). Avoid using it for private/admin detail pages.
 */
final class PublicTourCardMedia
{
    private const MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
    ];

    /**
     * Bounded page batch: no per-card media SELECT queries.
     *
     * @param array<int, string> $tourTitles id => public title, up to 12 items
     * @return array<int, array{src:string,alt:string,width:int,height:int}>
     */
    public function coversFor(array $tourTitles): array
    {
        if ($tourTitles === [] || config('filesystems.disks.public.visibility') !== 'public') {
            return [];
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('public');

        $covers = [];
        // The first accessible featured/sorted image is used. The cap prevents
        // an accidental unbounded scan if a tour receives thousands of images.
        $media = TourMedia::query()
            ->whereIn('tour_id', array_keys($tourTitles))
            ->where('disk', 'public')
            ->whereIn('mime_type', array_keys(self::MIME_EXTENSIONS))
            ->orderBy('tour_id')
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(480)
            ->get(['tour_id', 'path', 'mime_type', 'file_size', 'width', 'height', 'alt_text']);

        foreach ($media as $item) {
            $tourId = (int) $item->tour_id;
            if (isset($covers[$tourId]) || !isset($tourTitles[$tourId])) {
                continue;
            }
            $path = (string) $item->path;
            if (
                !$this->safeImagePath($path, (string) $item->mime_type)
                || (int) $item->file_size <= 0
                || (int) $item->width <= 0
                || (int) $item->height <= 0
            ) {
                continue;
            }

            try {
                if (!$disk->exists($path)) {
                    continue;
                }
                $src = $disk->url($path);
            } catch (Throwable) {
                // An unavailable public storage object is simply a missing card
                // photograph; it must not make public catalog browsing fail.
                continue;
            }
            if (!$this->safePublicUrl($src)) {
                continue;
            }

            $alt = trim(strip_tags((string) ($item->alt_text ?? '')));
            if ($alt === '') {
                $alt = $tourTitles[$tourId] . ' tour photograph';
            }

            $covers[$tourId] = [
                'src' => $src,
                'alt' => $alt,
                'width' => (int) $item->width,
                'height' => (int) $item->height,
            ];
        }

        return $covers;
    }

    private function safeImagePath(string $path, string $mime): bool
    {
        $allowed = self::MIME_EXTENSIONS[$mime] ?? null;
        if (
            $allowed === null || $path === '' || strlen($path) > 255
            || preg_match('~\A[a-zA-Z0-9._/-]+\z~D', $path) !== 1
        ) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $allowed, true);
    }

    private function safePublicUrl(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return !str_starts_with($url, '//');
        }
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
