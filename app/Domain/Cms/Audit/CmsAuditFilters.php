<?php

declare(strict_types=1);

namespace App\Domain\Cms\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Immutable, server-validated audit-log query values. Never accepts arbitrary
 * column names, sort clauses, or SQL fragments from the browser.
 */
final class CmsAuditFilters
{
    public function __construct(
        public readonly ?string $search,
        public readonly ?string $action,
        public readonly ?int $actorId,
        public readonly ?string $subjectType,
        public readonly ?string $subjectId,
        public readonly ?string $dateFrom,
        public readonly ?string $dateTo,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $rules = [
            'search' => ['sometimes', 'string', 'max:100'],
            'action' => ['sometimes', 'string', 'max:100', 'regex:/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/D'],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'subject_type' => ['sometimes', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9_]*\z/D'],
            'subject_id' => ['sometimes', 'string', 'max:64'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])],
        ];
        if ($request->query('date_from') !== null) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }
        Validator::make($request->query(), $rules)->validate();

        return new self(
            self::stringValue($request, 'search'),
            self::stringValue($request, 'action'),
            self::integerValue($request, 'actor_id'),
            self::stringValue($request, 'subject_type'),
            self::stringValue($request, 'subject_id'),
            self::stringValue($request, 'date_from'),
            self::stringValue($request, 'date_to'),
            self::integerValue($request, 'page') ?? 1,
            self::integerValue($request, 'per_page') ?? 25,
        );
    }

    private static function stringValue(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function integerValue(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_string($value) && ctype_digit($value)
            ? (int) $value
            : null;

    }
}
