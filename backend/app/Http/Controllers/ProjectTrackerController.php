<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\TrackerCircle;
use App\Models\TrackerRow;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ProjectTrackerController extends Controller implements HasMiddleware
{
    /**
     * The tracker is internal practice management. Client-portal accounts
     * must not read or mutate any tracker row, circle, or analytics —
     * this guard covers every action on the controller.
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, $next) {
                if ($request->user() && $request->user()->isClientRole()) {
                    return response()->json(['message' => 'Forbidden'], 403);
                }
                return $next($request);
            },
        ];
    }

    // Status → percentage_of_completion mapping (auto-computed on save)
    // Stage names now match project pipeline stages 1:1; percentages follow stage position.
    const STATUS_COMPLETION = [
        // PAS / SRH / PAT / FTO
        'Matter Created'                                         => 10,
        'Inventor / Technology Disclosure Requested'            => 20,
        'Disclosure Received'                                   => 30,
        'Search Parameters Defined'                             => 45,
        'Prior Art Search In Progress'                          => 60,
        'Search Report Drafted'                                 => 72,
        'Search Report Reviewed Internally'                     => 85,
        'Search Report Shared with Client'                      => 100,
        // PRV
        'Inventor Disclosure Requested'                         => 8,
        'Inventor Disclosure Received'                          => 15,
        'Prior Art Search (Optional)'                           => 20,
        'Draft Started'                                         => 30,
        'Draft Completed'                                       => 40,
        'Internal Review'                                       => 50,
        'Corrections Incorporated'                              => 55,
        'Partner Review'                                        => 60,
        'Client Review'                                         => 65,
        'Client Approved'                                       => 70,
        'Forms Prepared (Form 1, 2, 3)'                         => 78,
        'Government Fees Calculated'                            => 83,
        'Filed with IPO'                                        => 90,
        'Application Number Received'                           => 96,
        'Completed — CPT Deadline Set (12 months)'              => 100,
        // CPT / CPE
        'Inventor Disclosure Reviewed'                          => 10,
        'Claims Drafted'                                        => 18,
        'Claims Shared with Client'                             => 25,
        'Claims Approved by Client'                             => 30,
        'Specification Drafting Started'                        => 35,
        'Draft Shared with Client'                              => 60,
        'Client Feedback Received'                              => 65,
        'Revised Draft Completed'                               => 72,
        'Government Fees Paid'                                  => 88,
        'Completed — Awaiting Publication'                      => 100,
        // CPD
        'Filed with IPO — Awaiting Publication'                 => 100,
        // CVP
        'Priority Application Documents Received'               => 15,
        'Priority Date Verified'                                => 25,
        '12-Month Deadline Confirmed'                           => 30,
        'Claims Drafted (adapted for Indian law)'               => 40,
        'Specification Drafted'                                 => 50,
        'Client Approval'                                       => 65,
        'Forms Prepared (Form 1, 2, 3, 4 — Priority)'           => 75,
        'Filed with IPO (within 12 months of priority)'         => 92,
        // PCT
        'International Application Drafted'                     => 20,
        'Receiving Office Selected (RO/IN or others)'           => 28,
        'International Fees Calculated'                         => 35,
        'Application Filed at Receiving Office'                 => 50,
        'Filing Receipt / IB Reference Received'                => 57,
        'International Search Report (ISR) Received'            => 64,
        'Written Opinion Received'                              => 71,
        'Chapter II Examination (Optional)'                     => 78,
        'Client Review of ISR / Written Opinion'                => 84,
        'National Phase Entry Deadline Set (30 months)'         => 92,
        'International Publication Confirmed (18 months)'       => 96,
        'Completed — National Phase Entry Pending'              => 100,
        // NAP / NPE / NAF / NPA
        'PCT Application Documents Received'                    => 10,
        '31-Month National Phase Deadline Verified'             => 20,
        'National Phase Entry Decision Confirmed'               => 28,
        'Translation Prepared (if required)'                    => 35,
        'National Phase Entry Application Drafted'              => 43,
        'Claims Adapted for Indian Law'                         => 50,
        'Forms Prepared (Form 1, 2, 3 — National Phase)'        => 72,
        'Filed with IPO (within 31 months)'                     => 86,
        // DVA
        'Parent Application Identified'                         => 12,
        'Claims to Divide Identified'                           => 22,
        'Controller Objection / Invitation Noted'               => 30,
        'Divisional Claims Drafted'                             => 40,
        'Specification Prepared'                                => 50,
        'Forms Prepared (Form 1, 2)'                            => 70,
        'Filed with IPO — Linked to Parent'                     => 90,
        // PAD
        'Parent Patent Identified'                              => 12,
        'Improvement / Addition Defined'                        => 22,
        'Addition Claims Drafted'                               => 33,
        'Claims Reviewed Internally'                            => 42,
        'Forms Prepared (Form 1, 2 — Addition)'                 => 67,
        // 9EP / 98A
        'Application Filed and Date Recorded'                   => 20,
        '18-Month Publication Date Calculated'                  => 40,
        'Published in Official Journal'                         => 60,
        'Publication Number Confirmed'                          => 80,
        'Completed — Ready for Examination Request'             => 100,
        // 18F
        'Application Published (18F Trigger)'                   => 15,
        'RFE Deadline Calculated (48 months from filing)'       => 30,
        'Examination Request Decision Made'                     => 43,
        'Form 18 Prepared'                                      => 57,
        'Government Fee Calculated'                             => 71,
        'RFE Filed with IPO'                                    => 86,
        'Completed — Awaiting First Examination Report'         => 100,
        // 18A
        'Application Published (18A Trigger)'                   => 13,
        'Grounds for Acceleration Prepared'                     => 38,
        'Form 18A Prepared'                                     => 63,
        'Completed — Awaiting First Examination Report (Expedited)' => 100,
        // FER / SER / TER
        'Examination Report Received'                           => 11,
        'Objections Analyzed'                                   => 22,
        'Response Strategy Formulated'                          => 33,
        'Claims Amended / Arguments Drafted'                    => 44,
        'Client Communicated'                                   => 67,
        'Response Filed (Form 3 / 13)'                          => 89,
        'Completed — Awaiting Controller Decision'              => 100,
        // HRG
        'Hearing Notice Received'                               => 11,
        'Hearing Date Set'                                      => 22,
        'Arguments Prepared'                                    => 33,
        'Prior Art / Documents Compiled'                        => 44,
        'Written Arguments / Counter-Statement Filed'           => 67,
        'Hearing Attended'                                      => 78,
        'Awaiting Hearing Order'                                => 95,
        // GRT
        'Grant Order Received'                                  => 17,
        'Patent Certificate Issued'                             => 33,
        'Patent Number Recorded'                                => 50,
        'Annual Renewal Date Set (Year 1 from filing)'          => 67,
        'Form 27 Filing Schedule Set'                           => 83,
        'Completed — Patent Active'                             => 100,
        // RNF
        'Renewal Year Identified'                               => 20,
        'Renewal Fee Due Date Confirmed'                        => 40,
        'Renewal Decision Made by Client'                       => 60,
        'Renewal Fee Paid'                                      => 80,
        'Completed — Next Renewal Set'                          => 100,
        // RPO
        'Abandonment Trigger Identified (missed deadline)'      => 25,
        'Restoration Petition Evaluated'                        => 50,
        'Decision: Restore or Close'                            => 75,
        'Completed — Matter Closed'                             => 100,
        // OPP
        'Opposition Filed / Received'                           => 9,
        'Opposition Petition Analyzed'                          => 18,
        'Reply Statement Drafted'                               => 27,
        'Evidence Affidavit Prepared'                           => 36,
        'Evidence of Opponent Received'                         => 45,
        'Evidence Reply Prepared'                               => 55,
        'Hearing Scheduled'                                     => 64,
        'Hearing Arguments Prepared'                            => 73,
        'Order Received'                                        => 91,
        'Completed — Patent Maintained or Revoked'              => 100,
        // 27F
        'Form 27 Due Date Identified'                           => 25,
        'Working Statement Prepared'                            => 50,
        'Form 27 Filed'                                         => 100,
        // ROA
        'Refusal Order Received'                                => 33,
        'Appeal Decision Made'                                  => 67,
        'Completed — Appeal Filed or Matter Closed'             => 100,
        // ERH
        'Notice of Appeal Filed'                                => 11,
        'Grounds of Appeal Prepared'                            => 22,
        'Counter-Arguments by Examiner Received'                => 33,
        'Reply Filed'                                           => 44,
        'Oral Arguments Scheduled'                              => 56,
        'Judgment / Order Received'                             => 89,
        'Completed — Decision'                                  => 100,
        // 24F
        'Revocation Petition Received'                          => 13,
        'Reply Statement Prepared'                              => 25,
        'Evidence Filed'                                        => 38,
        'Counter-Evidence Received'                             => 50,
        // Cross-cutting
        'On Hold'                                               => null,
        'Abandoned'                                             => null,
        'Granted'                                               => 100,
        'Renewal Due'                                           => 95,
        'Completed'                                             => 100,
    ];

    public function inertiaIndex()
    {
        return Inertia::render('ProjectTracker');
    }

    public function circles(Request $request)
    {
        $query = TrackerCircle::with(['members:id,name,email,role']);
        if ($request->user()->isGalvanizer()) {
            $query->whereIn('slug', $request->user()->galvanizerCircleSlugs());
        }
        $circles = $query->get();
        return response()->json($circles);
    }

    public function rows(Request $request)
    {
        $circle = TrackerCircle::where('slug', $request->circle ?? 'a')->firstOrFail();
        if (! $this->canAccessTrackerCircle($request, $circle)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $rows = TrackerRow::with(['pcmUser:id,name', 'scmUser:id,name', 'prUser:id,name', 'project:id,service_code'])
            ->where('circle_id', $circle->id)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        return response()->json($rows->map(fn ($r) => array_merge($r->toArray(), [
            'pcm' => $r->pcmUser?->name,
            'scm' => $r->scmUser?->name,
            'pr'  => $r->prUser?->name,
            // Authoritative service code from the linked project — the frontend
            // must not re-derive it from docket-number string positions.
            'service_code' => $r->project?->service_code,
        ])));
    }

    // Lightweight project list for docket combobox
    public function projects(Request $request)
    {
        $q      = $request->q ?? '';
        $circle = $request->circle ? strtoupper($request->circle) : null;

        $query = Project::with(['client:id,company_name', 'partner:id,name', 'manager:id,name', 'secondaryManager:id,name', 'patentEngineer:id,name'])
            ->whereNull('deleted_at');

        if ($circle) {
            $query->where('circle', $circle);
        }
        if ($request->user()->isGalvanizer()) {
            $query->whereIn('circle', $request->user()->galvanizerCircleCodes());
        }

        if ($q) {
            $ql = strtolower($q);
            $query->where(function ($sub) use ($q, $ql) {
                $sub->whereRaw('LOWER(docket_number) LIKE ?', ["%{$ql}%"])
                    ->orWhereRaw('LOWER(project_code) LIKE ?', ["%{$ql}%"])
                    ->orWhereHas('client', fn ($c) => $c->whereRaw('LOWER(company_name) LIKE ?', ["%{$ql}%"]));
            });
        }

        return response()->json(
            $query->orderBy('created_at', 'desc')
                ->get()
                ->map(fn ($p) => [
                    'id'             => $p->id,
                    'project_code'   => $p->project_code,
                    'docket_number'  => $p->docket_number,
                    'client_name'    => $p->client?->company_name,
                    'pcm_id'         => $p->assigned_manager_id,
                    'pcm_name'       => $p->manager?->name,
                    'scm_id'         => $p->secondary_manager_id,
                    'scm_name'       => $p->secondaryManager?->name,
                    'pr_id'          => $p->patent_engineer_id,
                    'pr_name'        => $p->patentEngineer?->name,
                    'start_date'     => $p->start_date?->toDateString(),
                    'hard_deadline'  => $p->hard_deadline?->toDateString(),
                    'record_type'    => $p->case_type,
                    'circle'         => $p->circle,
                ])
        );
    }

    private function denyNonManagement(Request $request): ?\Illuminate\Http\JsonResponse
    {
        if (! in_array($request->user()->role, ['super_admin', 'partner', 'manager', 'galvanizer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return null;
    }

    public function createRow(Request $request)
    {
        if ($deny = $this->denyNonManagement($request)) return $deny;

        $circle = TrackerCircle::where('slug', $request->circle_slug ?? 'a')->firstOrFail();
        if (! $this->canAccessTrackerCircle($request, $circle)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $row = TrackerRow::create([
            'circle_id'               => $circle->id,
            'status'                  => null,
            'payment_status'          => null,
            'percentage_of_completion'=> 0,
            'sort_order'              => TrackerRow::where('circle_id', $circle->id)->max('sort_order') + 1,
        ]);

        return response()->json($row, 201);
    }

    public function updateRow(Request $request, $id)
    {
        if ($deny = $this->denyNonManagement($request)) return $deny;

        $row = TrackerRow::findOrFail($id);
        if (! $this->canAccessTrackerCircle($request, $row->circle)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $allowed = [
            'project_id', 'docket_number', 'client_name', 'record_type',
            'pcm_id', 'scm_id', 'pr_id', 'project_start_date', 'status',
            'delivery_due_date', 'payment_status', 'uin', 'sort_order',
        ];

        $data = $request->only($allowed);

        // Normalize empty strings to null
        foreach ($data as $k => $v) {
            if ($v === '' || $v === 'null') {
                $data[$k] = null;
            }
        }

        // Auto-compute % completion from status (locked)
        if (isset($data['status'])) {
            $pct = self::STATUS_COMPLETION[$data['status']] ?? null;
            if ($pct !== null) {
                $data['percentage_of_completion'] = $pct;
            }
            // null means On Hold — don't touch percentage
        }

        $row->update($data);

        // Sync pipeline stage and project status on linked project
        if (isset($data['status'])) {
            $projectId = $row->project_id;
            if ($projectId) {
                // Stage name IS the tracker status now (1:1 mapping)
                $newStatus = $data['status'];
                if (! in_array($newStatus, ['On Hold', 'Abandoned'])) {
                    $this->syncProjectStage($projectId, $newStatus);
                }

                // Map tracker row status to project.status
                if ($newStatus === 'Abandoned' || str_contains($newStatus, 'Matter Closed') || str_contains($newStatus, 'Restore or Close')) {
                    $projStatus = 'Abandoned';
                } elseif (in_array($newStatus, ['Granted', 'Completed — Patent Active']) || str_starts_with($newStatus, 'Completed')) {
                    $projStatus = str_contains($newStatus, 'Patent Active') ? 'Granted' : 'Completed';
                } elseif ($newStatus === 'On Hold') {
                    $projStatus = 'On Hold';
                } elseif ($newStatus === 'Matter Created') {
                    $projStatus = 'Open';
                } else {
                    $projStatus = 'In Progress';
                }
                Project::where('id', $projectId)->update(['status' => $projStatus]);
            }
        }

        return response()->json($row->fresh());
    }

    public function deleteRow(Request $request, $id)
    {
        if ($deny = $this->denyNonManagement($request)) return $deny;

        $row = TrackerRow::findOrFail($id);
        if (! $this->canAccessTrackerCircle($request, $row->circle)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $row->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function trackerAnalytics(Request $request)
    {
        $user = $request->user();
        if (! in_array($user->role, ['super_admin', 'partner', 'manager', 'galvanizer'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // All aggregation done in SQL — no full table load into PHP.
        $baseRows = \DB::table('tracker_rows');
        if ($user->isGalvanizer()) {
            $baseRows->whereIn('circle_id', $this->allowedCircleIds($user));
        }

        $summary = (clone $baseRows)->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN delivery_due_date < CURRENT_DATE THEN 1 ELSE 0 END) as overdue
        ")->first();

        $total   = (int) $summary->total;
        $overdue = (int) $summary->overdue;

        $byPayment = (clone $baseRows)
            ->selectRaw("COALESCE(payment_status, 'Not Set') as label, COUNT(*) as value")
            ->groupBy('payment_status')
            ->get()
            ->map(fn($r) => ['label' => $r->label, 'value' => (int) $r->value]);

        $byType = (clone $baseRows)
            ->selectRaw("COALESCE(record_type, 'Unknown') as type, COUNT(*) as count")
            ->groupBy('record_type')
            ->orderByDesc('count')
            ->get()
            ->map(fn($r) => ['type' => $r->type, 'count' => (int) $r->count]);

        $byStatus = (clone $baseRows)
            ->whereNotNull('status')
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn($r) => ['status' => $r->status, 'count' => (int) $r->count]);

        // Workload per team member via UNION — returns one row per (user, role) pair
        $circleFilter = '';
        if ($user->isGalvanizer()) {
            $ids = implode(',', array_map('intval', $this->allowedCircleIds($user)));
            $circleFilter = $ids !== '' ? " AND circle_id IN ({$ids})" : ' AND 1=0';
        }

        $workloadRaw = \DB::select("
            SELECT u.id, u.name, role_col as role, COUNT(*) as cnt
            FROM (
                SELECT pcm_id as uid, 'PCM' as role_col FROM tracker_rows WHERE pcm_id IS NOT NULL {$circleFilter}
                UNION ALL
                SELECT scm_id,        'SCM'             FROM tracker_rows WHERE scm_id IS NOT NULL {$circleFilter}
                UNION ALL
                SELECT pr_id,         'PR'              FROM tracker_rows WHERE pr_id  IS NOT NULL {$circleFilter}
            ) t
            JOIN users u ON u.id = t.uid
            GROUP BY u.id, u.name, role_col
        ");

        $workload = [];
        foreach ($workloadRaw as $row) {
            $key = $row->id;
            if (!isset($workload[$key])) {
                $workload[$key] = ['name' => $row->name, 'PCM' => 0, 'SCM' => 0, 'PR' => 0, 'total' => 0];
            }
            $workload[$key][$row->role] = (int) $row->cnt;
            $workload[$key]['total']    += (int) $row->cnt;
        }
        usort($workload, fn($a, $b) => $b['total'] - $a['total']);

        return response()->json([
            'total_cases'    => $total,
            'overdue'        => $overdue,
            'on_time_rate'   => $total > 0 ? round((($total - $overdue) / $total) * 100) : 100,
            'payment'        => $byPayment,
            'by_record_type' => $byType,
            'by_status'      => $byStatus,
            'workload'       => array_values($workload),
        ]);
    }

    public function calendarEvents(Request $request)
    {
        $user = $request->user();
        $hasGlobalAccess = in_array($user->role, ['super_admin', 'partner', 'manager', 'hr', 'finance']);
        $isClientUser = $user->isClientRole();

        // ── Source 1: Projects with hard_deadline ──────────────────────────────
        $projectQuery = Project::with([
            'client:id,company_name',
            'manager:id,name',
            'patentEngineer:id,name',
        ])
        ->whereNull('deleted_at')
        ->whereNotIn('status', ['Completed', 'Archived'])
        ->where(function ($q) {
            $q->whereNotNull('hard_deadline')
              ->orWhereNotNull('target_filing_date');
        });

        if ($isClientUser) {
            $client = $request->attributes->get('portal_client') ?? \App\Models\Client::forUser($user);
            if ($client) {
                $projectQuery->where('client_id', $client->id);
            } else {
                $projectQuery->whereRaw('1=0');
            }
        } elseif ($user->isGalvanizer()) {
            $user->applyProjectScope($projectQuery);
        } elseif (!$hasGlobalAccess) {
            $uid = $user->id;
            $projectQuery->where(function ($q) use ($uid) {
                $q->where('assigned_manager_id', $uid)
                  ->orWhere('assigned_partner_id', $uid)
                  ->orWhere('patent_engineer_id', $uid)
                  ->orWhereJsonContains('assigned_team', $uid);
            });
        }

        $projectEvents = $projectQuery->get()->flatMap(function ($p) use ($user, $hasGlobalAccess) {
            $myRole = null;
            if (!$hasGlobalAccess) {
                if ($p->assigned_manager_id === $user->id || $p->assigned_partner_id === $user->id) $myRole = 'PCM';
                elseif ($p->patent_engineer_id === $user->id) $myRole = 'PR';
            }

            $base = [
                'project_id'               => $p->id,
                'docket_number'            => $p->docket_number,
                'client_name'              => $p->client?->company_name,
                'record_type'              => $p->case_type ?? $p->project_type,
                'status'                   => $p->status,
                'pcm_id'                   => $p->assigned_manager_id,
                'pcm_name'                 => $p->manager?->name,
                'scm_id'                   => null,
                'scm_name'                 => null,
                'pr_id'                    => $p->patent_engineer_id,
                'pr_name'                  => $p->patentEngineer?->name,
                'percentage_of_completion' => 0,
                'my_role'                  => $myRole,
            ];

            $events = [];

            // Hard deadline → highest priority event
            if ($p->hard_deadline) {
                $events[] = array_merge($base, [
                    'id'               => 'hd_' . $p->id,
                    'delivery_due_date'=> $p->hard_deadline->toDateString(),
                    'event_type'       => 'hard_deadline',
                    'event_label'      => 'Hard Deadline',
                ]);
            }

            // Target filing date → secondary event (shown only if different from hard deadline)
            if ($p->target_filing_date) {
                $tdStr = $p->target_filing_date->toDateString();
                $hdStr = $p->hard_deadline?->toDateString();
                if ($tdStr !== $hdStr) {
                    $events[] = array_merge($base, [
                        'id'               => 'tfd_' . $p->id,
                        'delivery_due_date'=> $tdStr,
                        'event_type'       => 'target_filing',
                        'event_label'      => 'Target Filing',
                    ]);
                }
            }

            return $events;
        });

        // ── Source 2: Tracker rows NOT linked to any project ──────────────────
        $linkedProjectIds = $projectQuery->pluck('id')->all();

        $trackerQuery = TrackerRow::with(['pcmUser:id,name', 'scmUser:id,name', 'prUser:id,name'])
            ->whereNotNull('delivery_due_date')
            ->where(function ($q) use ($linkedProjectIds) {
                $q->whereNull('project_id')
                  ->orWhereNotIn('project_id', $linkedProjectIds);
            });

        if ($isClientUser) {
            // Client portal users see no orphan tracker rows (rows without a project_id have no client link)
            $trackerQuery->whereRaw('1=0');
        } elseif ($user->isGalvanizer()) {
            $trackerQuery->whereIn('circle_id', $this->allowedCircleIds($user));
        } elseif (!$hasGlobalAccess) {
            $uid = $user->id;
            $trackerQuery->where(function ($q) use ($uid) {
                $q->where('pcm_id', $uid)
                  ->orWhere('scm_id', $uid)
                  ->orWhere('pr_id',  $uid);
            });
        }

        $trackerEvents = $trackerQuery->get()->map(function ($r) use ($user, $hasGlobalAccess) {
            $myRole = null;
            if (!$hasGlobalAccess) {
                if ($r->pcm_id === $user->id)     $myRole = 'PCM';
                elseif ($r->scm_id === $user->id) $myRole = 'SCM';
                elseif ($r->pr_id  === $user->id) $myRole = 'PR';
            }
            return [
                'id'                       => 'tr_' . $r->id,
                'project_id'               => null,
                'docket_number'            => $r->docket_number,
                'client_name'              => $r->client_name,
                'record_type'              => $r->record_type,
                'delivery_due_date'        => $r->delivery_due_date?->toDateString(),
                'status'                   => $r->status,
                'pcm_id'                   => $r->pcm_id,
                'pcm_name'                 => $r->pcmUser?->name,
                'scm_id'                   => $r->scm_id,
                'scm_name'                 => $r->scmUser?->name,
                'pr_id'                    => $r->pr_id,
                'pr_name'                  => $r->prUser?->name,
                'percentage_of_completion' => $r->percentage_of_completion,
                'my_role'                  => $myRole,
                'event_type'               => 'delivery_due',
                'event_label'              => 'Delivery Due',
            ];
        });

        return response()->json(
            $projectEvents->concat($trackerEvents)
                ->sortBy('delivery_due_date')
                ->values()
        );
    }

    public function addMember(Request $request, $id)
    {
        if ($deny = $this->denyNonManagement($request)) return $deny;

        $circle = TrackerCircle::findOrFail($id);
        if (! $this->canAccessTrackerCircle($request, $circle)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $request->validate(['user_id' => 'required|exists:users,id']);
        $member = User::findOrFail($request->user_id);
        if ($member->isGalvanizer()) {
            $existingGalvanizerIds = $circle->members()
                ->where('role', 'galvanizer')
                ->where('users.id', '!=', $member->id)
                ->pluck('users.id')
                ->all();
            if ($existingGalvanizerIds) {
                $circle->members()->detach($existingGalvanizerIds);
            }
        }
        $circle->members()->syncWithoutDetaching([$request->user_id]);
        return response()->json($circle->load('members:id,name,email,role'));
    }

    public function removeMember(Request $request, $id, $userId)
    {
        if ($deny = $this->denyNonManagement($request)) return $deny;

        $circle = TrackerCircle::findOrFail($id);
        if (! $this->canAccessTrackerCircle($request, $circle)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $circle->members()->detach($userId);
        return response()->json($circle->load('members:id,name,email,role'));
    }

    private function canAccessTrackerCircle(Request $request, ?TrackerCircle $circle): bool
    {
        $user = $request->user();
        if (! $user->isGalvanizer()) {
            return true;
        }

        return $circle && in_array(strtolower($circle->slug), $user->galvanizerCircleSlugs(), true);
    }

    private function allowedCircleIds($user): array
    {
        if (! $user->isGalvanizer()) {
            return TrackerCircle::pluck('id')->all();
        }

        return TrackerCircle::whereIn('slug', $user->galvanizerCircleSlugs())->pluck('id')->all();
    }

    private function syncProjectStage(int $projectId, string $stageName): void
    {
        // Auto-seed pipeline stages if project has none yet
        $hasStages = ProjectStage::where('project_id', $projectId)->exists();
        if (!$hasStages) {
            $project = Project::find($projectId);
            $svc = strtoupper($project?->service_code ?? '');
            $defaultStages = $svc
                ? (new \App\Http\Controllers\ProjectController)->stagesForCode($svc)
                : ['Matter Created', 'Work In Progress', 'Internal Review', 'Partner Review', 'Client Approval', 'Filing / Submission', 'Completed'];
            foreach ($defaultStages as $index => $name) {
                ProjectStage::create([
                    'project_id'     => $projectId,
                    'stage_name'     => $name,
                    'status'         => $index === 0 ? 'In Progress' : 'Pending',
                    'sequence_order' => $index,
                    'duration_days'  => 15,
                    'due_date'       => \Illuminate\Support\Carbon::now()->addDays(($index + 1) * 15),
                ]);
            }
        }

        $targetStage = ProjectStage::where('project_id', $projectId)
            ->where('stage_name', $stageName)
            ->first();

        if (!$targetStage) return;

        ProjectStage::where('project_id', $projectId)
            ->where('sequence_order', '<', $targetStage->sequence_order)
            ->update(['status' => 'Completed', 'actual_end_at' => Carbon::now()]);

        $targetStage->update([
            'status'           => 'In Progress',
            'actual_start_at'  => Carbon::now(),
        ]);

        ProjectStage::where('project_id', $projectId)
            ->where('sequence_order', '>', $targetStage->sequence_order)
            ->update(['status' => 'Pending', 'actual_start_at' => null, 'actual_end_at' => null]);
    }

    /**
     * Called by ProjectController when a pipeline stage is manually advanced.
     * Writes the matching tracker status onto the linked tracker row (if any),
     * preserving manual overrides — stage name IS the tracker status (1:1 mapping).
     */
    public static function syncTrackerRowStatus(int $projectId, string $stageName): void
    {
        // Stage name IS the tracker status now (1:1 mapping)
        $row = TrackerRow::where('project_id', $projectId)->first();
        if (! $row) return;

        $pct = self::STATUS_COMPLETION[$stageName] ?? null;
        $update = ['status' => $stageName];
        if ($pct !== null) {
            $update['percentage_of_completion'] = $pct;
        }
        $row->update($update);
    }
}
