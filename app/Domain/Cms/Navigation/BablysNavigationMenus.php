<?php

declare(strict_types=1);

namespace App\Domain\Cms\Navigation;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Safe, versioned navigation drafts. P4A.2 deliberately has no publish action.
 * Public output comes ONLY from published snapshots, never draft revisions.
 */
final class BablysNavigationMenus
{
    /** @return list<array<string, int|string|null>> */
    public function summaries(): array
    {
        $rows = DB::table('bablys_navigation_menus')
            ->orderBy('menu_key')->get(['id', 'menu_key', 'draft_name', 'draft_version', 'published_version', 'updated_at']);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'key' => (string) $row->menu_key,
                'name' => (string) $row->draft_name,
                'draft_version' => (int) $row->draft_version,
                'published_version' => (int) $row->published_version,
                'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at,
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function editor(int $menuId): array
    {
        $row = DB::table('bablys_navigation_menus')->where('id', $menuId)->first();
        if (! $row instanceof stdClass) {
            throw new NotFoundHttpException('Navigation menu not found.');
        }
        return [
            'id' => (int) $row->id,
            'key' => (string) $row->menu_key,
            'name' => (string) $row->draft_name,
            'draft_items' => $this->decodeItems($row->draft_items),
            'draft_version' => (int) $row->draft_version,
            'published_version' => (int) $row->published_version,
            'updated_at' => $row->updated_at === null ? null : (string) $row->updated_at,
            'can_publish' => false,
        ];
    }

    public function create(string $key, string $name, int $actorId): int
    {
        $key = trim($key);
        $name = trim($name);
        if (preg_match('/\A[a-z][a-z0-9-]{2,47}\z/D', $key) !== 1 || $name === '') {
            throw ValidationException::withMessages(['menu_key' => 'A valid unique menu key and name are required.']);
        }
        // Database unique index also protects against concurrent menu creation.
        return (int) DB::table('bablys_navigation_menus')->insertGetId([
            'menu_key' => $key,
            'draft_name' => $name,
            'draft_items' => '[]',
            'draft_version' => 0,
            'published_version' => 0,
            'draft_updated_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $input Validated form data; normalized again at the domain boundary.
     * @return array{draft_version: int}
     */
    public function saveDraft(int $menuId, array $input, int $actorId): array
    {
        $expected = (int) $input['expected_version'];
        $name = trim((string) $input['name']);
        $items = $this->normalizeItems($input['items']);
        $encoded = $this->encodeItems($items);

        return DB::transaction(static function () use ($menuId, $expected, $name, $encoded, $actorId): array {
            $row = DB::table('bablys_navigation_menus')->where('id', $menuId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
            throw new NotFoundHttpException('Navigation menu not found.');
        }
            if ((int) $row->draft_version !== $expected) {
                throw ValidationException::withMessages([
                    'expected_version' => 'Another editor saved newer menu changes. Reload before saving.',
                ]);
            }
            $version = $expected + 1;
            DB::table('bablys_navigation_menus')->where('id', $menuId)->update([
                'draft_name' => $name,
                'draft_items' => $encoded,
                'draft_version' => $version,
                'draft_updated_by' => $actorId,
                'updated_at' => now(),
            ]);
            DB::table('bablys_navigation_menu_revisions')->insert([
                'navigation_menu_id' => $menuId,
                'draft_version' => $version,
                'draft_name' => $name,
                'draft_items' => $encoded,
                'edited_by' => $actorId,
                'created_at' => now(),
            ]);
            return ['draft_version' => $version];
        }, 3);
    }

    public function deleteUnpublished(int $menuId, int $expectedVersion): void
    {
        DB::transaction(static function () use ($menuId, $expectedVersion): void {
            $row = DB::table('bablys_navigation_menus')->where('id', $menuId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
            throw new NotFoundHttpException('Navigation menu not found.');
        }
            if ((int) $row->draft_version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'expected_version' => 'This menu has changed. Reload before deleting.',
                ]);
            }
            if ((int) $row->published_version > 0 || $row->published_items !== null) {
                throw ValidationException::withMessages([
                    'menu' => 'Published menus cannot be deleted. Unpublish through the future publication workflow.',
                ]);
            }
            DB::table('bablys_navigation_menus')->where('id', $menuId)->delete();
        }, 3);
    }

    /**
     * @return array<string, array{name: string, items: list<array<string, mixed>>}>
     */
    public function publicSnapshot(): array
    {
        $rows = DB::table('bablys_navigation_menus')
            ->where('published_version', '>', 0)
            ->whereNotNull('published_items')
            ->whereNotNull('published_name')
            ->orderBy('menu_key')
            ->get(['menu_key', 'published_name', 'published_items']);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->menu_key] = [
                'name' => (string) $row->published_name,
                'items' => $this->decodeItems($row->published_items),
            ];
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<array{id: string, label: string, kind: string, url: string, parent_id: ?string, new_tab: bool}>
     */
    private function normalizeItems(mixed $raw): array
    {
        if (! is_array($raw) || count($raw) > 80 || ! array_is_list($raw)) {
            throw ValidationException::withMessages(['items' => 'Provide an ordered list of no more than 80 items.']);
        }
        $items = [];
        $parents = [];
        foreach ($raw as $index => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['items' => 'Each menu entry must be an object.']);
            }
            $id = $item['id'] ?? null;
            $label = $item['label'] ?? null;
            $kind = $item['kind'] ?? null;
            $url = $item['url'] ?? null;
            $parent = $item['parent_id'] ?? null;
            $newTab = $item['new_tab'] ?? null;
            if (! is_string($id) || ! is_string($label) || ! is_string($kind)
                || ! is_string($url) || ($parent !== null && ! is_string($parent))
                || ! is_bool($newTab)) {
                throw ValidationException::withMessages(["items.$index" => 'Invalid menu item.']);
            }
            if (array_key_exists($id, $parents)) {
                throw ValidationException::withMessages(["items.$index.id" => 'Item identifiers must be unique.']);
            }
            if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $id) !== 1) {
                throw ValidationException::withMessages(["items.$index.id" => 'Invalid item identifier.']);
            }
            $label = trim($label);
            if ($label === '' || mb_strlen($label) > 100 || preg_match('/[<>\x00-\x1F\x7F]/', $label) === 1) {
                throw ValidationException::withMessages(["items.$index.label" => 'Enter a safe label (1–100 characters).']);
            }
            if ($kind !== 'internal' && $kind !== 'external') {
                throw ValidationException::withMessages(["items.$index.kind" => 'Choose internal or external.']);
            }
            $this->assertSafeUrl($kind, $url, $index);
            if ($kind === 'internal' && $newTab) {
                throw ValidationException::withMessages(["items.$index.new_tab" => 'Internal menu links open in the same tab.']);
            }
            $parents[$id] = $parent;
            $items[] = [
                'id' => $id, 'label' => $label, 'kind' => $kind,
                'url' => $url, 'parent_id' => $parent, 'new_tab' => $newTab,
            ];
        }
        // Validate references, eliminate cycles, limit nesting to root + 2 levels.
        foreach ($parents as $id => $parent) {
            $seen = [$id => true];
            $depth = 1;
            while ($parent !== null) {
                if (! array_key_exists($parent, $parents)) {
                    throw ValidationException::withMessages(['items' => 'A menu item refers to a missing parent.']);
                }
                if (isset($seen[$parent])) {
                    throw ValidationException::withMessages(['items' => 'Circular nesting is not allowed.']);
                }
                $seen[$parent] = true;
                $depth++;
                if ($depth > 3) {
                    throw ValidationException::withMessages(['items' => 'Menu nesting is limited to three levels.']);
                }
                $parent = $parents[$parent];
            }
        }
        return $items;
    }

    private function assertSafeUrl(string $kind, string $url, int $index): void
    {
        if ($url === '' || strlen($url) > 350 || preg_match('/[<>\\\\\x00-\x20\x7F]/', $url) === 1) {
            throw ValidationException::withMessages(["items.$index.url" => 'Enter a safe link without spaces or control characters.']);
        }
        if ($kind === 'internal') {
            if (preg_match('@\A/(?!/)[A-Za-z0-9/_~.\-?=&%#]*\z@D', $url) !== 1) {
                throw ValidationException::withMessages(["items.$index.url" => 'Internal URLs must begin with one slash, for example /tours.']);
            }
            return;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages(["items.$index.url" => 'External URLs must be valid HTTPS links without credentials.']);
        }
    }

    /** @param list<array<string, mixed>> $items */
    private function encodeItems(array $items): string
    {
        try {
            return json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new \RuntimeException('Could not encode the menu draft.', 0, $e);
        }
    }

    /** @return list<array<string, mixed>> */
    private function decodeItems(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $node) {
            if (! is_array($node)) {
                return [];
            }
            $out[] = [
                'id' => (string) ($node['id'] ?? ''),
                'label' => (string) ($node['label'] ?? ''),
                'kind' => (string) ($node['kind'] ?? ''),
                'url' => (string) ($node['url'] ?? ''),
                'parent_id' => isset($node['parent_id']) && is_string($node['parent_id']) ? $node['parent_id'] : null,
                'new_tab' => ($node['new_tab'] ?? false) === true,
            ];
        }
        return $out;
    }
}
