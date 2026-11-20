<?php

declare(strict_types=1);

namespace App\Domain\Travel\Departures;

use App\Domain\Cms\Audit\CmsMutationAudit;
use App\Models\Travel\Tour;
use App\Models\Travel\TourDeparture;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * P3E.4A staff-only departure management, no public routes or reservations.
 * Locks the parent tour first, then its departure rows. Future booking writes
 * must use the same lock order before mutating seats_committed.
 *
 * @phpstan-import-type DepartureInput from TourDepartureRules
 */
final class TourDepartureManager
{
    public function __construct(
        private readonly TourDepartureRules $rules,
        private readonly TourDepartureAvailability $availability,
        private readonly CmsMutationAudit $audit,
    ) {}

    /** @param array<array-key,mixed> $departures */
    public function replace(User $actor, Tour $tour, array $departures): void
    {
        DB::transaction(function () use ($actor, $tour, $departures): void {
            $this->authorize($actor, 'tours.edit');
            $lockedTour = Tour::query()->whereKey($tour->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($lockedTour->status->value, ['published', 'scheduled'], true)) {
                $this->authorize($actor, 'tours.publish');
            }
            $normalized = $this->rules->normalize($departures);
            $tourId = (int) $lockedTour->getKey();
            /** @var \Illuminate\Database\Eloquent\Collection<int,TourDeparture> $existing */
            $existing = TourDeparture::query()
                ->where('tour_id', $tourId)
                ->lockForUpdate()
                ->get();
            /** @var array<string,TourDeparture> $existingByCode */
            $existingByCode = [];
            foreach ($existing as $row) {
                $existingByCode[(string) $row->code] = $row;
            }
            $before = $this->editableRows($existing);
            if ($before === $normalized) {
                return;
            }
            $codes = [];
            foreach ($normalized as $input) {
                $code = $input['code'];
                $codes[] = $code;
                $row = $existingByCode[$code] ?? new TourDeparture();
                $committed = $row->exists ? (int) $row->seats_committed : 0;
                if ($input['capacity_total'] < $committed) {
                    $this->reject('departures', 'Capacity cannot be smaller than seats already committed.');
                }
                if ($committed > 0) {
                    if ((string) $row->starts_at !== $input['starts_at'] ||
                        (string) $row->ends_at !== (string) $input['ends_at'] ||
                        (string) $row->timezone_name !== $input['timezone_name']) {
                        $this->reject('departures', 'Dates and timezone cannot change after seats are committed.');
                    }
                    if (in_array($input['status'], ['cancelled', 'draft'], true)) {
                        $this->reject('departures', 'A departure with committed seats cannot be cancelled or returned to draft without a booking-resolution workflow.');
                    }
                }
                $row->fill(['tour_id' => $tourId] + $input);
                $row->save();
            }
            foreach ($existing as $row) {
                if (! in_array((string) $row->code, $codes, true)) {
                    if ((int) $row->seats_committed > 0) {
                        $this->reject('departures', 'Cannot delete a departure with committed seats.');
                    }
                    $row->delete();
                }
            }
            $oldByCode = [];
            foreach ($before as $row) {
                $oldByCode[$row['code']] = $row;
            }
            $newByCode = [];
            foreach ($normalized as $row) {
                $newByCode[$row['code']] = $row;
            }
            $changed = [];
            foreach (array_unique(array_merge(array_keys($oldByCode), array_keys($newByCode))) as $code) {
                if (($oldByCode[$code] ?? null) !== ($newByCode[$code] ?? null)) {
                    $changed[] = $code;
                }
            }
            sort($changed, SORT_STRING);
            // Do not include meeting points, dates, notes or seat counts in the audit.
            $this->audit->record('tour.departures.changed', $actor, 'tour', $lockedTour, [
                'departures_before' => count($before),
                'departures_after' => count($normalized),
                'changed_departure_codes' => $changed,
            ]);
        }, 3);
    }

    /**
     * Staff-only inventory list for P3E.4B UI and internal planning.
     * public_bookable is deliberately false until P3E.5 booking readiness.
     *
     * @return list<array{code:string,name:string,timezone_name:string,starts_at:string,ends_at:?string,booking_closes_at:?string,capacity_total:int,seats_committed:int,remaining_capacity:int,status:string,capacity_state:string,public_bookable:bool,meeting_point:?string,internal_notes:?string}>
     */
    public function forStaff(User $actor, Tour $tour, DateTimeInterface $at): array
    {
        $this->authorize($actor, 'tours.view');
        $existing = Tour::query()->whereKey($tour->getKey())->firstOrFail();
        $rows = TourDeparture::query()
            ->where('tour_id', (int) $existing->getKey())
            ->orderBy('starts_at')
            ->orderBy('code')
            ->get();
        $output = [];
        foreach ($rows as $row) {
            $availability = $this->availability->snapshot($row, $at);
            $output[] = [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'timezone_name' => (string) $row->timezone_name,
                'starts_at' => $this->utc((string) $row->starts_at),
                'ends_at' => $row->ends_at === null ? null : $this->utc((string) $row->ends_at),
                'booking_closes_at' => $row->booking_closes_at === null ? null : $this->utc((string) $row->booking_closes_at),
                'capacity_total' => (int) $row->capacity_total,
                'seats_committed' => (int) $row->seats_committed,
                'remaining_capacity' => $availability['remaining_capacity'],
                'status' => (string) $row->status,
                'capacity_state' => $availability['capacity_state'],
                'public_bookable' => false,
                'meeting_point' => $row->meeting_point,
                'internal_notes' => $row->internal_notes,
            ];
        }
        return $output;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int,TourDeparture> $rows
     * @return list<DepartureInput>
     */
    private function editableRows(\Illuminate\Database\Eloquent\Collection $rows): array
    {
        $output = [];
        foreach ($rows as $row) {
            $output[] = [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'timezone_name' => (string) $row->timezone_name,
                'starts_at' => (string) $row->starts_at,
                'ends_at' => $row->ends_at,
                'booking_closes_at' => $row->booking_closes_at,
                'capacity_total' => (int) $row->capacity_total,
                'status' => (string) $row->status,
                'meeting_point' => $row->meeting_point,
                'internal_notes' => $row->internal_notes,
            ];
        }
        usort($output, static fn (array $a, array $b): int => [$a['starts_at'], $a['code']] <=> [$b['starts_at'], $b['code']]);
        return $output;
    }

    private function utc(string $dbUtc): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dbUtc, new \DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new \LogicException('Invalid departure UTC timestamp in database.');
        }
        return $parsed->format('Y-m-d\TH:i:s\Z');
    }

    private function authorize(User $actor, string $permission): void
    {
        abort_unless(Gate::forUser($actor)->allows($permission), 403);
    }

    /** @return never */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
