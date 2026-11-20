<?php

declare(strict_types=1);

namespace App\Domain\Travel\Taxonomy;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\Destination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * P3B.2: secure destination mutations. No public routes or tour relations yet.
 * All mutation and audit writes share a single DB transaction.
 */
final class DestinationManager
{
    public function __construct(
        private readonly TaxonomySlug $slugs,
        private readonly CmsMutationAudit $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(User $actor, array $input): Destination
    {
        return DB::transaction(function () use ($actor, $input): Destination {
            $this->authorizeActor($actor);
            $slug = $this->slugs->normalize((string) $input['slug']);
            $this->assertSlugAvailable($slug);
            $countryCode = $this->countryCode((string) $input['country_code']);
            $latitude = $input['latitude'] ?? null;
            $longitude = $input['longitude'] ?? null;
            $this->assertCoordinates($latitude, $longitude);
            $status = (string) ($input['status'] ?? PublicationStatus::Draft->value);

            $destination = Destination::query()->create([
                'name' => trim((string) $input['name']),
                'slug' => $slug,
                'country_code' => $countryCode,
                'region_name' => $input['region_name'] ?? null,
                'city_name' => $input['city_name'] ?? null,
                'summary' => $input['summary'] ?? null,
                'description' => $input['description'] ?? null,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'status' => $status,
                'sort_order' => (int) ($input['sort_order'] ?? 0),
            ]);

            $this->audit->record('taxonomy.destination.created', $actor, 'destination', $destination, [
                'slug' => $slug,
                'country_code' => $countryCode,
                'status' => $status,
            ]);

            return $destination->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function update(User $actor, Destination $destination, array $input): Destination
    {
        return DB::transaction(function () use ($actor, $destination, $input): Destination {
            $this->authorizeActor($actor);
            $locked = $this->lockedDestination($destination);

            if (array_key_exists('slug', $input) &&
                $this->slugs->normalize((string) $input['slug']) !== $locked->slug) {
                throw ValidationException::withMessages([
                    'slug' => 'Destination slugs cannot change until URL redirects are supported.',
                ]);
            }

            $latitude = array_key_exists('latitude', $input) ? $input['latitude'] : $locked->latitude;
            $longitude = array_key_exists('longitude', $input) ? $input['longitude'] : $locked->longitude;
            $this->assertCoordinates($latitude, $longitude);

            $before = [
                'status' => $locked->status->value,
                'country_code' => $locked->country_code,
                'has_coordinates' => $locked->latitude !== null && $locked->longitude !== null,
            ];

            $updates = [];
            foreach ([
                'name', 'country_code', 'region_name', 'city_name', 'summary',
                'description', 'latitude', 'longitude', 'status', 'sort_order',
            ] as $field) {
                if (array_key_exists($field, $input)) {
                    $updates[$field] = match ($field) {
                        'name' => trim((string) $input[$field]),
                        'country_code' => $this->countryCode((string) $input[$field]),
                        default => $input[$field],
                    };
                }
            }

            $locked->fill($updates);
            if ($locked->isDirty()) {
                /** @var list<string> $changedFields */
                $changedFields = array_keys($locked->getDirty());
                sort($changedFields, SORT_STRING);
                $locked->save();
                $this->audit->record('taxonomy.destination.updated', $actor, 'destination', $locked, [
                    'changed_fields' => $changedFields,
                    'before' => $before,
                    'after' => [
                        'status' => $locked->status->value,
                        'country_code' => $locked->country_code,
                        'has_coordinates' => $locked->latitude !== null && $locked->longitude !== null,
                    ],
                ]);
            }

            return $locked->refresh();
        }, 3);
    }

    public function softDelete(User $actor, Destination $destination): void
    {
        DB::transaction(function () use ($actor, $destination): void {
            $this->authorizeActor($actor);
            $locked = $this->lockedDestination($destination);
            if ($locked->status === PublicationStatus::Published) {
                throw ValidationException::withMessages([
                    'destination' => 'Archive this published destination before deleting it.',
                ]);
            }

            $locked->delete();
            $this->audit->record('taxonomy.destination.deleted', $actor, 'destination', $locked, [
                'slug' => $locked->slug,
                'country_code' => $locked->country_code,
                'previous_status' => $locked->status->value,
            ]);
        }, 3);
    }

    private function authorizeActor(User $actor): void
    {
        abort_unless(Gate::forUser($actor)->allows('taxonomy.manage'), 403);
    }

    private function lockedDestination(Destination $destination): Destination
    {
        $locked = Destination::query()
            ->whereKey($destination->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof Destination) {
            abort(404);
        }

        return $locked;
    }

    private function assertSlugAvailable(string $slug): void
    {
        // Reserve slugs even after soft deletion to keep future SEO redirects safe.
        if (Destination::withTrashed()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'This destination slug is already in use.']);
        }
    }

    private function countryCode(string $input): string
    {
        $code = strtoupper(trim($input));
        if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) {
            throw ValidationException::withMessages(['country_code' => 'Use a two-letter country code.']);
        }

        return $code;
    }

    private function assertCoordinates(mixed $latitude, mixed $longitude): void
    {
        if (($latitude === null) !== ($longitude === null)) {
            throw ValidationException::withMessages([
                'latitude' => 'Provide both latitude and longitude, or clear both.',
                'longitude' => 'Provide both latitude and longitude, or clear both.',
            ]);
        }

        // The Form Requests validate numeric bounds; this extra check guards direct domain calls.
        if ($latitude !== null && (
            ! is_numeric($latitude) || ! is_numeric($longitude) ||
            (float) $latitude < -90 || (float) $latitude > 90 ||
            (float) $longitude < -180 || (float) $longitude > 180
        )) {
            throw ValidationException::withMessages(['latitude' => 'Invalid destination coordinates.']);
        }
    }
}
