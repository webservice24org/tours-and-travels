<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Audit;

use App\Domain\Cms\Audit\CmsAuditFilters;
use App\Domain\Cms\Audit\CmsAuditReader;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CmsAuditFeedController extends Controller
{
    public function index(Request $request, CmsAuditReader $reader): JsonResponse
    {
        $this->authorizeViewer($request, $reader);
        return response()->json($reader->paginate(CmsAuditFilters::fromRequest($request)));
    }

    public function options(Request $request, CmsAuditReader $reader): JsonResponse
    {
        $this->authorizeViewer($request, $reader);
        return response()->json(['data' => $reader->options()]);
    }

    public function show(Request $request, string $event, CmsAuditReader $reader): JsonResponse
    {
        $this->authorizeViewer($request, $reader);
        $record = $reader->find($event);
        abort_if($record === null, 404);

        return response()->json(['data' => $record]);
    }

    private function authorizeViewer(Request $request, CmsAuditReader $reader): void
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $reader->assertViewer($actor);
    }
}
