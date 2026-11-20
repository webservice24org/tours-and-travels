<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\Tour;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * P3D.1: transactional tour writes; no prices, inventory or public routes.
 * Permission and publication guards are rechecked inside the transaction.
 */
final class TourManager
{
    public function __construct(
        private readonly TourSlug $slugs,
        private readonly TourTaxonomyRules $taxonomy,
        private readonly TourPublicationRules $publication,
                private readonly CmsMutationAudit $audit,
        private readonly TourTagService $tags,
        private readonly TourHtmlSanitizer $html,
        private readonly TourDurationGuard $durationGuard,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): Tour
    {
        return DB::transaction(function () use ($actor, $data): Tour {
            $this->authorize($actor, 'tours.create');
            $status = (string) ($data['status'] ?? PublicationStatus::Draft->value);
            $this->authorizePublication($actor, $status);
            $this->assertTourDetails((string) $data['title'], (int) ($data['duration_days'] ?? 1), $data['duration_nights'] ?? null);
            $slug = $this->slugs->assertAvailable((string) $data['slug']);

            $categoryIds = $this->ids($data['category_ids'] ?? [], 'category_ids');
            $destinationIds = $this->ids($data['destination_ids'] ?? [], 'destination_ids');
            $this->taxonomy->assertAssignable($categoryIds, $destinationIds);
            $this->assertSchedule($status, $data['scheduled_for'] ?? null);

            $tour = Tour::query()->create([
                'title' => trim((string) $data['title']),
                'slug' => $slug,
                'summary' => $data['summary'] ?? null,
                'description' => $this->html->clean(isset($data['description']) ? (string) $data['description'] : null),
                'status' => $status,
                'duration_days' => (int) ($data['duration_days'] ?? 1),
                'duration_nights' => $data['duration_nights'] ?? null,
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'scheduled_for' => $status === PublicationStatus::Scheduled->value
                    ? $data['scheduled_for'] : null,
                'published_at' => $status === PublicationStatus::Published->value ? now() : null,
                'created_by' => $actor->getKey(),
                'updated_by' => $actor->getKey(),
            ]);
            $this->syncLinks($tour, $categoryIds, $destinationIds);
            if (array_key_exists('tag_names', $data)) {
                $this->tags->sync($tour, $data['tag_names']);
            }
            if ($status === PublicationStatus::Published->value) {
                $this->publication->assertReady($tour);
            }

            $this->audit->record('tour.created', $actor, 'tour', $tour, [
                'slug' => $tour->slug,
                'status' => $status,
                'category_count' => count($categoryIds),
                'destination_count' => count($destinationIds),
                'tag_count' => count($data['tag_names'] ?? []),
            ]);

            return $tour->refresh()->load(['categories', 'destinations', 'tags']);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, Tour $tour, array $data): Tour
    {
        return DB::transaction(function () use ($actor, $tour, $data): Tour {
            $this->authorize($actor, 'tours.edit');
            $locked = $this->lockTour($tour);
            $oldStatus = $locked->status->value;
            $status = (string) ($data['status'] ?? $oldStatus);
            $this->assertStatus($status);

            // An editor cannot silently edit a published/scheduled tour or make one live.
            if (
                in_array($oldStatus, ['published', 'scheduled'], true) ||
                in_array($status, ['published', 'scheduled'], true)
            ) {
                $this->authorize($actor, 'tours.publish');
            }
            if (array_key_exists('slug', $data)) {
                $this->slugs->assertUnchanged($locked, (string) $data['slug']);
            }

            $beforeCategories = $this->linkedIds('travel_tour_category', 'category_id', $locked);
            $beforeDestinations = $this->linkedIds('travel_tour_destination', 'destination_id', $locked);
            $categoryIds = array_key_exists('category_ids', $data)
                ? $this->ids($data['category_ids'], 'category_ids') : $beforeCategories;
            $destinationIds = array_key_exists('destination_ids', $data)
                ? $this->ids($data['destination_ids'], 'destination_ids') : $beforeDestinations;
            $this->taxonomy->assertAssignable($categoryIds, $destinationIds);
            $this->assertTourDetails(
                (string) ($data['title'] ?? $locked->title),
                (int) ($data['duration_days'] ?? $locked->duration_days),
                $data['duration_nights'] ?? (array_key_exists('duration_nights', $data) ? null : $locked->duration_nights),
            );

            // Parent tour is already locked; itinerary writes acquire that same lock.
            $this->durationGuard->assertCanResize($locked, (int) ($data['duration_days'] ?? $locked->duration_days));

            $schedule = array_key_exists('scheduled_for', $data)
                ? $data['scheduled_for'] : $locked->scheduled_for;
            $this->assertSchedule($status, $schedule);

            $fields = [
                'title',
                'summary',
                'description',
                'duration_days',
                'duration_nights',
                'is_featured',
                'sort_order',
                'status',
            ];
            $updates = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = match ($field) {
                        'title' => trim((string) $data[$field]),
                        'description' => $this->html->clean($data[$field] === null ? null : (string) $data[$field]),
                        default => $data[$field],
                    };
                }
            }
            $updates['scheduled_for'] = $status === PublicationStatus::Scheduled->value ? $schedule : null;
            if ($oldStatus !== PublicationStatus::Published->value && $status === PublicationStatus::Published->value) {
                $updates['published_at'] = now();
            } elseif ($status !== PublicationStatus::Published->value) {
                $updates['published_at'] = null;
            }

            $locked->fill($updates);
            /** @var list<string> $changedFields */
            $changedFields = array_keys($locked->getDirty());
            sort($changedFields, SORT_STRING);
            $linksChanged = $beforeCategories !== $categoryIds || $beforeDestinations !== $destinationIds;

            if ($changedFields !== [] || $linksChanged) {
                $locked->updated_by = (int) $actor->getKey();
                $locked->save();
                if ($linksChanged) {
                    $this->syncLinks($locked, $categoryIds, $destinationIds);
                }
                if ($status === PublicationStatus::Published->value) {
                    $this->publication->assertReady($locked);
                }
                if ($changedFields !== []) {
                    $this->audit->record('tour.updated', $actor, 'tour', $locked, [
                        'changed_fields' => $changedFields,
                        'status_before' => $oldStatus,
                        'status_after' => $status,
                    ]);
                }
                if ($linksChanged) {
                    $this->audit->record('tour.taxonomy.changed', $actor, 'tour', $locked, [
                        'category_ids_before' => $beforeCategories,
                        'category_ids_after' => $categoryIds,
                        'destination_ids_before' => $beforeDestinations,
                        'destination_ids_after' => $destinationIds,
                    ]);
                }
            }

            if (array_key_exists('tag_names', $data) && $this->tags->sync($locked, $data['tag_names'])) {
                $locked->updated_by = (int) $actor->getKey();
                $locked->save();
                $this->audit->record('tour.tags.changed', $actor, 'tour', $locked, [
                    'tag_count' => count($data['tag_names']),
                ]);
            }

            return $locked->refresh()->load(['categories', 'destinations', 'tags']);
        }, 3);
    }

    public function softDelete(User $actor, Tour $tour): void
    {
        DB::transaction(function () use ($actor, $tour): void {
            $this->authorize($actor, 'tours.edit');
            $locked = $this->lockTour($tour);
            if (in_array($locked->status->value, ['published', 'scheduled'], true)) {
                throw ValidationException::withMessages([
                    'tour' => 'Unpublish or archive this tour before deleting it.',
                ]);
            }
            $locked->delete();
            $this->audit->record('tour.deleted', $actor, 'tour', $locked, [
                'slug' => $locked->slug,
                'previous_status' => $locked->status->value,
            ]);
        }, 3);
    }

    private function authorize(User $actor, string $permission): void
    {
        abort_unless(Gate::forUser($actor)->allows($permission), 403);
    }

    private function authorizePublication(User $actor, string $status): void
    {
        $this->assertStatus($status);
        if (in_array($status, ['published', 'scheduled'], true)) {
            $this->authorize($actor, 'tours.publish');
        }
    }

    private function lockTour(Tour $tour): Tour
    {
        $locked = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->first();
        if (!$locked instanceof Tour) {
            abort(404);
        }
        return $locked;
    }

    private function assertSchedule(string $status, mixed $scheduledFor): void
    {
        if ($status !== PublicationStatus::Scheduled->value) {
            return;
        }
        if ($scheduledFor === null || $scheduledFor === '') {
            throw ValidationException::withMessages([
                'scheduled_for' => 'A future scheduled date is required for scheduled tours.',
            ]);
        }
        try {
            $date = $scheduledFor instanceof \DateTimeInterface
                ? Carbon::instance($scheduledFor)
                : Carbon::parse((string) $scheduledFor);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['scheduled_for' => 'Invalid scheduled date.']);
        }
        if ($date->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['scheduled_for' => 'Scheduled date must be in the future.']);
        }
    }

    private function assertStatus(string $status): void
    {
        if (PublicationStatus::tryFrom($status) === null) {
            throw ValidationException::withMessages(['status' => 'Invalid tour status.']);
        }
    }

    private function assertTourDetails(string $title, int $days, mixed $nights): void
    {
        if (mb_strlen(trim($title)) < 2 || $days < 1 || $days > 365) {
            throw ValidationException::withMessages(['title' => 'Provide a title and valid duration.']);
        }
        if ($nights !== null && (!is_int($nights) || $nights < 0 || $nights > $days)) {
            throw ValidationException::withMessages(['duration_nights' => 'Nights must not exceed duration days.']);
        }
    }

    /** @return list<int> */
    private function ids(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw ValidationException::withMessages([$field => 'Expected a list of IDs.']);
        }
        $ids = [];
        foreach ($value as $id) {
            if (!is_int($id) || $id <= 0) {
                throw ValidationException::withMessages([$field => 'IDs must be positive integers.']);
            }
            $ids[] = $id;
        }
        return $ids;
    }


    /**
     * Synchronize tour categories and destinations.
     *
     * @param list<int> $categories
     * @param list<int> $destinations
     */
    private function syncLinks(
        Tour $tour,
        array $categories,
        array $destinations
    ): void {
        $categoryMap = [];

        foreach ($categories as $index => $id) {
            $categoryMap[$id] = ['sort_order' => $index];
        }

        $destinationMap = [];

        foreach ($destinations as $index => $id) {
            $destinationMap[$id] = ['sort_order' => $index];
        }

        $tour->categories()->sync($categoryMap);
        $tour->destinations()->sync($destinationMap);
    }


    /** @return list<int> */
    private function linkedIds(string $table, string $column, Tour $tour): array
    {
        $values = DB::table($table)->where('tour_id', $tour->getKey())
            ->orderBy('sort_order')->orderBy($column)->pluck($column);
        $ids = [];
        foreach ($values as $value) {
            $ids[] = (int) $value;
        }
        return $ids;
    }
}
