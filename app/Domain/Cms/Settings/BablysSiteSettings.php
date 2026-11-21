<?php

declare(strict_types=1);

namespace App\Domain\Cms\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;

/**
 * Versioned site-wide editorial settings. P4A.1 saves drafts only.
 * Publication/rollback are intentionally reserved for P4A.3.
 * These values do NOT control tour prices, booking rules or private keys.
 */
final class BablysSiteSettings
{
    /** @return array<string, array<string, string>> */
    public function defaults(): array
    {
        return [
            'branding' => [
                'site_name' => "Bably's Club",
                'tagline' => 'Tours & Travels',
                'legal_name' => '',
            ],
            'contact' => [
                'email' => '',
                'phone' => '',
                'whatsapp' => '',
                'office_address' => '',
                'business_hours' => '',
            ],
            'social' => [
                'facebook' => '',
                'instagram' => '',
                'youtube' => '',
                'linkedin' => '',
            ],
            'legal' => [
                'copyright_notice' => '',
                'privacy_url' => '',
                'terms_url' => '',
            ],
        ];
    }

    /** @return array{draft: array<string, array<string, string>>, published: array<string, array<string, string>>, draft_version: int, published_version: int, draft_updated_at: ?string} */
    public function editorSnapshot(): array
    {
        $row = DB::table('bablys_site_settings')->where('scope', 'global')->first();
        return [
            'draft' => $this->decode($row?->draft_payload),
            'published' => $this->decode($row?->published_payload),
            'draft_version' => (int) ($row->draft_version ?? 0),
            'published_version' => (int) ($row->published_version ?? 0),
            'draft_updated_at' => $row === null || $row->updated_at === null ? null : (string) $row->updated_at,
        ];
    }

    /** @return array<string, array<string, string>> */
    public function publicSnapshot(): array
    {
        $row = DB::table('bablys_site_settings')->where('scope', 'global')->first(['published_payload']);
        return $this->decode($row?->published_payload);
    }

    /**
     * @param array<string, mixed> $validated
     * @return array{draft_version: int}
     */
    public function saveDraft(array $validated, int $actorId): array
    {
        $expected = (int) $validated['expected_version'];
        $payload = $this->normalize($validated);
        return DB::transaction(function () use ($expected, $payload, $actorId): array {
            // Insert-ignore avoids duplicate singleton rows when two staff users open
            // the first settings editor at the same time; the unique scope is authoritative.
            DB::table('bablys_site_settings')->insertOrIgnore([
                'scope' => 'global',
                'draft_version' => 0,
                'published_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $row = DB::table('bablys_site_settings')->where('scope', 'global')
                ->lockForUpdate()->first();
            if (!$row instanceof stdClass) {
                throw new \RuntimeException('Could not load the global settings row.');
            }
            if ((int) $row->draft_version !== $expected) {
                throw ValidationException::withMessages([
                    'expected_version' => 'Another editor saved newer settings. Reload this page before saving.',
                ]);
            }
            $newVersion = $expected + 1;
            try {
                $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $e) {
                throw new \RuntimeException('Could not encode site settings.', 0, $e);
            }
            DB::table('bablys_site_settings')->where('id', $row->id)->update([
                'draft_payload' => $encoded,
                'draft_version' => $newVersion,
                'draft_updated_by' => $actorId,
                'updated_at' => now(),
            ]);
            DB::table('bablys_site_settings_revisions')->insert([
                'site_settings_id' => $row->id,
                'draft_version' => $newVersion,
                'draft_payload' => $encoded,
                'edited_by' => $actorId,
                'created_at' => now(),
            ]);
            return ['draft_version' => $newVersion];
        }, 3);
    }

    /**
     * Accept only known keys, even if a caller supplies unvalidated extra values.
     * @param array<string, mixed> $input
     * @return array<string, array<string, string>>
     */
    private function normalize(array $input): array
    {
        $payload = $this->defaults();
        foreach ($payload as $group => $items) {
            $source = $input[$group] ?? [];
            if (!is_array($source)) {
                throw new \InvalidArgumentException('Expected validated settings groups.');
            }
            foreach ($items as $key => $fallback) {
                $value = $source[$key] ?? $fallback;
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Expected validated settings strings.');
                }
                $payload[$group][$key] = trim($value);
            }
        }
        return $payload;
    }

    /** @return array<string, array<string, string>> */
    private function decode(mixed $raw): array
    {
        $base = $this->defaults();
        if (!is_string($raw) || $raw === '') {
            return $base;
        }
        try {
            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $base;
        }
        if (!is_array($parsed)) {
            return $base;
        }
        foreach ($base as $group => $items) {
            $parts = $parsed[$group] ?? null;
            if (!is_array($parts)) {
                continue;
            }
            foreach ($items as $key => $fallback) {
                if (isset($parts[$key]) && is_string($parts[$key])) {
                    $base[$group][$key] = $parts[$key];
                }
            }
        }
        return $base;
    }
}
