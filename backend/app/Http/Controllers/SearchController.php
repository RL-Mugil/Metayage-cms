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
        if (!in_array($role, ['client', 'client_admin', 'hr'])) {
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

        if ($role === 'client' || $role === 'client_admin') {
            // scope to client's own records via projects.client_id matching client's user record
            $clientId = DB::table('clients')->where('account_manager_id', $user->id)->value('id');
            $pq->where('projects.client_id', $clientId ?? 0);
        } elseif (in_array($role, ['associate', 'paralegal'])) {
            $pq->where(function ($w) use ($user) {
                $w->where('projects.assigned_partner_id', $user->id)
                  ->orWhere('projects.assigned_manager_id', $user->id)
                  ->orWhere('projects.patent_engineer_id', $user->id);
            });
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
        if (!in_array($role, ['client', 'client_admin', 'hr', 'finance'])) {
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

        return response()->json(['results' => $results, 'total' => count($results)]);
    }
}
