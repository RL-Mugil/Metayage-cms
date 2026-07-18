# MYPL-CMS — Codex Handoff
**Date:** 2026-07-16  
**Production:** https://myipstrategy.com  
**Server:** root@139.59.85.216 · `/var/www/mypl-cms`  
**Stack:** Laravel 12 · Inertia.js · React 19 · TypeScript · PostgreSQL · Tailwind

---

## What Was Built in This Session

### 1. Indian Patent Workflow Engine — Full Stage Vocabulary (30 service codes)

`ProjectController.php::stagesForServiceCode()` now returns accurate IPO prosecution stages for every service code:

| Code | Name | Stages |
|------|------|--------|
| PAS/SRH/FTO/PAT | Prior Art Search | 8 |
| PRV | Provisional | 16 |
| CPT/CPE | Complete Spec from Provisional | 17 |
| CPD | Direct Complete Spec | 17 |
| CVP | Convention Priority | 12 |
| PCT | PCT International | 14 |
| NAP/NPE/NAF/NPA | National Phase India | 14 |
| DVA | Divisional | 13 |
| PAD | Patent of Addition | 12 |
| 9EP/98A | Publication | 5 |
| 18F | Request for Examination | 7 |
| 18A | Accelerated Examination | 8 |
| FER/SER/TER | Examination Reports | 9 |
| HRG | Hearing | 9 |
| GRT | Grant | 6 |
| RNF | Annual Renewal | 5 |
| RPO | Deemed Abandoned | 4 |
| OPP | Opposition | 11 |
| 27F | Form 27 Working | 4 |
| ROA | Refused | 3 |
| ERH | Appeal (IPAB/HC) | 9 |
| 24F | Revocation | 8 |

**Key invariant:** Stage names in `stagesForServiceCode()` ↔ `STAGES_BY_SERVICE` in frontend ↔ tracker statuses are **all identical strings** — no translation maps needed.

### 2. Project Tracker — New Stage Vocabulary

**`ProjectTracker.tsx`**
- `STAGES_BY_SERVICE` — exact mirror of backend stage arrays for all 30 codes
- `getStatusesForServiceCode(docketNumber)` — returns service-specific stages + "On Hold"/"Abandoned" for the status dropdown; already wired to the picker at click-time
- `getCompletionForStatus(status, docketNumber)` — computes % by stage position (most accurate: uses service code first, then searches all arrays)
- `getDotColor(status)` — derives Tailwind bg class from stage name content (no hardcoded map to maintain)

**`ProjectTrackerController.php`**
- `STATUS_COMPLETION` — 160+ entries covering all new stage names with accurate percentages
- STATUS_STAGE / STAGE_STATUS constants **removed** — stage name = tracker status (1:1), no translation
- `updateRow()` — uses `$data['status']` directly as the project stage name to advance; maps to project.status (Granted/Abandoned/In Progress/Open)
- `syncTrackerRowStatus()` — uses `$stageName` directly as new tracker status
- `syncProjectStage()` auto-seed fallback — now uses the project's actual service code stages via `ProjectController::stagesForCode()`

### 3. Elevation → Tracker Docket/UIN Sync

**`ProjectController.php::elevate()`** — after updating `project.docket_number`, immediately syncs the linked `TrackerRow`:
```php
\App\Models\TrackerRow::where('project_id', $project->id)
    ->update(['docket_number' => $newDocket, 'uin' => $newDocket]);
```

### 4. KPI Real-Time Updates (Patent Portfolio)

`PatentPortfolio.tsx` — `visibilitychange` + `window.focus` listeners trigger `load()` so KPI tiles (granted count etc.) refresh without a page reload when user returns to the tab.

### 5. Status ↔ patent_granted Bidirectional Sync (Projects)

`Projects.tsx` — edit form status dropdown and patent_granted checkbox sync each other. Inline status badge picker sends `patent_granted` in the same API call. Backend grants stats query counts both `patent_granted = true` OR `status = 'Granted'`.

Terminal statuses (Granted/Refused/Abandoned/Closed/Completed) auto-complete all pipeline stages.

### 6. Patent Lifecycle — Dedicated Route

`PatentLifecycle.tsx` at `/patent-lifecycle` — full 4-phase visual diagram with new stage vocabulary. Removed from analytics tab; now its own page.

### 7. Clickable Stage Circles in Detail Panel

`project-detail-panel.tsx` — stage circles are `<button>` elements that call `api.updateProjectStage(projectId, stageName)`, show a spinner while advancing, and reload the detail data on completion.

### 8. ProjectShow Full Detail Page (`/projects/:id`)

`ProjectShow.tsx` — full page with Pipeline, Tasks, Invoices, Ledger, History tabs. Fetches from `/api/projects/${id}/detail`. Back button → `/projects`. History tab shows elevation log.

> **Pending:** CRUD actions (Raise Invoice, Assign Task) inside ProjectShow are not yet implemented. The tabs render existing data but have no create/edit modals.

### 9. Kanban — Updated Stage Mapping

`Kanban.tsx` — `STAGE_COLUMN` map updated with ~70 new stage names across 5 columns (Research, Drafting, Client Review, Filing, Examination).

---

## Architecture

### Data Flow: Project Stage → Tracker Status
```
User clicks stage circle in detail panel
  → api.updateProjectStage(id, stageName)        [PUT /api/projects/{id}/stage]
  → ProjectController::updateStage()
  → ProjectStage::update(status: 'In Progress')
  → ProjectTrackerController::syncTrackerRowStatus(projectId, stageName)
  → TrackerRow::update(status: stageName, pct: STATUS_COMPLETION[stageName])
```

### Data Flow: Tracker Status → Project Stage
```
User picks status in Project Tracker spreadsheet
  → api.updateTrackerRow(id, {status})           [PUT /api/tracker/rows/{id}]
  → ProjectTrackerController::updateRow()
  → syncProjectStage(projectId, status)           [status IS the stage name now]
  → ProjectStage advances to that stage
  → Project::update(status: 'In Progress'|'Granted'|...)
```

### Data Flow: Service Code Elevation
```
User clicks "Change Service" → selects new code
  → api.elevateProject(id, {to_service})         [POST /api/projects/{id}/elevate]
  → ProjectController::elevate()
  → project.docket_number = newDocket
  → TrackerRow::update(docket_number: newDocket, uin: newDocket)
  → reseedStages(project)                         [deletes old stages, seeds new]
  → ProjectElevation::create(...)
```

---

## Key Files

| File | Purpose |
|------|---------|
| `backend/app/Http/Controllers/ProjectController.php` | CRUD, stages, elevation, stats; `stagesForServiceCode()` line ~575; `stagesForCode()` public proxy ~572; `elevate()` ~930 |
| `backend/app/Http/Controllers/ProjectTrackerController.php` | Tracker rows, circles, `STATUS_COMPLETION` ~35, `syncTrackerRowStatus()` ~730, `syncProjectStage()` ~637 |
| `backend/app/Http/Controllers/PatentPortfolioController.php` | KPI aggregation, `pending_action` derivation |
| `backend/resources/js/pages/ProjectTracker.tsx` | Spreadsheet UI; `STAGES_BY_SERVICE` ~15, `getStatusesForServiceCode()`, `getCompletionForStatus()`, `getDotColor()` |
| `backend/resources/js/pages/Projects.tsx` | Projects list + edit form; status↔patent_granted sync |
| `backend/resources/js/pages/ProjectShow.tsx` | Full project detail page at `/projects/:id` |
| `backend/resources/js/pages/Kanban.tsx` | `STAGE_COLUMN` map ~14 |
| `backend/resources/js/components/project-detail-panel.tsx` | Slide-over panel; clickable stage circles |
| `backend/resources/js/pages/PatentPortfolio.tsx` | KPI dashboard; visibility-change reload |
| `backend/resources/js/pages/PatentLifecycle.tsx` | Visual lifecycle diagram |

---

## Database Schema (Relevant Tables)

```
projects          — id, docket_number, original_docket, service_code, status, patent_granted, circle, ...
project_stages    — id, project_id, stage_name, status (Pending|In Progress|Completed), sequence_order, ...
project_elevations— id, project_id, from_service_code, to_service_code, from_docket, to_docket, elevated_at, ...
tracker_rows      — id, circle_id, project_id, docket_number, uin, status, percentage_of_completion, ...
tracker_circles   — id, name, slug (a|b)
```

**Critical invariant:** `project_stages.stage_name` values must exactly match the strings returned by `stagesForServiceCode()`. The tracker status column stores these same strings directly.

---

## Open Work Items

### High Priority
1. **ProjectShow CRUD** — "Raise Invoice" and "Assign Task" modals inside `/projects/:id`. The tabs render data but have no create/edit capability. API endpoints exist (`/api/projects/{id}/detail` returns tasks/invoices arrays).
2. **Backfill tracker status from current In Progress stage** — existing tracker rows with old-vocabulary statuses (e.g., "IDF Received", "Drafting in Progress") won't match new stage names. A one-time migration should set `tracker_rows.status = project_stages.stage_name WHERE status = 'In Progress'` for linked rows.

### Medium Priority
3. **Migration: reseed_indian_patent_workflow** — the plan file documents a 3-step migration (reseed stages + remap tracker status + backfill project.status). The migration file exists at `backend/database/migrations/2026_07_16_000000_reseed_indian_patent_workflow.php` but may need review before running in prod.
4. **Projects.tsx `saving` TS error** — pre-existing undeclared variable at line 647–649. Doesn't block build (vite compiles) but tsc reports it.
5. **Kanban `STAGE_COLUMN`** — some newer stage names (from NAP, DVA, 9EP, GRT, etc.) not yet mapped. Projects using those service codes after elevation won't appear on the board.

### Low Priority
6. **PatentPortfolio KPI — Refused/Abandoned counts** — currently only "Granted" KPI tile is tracked. Consider adding Refused and Abandoned tiles.
7. **Stage auto-advance on elevation** — when a project is elevated (e.g., PRV→CPT), the new stages are seeded. But the "In Progress" stage defaults to position 0 ("Matter Created"). It could be smarter — e.g., "Matter Created" → auto-complete, start at the first substantive stage.

---

## Deployment

```bash
# From local MYPL-CMS directory
bash deploy-rebuild.sh
```

Full rebuild: packages → uploads → extracts → composer install → npm install → vite build → migrate → cache clear → php-fpm reload.

No downtime: PHP-FPM reload is graceful; old workers finish in-flight requests.

**Supervisor processes:**
- `mypl-horizon` — Laravel Horizon (queue worker)
- `mypl-scheduler` — `php artisan schedule:work` (cron tick every minute)

---

## Service Code Reference (Quick Lookup)

```
PAS → Prior Art Search          PRV → Provisional
SRH → Search Report             CPT → Complete (from PRV)
FTO → Freedom to Operate        CPD → Complete Direct
CVP → Convention Priority       CPE → Convention Complete
PCT → PCT International         NAP/NPE/NAF/NPA → National Phase
DVA → Divisional                PAD → Patent of Addition
9EP/98A → Publication           18F → Request Examination
18A → Accelerated Exam          FER/SER/TER → Exam Reports
HRG → Hearing                   GRT → Grant
RNF → Renewal                   RPO → Deemed Abandoned
OPP → Opposition                27F → Form 27 Working
ROA → Refused                   ERH → Appeal
24F → Revocation
```

Elevation path: `PAS → PRV → CPT → 9EP → 18F → FER → HRG → GRT → RNF`
