<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\SiteSettings;
use App\Support\HtmlSanitizer;
use App\Support\LegalPageDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * System-admin controls for what every user (and the public) sees: the environment badge,
 * maintenance mode, and the editable privacy / terms pages. All writes are super_admin only.
 */
class SiteAdminController extends Controller
{
    /** Everything the Settings screen needs in one call. */
    public function show(): JsonResponse
    {
        Gate::authorize('manage-site');

        $pages = [];
        foreach (SiteSettings::PAGE_SLUGS as $slug) {
            $saved = SiteSettings::get("page_{$slug}_html");
            $pages[$slug] = [
                'html'       => $saved !== null && $saved !== '' ? $saved : LegalPageDefaults::for($slug),
                'customized' => $saved !== null && $saved !== '',
                'updated_at' => SiteSettings::updatedAt("page_{$slug}_html"),
            ];
        }

        return response()->json([
            'badge'       => SiteSettings::badge(),
            'maintenance' => SiteSettings::maintenance(),
            'pages'       => $pages,
        ]);
    }

    public function updateBadge(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');

        $data = $request->validate(['text' => ['required', 'string', 'min:1', 'max:40']]);
        $text = trim(strip_tags($data['text']));
        abort_if($text === '', 422, 'Badge text cannot be empty.');

        DB::transaction(function () use ($request, $text) {
            SiteSettings::put('env_badge_text', $text);
            $this->audit($request, 'update_env_badge', ['text' => $text]);
        });

        return response()->json(['badge' => $text]);
    }

    public function updateMaintenance(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'title'   => ['nullable', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:600'],
            'contact' => ['nullable', 'email:rfc', 'max:150'],
        ]);

        DB::transaction(function () use ($request, $data) {
            SiteSettings::put('maintenance_enabled', $data['enabled'] ? 'true' : 'false');
            foreach (['title', 'tagline', 'message', 'contact'] as $field) {
                SiteSettings::put("maintenance_{$field}", trim(strip_tags((string) ($data[$field] ?? ''))));
            }
            $this->audit($request, $data['enabled'] ? 'maintenance_on' : 'maintenance_off', ['enabled' => $data['enabled']]);
        });

        return response()->json(['maintenance' => SiteSettings::maintenance()]);
    }

    public function updatePage(Request $request, string $slug): JsonResponse
    {
        Gate::authorize('manage-site');
        abort_unless(in_array($slug, SiteSettings::PAGE_SLUGS, true), 404);

        $data = $request->validate(['html' => ['required', 'string', 'max:200000']]);
        $clean = HtmlSanitizer::clean($data['html']);
        abort_if($clean === '', 422, 'The page cannot be empty.');

        DB::transaction(function () use ($request, $slug, $clean) {
            SiteSettings::put("page_{$slug}_html", $clean);
            $this->audit($request, 'update_legal_page', ['page' => $slug, 'length' => strlen($clean)]);
        });

        return response()->json(['html' => $clean, 'customized' => true]);
    }

    /** Discard the custom version and fall back to the built-in text. */
    public function resetPage(Request $request, string $slug): JsonResponse
    {
        Gate::authorize('manage-site');
        abort_unless(in_array($slug, SiteSettings::PAGE_SLUGS, true), 404);

        DB::transaction(function () use ($request, $slug) {
            SiteSettings::forget("page_{$slug}_html");
            $this->audit($request, 'reset_legal_page', ['page' => $slug]);
        });

        return response()->json(['html' => LegalPageDefaults::for($slug), 'customized' => false]);
    }

    /** Public: the marketing site fetches the admin-edited page; null means "keep the static text". */
    public function publicPage(string $slug): JsonResponse
    {
        abort_unless(in_array($slug, SiteSettings::PAGE_SLUGS, true), 404);

        $saved = SiteSettings::get("page_{$slug}_html");

        return response()
            ->json(['html' => $saved !== null && $saved !== '' ? $saved : null])
            ->header('Cache-Control', 'public, max-age=60');
    }

    private function audit(Request $request, string $action, array $metadata): void
    {
        AuditLog::create([
            'user_id'      => $request->user()->id,
            'action'       => $action,
            'subject_type' => 'SiteSettings',
            'metadata'     => $metadata,
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);
    }
}
