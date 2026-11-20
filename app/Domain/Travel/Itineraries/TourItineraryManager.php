<?php

declare(strict_types=1);

namespace App\Domain\Travel\Itineraries;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Models\Travel\Tour;
use App\Models\Travel\TourItineraryDay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * P3E.1 domain-only itinerary writes. This class does not expose HTTP routes.
 * Writes lock the parent tour, validate the whole replacement and audit once.
 */
final class TourItineraryManager
{
    public function __construct(
        private readonly TourItineraryRules $rules,
        private readonly CmsMutationAudit $audit,
    ) {}

    /**
     * Replace the ordered list of itinerary days. The list may be partial in P3E.1.
     * Day IDs are retained for existing day positions; unchanged writes are no-ops.
     *
     * @param array<array-key, mixed> $days
     */
    public function replace(User $actor, Tour $tour, array $days): void
    {
        DB::transaction(function () use ($actor, $tour, $days): void {
            $this->authorize($actor, 'tours.edit');
            $locked = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status->value, ['published', 'scheduled'], true)) {
                $this->authorize($actor, 'tours.publish');
            }
            $normalized = $this->rules->normalize($days, (int) $locked->duration_days);
            $before = $this->dayRows((int) $locked->getKey());
            if ($before === $normalized) {
                return;
            }

            $tourId = (int) $locked->getKey();
            foreach ($normalized as $day) {
                TourItineraryDay::query()->updateOrCreate(
                    ['tour_id' => $tourId, 'day_number' => $day['day_number']],
                    $day,
                );
            }
            TourItineraryDay::query()
                ->where('tour_id', $tourId)
                ->where('day_number', '>', count($normalized))
                ->delete();

            $changed = [];
            $total = max(count($before), count($normalized));
            for ($index = 0; $index < $total; $index++) {
                if (($before[$index] ?? null) !== ($normalized[$index] ?? null)) {
                    $changed[] = $index + 1;
                }
            }
            // Never send itinerary descriptions, highlights, or names to audit logs.
            $this->audit->record('tour.itinerary.changed', $actor, 'tour', $locked, [
                'days_before' => count($before),
                'days_after' => count($normalized),
                'changed_day_numbers' => $changed,
            ]);
        }, 3);
    }

    /**
     * Staff-only ordered read contract for the forthcoming P3E.2 editor.
     * Not a public catalog API; future public responses must independently check
     * Tour::publiclyAvailable() and the relevant publication invariants.
     *
     * @return list<array{day_number:int,title:string,location_label:?string,description:?string,overnight_location:?string,meals:list<string>,highlights:list<string>}>
     */
    public function forStaff(User $actor, Tour $tour): array
    {
        $this->authorize($actor, 'tours.view');
        $existing = Tour::query()->whereKey($tour->getKey())->firstOrFail();
        return $this->dayRows((int) $existing->getKey());
    }

    /**
     * @return list<array{day_number:int,title:string,location_label:?string,description:?string,overnight_location:?string,meals:list<string>,highlights:list<string>}>
     */
    private function dayRows(int $tourId): array
    {
        $rows = TourItineraryDay::query()
            ->where('tour_id', $tourId)
            ->orderBy('day_number')
            ->get();
        $result = [];
        foreach ($rows as $day) {
            $result[] = [
                'day_number' => (int) $day->day_number,
                'title' => (string) $day->title,
                'location_label' => $day->location_label,
                'description' => $day->description,
                'overnight_location' => $day->overnight_location,
                'meals' => $day->meals,
                'highlights' => $day->highlights,
            ];
        }
        return $result;
    }

    private function authorize(User $actor, string $permission): void
    {
        abort_unless(Gate::forUser($actor)->allows($permission), 403);
    }
}
