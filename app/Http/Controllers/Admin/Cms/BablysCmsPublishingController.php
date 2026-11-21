<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Cms;

use App\Domain\Cms\Publishing\BablysCmsPublishing;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class BablysCmsPublishingController extends Controller
{
    public function index(Request $request, BablysCmsPublishing $publisher): Response
    {
        Gate::authorize('settings.manage');
        return $this->privatePage(Inertia::render('admin/cms-publishing/index', $publisher->dashboard())->toResponse($request));
    }

    public function previewSettings(Request $request, BablysCmsPublishing $publisher): Response
    {
        Gate::authorize('settings.manage');
        return $this->privatePage(Inertia::render('admin/cms-publishing/preview', [
            'snapshot' => $publisher->previewSettings(),
        ])->toResponse($request));
    }

    public function previewMenu(Request $request, BablysCmsPublishing $publisher, int $menu): Response
    {
        Gate::authorize('settings.manage');
        return $this->privatePage(Inertia::render('admin/cms-publishing/preview', [
            'snapshot' => $publisher->previewMenu($menu),
        ])->toResponse($request));
    }

    public function publishSettings(Request $request, BablysCmsPublishing $publisher): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $this->versions($request);
        $publisher->publishSettings($data['expected_draft_version'], $data['expected_publication_serial'], $this->actor($request));
        return redirect()->route('admin.cms-publishing.settings.preview')->with('success', 'Website settings published. Existing draft preserved.');
    }

    public function publishMenu(Request $request, BablysCmsPublishing $publisher, int $menu): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $this->versions($request);
        $publisher->publishMenu($menu, $data['expected_draft_version'], $data['expected_publication_serial'], $this->actor($request));
        return redirect()->route('admin.cms-publishing.navigation.preview', ['menu' => $menu])->with('success', 'Navigation menu published.');
    }

    public function rollbackSettings(Request $request, BablysCmsPublishing $publisher): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $this->rollbackInput($request);
        $publisher->rollbackSettings($data['publication_event_id'], $data['expected_draft_version'],
            $data['expected_publication_serial'], $this->actor($request));
        return redirect()->route('admin.cms-publishing.settings.preview')->with('success', 'Previously published settings restored. Draft unchanged.');
    }

    public function rollbackMenu(Request $request, BablysCmsPublishing $publisher, int $menu): RedirectResponse
    {
        Gate::authorize('settings.manage');
        $data = $this->rollbackInput($request);
        $publisher->rollbackMenu($menu, $data['publication_event_id'], $data['expected_draft_version'],
            $data['expected_publication_serial'], $this->actor($request));
        return redirect()->route('admin.cms-publishing.navigation.preview', ['menu' => $menu])->with('success', 'Previously published menu restored. Draft unchanged.');
    }

    /** @return array{expected_draft_version: int, expected_publication_serial: int} */
    private function versions(Request $request): array
    {
        $data = $request->validate([
            'expected_draft_version' => ['required', 'integer', 'min:0'],
            'expected_publication_serial' => ['required', 'integer', 'min:0'],
        ]);
        return [
            'expected_draft_version' => (int) $data['expected_draft_version'],
            'expected_publication_serial' => (int) $data['expected_publication_serial'],
        ];
    }

    /** @return array{publication_event_id: int, expected_draft_version: int, expected_publication_serial: int} */
    private function rollbackInput(Request $request): array
    {
        $data = $request->validate([
            'publication_event_id' => ['required', 'integer', 'min:1'],
            'expected_draft_version' => ['required', 'integer', 'min:0'],
            'expected_publication_serial' => ['required', 'integer', 'min:0'],
        ]);
        return [
            'publication_event_id' => (int) $data['publication_event_id'],
            'expected_draft_version' => (int) $data['expected_draft_version'],
            'expected_publication_serial' => (int) $data['expected_publication_serial'],
        ];
    }

    private function actor(Request $request): int
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        return (int) $actor->getKey();
    }

    private function privatePage(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        return $response;
    }
}
