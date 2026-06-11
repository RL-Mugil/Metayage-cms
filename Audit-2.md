# MYPL-CMS / IPFlow — Engineering Audit #2 (2026-06-11)

Scope: `backend/` Laravel 13 + Inertia React app (the deployed product at
https://mypl-cms.139-59-85-216.sslip.io). The `src/` TanStack Start tree is a
dead Lovable mock — its PM2 process was deleted from the server on 2026-06-04
and nothing routes to it. It should be archived or deleted from the repo.

## Reality vs. the spec prompt

The audit prompt describes Laravel 12 + Inertia + PostgreSQL 16 + Redis +
Horizon + Reverb + MinIO + FastAPI + Spatie + Tiptap. What actually exists:

| Claimed | Reality |
|---|---|
| Laravel 12 | Laravel 13.8, PHP 8.3+ |
| Multi-tenant RLS | Single-tenant; RBAC by a `role` string column |
| Horizon/Reverb/Redis | Installed via composer; queue=database, broadcast=log — neither actually used |
| Spatie Permission | Tables migrated, middleware aliased, **never used** (zero roles/permissions seeded; RBAC is hand-rolled `in_array($user->role, [...])`) |
| MinIO | Local filesystem disk |
| FastAPI sidecar | `ai-sidecar/` exists locally; controller proxies to 127.0.0.1:8001 |
| Workflow/Kanban/Approvals/Payroll/Recruitment engines | Inertia pages exist; most HRMS sub-pages and Approvals/Discussions/Feedback/Portal render static or mock content with no API behind them |

## Critical (fixed this session unless noted)

1. **ReportsController had zero RBAC** — any authenticated user, including
   client-portal logins, could pull ALL employee HR records
   (`?type=hrms`), the entire client book, and all invoices.
   → FIXED: per-report-type role allowlist.
2. **DocumentController had zero RBAC and no path scoping** — any user could
   list, upload, and delete any stored file (`destroy` accepted an arbitrary
   path). → FIXED: internal-roles gate, delete restricted to managers+ and to
   `documents/` prefix, MIME allowlist on upload, audit logging.
   Remaining gap: documents are still firm-wide (no client/project linkage;
   `Document`/`DocumentVersion` models exist but are unused).
3. **TaskController: clients could create/update any task; associates could
   edit others' tasks** (no role check on store/update/addTimeEntry).
   → FIXED: client role blocked; associates/paralegals restricted to own tasks.
4. **Tracker API has no role gating** (`/api/tracker/*` under plain
   `auth:sanctum`) — a client login can read/edit/delete tracker rows
   containing every client's docket, status and payment state.
   → NOT FIXED on instruction ("don't change anything with Project Tracker").
   Recommend a one-line client-role gate when you allow it.
5. **No login throttling** on either web or API login. → FIXED: `throttle:10,1`.

## High (fixed)

6. **Invoice/receipt/employee/project code race conditions** — all generated
   via `count()+1` or `rand()`; concurrent requests → unique-constraint 500s
   (or, for dockets, silent duplicates). → FIXED: sequential max-code lookup
   under `lockForUpdate()` inside DB transactions.
7. **Client ledger running balance was wrong on invoice creation** — it wrote
   `balance = invoice total` instead of continuing the running balance, while
   payments continued from the latest row. Mixed logic = corrupt ledger.
   → FIXED: both continue from latest ledger row, row-locked.
   NOTE: existing ledger rows created before this fix may carry wrong balances
   — needs a one-time reconciliation script before the ledger is trusted.
8. **recordPayment had no transaction and no invoice lock** — double-submit
   could double-credit; overpayment silently clamped. → FIXED: transaction +
   `lockForUpdate`, overpayment now rejected with a validation error.
9. **createEmployee defaulted passwords to `changeme123`** and created
   User/Employee/LeaveBalance without a transaction. → FIXED: random 32-char
   password when none supplied (user must reset), all writes transactional.
10. **Login user enumeration** — "account suspended" leaked email existence
    before password check. → FIXED: status checked after `Auth::attempt`.
11. **clockOut could store negative duration** (Carbon 3 signed diff,
    arguments reversed). → FIXED.
12. **AIController logged `$request->query`** (the ParameterBag object, not
    the query text) and used `env()` at runtime, which returns null once
    `php artisan optimize` caches config (the deploy script does this).
    → FIXED: `input('query')` + `config('services.ai_sidecar.url')`.
13. **Invoice `status` accepted any string** on update. → FIXED: enum rule.
14. **Leave `to_date` could precede `from_date`**. → FIXED: `after_or_equal`.

## High (not fixed — needs decisions)

- **Leave approval does not deduct leave balance** — `updateLeave` flips the
  status only. Balances are decorative. Needs leave_type → balance-column
  mapping and a transactional deduction (+ restore on cancellation).
- **Hard delete of clients cascades** through projects/invoices/ledger
  (`onDelete('cascade')`) — one partner click destroys financial history.
  Recommend soft-deletes on clients/invoices or blocking delete when
  financial records exist.
- **Salary is plaintext** in `employees` and returned to managers/partners by
  `/api/hrms/employees`. Recommend `encrypted` cast + field-level redaction
  for non-HR roles. Client bank details (`bank_account`, `bank_ifsc`) same.
- **Calendar/tracker user matching is by first name substring**
  (`LOWER(pcm) LIKE %first%`) — "Ram" matches "Ramesh"; renames break it.
  Structural fix: store user IDs, not name strings, in tracker PCM/SCM/PR.
- **Permission matrix in AuthController is advisory** — returned to the UI but
  not enforced server-side as a single source of truth. HR role can read all
  clients/projects via API despite matrix saying `none`. Either enforce the
  matrix in middleware or migrate to Spatie (already installed).

## Medium

- Dashboard WIP hardcodes $150/hr.
- 18% GST hardcoded; no CGST/SGST/IGST split though `tax_details` column exists.
- Quotations are read-only (no create/update endpoints). Credit notes,
  reconciliation: absent.
- `Document` upload max 50MB but no malware scanning, no signed URLs;
  `Storage::url()` on local disk yields broken URLs (no download path at all).
- No pagination anywhere — every index endpoint returns full tables.
- No FormRequest classes, no Policies, no service layer — all logic in
  controllers (maintainability, not correctness).
- `assigned_team` JSON column queried with `whereJsonContains` — no index.
- Reverb/Horizon: configured in deploy script but no events are broadcast and
  no jobs are queued; NotificationBroadcast event exists, unused.

## Production readiness: 38/100

Present: SSL + auto-renew, isolated PHP-FPM pool/DB/nginx, audit logging,
health endpoint (`/up`), Horizon supervisor entry.
Missing: error monitoring (no Sentry/equivalent), backups (no pg_dump cron
evidence), CI/CD (manual tar-over-scp deploys, `composer update` on deploy —
unpinned dep drift), zero tests (only Laravel example stubs), no rate limiting
beyond the new login throttle, queue workers configured but unused, no
APP_DEBUG verification possible for production .env (SSH not available to this
session).

## Test coverage: 0%

Recommended first tests (highest value): RBAC feature tests per role per
endpoint (client-role isolation above all), financial invariants (ledger
running balance, payment ≤ balance_due, invoice numbering uniqueness under
parallel requests), leave/attendance flows.

## Files changed this session

- app/Http/Controllers/ReportsController.php — role allowlist per report type
- app/Http/Controllers/DocumentController.php — RBAC, path scoping, MIME allowlist, audit logs
- app/Http/Controllers/TaskController.php — client blocked, ownership checks
- app/Http/Controllers/AuthController.php — enumeration fix
- app/Http/Controllers/HRMSController.php — random default password, transaction, code race fix, clockOut fix, leave date rule
- app/Http/Controllers/FinancialController.php — transactions, locks, sequential codes, ledger fix, overpayment guard, status enum
- app/Http/Controllers/ProjectController.php — transactional create, sequential project code
- app/Http/Controllers/AIController.php — query logging bug, config() over env()
- config/services.php — ai_sidecar.url
- routes/api.php, routes/web.php — login throttling

All files pass `php -l`. DEPLOYED 2026-06-11 (bundle `app-BLnYi_My.js`).

## Addendum — wiring sprint (same day, deployed)

Frontend↔backend wiring audit found the API client matched routes 1:1, but
14 pages were pure mocks. Wired with real backends this session:

- **Documents** — was 100% fake (download button had no handler). Now: real
  list/upload/delete + new `GET /api/documents/download` streaming endpoint,
  folder support (Patents/Trademarks/Contracts/Correspondence/Invoices/General),
  collision-safe filenames, error/loading states.
- **Leave** — approve/reject buttons only mutated local state; balances were
  invented client-side. Now: managers see all firm requests, approve/reject
  hits the API, approval deducts the right balance column transactionally
  (shortfall → LOP days), approvers can't approve their own requests,
  balances render from leave_balances.
- **Approvals** — empty shell. Now lists real pending/resolved leave requests
  and expense claims; resolve actions persist (leave resolution shares the
  same balance-deducting service).
- **Discussions** — fully fake. Now real threads/messages on the existing
  discussion_threads/discussion_messages tables (new `tag` column). Dropped
  the cosmetic reactions/unread-count UI rather than fake it.
- **Settings** — save buttons were no-ops. Now: real profile update + password
  change (current-password verified, audit-logged). Removed the fake 2FA
  toggle, fake sessions list, and dead "Change Photo" button. Notification/
  appearance/system prefs persist to localStorage (client-side only, labeled
  as such by behavior).
- **employees.salary column did not exist** — the form collected it, the API
  validated it, then it silently vanished (not in fillable, no column).
  Added column + fillable. Salary/bank/ID fields now hidden from
  non-HR roles in API responses.
- Fixed pre-existing TS errors (vite/client types, echo generic, notification
  meta null-checks); `tsc --noEmit` is now clean.

## Addendum 2 — Payroll module (2026-06-11, deployed, bundle app-BRYXmyTH.js)

Built the real payroll module (was the riskiest fake — trusted-looking numbers):
- Tables `payroll_runs` (unique per month) + `payslips` (snapshot all amounts +
  employee name/code/designation so historical slips survive employee edits).
- `App\Services\PayrollService` — Indian salary math v1: basic 50% of payable
  gross, HRA 50% of basic, special allowance remainder; PF 12% of basic capped
  at the ₹15k EPF ceiling (max ₹1,800); ESI 0.75% when gross ≤ ₹21k; PT flat
  ₹200; TDS manual per slip; LOP prorates by calendar days first.
- Lifecycle: Draft → Finalized (locks payslips) → Paid. LOP/TDS adjustable
  only in Draft; totals recomputed transactionally with row locks.
- RBAC: super_admin/hr create-edit-finalize; finance/super_admin mark paid;
  partner read-only; every employee sees own finalized/paid slips
  (drafts hidden); clients blocked. Audit-logged at every transition.
- 7 PHPUnit tests on the computation service (caps, ESI threshold, LOP
  proration incl. full-month, clamping, TDS, earnings-reconstruct-gross
  invariant) — all passing. First real tests in the codebase.
- Payroll.tsx fully rewritten: runs list, per-slip adjust UI (draft only),
  finalize/pay/delete actions, CSV export of runs and individual slips,
  employee "My Payslips" self-service section.

Documented simplifications (v1): PT is a flat ₹200 (state slabs vary), TDS is
manual (no regime/declaration engine), no DA/conveyance/bonus components, no
arrears or FnF settlement, no Form 16/24Q. LOP days are entered by HR rather
than auto-imported from attendance.

Still demo-only (no backend; would need new data models): Portal, Bulk,
Compliance, Integrations, Feedback, Reminders, HRMS Performance /
Recruitment / Offboarding. These render but their data is illustrative.

Deploy notes: first deploy failed mid-migration (prod already had
discussion_threads.tag — schema drift) and `set -e` silently skipped
cache-clear/FPM-reload while the script printed "Done!". Migration is now
hasColumn-guarded; second deploy completed, verified via bundle hash +
401-not-404 on new routes + `/up` = 200. Horizon supervisor shows a spawn
error on the droplet — harmless today (nothing queues jobs) but fix before
using queues/Reverb. The script still runs `composer update` and
`migrate --force` on prod; tighten when convenient.
