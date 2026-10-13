<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SiteEnquiry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Monitoring dashboard for the public websites (canpatentit.com/login). Signs in system admins
 * with a short-lived bearer token so the static site never needs the portal's session cookies.
 */
class CpiAdminController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        $user = User::query()->where('email', $data['email'])->first();
        $ok = $user && Hash::check($data['password'], $user->password) && $user->status === 'Active'
            && Gate::forUser($user)->allows('manage-site');
        if (! $ok) {
            // One message for every failure so the form reveals nothing about accounts.
            throw ValidationException::withMessages(['email' => ['Those details are not valid for this dashboard.']]);
        }

        $token = $user->createToken('cpi-admin', ['cpi-admin'], now()->addHours(8))->plainTextToken;

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'cpi_admin_login', 'subject_type' => User::class,
            'subject_id' => $user->id, 'metadata' => [], 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['token' => $token, 'name' => $user->name, 'expires_in' => 8 * 3600]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function overview(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');

        $days = collect(range(13, 0))->map(fn ($i) => Carbon::today()->subDays($i)->toDateString());
        $perDay = SiteEnquiry::query()->where('created_at', '>=', Carbon::today()->subDays(13))
            ->get(['created_at'])->groupBy(fn ($r) => $r->created_at->toDateString())->map->count();

        return response()->json([
            'totals' => [
                'all' => SiteEnquiry::count(),
                'new' => SiteEnquiry::where('status', 'new')->count(),
                'last_7_days' => SiteEnquiry::where('created_at', '>=', now()->subDays(7))->count(),
                'mail_failed' => SiteEnquiry::where('mail_status', 'failed')->count(),
                'canpatentit' => SiteEnquiry::where('source', 'canpatentit')->count(),
                'myipstrategy' => SiteEnquiry::where('source', 'myipstrategy')->count(),
                'quiz' => SiteEnquiry::where('type', 'quiz')->count(),
                'bookings_upcoming' => \App\Models\CpiBooking::where('status', 'confirmed')->where('start_utc', '>=', now())->count(),
                'articles_published' => \App\Models\CpiArticle::where('status', 'published')->count(),
            ],
            'per_day' => $days->map(fn ($d) => ['date' => $d, 'count' => (int) ($perDay[$d] ?? 0)])->values(),
            'health' => [
                'recipient' => config('services.contact.recipient') ?: null,
                'mailer' => config('mail.default'),
                'turnstile' => (bool) config('services.turnstile.secret_key'),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage-site');
        $q = SiteEnquiry::query()->latest('id');
        if (in_array($request->query('status'), ['new', 'handled'], true)) {
            $q->where('status', $request->query('status'));
        }
        if (in_array($request->query('source'), ['canpatentit', 'myipstrategy'], true)) {
            $q->where('source', $request->query('source'));
        }
        if (in_array($request->query('type'), ['enquiry', 'quiz', 'booking'], true)) {
            $q->where('type', $request->query('type'));
        }
        if ($request->query('mail') === 'failed') {
            $q->where('mail_status', 'failed');
        }

        return response()->json($q->limit(200)->get());
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        Gate::authorize('manage-site');

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['received_utc', 'site', 'type', 'name', 'email', 'company', 'stage', 'area', 'status', 'email_status', 'message']);
            // Spreadsheet formula injection: neutralise cells that start with = + - @.
            $safe = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') ? "'".$v : $v;
            SiteEnquiry::orderBy('id')->chunk(200, function ($rows) use ($out, $safe) {
                foreach ($rows as $r) {
                    fputcsv($out, array_map($safe, [$r->created_at?->toDateTimeString(), $r->source, $r->type, $r->name, $r->email, $r->organization, $r->stage, $r->domain, $r->status, $r->mail_status, $r->message]));
                }
            });
            fclose($out);
        }, 'enquiries.csv', ['Content-Type' => 'text/csv']);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        Gate::authorize('manage-site');
        $data = $request->validate(['status' => ['required', 'in:new,handled']]);
        $row = SiteEnquiry::findOrFail($id);
        $row->update($data);

        return response()->json($row);
    }
}
