<?php

declare(strict_types=1);

namespace App\Domain\Travel\Departures;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * Strict staff-only departure input validation. All date-time inputs must
 * include an explicit UTC offset and agree with the named IANA time zone.
 * We store UTC SQL DATETIME strings, never interpret ambiguous local times.
 *
 * @phpstan-type DepartureInput array{code:string,name:string,timezone_name:string,starts_at:string,ends_at:?string,booking_closes_at:?string,capacity_total:int,status:string,meeting_point:?string,internal_notes:?string}
 */
final class TourDepartureRules
{
    /** @var list<string> */
    public const STATUSES = ['draft', 'open', 'closed', 'cancelled'];

    /** @param array<array-key,mixed> $departures
     *  @return list<DepartureInput>
     */
    public function normalize(array $departures): array
    {
        if (! array_is_list($departures) || count($departures) > 200) {
            $this->reject('departures', 'Provide an ordered list of at most 200 departures.');
        }

        /** @var array<string,bool> $codes */
        $codes = [];
        $output = [];
        foreach ($departures as $index => $input) {
            $field = 'departures.'.$index;
            if (! is_array($input) || array_is_list($input)) {
                $this->reject($field, 'Departure must be a JSON object.');
            }
            /** @var array<array-key,mixed> $input */
            $this->keys($input, [
                'code', 'name', 'timezone_name', 'starts_at', 'ends_at',
                'booking_closes_at', 'capacity_total', 'status', 'meeting_point',
                'internal_notes',
            ], $field);
            $code = strtolower($this->requiredText($input['code'] ?? null, $field.'.code', 60));
            if (preg_match('/^[a-z][a-z0-9_-]{1,59}$/D', $code) !== 1 || isset($codes[$code])) {
                $this->reject($field.'.code', 'Use a unique 2–60 character lowercase code starting with a letter.');
            }
            $codes[$code] = true;
            $name = $this->requiredText($input['name'] ?? null, $field.'.name', 120);
            $zoneName = $this->requiredText($input['timezone_name'] ?? null, $field.'.timezone_name', 64);
            if (! in_array($zoneName, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
                $this->reject($field.'.timezone_name', 'Use a valid IANA timezone such as Asia/Dhaka.');
            }
            $zone = new DateTimeZone($zoneName);
            $starts = $this->date($input['starts_at'] ?? null, $zone, $field.'.starts_at', false);
            if ($starts === null) {
                $this->reject($field.'.starts_at', 'Departure start is required.');
            }
            $ends = $this->date($input['ends_at'] ?? null, $zone, $field.'.ends_at', true);
            $closes = $this->date($input['booking_closes_at'] ?? null, $zone, $field.'.booking_closes_at', true);
            if ($ends !== null && $ends <= $starts) {
                $this->reject($field.'.ends_at', 'End must be after departure start.');
            }
            if ($closes !== null && $closes > $starts) {
                $this->reject($field.'.booking_closes_at', 'Booking cutoff cannot be after departure start.');
            }
            $capacity = $input['capacity_total'] ?? null;
            if (! is_int($capacity) || $capacity < 1 || $capacity > 10000) {
                $this->reject($field.'.capacity_total', 'Capacity must be an integer between 1 and 10,000.');
            }
            $status = $input['status'] ?? 'draft';
            if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
                $this->reject($field.'.status', 'Unknown departure status.');
            }
            if ($status === 'open' && $starts <= now('UTC')->format('Y-m-d H:i:s')) {
                $this->reject($field.'.starts_at', 'Only future departures may be opened.');
            }
            $output[] = [
                'code' => $code,
                'name' => $name,
                'timezone_name' => $zoneName,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'booking_closes_at' => $closes,
                'capacity_total' => $capacity,
                'status' => $status,
                'meeting_point' => $this->optionalText($input['meeting_point'] ?? null, $field.'.meeting_point', 180),
                'internal_notes' => $this->optionalText($input['internal_notes'] ?? null, $field.'.internal_notes', 2000),
            ];
        }
        usort($output, static fn (array $a, array $b): int => [$a['starts_at'], $a['code']] <=> [$b['starts_at'], $b['code']]);
        return $output;
    }

    /** @param array<array-key,mixed> $value
     *  @param list<string> $allowed
     */
    private function keys(array $value, array $allowed, string $field): void
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $this->reject($field, 'Unsupported departure field supplied.');
            }
        }
    }

    private function requiredText(mixed $value, string $field, int $limit): string
    {
        if (! is_string($value)) {
            $this->reject($field, 'Expected text.');
        }
        $text = trim($value);
        if ($text === '' || mb_strlen($text) > $limit || strip_tags($text) !== $text) {
            $this->reject($field, 'Text is empty, too long or contains HTML.');
        }
        return $text;
    }

    private function optionalText(mixed $value, string $field, int $limit): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->requiredText($value, $field, $limit);
    }

    private function date(mixed $value, DateTimeZone $zone, string $field, bool $optional): ?string
    {
        if ($value === null && $optional) {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            $this->reject($field, 'Use an ISO 8601 timestamp with seconds and explicit timezone offset.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $this->reject($field, 'Invalid calendar date or timezone offset.');
        }
        if ($parsed->getOffset() !== $zone->getOffset($parsed)) {
            $this->reject($field, 'Timestamp offset does not agree with selected timezone at that instant.');
        }
        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return never */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
