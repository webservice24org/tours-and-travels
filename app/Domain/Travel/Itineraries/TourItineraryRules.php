<?php

declare(strict_types=1);

namespace App\Domain\Travel\Itineraries;

use Illuminate\Validation\ValidationException;

/**
 * Pure normalization for the ordered day-by-day content.
 * P3E.1 deliberately stores plain text, not arbitrary untrusted HTML.
 * A partial (or empty) sequence is valid while a tour is being drafted.
 */
final class TourItineraryRules
{
    /** @var list<string> */
    private const MEAL_ORDER = ['breakfast', 'lunch', 'dinner'];

    /**
     * @param array<array-key, mixed> $days
     * @return list<array{day_number:int,title:string,location_label:?string,description:?string,overnight_location:?string,meals:list<string>,highlights:list<string>}>
     */
    public function normalize(array $days, int $durationDays): array
    {
        if (! array_is_list($days)) {
            $this->reject('days', 'Itinerary days must be a consecutive list.');
        }
        if ($durationDays < 1 || $durationDays > 365) {
            $this->reject('days', 'Tour duration must be between 1 and 365 days.');
        }
        if (count($days) > $durationDays) {
            $this->reject('days', 'Itinerary has more days than the tour duration.');
        }

        $normalized = [];
        foreach ($days as $position => $raw) {
            $field = 'days.'.$position;
            if (! is_array($raw) || array_is_list($raw)) {
                $this->reject($field, 'Each itinerary day must be an object.');
            }
            /** @var array<array-key, mixed> $raw */
            $allowed = ['day_number', 'title', 'location_label', 'description', 'overnight_location', 'meals', 'highlights'];
            foreach (array_keys($raw) as $key) {
                if (! is_string($key) || ! in_array($key, $allowed, true)) {
                    $this->reject($field, 'An unsupported itinerary field was supplied.');
                }
            }

            $dayNumber = $position + 1;
            if (array_key_exists('day_number', $raw) && $raw['day_number'] !== $dayNumber) {
                $this->reject($field.'.day_number', 'Day numbers must match their position in the list.');
            }
            $normalized[] = [
                'day_number' => $dayNumber,
                'title' => (string) $this->text($raw['title'] ?? null, $field.'.title', 180, true),
                'location_label' => $this->text($raw['location_label'] ?? null, $field.'.location_label', 160, false),
                'description' => $this->text($raw['description'] ?? null, $field.'.description', 10000, false),
                'overnight_location' => $this->text($raw['overnight_location'] ?? null, $field.'.overnight_location', 160, false),
                'meals' => $this->meals($raw['meals'] ?? [], $field.'.meals'),
                'highlights' => $this->highlights($raw['highlights'] ?? [], $field.'.highlights'),
            ];
        }
        return $normalized;
    }

    private function text(mixed $value, string $field, int $max, bool $required): ?string
    {
        if ($value === null && ! $required) {
            return null;
        }
        if (! is_string($value)) {
            $this->reject($field, 'This field must be text.');
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            if ($required) {
                $this->reject($field, 'This field is required.');
            }
            return null;
        }
        if (mb_strlen($trimmed) > $max || strip_tags($trimmed) !== $trimmed) {
            $this->reject($field, 'Text exceeds its limit or contains HTML markup.');
        }
        return $trimmed;
    }

    /** @return list<string> */
    private function meals(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 3) {
            $this->reject($field, 'Meals must be a list containing breakfast, lunch or dinner.');
        }
        $picked = [];
        foreach ($value as $meal) {
            if (! is_string($meal) || ! in_array($meal, self::MEAL_ORDER, true) || in_array($meal, $picked, true)) {
                $this->reject($field, 'Meals must be distinct supported meal names.');
            }
            $picked[] = $meal;
        }
        // Canonical ordering prevents spurious audit events on reorder of checkboxes.
        return array_values(array_intersect(self::MEAL_ORDER, $picked));
    }

    /** @return list<string> */
    private function highlights(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 10) {
            $this->reject($field, 'Highlights must be a list with no more than ten entries.');
        }
        $result = [];
        $seen = [];
        foreach ($value as $position => $highlight) {
            $entry = $this->text($highlight, $field.'.'.$position, 200, true);
            if ($entry === null) {
                $this->reject($field.'.'.$position, 'Highlight is required.');
            }
            $key = mb_strtolower($entry);
            if (isset($seen[$key])) {
                $this->reject($field, 'Duplicate highlights are not allowed.');
            }
            $seen[$key] = true;
            $result[] = $entry;
        }
        return $result;
    }

    /** @return never */
    private function reject(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
