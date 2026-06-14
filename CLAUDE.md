# CLAUDE.md — IPFlow / MYPL-CMS Master Context

> **This file is the single source of truth for AI assistants working on this codebase.**
> Read this fully before writing any code. Every instruction here is mandatory.

---

## 🧠 Your Identity

You are a **principal-level full-stack architect** with deep expertise in:
- **Laravel / PHP 8.3** (backend API, Eloquent ORM, Sanctum, Policies, Queues)
- **React 19 / TypeScript** (TanStack Start, TanStack Router, TanStack Query, Radix UI, Tailwind CSS v4)
- **PostgreSQL 16** (advanced queries, indexing, row-level locking, read replicas)
- **Indian IP law practice management** (patents, trademarks, copyrights, compliance)
- **Indian financial regulations** (GST, TDS, professional tax, payroll)
- **Security engineering** (OWASP Top 10, RBAC, encryption at rest, SQL injection prevention)

You write production-grade code — never prototypes, never placeholders, never `// TODO` comments without a corresponding implementation plan. Every line you write must be deployable.

---

## 🏢 What Is IPFlow / MYPL-CMS?

### The Business

**IPFlow** is the internal operations platform for **Metayage** (branded "MYPL"), an Indian Intellectual Property (IP) law firm. The firm manages patents, trademarks, and copyrights for corporate clients across jurisdictions (India IPO, USPTO, EPO, WIPO, and others).

### What Does an IP Law Firm Actually Do?

An IP firm is hired by companies (clients) to protect their inventions, brands, and creative works. Each engagement is called a **matter** or **case/project**. The lifecycle of a typical patent matter looks like this:

```
Client Intake → Patentability Search → Drafting Specification → Filing Application
→ Patent Office Examination → Office Action Response → Opposition (if any)
→ Grant/Registration → Renewal/Maintenance
```

Every step has **hard legal deadlines** (miss them = you lose the patent forever). The firm juggles hundreds of these simultaneously across dozens of clients and multiple patent offices worldwide. IPFlow exists to make sure **nothing falls through the cracks**.

### Why This System Exists

Before IPFlow, Metayage used spreadsheets, email, and manual tracking. This caused:
- Missed deadlines (legal malpractice risk)
- Unbilled work (revenue leakage)
- No visibility into workload distribution
- Paper-based HR and attendance
- Manual invoice generation

IPFlow replaces all of that with a unified platform.

---

## 🏗️ System Architecture

### Three-Tier Stack

```
┌─────────────────────────────────────────────────┐
│  FRONTEND (React SPA)                           │
│  TanStack Start + Router + Query                │
│  Radix UI / shadcn components + Tailwind v4     │
│  Port: 5173 (dev) / Nginx (prod)                │
├─────────────────────────────────────────────────┤
│  BACKEND (Laravel 12 API)                       │
│  PHP 8.3 + Sanctum (token auth)                 │
│  Eloquent ORM + PostgreSQL                      │
│  Port: 8000 (dev) / PHP-FPM (prod)              │
├─────────────────────────────────────────────────┤
│  DATABASE (PostgreSQL 16)                       │
│  30+ tables, encrypted PII columns              │
│  Port: 5432 (dev) / 5433 (prod)                 │
└─────────────────────────────────────────────────┘
```

### Deployment

- **Production server:** DigitalOcean droplet at `139.59.85.216`
- **Domain:** `mypl-cms.139-59-85-216.sslip.io` (SSL via Let's Encrypt)
- **Reverse proxy:** Nginx → PHP-FPM (backend) + serves built frontend static files
- **Deployment:** Manual via `deploy-rebuild.sh` (SCP + SSH)

### Key Directories

```
MYPL-CMS/
├── src/                          # Frontend (React + TypeScript)
│   ├── components/               # Reusable UI components
│   │   ├── ui/                   # shadcn/Radix primitives (46 components)
│   │   ├── app-sidebar.tsx       # Main navigation sidebar
│   │   ├── page-header.tsx       # Page header with eyebrow/title/actions
│   │   └── stat-card.tsx         # Metric cards used on dashboards
│   ├── hooks/                    # Custom React hooks
│   ├── lib/                      # Utilities and API client
│   │   ├── api-client.ts         # Central API client (all backend calls)
│   │   ├── mock-data.ts          # Legacy mock data (being phased out)
│   │   └── utils.ts              # Tailwind cn() helper
│   ├── routes/                   # TanStack file-based routes
│   │   ├── __root.tsx            # Root layout + login page + shell
│   │   ├── index.tsx             # Dashboard
│   │   ├── clients.tsx           # CRM
│   │   ├── projects.tsx          # Matter management
│   │   ├── financial.tsx         # Invoicing & payments
│   │   ├── ai.tsx                # AI assistant chat
│   │   ├── hrms/                 # HRMS sub-routes
│   │   │   ├── index.tsx         # HR overview
│   │   │   ├── employees.tsx     # Employee directory
│   │   │   ├── attendance.tsx    # Clock in/out
│   │   │   ├── leave.tsx         # Leave requests
│   │   │   ├── payroll.tsx       # Payroll runs
│   │   │   ├── performance.tsx   # Reviews & goals
│   │   │   ├── recruitment.tsx   # Job postings
│   │   │   └── offboarding.tsx   # Exit management
│   │   └── [20+ other routes]    # tracker, kanban, reports, etc.
│   ├── router.tsx                # Router configuration
│   ├── server.ts                 # SSR server entry (TanStack Start)
│   └── styles.css                # Global styles + Tailwind config
├── backend/                      # Laravel 12 API
│   ├── app/
│   │   ├── Http/
│   │   │   ├── Controllers/      # 27 controllers
│   │   │   ├── Middleware/       # Inertia middleware
│   │   │   ├── Requests/        # 8 form request validators
│   │   │   └── PaginationHelper.php
│   │   ├── Models/               # 42 Eloquent models
│   │   ├── Services/             # Business logic (3 services)
│   │   ├── Policies/             # Authorization (3 policies)
│   │   ├── Casts/                # Custom casts (EncryptedSafe)
│   │   └── Providers/
│   ├── config/                   # Laravel config files
│   ├── database/
│   │   ├── migrations/           # 30 migration files
│   │   ├── seeders/
│   │   └── factories/
│   ├── routes/
│   │   ├── api.php               # All API routes (185 lines, 60+ endpoints)
│   │   └── web.php               # Inertia web routes
│   └── tests/
│       ├── Feature/              # 10 feature test files
│       └── Unit/                 # 2 unit test files
├── package.json                  # Frontend dependencies
├── vite.config.ts                # Vite + TanStack Start config
├── tsconfig.json                 # TypeScript configuration
└──
 deploy-rebuild.sh             # Production deployment script
```

---

## 📋 Complete Module Map

### Module 1: Dashboard (`/`)
**Purpose:** Bird's-eye view of the firm's operational state.
**Key Metrics:** Active matters, active clients, WIP (unbilled) balance, MTD revenue, realization rate.
**Data Sources:** `/api/dashboard/metrics`, `/api/projects`, `/api/tasks`, `/api/financial/invoices`
**Backend:** `DashboardController::metrics()`

### Module 2: CRM / Clients (`/clients`)
**Purpose:** Master client database. Every billing, matter, and contact flows from here.
**Key Fields:**
- `client_code` — Auto-generated: Letter + 2 digits + suffix (`C00M` = Indian, `C00Y` = foreign)
- `legal_name` / `company_name` — Official registered name
- `client_type` — `individual` or `organization`
- `nationality` — Determines GST classification
- `gst_type` — Auto-computed: `B2B` (has GSTIN), `B2C` (individual), `Export` (non-Indian), `Unregistered`
- `has_gstin` / `gstin` — Indian GST registration
- `pan_number` / `cin_number` — Indian tax/corporate IDs
- `account_manager_id` — Firm employee responsible for this client
- `status` — `Active`, `Inactive`, `Prospect`, `On Hold`

**Flows:**
1. **Create Client:** Form → validates → auto-generates `client_code` → computes `gst_type` → saves → audit log
2. **Import Clients:** Upload CSV/XLSX or paste Google Sheet URL → parse → validate each row → bulk create
3. **Client Portal:** Toggle `portal_enabled` to give clients read access to their matters/invoices
4. **Delete Protection:** Cannot delete if invoices exist (soft-delete via `Inactive` status)

**Backend:** `ClientController` (446 lines), `StoreClientRequest`, `UpdateClientRequest`
**Model:** `Client` (soft deletes, encrypted banking fields via `EncryptedSafe` cast)

### Module 3: Cases / Projects (`/projects`)
**Purpose:** Track IP matters through their lifecycle stages.
**Key Fields:**
- `project_code` — Auto: `PRJ-2026-10001`
- `docket_number` — Auto: `{ClientCode}{Seq}{OfficeCode}{ServiceCode}` (e.g., `C00M001INPAT`)
- `project_type` — Patent (Utility/Design/PCT), Trademark, Copyright, Design, Trade Secret
- `case_type` — Filing, Opposition, Renewal, Litigation, etc.
- `patent_office_code` — IN (India), US (USPTO), EP (EPO), WO (WIPO), etc.
- `assigned_partner_id` / `assigned_manager_id` / `patent_engineer_id` — Team assignments
- `hard_deadline` — The absolute legal deadline (cannot be missed)
- `urgency` — Low, Medium, High, Critical

**Lifecycle Pipeline (7 stages):**
```
Intake → Drafting → Filing → Examination → Object Received → Granted → Renewal
```
Each project has `ProjectStage` records tracking progress through this pipeline. Moving to a new stage auto-completes all prior stages and creates notifications.

**Flows:**
1. **Create Project:** Select client → fill details → auto-generates `project_code` and `docket_number` → seeds 7 default stages
2. **Stage Transition:** Click stage → marks prior stages "Completed" → current "In Progress" → future "Pending" → notifies manager
3. **RBAC:** Clients see only their own projects. Associates see only projects assigned to them.

**Backend:** `ProjectController`, `StoreProjectRequest`, `UpdateProjectRequest`
**Model:** `Project` (soft deletes), `ProjectStage`

### Module 4: Project Tracker (`/tracker`)
**Purpose:** Spreadsheet-style operational tracker (the firm's daily work management view).
**Concept:** Borrowed from the firm's pre-existing Google Sheets workflow. Organized into "Circles" (team groups), each containing "Rows" (individual matter entries).
**Key Fields per Row:**
- `docket_number`, `client_name`, `record_type`
- `pcm_id` — Primary Case Manager
- `scm_id` — Secondary Case Manager
- `pr_id` — Patent Representative
- `delivery_due_date`, `payment_status`, `percentage_of_completion`

**Backend:** `ProjectTrackerController` (circles CRUD, rows CRUD, calendar events, analytics)
**Models:** `TrackerCircle`, `TrackerRow`

### Module 5: Tasks (`/tasks`)
**Purpose:** Granular work items within a matter.
**Key Fields:** `title`, `project_id`, `assignee_id`, `reviewer_id`, `priority`, `due_date`, `estimated_hours`, `actual_hours`, `billable`, `status`
**Time Tracking:** `TimeEntry` model logs hours per task/project for billing.
**Backend:** `TaskController`

### Module 6: Kanban (`/kanban`)
**Purpose:** Visual board view of tasks grouped by status columns.
**Frontend-only view** over the same task data.

### Module 7: Financial Suite (`/financial`)
**Purpose:** Invoicing, payments, quotations, and client ledger.

**Invoice Flow:**
```
Create Invoice (line items + auto-tax)
→ Status: Draft
→ Send to client → Sent
→ Client views → Viewed
→ Partial payment → Partially Paid
→ Full payment → Paid
→ Past due date → Overdue
→ Cancel → Cancelled (should reverse ledger)
```

**Key Concepts:**
- **Sequential codes:** `INV-2026-00001` (row-locked to prevent duplicate codes under concurrency)
- **Tax:** Currently hardcoded 18% GST (needs to be variable — 0% for exports, different rates per service)
- **Client Ledger:** Double-entry-style running balance. Invoice = debit. Payment = credit.
- **Receipt codes:** `REC-2026-00001` for payment receipts
- **Currency:** Multi-currency support (`INR`, `USD`, `EUR`, `AED`, etc.)

**Backend:** `FinancialController` (296 lines), `PayrollController`
**Models:** `Invoice`, `InvoiceItem`, `Payment`, `ClientLedger`, `Quotation`

### Module 8: Enterprise HRMS (`/hrms/*`)

**Sub-modules:**

| Route | Feature | Key Operations |
|-------|---------|----------------|
| `/hrms` | Overview | Stats (total, active, on leave, departments) |
| `/hrms/employees` | Directory | CRUD employees, auto-generates `EMP-2026-0001` codes, creates linked User account |
| `/hrms/attendance` | Clock In/Out | GPS-optional, one check-in per day, auto-calculates duration |
| `/hrms/leave` | Leave Mgmt | Apply leave → Pending → Approved/Rejected. Deducts from leave balance (Earned/Casual/Sick). Excess = LOP days |
| `/hrms/payroll` | Payroll | Monthly runs with Indian salary structure (Basic 50%, HRA 25%, Special 25%, PF, ESI, PT, TDS) |
| `/hrms/performance` | Reviews | Goals, 360° feedback, performance reviews |
| `/hrms/recruitment` | Hiring | Job postings, candidates pipeline |
| `/hrms/offboarding` | Exit | Checklist-based exit process |

**Payroll Computation (Indian structure):**
```
Gross Salary (from employee record)
  - LOP Deduction = Gross × (LOP days / calendar days)
  = Payable Gross
    → Basic = 50% of Payable Gross
    → HRA = 50% of Basic (25% of Gross)
    → Special Allowance = remainder

Deductions:
  - PF (Employee) = 12% of Basic, capped at ₹15,000 basic (max ₹1,800/month)
  - ESI = 0.75% of Gross (only if Gross ≤ ₹21,000)
  - Professional Tax = ₹200/month (state-dependent)
  - TDS = manual input per payslip (income tax)

Net Pay = Payable Gross - Total Deductions
```

**Backend:** `HRMSController` (375 lines), `PayrollController`, `PayrollService`, `LeaveApprovalService`
**Models:** `Employee` (encrypted PII), `Department`, `Designation`, `Attendance`, `LeaveRequest`, `LeaveBalance`, `PayrollRun`, `Payslip`

### Module 9: Documents / DMS (`/documents`)
**Purpose:** Document management for case files, specifications, forms, evidence.
**Backend:** `DocumentController`
**Models:** `Document`, `DocumentVersion`

### Module 10: AI Assistant (`/ai`)
**Purpose:** Natural language interface to query firm data and get general assistance.
**Flow:** User types question → Backend sends to Groq LLM with DB schema context → LLM generates SQL (if data needed) → SQL executed on read-only connection → Results formatted and returned with explanation.
**Backend:** `AIController` → `AIQueryService` → Groq API
**Security:** Only SELECT queries allowed. Execute on read-only DB connection.

### Module 11: Compliance & Audit (`/compliance`)
**Purpose:** Track IP deadlines, regulatory filings, and compliance items.
**Statuses:** Critical, At Risk, On Track, Compliant, Resolved
**Backend:** `ComplianceController`
**Model:** `ComplianceItem`

### Module 12: Approvals (`/approvals`)
**Purpose:** Multi-level approval workflows for leave, expenses, documents.
**Backend:** `ApprovalController`
**Model:** `Approval`

### Module 13: Discussions (`/discussions`)
**Purpose:** Threaded conversations attached to matters/clients.
**Backend:** `DiscussionController`
**Models:** `DiscussionThread`, `DiscussionMessage`

### Module 14: Reports (`/reports`)
**Purpose:** 10 report types covering all operational areas.
**Types:** `client-portfolio`, `matter-status`, `financial-summary`, `hrms`, `ip-deadline`, `productivity`, `tracker-workload`, `overdue-cases`, `deadline-forecast`, `payment-collection`
**Backend:** `ReportsController` (225 lines, single `getData()` with type switch)

### Module 15: Settings (`/settings`)
**Purpose:** User profile, password change, notification preferences, system settings.
**Backend:** `SettingsController`

### Module 16: Notifications (`/notifications`)
**Purpose:** In-app notification feed.
**Backend:** `NotificationController`
**Model:** `Notification`

### Module 17: Client Portal (`/portal`)
**Purpose:** Enable/disable client access to their data through the same app with restricted views.
**Backend:** `PortalController`

### Module 18: Integrations (`/integrations`)
**Purpose:** Third-party tool connections (Google Workspace, Slack, etc.)
**Backend:** `IntegrationController`
**Model:** `Integration`

### Module 19: Bulk Operations (`/bulk`)
**Purpose:** Mass status changes, archival, and notifications across clients/projects/tasks.
**Backend:** `BulkController`

### Module 20: Feedback / CSAT (`/feedback`)
**Purpose:** Client satisfaction surveys and internal feedback collection.
**Backend:** `FeedbackController`
**Model:** `FeedbackEntry`

### Module 21: Reminders (`/reminders`)
**Purpose:** Scheduled reminders for deadlines, follow-ups, renewals.
**Backend:** `ReminderController`
**Model:** `Reminder`

---

## 🔐 RBAC (Role-Based Access Control)

### User Roles

| Role | Scope | Description |
|------|-------|-------------|
| `super_admin` | Everything | System administrator, full access to all modules |
| `partner` | Firm-wide | Senior partner, can approve/delete, sees all data |
| `manager` | Team-scoped | Manages team workload, sees assigned projects |
| `hr` | HRMS only | Human resources — manages employees, leave, payroll |
| `finance` | Financial only | Invoicing, payments, financial reports |
| `associate` | Self + assigned | IP attorney — sees assigned projects/tasks only |
| `paralegal` | Self + assigned | Support staff — similar to associate but no legal authority |
| `client` | Own data only | External client — sees only their matters, invoices, documents |

### Access Matrix

| Module | super_admin | partner | manager | hr | finance | associate | paralegal | client |
|--------|:-----------:|:-------:|:-------:|:--:|:-------:|:---------:|:---------:|:------:|
| Dashboard | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ |
| Clients | Full | Full | Full | ❌ | View | View | View | Own |
| Projects | Full | Full | Full | ❌ | View | Assigned | Assigned | Own |
| Tasks | Full | Full | Full | ❌ | ❌ | Assigned | Assigned | Own |
| Financial | Full | Full | View | ❌ | Full | ❌ | ❌ | Own |
| HRMS | Full | Full | Dept | Full | Self | Self | Self | ❌ |
| Reports | Full | Full | View | ❌ | View | ❌ | ❌ | ❌ |
| Settings | Full | Full | Self | Self | Self | Self | Self | Self |
| AI | Full | Full | Full | ❌ | ❌ | Full | Full | ❌ |
| Compliance | Full | Full | Full | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## 🗄️ Database Schema (Key Tables)

### Core

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `users` | Auth & identity | `name`, `email`, `password`, `role`, `status`, `avatar_url` |
| `clients` | Client master | `client_code`, `legal_name`, `company_name`, `client_type`, `nationality`, `gst_type`, `has_gstin`, `gstin`, `pan_number`, `status` |
| `client_contacts` | People at client orgs | `client_id`, `name`, `email`, `phone`, `role_type` |
| `projects` | IP matters/cases | `project_code`, `docket_number`, `client_id`, `project_type`, `case_type`, `patent_office_code`, `status`, `urgency`, `hard_deadline` |
| `project_stages` | Pipeline stages | `project_id`, `stage_name`, `status`, `sequence_order`, `due_date` |
| `tasks` | Work items | `project_id`, `title`, `assignee_id`, `priority`, `due_date`, `status`, `billable` |
| `time_entries` | Hours logged | `project_id`, `task_id`, `user_id`, `duration_hours`, `entry_date`, `billable` |

### Financial

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `invoices` | Bills to clients | `invoice_code`, `client_id`, `project_id`, `subtotal`, `tax_amount`, `total_amount`, `balance_due`, `status`, `currency` |
| `invoice_items` | Line items | `invoice_id`, `description`, `quantity`, `unit_rate`, `amount`, `tax_rate` |
| `payments` | Money received | `client_id`, `invoice_id`, `receipt_code`, `amount`, `payment_method`, `status` |
| `client_ledgers` | Running balance | `client_id`, `document_type`, `document_reference`, `debit`, `credit`, `balance` |
| `quotations` | Estimates | `client_id`, `project_id`, `subtotal`, `total_amount`, `status` |

### HRMS

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `employees` | Staff records | `employee_code`, `user_id`, `full_name`, `work_email`, `department_id`, `designation_id`, `salary` (encrypted) |
| `departments` | Org units | `name`, `head_id` |
| `designations` | Job titles | `title` |
| `attendances` | Clock in/out | `employee_id`, `attendance_date`, `check_in`, `check_out`, `duration_minutes`, `status` |
| `leave_requests` | Time off | `employee_id`, `leave_type`, `from_date`, `to_date`, `total_days`, `status` |
| `leave_balances` | Annual quotas | `employee_id`, `year`, `earned_leave`, `casual_leave`, `sick_leave`, `lop_days` |
| `payroll_runs` | Monthly batch | `month`, `year`, `status`, `run_by_id` |
| `payslips` | Per-employee pay | `payroll_run_id`, `employee_id`, `basic`, `hra`, `pf_employee`, `net_pay` |

### Tracker & Collaboration

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `tracker_circles` | Team groups | `name`, `members` (JSON array of user IDs) |
| `tracker_rows` | Case entries | `docket_number`, `client_name`, `pcm_id`, `scm_id`, `pr_id`, `delivery_due_date`, `payment_status`, `percentage_of_completion` |
| `discussion_threads` | Conversations | `title`, `project_id`, `created_by_id` |
| `discussion_messages` | Replies | `thread_id`, `user_id`, `content` |
| `approvals` | Approval queue | `type`, `requester_id`, `approver_id`, `status` |
| `audit_logs` | Activity trail | `user_id`, `action`, `subject_type`, `subject_id`, `metadata`, `ip_address` |
| `ip_notifications` | In-app alerts | `user_id`, `title`, `message`, `is_read`, `action_url` |

### Security

| Table | Purpose | Notes |
|-------|---------|-------|
| `personal_access_tokens` | Sanctum tokens | API authentication |
| `sessions` | User sessions | Database-backed sessions |
| `compliance_items` | Regulatory items | Deadlines, jurisdiction, status |

---

## 🎨 Frontend Conventions

### Tech Stack
- **Framework:** TanStack Start (file-based routing, SSR-capable)
- **State:** TanStack Query for server state, React `useState` for local state
- **UI Components:** shadcn/ui (46 components from Radix UI primitives)
- **Styling:** Tailwind CSS v4 + `tw-animate-css`
- **Icons:** `lucide-react`
- **Forms:** `react-hook-form` + `zod` validation + `@hookform/resolvers`
- **Charts:** `recharts`
- **Toasts:** `sonner`

### Design System
- **Font Display:** Bricolage Grotesque (headings, titles)
- **Font Body:** Inter (body text, UI labels)
- **Font Mono:** JetBrains Mono (codes, dates, IDs)
- **Primary Color:** Blue-ish (`hsl(221 83% 53%)`)
- **Accent "Gold":** Warm amber (`hsl(45 93% 47%)`)
- **Background:** Near-black (`#09090b`) on dark mode
- **Cards:** Glassmorphism with `backdrop-blur` and subtle borders

### Component Patterns
```tsx
// Route component pattern
export const Route = createFileRoute("/path")({
  head: () => ({ meta: [{ title: "Page Title — IPFlow" }] }),
  component: PageComponent,
});

function PageComponent() {
  // 1. Hooks at top (useQuery, useState, etc.)
  // 2. Early returns for loading/error
  // 3. Main JSX render
  return (
    <div>
      <PageHeader eyebrow="Section" title="Page Title" actions={<Button>Action</Button>} />
      <div className="px-8 py-6 space-y-6">
        {/* Content */}
      </div>
    </div>
  );
}
```

### File Naming
- Routes: `kebab-case.tsx` (e.g., `clients.tsx`, `hrms/payroll.tsx`)
- Components: `kebab-case.tsx` (e.g., `app-sidebar.tsx`, `stat-card.tsx`)
- Hooks: `use-kebab-case.tsx` (e.g., `use-mobile.tsx`)
- Utilities: `kebab-case.ts` (e.g., `api-client.ts`)

---

## ⚙️ Backend Conventions

### Tech Stack
- **Framework:** Laravel 12 (PHP 8.3)
- **Auth:** Sanctum (token-based for API, session-based for Inertia)
- **ORM:** Eloquent with explicit `$fillable`, `$casts`, `$hidden`
- **Validation:** Form Request classes (`StoreXxxRequest`, `UpdateXxxRequest`)
- **Encryption:** Custom `EncryptedSafe` cast for PII (tolerates legacy plaintext)

### Controller Patterns
```php
// Standard controller structure
class XxxController extends Controller
{
    // Inertia routes (if any) — render SPA pages
    public function inertiaIndex(Request $request) { return Inertia::render('Page'); }

    // API: List
    public function index(Request $request) {
        // 1. RBAC check (use middleware or $this->authorize())
        // 2. Build query with filters, search, sorting
        // 3. Return paginated JSON via PaginationHelper
    }

    // API: Show
    public function show(Request $request, $id) {
        // 1. Find or 404
        // 2. RBAC scope check
        // 3. Return with eager-loaded relations
    }

    // API: Create
    public function store(StoreXxxRequest $request) {
        // 1. Use validated data from Form Request
        // 2. Wrap in DB::transaction for atomicity
        // 3. Generate sequential codes with lockForUpdate()
        // 4. Create audit log INSIDE the transaction
        // 5. Return 201 with the created resource
    }

    // API: Update
    public function update(UpdateXxxRequest $request, $id) {
        // 1. FindOrFail
        // 2. Update with validated data
        // 3. Audit log
        // 4. Return fresh model
    }

    // API: Delete (usually soft-delete or status change)
    public function destroy(Request $request, $id) {
        // 1. RBAC: typically super_admin or partner only
        // 2. Check for dependent records
        // 3. Soft-delete or status → Inactive
        // 4. Audit log
    }
}
```

### Sequential Code Generation (CRITICAL PATTERN)
Every entity with a human-readable code must use this pattern:
```php
DB::transaction(function () {
    $year = date('Y');
    $last = Model::where('code', 'like', "PREFIX-{$year}-%")
        ->orderBy('code', 'desc')
        ->lockForUpdate()          // ← MANDATORY: prevents duplicate codes
        ->value('code');
    $seq = $last ? ((int) substr($last, -5)) + 1 : 1;
    $code = sprintf('PREFIX-%s-%05d', $year, $seq);
    // ... create the record ...
});
```

### Audit Logging
Every create/update/delete action must log:
```php
AuditLog::create([
    'user_id'      => $user->id,
    'action'       => 'create|update|delete|stage_change|etc.',
    'subject_type' => 'Client|Project|Invoice|etc.',
    'subject_id'   => $record->id,
    'metadata'     => ['relevant' => 'context'],
    'ip_address'   => $request->ip(),
    'user_agent'   => $request->userAgent(),
]);
```

### Pagination
All list endpoints return this structure:
```json
{
  "data": [...],
  "total": 150,
  "per_page": 25,
  "current_page": 1,
  "last_page": 6,
  "has_more": true
}
```

---

## 🔒 Security Rules (NON-NEGOTIABLE)

1. **Never commit `.env` files.** Use `.env.example` with placeholders.
2. **Never hardcode credentials** in frontend code. Gate dev tools behind `import.meta.env.DEV`.
3. **Always use `lockForUpdate()`** inside transactions for sequential code generation.
4. **Always create AuditLog entries** inside the same transaction as the business operation.
5. **AI-generated SQL** must execute on a **read-only database connection** (`ai_readonly`).
6. **PII fields** (Aadhaar, PAN, bank accounts, salary) must use `EncryptedSafe` cast.
7. **RBAC checks** must happen via middleware or policies, not inline `in_array()` in controllers.
8. **Sanctum tokens** are the only valid auth mechanism for API routes. Never trust HTTP headers alone.
9. **Rate limiting** must be applied to all API routes. Expensive endpoints (AI, reports, imports) get stricter limits.
10. **Sessions must be encrypted** (`SESSION_ENCRYPT=true`).

---

## 🧪 Testing Standards

### Backend
- **Framework:** PHPUnit via `php artisan test`
- **Database:** Use `RefreshDatabase` trait for isolation
- **Auth in tests:** Use `$this->actingAs($user)` with factory-created users
- **Minimum required:** Every controller action must have at least one happy-path and one RBAC test
- **Financial tests are MANDATORY** — invoicing, payments, and ledger entries must be tested

### Frontend
- **Framework:** Vitest + React Testing Library
- **Key tests:** Form submissions, data display, RBAC-based UI hiding, error states

### Running Tests
```bash
# Backend
cd backend && php artisan test
cd backend && php artisan test --filter=FinancialControllerTest

# Frontend (once set up)
npm run test
```

---

## 🚀 Development Commands

```bash
# Frontend dev server
npm run dev                    # Starts Vite on port 5173

# Frontend production build
npm run build                  # Builds to dist/

# Backend dev server
cd backend && php artisan serve  # Starts on port 8000

# Backend migrations
cd backend && php artisan migrate
cd backend && php artisan migrate:fresh --seed  # Reset + seed

# Backend tests
cd backend && php artisan test

# Deploy to production
bash deploy-rebuild.sh         # Builds, uploads, deploys
```

---

## 📐 Code Quality Rules

1. **No `any` types in TypeScript.** Define proper interfaces for every API entity and response.
2. **No raw `useEffect` for data fetching.** Use `useQuery` from TanStack Query.
3. **No God controllers.** If a controller exceeds 200 lines, extract a Service class.
4. **No inline RBAC.** Use middleware (`role:admin,partner`) or `$this->authorize()`.
5. **No unscoped queries in controllers.** Always check user role and filter data accordingly.
6. **No `DB::select($rawSql)` with user input.** Use parameterized queries or Eloquent.
7. **No mock data in production.** Remove imports from `mock-data.ts` in route components.
8. **All financial mutations must be transactional** with `lockForUpdate()` on balance fields.
9. **All dates use Carbon.** No `date()` or `strtotime()` directly.
10. **All API responses use API Resources** (transformers), not raw model `->toJson()`.

---

## 🇮🇳 Indian IP Domain Knowledge

### Patent Types
- **Utility Patent** — Protects inventions (how things work). Most common. 20-year term.
- **Design Patent** — Protects ornamental design (how things look). 15-year term.
- **PCT (Patent Cooperation Treaty)** — International filing that delays national phase entry to 30+ months.

### Patent Lifecycle at the Indian Patent Office (IPO)
```
Prior Art Search → Drafting Specification + Claims → Filing (Form 1, 2, 3, 5)
→ Publication (18 months from priority) → Request for Examination (Form 18)
→ First Examination Report (FER) → Response to FER
→ Hearing (if objections persist) → Grant → Annual Renewal Fees (every year)
```

### Trademark Lifecycle
```
TM Search → Filing (TM-A) → Examination → Objection/Show Cause
→ Publication in TM Journal → Opposition Period (4 months)
→ Counter-statement (Form TM-O) → Registration
→ Renewal every 10 years
```

### Key IP Deadlines (NEVER miss these)
| Deadline | Consequence |
|----------|-------------|
| PCT national phase entry (30/31 months) | Application abandoned permanently |
| Response to Office Action (6 months typically) | Application abandoned |
| Annual patent renewal fee | Patent lapses |
| TM opposition response (2 months) | Deemed to not contest |
| Convention priority (12 months for patents, 6 months for designs) | Lose priority date |

### Indian GST for IP Services
- **B2B (registered):** 18% IGST/CGST+SGST — client has GSTIN
- **B2C (unregistered individual):** 18% — no GSTIN
- **Export of services:** 0% (zero-rated) — invoice in foreign currency, payment in forex
- **Reverse Charge Mechanism (RCM):** Applies when hiring foreign attorneys

### Docket Numbering Convention
```
{ClientCode}{Sequence:3}{PatentOffice}{ServiceCode}
Example: C00M001INPAT
  C00M  = Client code (Indian client, M suffix)
  001   = First matter for this client
  IN    = Indian Patent Office
  PAT   = Patent filing service
```

---

## 💡 When You're Unsure

1. **Security question?** Default to the most restrictive option.
2. **RBAC question?** Check the access matrix above. When in doubt, deny access.
3. **Financial calculation?** Write a unit test first, then implement.
4. **IP domain question?** Ask the user — don't guess on legal/compliance matters.
5. **Architecture question?** Follow existing patterns (Services, Policies, Form Requests).
6. **Frontend state?** Use TanStack Query for server data, React state for UI-only state.

---

*Last updated: 2026-06-13 by codebase audit*
