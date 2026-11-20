<?php

declare(strict_types=1);

namespace App\Domain\Travel\Pricing;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * No floats, exchange conversions, client-computed totals, or implicit tax.
 * All amounts are nonnegative integer minor units of the selected currency.
 *
 * @phpstan-type PricingRate array{traveler_type:string,regular_minor:int,sale_minor:?int,sale_starts_at:?string,sale_ends_at:?string}
 * @phpstan-type PricingVariant array{code:string,name:string,currency:string,pricing_model:string,min_travelers:int,max_travelers:?int,is_active:bool,sort_order:int,rates:list<PricingRate>}
 */
final class TourPricingRules
{
    /** @var array<string, int> ISO 4217 decimal exponent subset for P3E.3A. */
    public const CURRENCIES = [
        'BDT' => 2, 'USD' => 2, 'EUR' => 2, 'GBP' => 2, 'JPY' => 0,
    ];

    public const MAX_AMOUNT_MINOR = 1000000000;

    /**
     * @param array<array-key, mixed> $variants
     * @return list<PricingVariant>
     */
    public function normalize(array $variants): array
    {
        if (! array_is_list($variants) || count($variants) > 30) {
            $this->reject('variants', 'Submit an ordered list of up to 30 price variants.');
        }

        $output = [];
        $codes = [];
        foreach ($variants as $position => $input) {
            $field = 'variants.'.$position;
            if (! is_array($input) || array_is_list($input)) {
                $this->reject($field, 'Each price variant must be an object.');
            }
            /** @var array<array-key,mixed> $input */
            $this->assertKeys($input, ['code','name','currency','pricing_model','min_travelers','max_travelers','is_active','rates'], $field);
            $code = $this->string($input['code'] ?? null, $field.'.code', 60);
            $code = strtolower($code);
            if (preg_match('/^[a-z][a-z0-9_-]{1,59}$/D', $code) !== 1 || isset($codes[$code])) {
                $this->reject($field.'.code', 'Use a distinct code of 2–60 lowercase letters, numbers, _ or - starting with a letter.');
            }
            $codes[$code] = true;
            $name = $this->string($input['name'] ?? null, $field.'.name', 120);
            if (mb_strlen($name) < 2) {
                $this->reject($field.'.name', 'Variant name requires at least two characters.');
            }
            $currency = strtoupper($this->string($input['currency'] ?? null, $field.'.currency', 3));
            if (! array_key_exists($currency, self::CURRENCIES)) {
                $this->reject($field.'.currency', 'Unsupported currency; use BDT, USD, EUR, GBP or JPY.');
            }
            $model = $input['pricing_model'] ?? null;
            if (! in_array($model, ['per_person', 'per_group'], true)) {
                $this->reject($field.'.pricing_model', 'Pricing model must be per_person or per_group.');
            }
            $minimum = $this->travelerCount($input['min_travelers'] ?? 1, $field.'.min_travelers');
            $maximum = array_key_exists('max_travelers', $input) && $input['max_travelers'] !== null
                ? $this->travelerCount($input['max_travelers'], $field.'.max_travelers') : null;
            if ($maximum !== null && $maximum < $minimum) {
                $this->reject($field.'.max_travelers', 'Maximum group size must not be below minimum.');
            }
            $active = $input['is_active'] ?? true;
            if (! is_bool($active)) {
                $this->reject($field.'.is_active', 'Active flag must be a boolean.');
            }
            $rawRates = $input['rates'] ?? null;
            if (! is_array($rawRates) || ! array_is_list($rawRates) || count($rawRates) < 1 || count($rawRates) > 2) {
                $this->reject($field.'.rates', 'Provide one or two price rates.');
            }

            /** @var array<string,PricingRate> $byType */
            $byType = [];
            foreach ($rawRates as $ratePosition => $rate) {
                $rField = $field.'.rates.'.$ratePosition;
                if (! is_array($rate) || array_is_list($rate)) {
                    $this->reject($rField, 'Each price rate must be an object.');
                }
                /** @var array<array-key,mixed> $rate */
                $this->assertKeys($rate, ['traveler_type','regular_minor','sale_minor','sale_starts_at','sale_ends_at'], $rField);
                $type = $rate['traveler_type'] ?? null;
                if (! is_string($type) || ! in_array($type, ['adult','child','group'], true) || isset($byType[$type])) {
                    $this->reject($rField.'.traveler_type', 'Use distinct adult, child or group rate types.');
                }
                $regular = $this->amount($rate['regular_minor'] ?? null, $rField.'.regular_minor', $type === 'child');
                $sale = array_key_exists('sale_minor', $rate) && $rate['sale_minor'] !== null
                    ? $this->amount($rate['sale_minor'], $rField.'.sale_minor', $type === 'child') : null;
                if ($sale !== null && $sale >= $regular && $regular > 0) {
                    $this->reject($rField.'.sale_minor', 'Sale amount must be lower than regular amount.');
                }
                if ($regular === 0 && $sale !== null) {
                    $this->reject($rField.'.sale_minor', 'A free child rate cannot have a sale price.');
                }
                $start = $this->date($rate['sale_starts_at'] ?? null, $rField.'.sale_starts_at');
                $end = $this->date($rate['sale_ends_at'] ?? null, $rField.'.sale_ends_at');
                if ($sale === null && ($start !== null || $end !== null)) {
                    $this->reject($rField.'.sale_minor', 'Sale dates require a sale amount.');
                }
                if ($start !== null && $end !== null && $end <= $start) {
                    $this->reject($rField.'.sale_ends_at', 'Sale end must be after sale start.');
                }
                $byType[$type] = [
                    'traveler_type' => $type,
                    'regular_minor' => $regular,
                    'sale_minor' => $sale,
                    'sale_starts_at' => $start,
                    'sale_ends_at' => $end,
                ];
            }
            if ($model === 'per_person' && (! isset($byType['adult']) || isset($byType['group']))) {
                $this->reject($field.'.rates', 'Per-person pricing requires an adult rate and optionally a child rate.');
            }
            if ($model === 'per_group' && (count($byType) !== 1 || ! isset($byType['group']))) {
                $this->reject($field.'.rates', 'Per-group pricing requires one group rate and no person rates.');
            }
            $canonicalRates = [];
            foreach (['adult','child','group'] as $type) {
                if (isset($byType[$type])) {
                    $canonicalRates[] = $byType[$type];
                }
            }
            $output[] = [
                'code' => $code,
                'name' => $name,
                'currency' => $currency,
                'pricing_model' => $model,
                'min_travelers' => $minimum,
                'max_travelers' => $maximum,
                'is_active' => $active,
                'sort_order' => $position,
                'rates' => $canonicalRates,
            ];
        }
        return $output;
    }

    public function fractionDigits(string $currency): int
    {
        if (! array_key_exists($currency, self::CURRENCIES)) {
            $this->reject('currency', 'Unsupported currency.');
        }
        return self::CURRENCIES[$currency];
    }

    /**
     * @param array<array-key,mixed> $value
     * @param list<string> $allowed
     */
    private function assertKeys(array $value, array $allowed, string $field): void
    {
        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $this->reject($field, 'Unsupported price field supplied.');
            }
        }
    }

    private function string(mixed $value, string $field, int $limit): string
    {
        if (! is_string($value)) {
            $this->reject($field, 'Expected text.');
        }
        $trimmed = trim($value);
        if ($trimmed === '' || mb_strlen($trimmed) > $limit || strip_tags($trimmed) !== $trimmed) {
            $this->reject($field, 'Text is empty, too long or contains markup.');
        }
        return $trimmed;
    }

    private function travelerCount(mixed $count, string $field): int
    {
        if (! is_int($count) || $count < 1 || $count > 1000) {
            $this->reject($field, 'Travelers must be an integer from 1 to 1000.');
        }
        return $count;
    }

    private function amount(mixed $amount, string $field, bool $allowZero): int
    {
        if (! is_int($amount) || $amount < ($allowZero ? 0 : 1) || $amount > self::MAX_AMOUNT_MINOR) {
            $this->reject($field, 'Enter an integer amount in minor currency units within the supported limit.');
        }
        return $amount;
    }

    private function date(mixed $date, string $field): ?string
    {
        if ($date === null) {
            return null;
        }
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $date) !== 1) {
            $this->reject($field, 'Provide an ISO 8601 timestamp with timezone (seconds required).');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $this->reject($field, 'Invalid timestamp.');
        }
        return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** @return never */
    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
