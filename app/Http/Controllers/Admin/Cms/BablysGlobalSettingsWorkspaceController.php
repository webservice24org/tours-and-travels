<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Navigation\BablysNavigationMenus;
use App\Domain\Cms\Publishing\BablysCmsPublishing;
use App\Domain\Cms\Settings\BablysSiteSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\SaveBablysNavigationMenuRequest;
use App\Http\Requests\Admin\Cms\UpdateBablysSiteSettingsRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * WordPress-like presentation layer for existing P4A.1–P4A.4 services.
 * No separate storage, unreviewed publication, or new authorization policy.
 */
final class BablysGlobalSettingsWorkspaceController extends Controller
{
    /** @var array<string, array<string, string>> */
    private const MENU_SLOTS = [
        'header' => ['main-header' => 'Main Header'],
        'footer' => [
            'footer-domestic' => 'Domestic Tours',
            'footer-global' => 'Global Destinations',
            'footer-links' => 'Footer Bottom Links',
        ],
    ];

    public function overview(Request $request, BablysSiteSettings $settings, BablysCmsPublishing $publishing): Response
    {
        Gate::authorize('settings.manage');
        return $this->privatePage(Inertia::render('admin/global-settings/workspace', [
            'section' => 'overview',
            'settings' => $settings->editorSnapshot(),
            'settings_publication_serial' => (int) ($publishing->previewSettings()['publication_serial'] ?? 0),
            'menus' => [],
        ])->toResponse($request));
    }

    public function header(Request $request, BablysSiteSettings $settings, BablysNavigationMenus $menus, BablysCmsPublishing $publishing): Response
    {
        return $this->editor($request, $settings, $menus, $publishing, 'header');
    }

    public function footer(Request $request, BablysSiteSettings $settings, BablysNavigationMenus $menus, BablysCmsPublishing $publishing): Response
    {
        return $this->editor($request, $settings, $menus, $publishing, 'footer');
    }

    private function editor(Request $request, BablysSiteSettings $settings, BablysNavigationMenus $menus, BablysCmsPublishing $publishing, string $section): Response
    {
        Gate::authorize('settings.manage');
        $known = [];
        foreach ($menus->summaries() as $summary) {
            $known[(string) $summary['key']] = $summary;
        }
        $slots = [];
        foreach (self::MENU_SLOTS[$section] as $key => $label) {
            $summary = $known[$key] ?? null;
            $editor = $summary === null ? null : $menus->editor((int) $summary['id']);
            if ($editor !== null) {
                $editor['publication_serial'] = (int) ($publishing->previewMenu((int) $summary['id'])['publication_serial'] ?? 0);
            }
            $slots[] = ['key' => $key, 'label' => $label, 'menu' => $editor];
        }
        return $this->privatePage(Inertia::render('admin/global-settings/workspace', [
            'section' => $section,
            'settings' => $settings->editorSnapshot(),
            'settings_publication_serial' => (int) ($publishing->previewSettings()['publication_serial'] ?? 0),
            'menus' => $slots,
        ])->toResponse($request));
    }

    public function saveHeaderSettings(UpdateBablysSiteSettingsRequest $request, BablysSiteSettings $settings): RedirectResponse
    {
        return $this->saveSettings($request, $settings, 'header');
    }

    public function saveFooterSettings(UpdateBablysSiteSettingsRequest $request, BablysSiteSettings $settings): RedirectResponse
    {
        return $this->saveSettings($request, $settings, 'footer');
    }

    private function saveSettings(UpdateBablysSiteSettingsRequest $request, BablysSiteSettings $settings, string $section): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $settings->saveDraft($request->validated(), $this->actor($request));
        return $this->backTo($section, 'Settings draft saved. Public website unchanged until you publish.');
    }

    public function publishHeaderSettings(Request $request, BablysCmsPublishing $publisher): RedirectResponse
    {
        return $this->publishSettings($request, $publisher, 'header');
    }

    public function publishFooterSettings(Request $request, BablysCmsPublishing $publisher): RedirectResponse
    {
        return $this->publishSettings($request, $publisher, 'footer');
    }

    private function publishSettings(Request $request, BablysCmsPublishing $publisher, string $section): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $versions = $this->versions($request);
        $publisher->publishSettings($versions['draft'], $versions['serial'], $this->actor($request));
        return $this->backTo($section, 'Approved site settings published.');
    }

    public function createMenu(Request $request, BablysNavigationMenus $menus, string $section): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $this->section($section);
        $data = $request->validate(['menu_key' => ['required', 'string', 'max:48']]);
        $key = (string) $data['menu_key'];
        $name = self::MENU_SLOTS[$section][$key] ?? null;
        if ($name === null) {
            throw ValidationException::withMessages(['menu_key' => 'This menu location is not managed in the selected editor.']);
        }
        $menus->create($key, $name, $this->actor($request));
        return $this->backTo($section, 'Menu created. Add links, save a draft, then publish.');
    }

    public function saveMenu(SaveBablysNavigationMenuRequest $request, BablysNavigationMenus $menus, string $section, int $menu): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $this->assertMenu($menus, $section, $menu);
        $menus->saveDraft($menu, $request->validated(), $this->actor($request));
        return $this->backTo($section, 'Menu draft saved. Public menu unchanged until publication.');
    }

    public function publishMenu(Request $request, BablysNavigationMenus $menus, BablysCmsPublishing $publisher, string $section, int $menu): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $this->assertMenu($menus, $section, $menu);
        $versions = $this->versions($request);
        $publisher->publishMenu($menu, $versions['draft'], $versions['serial'], $this->actor($request));
        return $this->backTo($section, 'Approved navigation menu published.');
    }

    private function assertMenu(BablysNavigationMenus $menus, string $section, int $menu): void
    {
        $this->section($section);
        $editor = $menus->editor($menu);
        abort_unless(array_key_exists((string) $editor['key'], self::MENU_SLOTS[$section]), 404);
    }

    private function section(string $section): void
    {
        abort_unless(array_key_exists($section, self::MENU_SLOTS), 404);
    }

    /** @return array{draft: int, serial: int} */
    private function versions(Request $request): array
    {
        $data = $request->validate([
            'expected_draft_version' => ['required', 'integer', 'min:0'],
            'expected_publication_serial' => ['required', 'integer', 'min:0'],
        ]);
        return ['draft' => (int) $data['expected_draft_version'], 'serial' => (int) $data['expected_publication_serial']];
    }

    private function actor(Request $request): int
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        return (int) $actor->getKey();
    }

    private function backTo(string $section, string $message): RedirectResponse
    {
        return redirect()->route('admin.global-settings.'.$section)->with('success', $message);
    }

    private function privatePage(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
