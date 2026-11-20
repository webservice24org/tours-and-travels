<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourItineraryDay;
use Illuminate\Database\Eloquent\Builder;

/**
 * Public read-only itinerary and upcoming departure preview for a tour that
 * has ALREADY passed Tour::publiclyAvailable(). Never call this for drafts.
 *
 * Departure records are staff inventory. Only an allowlisted, limited set of
 * nonbinding date labels is returned. Never publish capacity, seat counts,
 * booking close timestamps, row IDs, codes, internal notes or meeting points.
 */
final class PublishedTourSchedule
{
    private const MAX_DAYS = 60;
    private const MAX_UPCOMING = 8;

    /**
     * @return list<array{day_number:int,title:string,location:?string,description:?string,overnight:?string,meals:list<string>,highlights:list<string>}>
     */
    public function itineraryFor(int $publishedTourId): array
    {
        if ($publishedTourId <= 0) {
            return [];
        }

        $rows = TourItineraryDay::query()
            ->where('tour_id', $publishedTourId)
            ->orderBy('day_number')
            ->orderBy('id')
            ->limit(self::MAX_DAYS)
            ->get(['day_number', 'title', 'location_label', 'description', 'overnight_location', 'meals', 'highlights']);

        $days = [];
        foreach ($rows as $row) {
            $location = self::optionalText($row->location_label, 160);
            $overnight = self::optionalText($row->overnight_location, 160);
            $description = self::optionalText($row->description, 10000);

            $days[] = [
                'day_number' => (int) $row->day_number,
                'title' => self::cleanText((string) $row->title, 180),
                'location' => $location,
                'description' => $description,
                'overnight' => $overnight,
                'meals' => self::cleanList($row->meals, 12),
                'highlights' => self::cleanList($row->highlights, 16),
            ];
        }

        return $days;
    }

    /**
     * @return list<array{name:string,starts_at_utc:string,ends_at_utc:?string,timezone:string}>
     */
    public function upcomingFor(int $publishedTourId): array
    {
        if ($publishedTourId <= 0) {
            return [];
        }

        $nowUtc = now('UTC')->format('Y-m-d H:i:s');
        $rows = TourDeparture::query()
            ->where('tour_id', $publishedTourId)
            ->where('status', 'open')
            ->where('starts_at', '>', $nowUtc)
            ->where(static function (Builder $query) use ($nowUtc): void {
                $query->whereNull('booking_closes_at')
                    ->orWhere('booking_closes_at', '>', $nowUtc);
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(self::MAX_UPCOMING)
            ->get(['name', 'starts_at', 'ends_at', 'timezone_name']);

        $departures = [];
        foreach ($rows as $row) {
            // The migration stores UTC timestamps as SQL DATETIME strings; do
            // not apply app/server timezone conversion or silently treat them
            // as local time. The IANA timezone is display context only.
            $departures[] = [
                'name' => self::cleanText((string) $row->name, 120),
                'starts_at_utc' => self::utcIso((string) $row->starts_at),
                'ends_at_utc' => $row->ends_at === null ? null : self::utcIso((string) $row->ends_at),
                'timezone' => (string) $row->timezone_name,
            ];
        }

        return $departures;
    }

    private static function utcIso(string $datetime): string
    {
        return str_replace(' ', 'T', $datetime).'Z';
    }

    private static function cleanText(string $text, int $limit): string
    {
        return mb_substr(trim(strip_tags($text)), 0, $limit);
    }

    private static function optionalText(?string $text, int $limit): ?string
    {
        if ($text === null) {
            return null;
        }
        $clean = self::cleanText($text, $limit);
        return $clean === '' ? null : $clean;
    }

    /** @param list<string> $entries
     *  @return list<string>
     */
    private static function cleanList(array $entries, int $maxEntries): array
    {
        $result = [];
        foreach (array_slice($entries, 0, $maxEntries) as $entry) {
            $value = self::cleanText($entry, 180);
            if ($value !== '') {
                $result[] = $value;
            }
        }
        return $result;
    }
}
