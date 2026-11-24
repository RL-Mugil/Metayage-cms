<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A person's own saved filter sets per page; nobody sees anyone else's. */
class SavedViewController extends Controller
{
    private const MAX_PER_PAGE = 20;

    public function index(Request $request): JsonResponse
    {
        $page = (string) $request->query('page', '');
        abort_unless(in_array($page, SavedView::PAGES, true), 422, 'Unknown page.');

        return response()->json(SavedView::where('user_id', $request->user()->id)->where('page', $page)->orderBy('name')->get(['id', 'name', 'filters']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => 'required|in:'.implode(',', SavedView::PAGES),
            'name' => 'required|string|max:60',
            'filters' => 'required|array|max:20',
            'filters.*' => 'nullable',
        ]);
        $user = $request->user();
        abort_if(SavedView::where('user_id', $user->id)->where('page', $data['page'])->count() >= self::MAX_PER_PAGE, 422, 'You can keep up to 20 views per page.');
        abort_if(strlen(json_encode($data['filters'])) > 4000, 422, 'That view is too large to save.');

        $view = DB::transaction(function () use ($user, $data, $request) {
            $view = SavedView::updateOrCreate(['user_id' => $user->id, 'page' => $data['page'], 'name' => $data['name']], ['filters' => $data['filters']]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'create', 'subject_type' => 'SavedView', 'subject_id' => $view->id,
                'metadata' => ['page' => $data['page'], 'name' => $data['name']], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

            return $view;
        });

        return response()->json($view->only(['id', 'name', 'filters']), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $view = SavedView::where('user_id', $request->user()->id)->findOrFail($id);
        DB::transaction(function () use ($view, $request) {
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'delete', 'subject_type' => 'SavedView', 'subject_id' => $view->id,
                'metadata' => ['name' => $view->name], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
            $view->delete();
        });

        return response()->json(['ok' => true]);
    }
}
