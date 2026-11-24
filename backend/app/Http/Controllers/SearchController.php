<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    private const PER_TYPE = 5;

    public function search(Request $request)
    {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['results' => [], 'total' => 0]);
        }

        $user  = $request->user();
        $role  = $user->role;
        $like  = '%' . $q . '%';
        $results = [];

        // ── Clients (not visible to client/hr roles) ──
        $isPortal = $user->isClientRole() || $user->isInventor();
        if (! $isPortal && $role !== 'hr') {
            $clientQuery = DB::table('clients')
                ->whereNull('deleted_at')
                ->where(function ($w) use ($like) {
                    $w->where('legal_name',    'ilike', $like)
                      ->orWhere('company_name', 'ilike', $like)
                      ->orWhere('client_code',  'ilike', $like);
                });
            if ($user->isGalvanizer()) {
                $user->applyClientScope($clientQuery);
            }
            $clients = $clientQuery
                ->select('id', 'legal_name', 'company_name', 'client_code', 'status')
                ->limit(self::PER_TYPE)
                ->get();

            foreach ($clients as $c) {
                $results[] = [
                    'type'     => 'client',
                    'id'       => $c->id,
                    'title'    => $c->company_name ?: $c->legal_name,
                    'subtitle' => ($c->client_code ?? '') . ' · ' . ($c->status ?? ''),
                    'url'      => '/clients?open=' . $c->id,
                ];
            }
        }

        // ── Projects ──
        $pq = DB::table('projects')
            ->whereNull('projects.deleted_at')
            ->leftJoin('clients', 'projects.client_id', '=', 'clients.id')
            ->where(function ($w) use ($like) {
                $w->where('projects.project_code',    'ilike', $like)
                  ->orWhere('projects.docket_number',  'ilike', $like)
                  ->orWhere('projects.project_name',   'ilike', $like)
                  ->orWhere('projects.invention_title', 'ilike', $like)
                  ->orWhere('clients.company_name',    'ilike', $like)
                  ->orWhere('clients.legal_name',      'ilike', $like);
            })
            ->select(
                'projects.id',
                'projects.project_code',
                'projects.docket_number',
                'projects.project_type',
                'projects.project_name',
                'clients.company_name as client_name',
                'clients.legal_name   as client_legal'
            );

        if ($user->isClientRole()) {
            // Every client portal role (client, client_admin, client_finance): their own client's cases only.
            $pq->whereIn('projects.client_id', \App\Models\Client::query()->visibleToUser($user)->select('id'));
        } elseif ($user->isInventor()) {
            $pq->whereIn('projects.id', $user->projectsAsInventor()->pluck('projects.id'));
        } elseif ($role === 'hr') {
            $pq->whereRaw('1 = 0');
        } elseif (in_array($role, ['associate', 'paralegal'])) {
            $pq->whereIn('projects.id', \App\Models\Project::assignedTo($user->id)->select('id'));
        } elseif ($user->isGalvanizer()) {
            $codes = $user->galvanizerCircleCodes();
            $uid   = $user->id;
            $pq->where(function ($w) use ($codes, $uid) {
                $w->whereIn('projects.circle', $codes)
                  ->orWhere('projects.assigned_manager_id', $uid)
                  ->orWhere('projects.secondary_manager_id', $uid)
                  ->orWhere('projects.patent_engineer_id', $uid);
            });
        }

        foreach ($pq->limit(self::PER_TYPE)->get() as $p) {
            $results[] = [
                'type'     => 'project',
                'id'       => $p->id,
                'title'    => $p->project_code ?? $p->docket_number,
                'subtitle' => ($p->client_name ?: ($p->client_legal ?? '')) . ' · ' . ($p->project_type ?? ''),
                'url'      => '/projects?open=' . $p->id,
            ];
        }

        // ── Tasks (not for client / hr / finance) ──
        if (! $isPortal && ! in_array($role, ['hr', 'finance'], true)) {
            $tq = DB::table('tasks')
                ->where('tasks.title', 'ilike', $like)
                ->leftJoin('projects', 'tasks.project_id', '=', 'projects.id')
                ->select('tasks.id', 'tasks.title', 'tasks.priority', 'projects.project_code');

            if (in_array($role, ['associate', 'paralegal'])) {
                $tq->where('tasks.assignee_id', $user->id);
            } elseif ($user->isGalvanizer()) {
                $codes = $user->galvanizerCircleCodes();
                $uid   = $user->id;
                $tq->where(function ($w) use ($codes, $uid) {
                    $w->whereIn('projects.circle', $codes)
                      ->orWhere('projects.assigned_manager_id', $uid)
                      ->orWhere('projects.secondary_manager_id', $uid)
                      ->orWhere('projects.patent_engineer_id', $uid);
                });
            }

            foreach ($tq->limit(self::PER_TYPE)->get() as $t) {
                $results[] = [
                    'type'     => 'task',
                    'id'       => $t->id,
                    'title'    => $t->title,
                    'subtitle' => ($t->project_code ?? 'No project') . ' · ' . ucfirst(strtolower($t->priority ?? '')) . ' priority',
                    'url'      => '/tasks?open=' . $t->id,
                ];
            }
        }

        // ── Invoices (portal register, by UIN) ──
        if (in_array($role, ['super_admin', 'partner', 'director', 'manager', 'finance', 'client', 'client_admin', 'client_finance'], true)) {
            $invoices = app(\App\Services\FinanceFigures::class)->registerInvoices($user)
                ->where('invoice_uin', 'ilike', $like)->with('client:id,legal_name,company_name')
                ->orderByDesc('invoice_date')->limit(self::PER_TYPE)->get(['id', 'invoice_uin', 'client_id', 'client_name', 'balance_due', 'total_bcy', 'invoice_amount']);
            foreach ($invoices as $i) {
                $results[] = [
                    'type'     => 'invoice',
                    'id'       => $i->id,
                    'title'    => $i->invoice_uin,
                    'subtitle' => ($i->client?->legal_name ?: $i->client?->company_name ?: $i->client_name ?: '').' · '
                        .((float) $i->balance_due > 0 ? '₹'.number_format((float) $i->balance_due).' due' : 'Paid'),
                    'url'      => '/pending-payments?search='.rawurlencode((string) $i->invoice_uin),
                ];
            }
        }

        return response()->json(['results' => $results, 'total' => count($results)]);
    }
}
