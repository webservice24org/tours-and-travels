<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Models\Travel\Tour;
use App\Models\Travel\TourTag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Cross-tour tags. Writes are called inside TourManager's transaction. */
final class TourTagService
{
    public function sync(Tour $tour, mixed $names): bool
    {
        if (! is_array($names) || ! array_is_list($names) || count($names) > 20) {
            throw ValidationException::withMessages(['tag_names' => 'Choose at most 20 tags.']);
        }
        $map = [];
        $seen = [];
        foreach ($names as $index => $input) {
            if (! is_string($input)) {
                throw ValidationException::withMessages(['tag_names' => 'Tag names must be text.']);
            }
            $name = trim(preg_replace('/\s+/u', ' ', $input) ?? $input);
            $key = Str::lower($name);
            if ($name === '' || mb_strlen($name) > 80 || str_contains($name, '<') || str_contains($name, '>')) {
                throw ValidationException::withMessages(['tag_names' => 'Tag names must be 1–80 characters and contain no markup.']);
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $tag = TourTag::query()->where('name_key', $key)->first();
            if (! $tag instanceof TourTag) {
                try {
                    $tag = TourTag::query()->create(['name' => $name, 'name_key' => $key]);
                } catch (QueryException $error) {
                    // A concurrent writer may have inserted the same unique tag.
                    $tag = TourTag::query()->where('name_key', $key)->first();
                    if (! $tag instanceof TourTag) {
                        throw $error;
                    }
                }
            }
            $map[(int) $tag->getKey()] = ['sort_order' => $index];
        }
        $before = $tour->tags()->orderByPivot('sort_order')->pluck('travel_tour_tags.id')->map(static fn (mixed $id): int => (int) $id)->all();
        $after = array_map('intval', array_keys($map));
        if ($before === $after) {
            return false;
        }
        $tour->tags()->sync($map);
        return true;
    }
}
