<?php

declare(strict_types=1);

namespace App\Domain\Cms\Publishing;

use App\Domain\Cms\Settings\BablysSiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Version-checked atomic CMS publishing/rollback. Never mutates draft snapshots.
 * P4A.4 will bind live public header/footer to the published-only APIs.
 */
final class BablysCmsPublishing
{
    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $settings = DB::table('bablys_site_settings')->where('scope', 'global')->first();
        $menus = DB::table('bablys_navigation_menus')->orderBy('menu_key')
            ->get(['id', 'menu_key', 'draft_name', 'draft_version', 'published_version', 'publication_serial', 'published_at']);
        $list = [];
        foreach ($menus as $menu) {
            $list[] = [
                'id' => (int) $menu->id,
                'key' => (string) $menu->menu_key,
                'name' => (string) $menu->draft_name,
                'draft_version' => (int) $menu->draft_version,
                'published_version' => (int) $menu->published_version,
                'publication_serial' => (int) $menu->publication_serial,
                'published_at' => $menu->published_at === null ? null : (string) $menu->published_at,
            ];
        }
        return [
            'settings' => [
                'draft_version' => $settings === null ? 0 : (int) $settings->draft_version,
                'published_version' => $settings === null ? 0 : (int) $settings->published_version,
                'publication_serial' => $settings === null ? 0 : (int) $settings->publication_serial,
                'published_at' => $settings?->published_at,
            ],
            'menus' => $list,
        ];
    }

    /** @return array<string, mixed> */
    public function previewSettings(): array
    {
        $editor = app(BablysSiteSettings::class)->editorSnapshot();
        $row = DB::table('bablys_site_settings')->where('scope', 'global')->first();
        return [
            'kind' => 'settings',
            'name' => 'Global website settings',
            'key' => null,
            'subject_id' => $row === null ? null : (int) $row->id,
            'draft' => $editor['draft'],
            'published' => $editor['published'],
            'draft_version' => $editor['draft_version'],
            'published_version' => $editor['published_version'],
            'publication_serial' => $row === null ? 0 : (int) $row->publication_serial,
            'history' => $row === null ? [] : $this->history('settings', (int) $row->id),
        ];
    }

    /** @return array<string, mixed> */
    public function previewMenu(int $menuId): array
    {
        $row = $this->findMenu($menuId);
        return [
            'kind' => 'navigation',
            'name' => (string) $row->draft_name,
            'key' => (string) $row->menu_key,
            'subject_id' => (int) $row->id,
            'draft' => ['name' => (string) $row->draft_name, 'items' => $this->decodeList($row->draft_items)],
            'published' => $row->published_items === null ? null : [
                'name' => (string) $row->published_name,
                'items' => $this->decodeList($row->published_items),
            ],
            'draft_version' => (int) $row->draft_version,
            'published_version' => (int) $row->published_version,
            'publication_serial' => (int) $row->publication_serial,
            'history' => $this->history('navigation', $menuId),
        ];
    }

    /**
     * @return list<array{id: int, action: string, serial: int, source_draft_version: int, actor_id: ?int, created_at: string}>
     */
    private function history(string $type, int $id): array
    {
        $events = DB::table('bablys_cms_publication_events')
            ->where('subject_type', $type)->where('subject_id', $id)
            ->orderByDesc('id')->limit(30)->get(['id','action','publication_serial','source_draft_version','actor_id','created_at']);
        $out = [];
        foreach ($events as $event) {
            $out[] = [
                'id' => (int) $event->id,
                'action' => (string) $event->action,
                'serial' => (int) $event->publication_serial,
                'source_draft_version' => (int) $event->source_draft_version,
                'actor_id' => $event->actor_id === null ? null : (int) $event->actor_id,
                'created_at' => (string) $event->created_at,
            ];
        }
        return $out;
    }

    public function publishSettings(int $draftVersion, int $serial, int $actorId): void
    {
        DB::transaction(function () use ($draftVersion, $serial, $actorId): void {
            $row = DB::table('bablys_site_settings')->where('scope', 'global')->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw ValidationException::withMessages(['expected_draft_version' => 'Save a settings draft before publishing.']);
            }
            $this->assertExpected($row, $draftVersion, $serial);
            if ((int) $row->draft_version < 1 || ! is_string($row->draft_payload) || $row->draft_payload === '') {
                throw ValidationException::withMessages(['expected_draft_version' => 'Save a settings draft before publishing.']);
            }
            // Sanitize projections with the existing typed settings contract.
            $settings = app(BablysSiteSettings::class)->editorSnapshot()['draft'];
            $snapshot = $this->encode($settings);
            $next = $serial + 1;
            DB::table('bablys_site_settings')->where('id', $row->id)->update([
                'published_payload' => $snapshot,
                'published_version' => $draftVersion,
                'publication_serial' => $next,
                'published_by' => $actorId,
                'published_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record('settings', (int) $row->id, 'publish', $next, $draftVersion, $snapshot, $actorId, null);
        }, 3);
    }

    public function publishMenu(int $menuId, int $draftVersion, int $serial, int $actorId): void
    {
        DB::transaction(function () use ($menuId, $draftVersion, $serial, $actorId): void {
            $row = DB::table('bablys_navigation_menus')->where('id', $menuId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new NotFoundHttpException('Navigation menu not found.');
            }
            $this->assertExpected($row, $draftVersion, $serial);
            if ((int) $row->draft_version < 1 || ! is_string($row->draft_items)) {
                throw ValidationException::withMessages(['expected_draft_version' => 'Save the menu draft before publishing.']);
            }
            $snapshot = $this->encode([
                'name' => (string) $row->draft_name,
                'items' => $this->decodeList($row->draft_items),
            ]);
            $next = $serial + 1;
            DB::table('bablys_navigation_menus')->where('id', $menuId)->update([
                'published_name' => (string) $row->draft_name,
                'published_items' => (string) $row->draft_items,
                'published_version' => $draftVersion,
                'publication_serial' => $next,
                'published_by' => $actorId,
                'published_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record('navigation', $menuId, 'publish', $next, $draftVersion, $snapshot, $actorId, null);
        }, 3);
    }

    public function rollbackSettings(int $eventId, int $draftVersion, int $serial, int $actorId): void
    {
        DB::transaction(function () use ($eventId, $draftVersion, $serial, $actorId): void {
            $row = DB::table('bablys_site_settings')->where('scope', 'global')->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new NotFoundHttpException('No published settings to restore.');
            }
            $this->assertExpected($row, $draftVersion, $serial);
            $previous = $this->historical('settings', (int) $row->id, $eventId, $serial);
            $snapshot = (string) $previous->snapshot;
            // Settings snapshots were written through the typed allowlist. Preserve unchanged draft.
            $next = $serial + 1;
            DB::table('bablys_site_settings')->where('id', $row->id)->update([
                'published_payload' => $snapshot,
                'published_version' => (int) $previous->source_draft_version,
                'publication_serial' => $next,
                'published_by' => $actorId,
                'published_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record('settings', (int) $row->id, 'rollback', $next,
                (int) $previous->source_draft_version, $snapshot, $actorId, $eventId);
        }, 3);
    }

    public function rollbackMenu(int $menuId, int $eventId, int $draftVersion, int $serial, int $actorId): void
    {
        DB::transaction(function () use ($menuId, $eventId, $draftVersion, $serial, $actorId): void {
            $row = DB::table('bablys_navigation_menus')->where('id', $menuId)->lockForUpdate()->first();
            if (! $row instanceof stdClass) {
                throw new NotFoundHttpException('Navigation menu not found.');
            }
            $this->assertExpected($row, $draftVersion, $serial);
            $previous = $this->historical('navigation', $menuId, $eventId, $serial);
            $saved = json_decode((string) $previous->snapshot, true);
            if (! is_array($saved) || ! isset($saved['name'], $saved['items'])
                || ! is_string($saved['name']) || ! is_array($saved['items'])) {
                throw new \RuntimeException('Invalid historical navigation snapshot.');
            }
            $next = $serial + 1;
            DB::table('bablys_navigation_menus')->where('id', $menuId)->update([
                'published_name' => $saved['name'],
                'published_items' => $this->encode($saved['items']),
                'published_version' => (int) $previous->source_draft_version,
                'publication_serial' => $next,
                'published_by' => $actorId,
                'published_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record('navigation', $menuId, 'rollback', $next,
                (int) $previous->source_draft_version, (string) $previous->snapshot, $actorId, $eventId);
        }, 3);
    }

    private function assertExpected(stdClass $row, int $expectedDraft, int $expectedSerial): void
    {
        if ((int) $row->draft_version !== $expectedDraft) {
            throw ValidationException::withMessages(['expected_draft_version' => 'Draft changed. Reload before publishing or restoring.']);
        }
        if ((int) $row->publication_serial !== $expectedSerial) {
            throw ValidationException::withMessages(['expected_publication_serial' => 'Published content changed. Reload before publishing or restoring.']);
        }
    }

    private function historical(string $type, int $subjectId, int $eventId, int $serial): stdClass
    {
        if ($serial < 1) {
            throw ValidationException::withMessages(['publication_event_id' => 'Nothing has been published yet.']);
        }
        $event = DB::table('bablys_cms_publication_events')
            ->where('subject_type', $type)->where('subject_id', $subjectId)
            ->where('id', $eventId)->where('publication_serial', '<', $serial)
            ->first();
        if (! $event instanceof stdClass) {
            throw ValidationException::withMessages(['publication_event_id' => 'Choose a historical publication belonging to this item.']);
        }
        return $event;
    }

    private function findMenu(int $id): stdClass
    {
        $row = DB::table('bablys_navigation_menus')->where('id', $id)->first();
        if (! $row instanceof stdClass) {
            throw new NotFoundHttpException('Navigation menu not found.');
        }
        return $row;
    }

    private function record(string $type, int $id, string $action, int $serial, int $draftVersion, string $snapshot, int $actorId, ?int $restoredId): void
    {
        DB::table('bablys_cms_publication_events')->insert([
            'subject_type' => $type, 'subject_id' => $id,
            'action' => $action, 'publication_serial' => $serial,
            'source_draft_version' => $draftVersion, 'snapshot' => $snapshot,
            'actor_id' => $actorId, 'restored_event_id' => $restoredId,
            'created_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function decodeList(mixed $raw): array
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
        $list = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                return [];
            }
            $list[] = $item;
        }
        return $list;
    }

    /** @param mixed $value */
    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new \RuntimeException('Could not encode CMS publication.', 0, $e);
        }
    }
}
