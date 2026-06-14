# IPFlow / MYPL-CMS — Improvement Roadmap & Fix Guide

> **Generated:** 2026-06-13  
> **Scope:** Full-stack audit covering Laravel backend, React frontend, Python AI sidecar, PostgreSQL, and DevOps  
> **Severity Summary:** 8 Critical · 11 High · 14 Medium · 9 Low

---

## Table of Contents

- [Phase 1 — Security (MUST DO before production)](#phase-1--security-must-do-before-production)
  - [1.1 Rotate the leaked Groq API key](#11-rotate-the-leaked-groq-api-key)
  - [1.2 Remove hardcoded passwords from the frontend](#12-remove-hardcoded-passwords-from-the-frontend)
  - [1.3 Create a read-only PostgreSQL user for AI SQL execution](#13-create-a-read-only-postgresql-user-for-ai-sql-execution)
  - [1.4 Add JWT/token validation to the AI sidecar](#14-add-jwttoken-validation-to-the-ai-sidecar)
  - [1.5 Fix CORS on the AI sidecar](#15-fix-cors-on-the-ai-sidecar)
  - [1.6 Set SESSION_ENCRYPT=true](#16-set-session_encrypttrue)
  - [1.7 Change the database password](#17-change-the-database-password)
  - [1.8 Add rate limiting to all API routes](#18-add-rate-limiting-to-all-api-routes)
- [Phase 2 — Architecture (2-3 weeks)](#phase-2--architecture-2-3-weeks)
  - [2.1 Extract RBAC into middleware/policy layer](#21-extract-rbac-into-middlewarepolicy-layer)
  - [2.2 Define TypeScript interfaces for all API entities](#22-define-typescript-interfaces-for-all-api-entities)
  - [2.3 Replace useEffect fetching with React Query hooks](#23-replace-useeffect-fetching-with-react-query-hooks)
  - [2.4 Remove one of the two AI implementations](#24-remove-one-of-the-two-ai-implementations)
  - [2.5 Add API Resources (transformers) to all controller responses](#25-add-api-resources-transformers-to-all-controller-responses)
  - [2.6 Parallelize dashboard API calls with Promise.all](#26-parallelize-dashboard-api-calls-with-promiseall)
- [Phase 3 — Data Integrity (1-2 weeks)](#phase-3--data-integrity-1-2-weeks)
  - [3.1 Fix client code generation race condition](#31-fix-client-code-generation-race-condition)
  - [3.2 Move audit logging inside transactions](#32-move-audit-logging-inside-transactions)
  - [3.3 Make tax rates configurable per client/jurisdiction](#33-make-tax-rates-configurable-per-clientjurisdiction)
  - [3.4 Implement proper leave day calculation (exclude weekends)](#34-implement-proper-leave-day-calculation-exclude-weekends)
  - [3.5 Reverse ledger entry on invoice cancellation](#35-reverse-ledger-entry-on-invoice-cancellation)
- [Phase 4 — Testing & DevOps (ongoing)](#phase-4--testing--devops-ongoing)
  - [4.1 Add tests for Financial, Reports, Compliance, Documents](#41-add-tests-for-financial-reports-compliance-documents)
  - [4.2 Add frontend component tests](#42-add-frontend-component-tests)
  - [4.3 Create CI/CD pipeline (GitHub Actions)](#43-create-cicd-pipeline-github-actions)
  - [4.4 Add Dockerfile and docker-compose for local development](#44-add-dockerfile-and-docker-compose-for-local-development)
  - [4.5 Add API versioning](#45-add-api-versioning-apiv1)
  - [4.6 Generate OpenAPI documentation](#46-generate-openapi-documentation)
- [Time Estimates Summary](#time-estimates-summary)

---

## Phase 1 — Security (MUST DO before production)

This phase is non-negotiable. If any of these issues exist when real users are on the system, you're exposed to data breaches, financial fraud, and legal liability.

---

### 1.1 Rotate the leaked Groq API key

**File:** `backend/.env` (line 59)

**What happened:**  
Your `.env` file contains a real, working Groq API key in plaintext:
```
GROQ_API_KEY=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
```
This file is committed to git. That means anyone who has ever cloned your repo, any fork, any backup — they all have your key.

**Why it matters:**  
An attacker can use your key to make unlimited Groq API calls. You get billed. They get free AI. Worse, if the same key pattern is reused elsewhere, it could be a pivot point.

**How to fix:**
1. Go to Groq's dashboard → regenerate/revoke this API key immediately.
2. Create a new key.
3. On your server, set it as a real environment variable:
   ```bash
   export GROQ_API_KEY=gsk_new_key_here
   ```
4. Make sure `backend/.env` is in `.gitignore` and **remove it from git history**:
   ```bash
   # Using BFG Repo Cleaner (recommended)
   bfg --delete-files .env
   git reflog expire --expire=now --all && git gc --prune=now --aggressive
   ```
5. Never commit `.env` files — use `.env.example` with placeholder values instead.

---

### 1.2 Remove hardcoded passwords from the frontend

**File:** `src/routes/__root.tsx` (lines 110-118)

**What happened:**  
Your login page has a "Quick Switch Roles" feature that autofills real credentials:
```tsx
const handleQuickFill = (quickEmail: string) => {
    setEmail(quickEmail);
    setPassword("password123");
    if (quickEmail === "admin@ipflow.com") {
        setPassword("admin123");
    } else if (quickEmail === "priya@helios.com") {
        setPassword("client123");
    }
};
```

**Why it matters:**  
When Vite builds your app, this code goes into the JavaScript bundle served to every browser. Anyone can open DevTools → Sources → search for "password" and see all your credentials. It's equivalent to printing your office keys on the front door.

**How to fix:**  
Wrap the entire quick-fill section in a development-only check:

```tsx
// Only render quick-fill buttons in development mode
{import.meta.env.DEV && (
  <>
    <div className="relative flex py-2 items-center">
      <div className="flex-grow border-t border-zinc-800"></div>
      <span className="flex-shrink mx-4 text-[10px] text-zinc-500 uppercase tracking-widest font-semibold">
        Quick Switch Roles
      </span>
      <div className="flex-grow border-t border-zinc-800"></div>
    </div>

    <div className="grid grid-cols-2 gap-2 text-xs">
      <button onClick={() => handleQuickFill("suresh@metayage.com")} ...>
        <span className="font-semibold text-gold">Partner</span>
        <span className="text-[10px] text-zinc-500 truncate">suresh@metayage.com</span>
      </button>
      {/* ... other quick fill buttons ... */}
    </div>
  </>
)}
```

In production builds (`vite build`), Vite will tree-shake this out entirely — the passwords won't exist in the bundle at all.

---

### 1.3 Create a read-only PostgreSQL user for AI SQL execution

**Files:**  
- `backend/app/Services/AIQueryService.php` (lines 44-48)
- `ai-sidecar/main.py` (lines 119-133)

**What happened:**  
Both AI services take SQL generated by an LLM and execute it against your production database. The "safety" check is a regex that looks for dangerous keywords like `DROP`, `DELETE`, etc.:

```php
private function guardSql(string $sql): void
{
    if (!preg_match('/^\s*SELECT\b/i', $sql)) {
        throw new \RuntimeException('Only SELECT queries are permitted.');
    }
    if (preg_match('/\b(DROP|DELETE|INSERT|UPDATE|ALTER|TRUNCATE|EXEC|GRANT|REVOKE)\b/i', $sql)) {
        throw new \RuntimeException('Unsafe SQL keyword detected.');
    }
}
```

**Why it matters:**  
LLMs are creative. A regex blocklist will always have gaps. Examples that bypass your current guard:

```sql
-- Stacked queries (semicolons aren't checked)
SELECT * FROM users; DROP TABLE users

-- Subquery mutation (DELETE is inside parens, not word-boundary matched correctly)
SELECT * FROM (DELETE FROM users RETURNING *) AS x

-- CTE-based mutation
WITH deleted AS (DELETE FROM users RETURNING *) SELECT * FROM deleted
```

You simply **cannot** make a regex that catches all possible SQL attacks. The SQL language is too flexible.

**How to fix:**  

**Step 1:** Create a dedicated PostgreSQL user that *literally cannot* do anything except SELECT:
```sql
-- Run as postgres superuser
CREATE USER ai_reader WITH PASSWORD 'strong_random_password_here';
GRANT CONNECT ON DATABASE ipflow TO ai_reader;
GRANT USAGE ON SCHEMA public TO ai_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO ai_reader;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO ai_reader;
```

**Step 2:** Configure a second database connection in `backend/config/database.php`:
```php
'connections' => [
    'pgsql' => [
        // ... your existing connection
    ],

    'ai_readonly' => [
        'driver'   => 'pgsql',
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'ipflow'),
        'username' => 'ai_reader',
        'password' => env('DB_AI_PASSWORD'),
        'charset'  => 'utf8',
        'prefix'   => '',
        'schema'   => 'public',
    ],
],
```

**Step 3:** Use the read-only connection in AIQueryService.php:
```php
// Before (dangerous):
$results = DB::select($sql);

// After (safe — database itself enforces read-only):
$results = DB::connection('ai_readonly')->select($sql);
```

Now even if the LLM generates `DROP TABLE users`, PostgreSQL itself rejects it with a permission error. Defense at the database level is unbypassable.

---

### 1.4 Add JWT/token validation to the AI sidecar

**File:** `ai-sidecar/main.py` (lines 78-80)

**What happened:**  
The Python sidecar trusts whoever calls it. Authentication is based on HTTP headers that the caller sets:
```python
async def handle_query(
    request: QueryRequest,
    x_user_id: str = Header(None),
    x_user_role: str = Header(None)
):
    if not x_user_id or not x_user_role:
        raise HTTPException(status_code=401, detail="User authentication context missing.")
```

**Why it matters:**  
Any script can do:
```bash
curl -H "x-user-id: 1" -H "x-user-role: super_admin" \
     -d '{"query":"show me all salaries"}' \
     http://your-server:8001/api/query
```
And they'll get full admin access. The sidecar has no way to verify those headers are real.

**How to fix:**

**Option A (simplest — recommended):** Remove the sidecar entirely.  
`AIQueryService.php` in the Laravel backend does the same thing, already behind Sanctum auth. Delete the `ai-sidecar/` directory. One less service to maintain, one less attack surface.

**Option B (if you keep the sidecar):** Share a signing secret.  
Laravel signs a short-lived JWT with the user's real ID and role. The sidecar verifies the JWT signature.

In Laravel:
```php
// Generate signed token for sidecar
$token = base64_encode(json_encode([
    'user_id' => $user->id,
    'role'    => $user->role,
    'exp'     => time() + 60,
]));
$signature = hash_hmac('sha256', $token, config('app.key'));
$signedToken = "$token.$signature";
```

In the sidecar:
```python
import hmac, hashlib, json, base64, time

SHARED_SECRET = os.getenv("APP_KEY")

def verify_token(auth_header: str) -> dict:
    token, signature = auth_header.rsplit(".", 1)
    expected = hmac.new(SHARED_SECRET.encode(), token.encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise HTTPException(status_code=401, detail="Invalid signature")
    payload = json.loads(base64.b64decode(token))
    if payload["exp"] < time.time():
        raise HTTPException(status_code=401, detail="Token expired")
    return payload
```

**Option C:** Make the sidecar only accessible from localhost/internal network, not publicly exposed. Laravel calls it as an internal service.

---

### 1.5 Fix CORS on the AI sidecar

**File:** `ai-sidecar/main.py` (lines 15-21)

**What happened:**
```python
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],         # Anyone on the internet
    allow_credentials=True,      # With cookies
    allow_methods=["*"],
    allow_headers=["*"],
)
```

**Why it matters:**  
`allow_origins=["*"]` + `allow_credentials=True` is actually a specification violation. More importantly, it means any website on the internet can make authenticated requests to your sidecar using the user's cookies. An attacker hosts `evil-site.com`, the user visits it while logged into IPFlow, and `evil-site.com`'s JavaScript can now query your AI endpoint as that user.

**How to fix:**  
Replace `"*"` with your actual frontend origin:
```python
app.add_middleware(
    CORSMiddleware,
    allow_origins=["https://ipflow.yourdomain.com"],  # Your actual domain
    allow_credentials=True,
    allow_methods=["POST"],        # Only POST is needed
    allow_headers=["Authorization", "Content-Type"],
)
```

---

### 1.6 Set SESSION_ENCRYPT=true

**File:** `backend/.env` (line 33)

**What happened:**  
`SESSION_ENCRYPT=false`. Sessions are stored in the database (the `sessions` table) in plaintext.

**Why it matters:**  
If anyone gets read access to your database (via SQL injection through the AI feature, backup leak, compromised DBA credentials), they can read the session data and impersonate any logged-in user without needing their password. With encryption, the session payload is gibberish without the `APP_KEY`.

**How to fix:**
```diff
- SESSION_ENCRYPT=false
+ SESSION_ENCRYPT=true
```
Existing sessions will be invalidated (users need to re-login once), but that's acceptable.

---

### 1.7 Change the database password

**File:** `backend/.env` (line 28)

**What happened:**  
`DB_PASSWORD=password` with username `postgres` (the superuser).

**Why it matters:**  
If your PostgreSQL port (5432) is accessible from any network (even internal), anyone can connect:
```bash
psql -h your-server -U postgres -W
# Enter password: password
```
They get full superuser access — read all data, drop tables, create backdoor users.

**How to fix:**
```bash
# As postgres superuser, change the password
ALTER USER postgres WITH PASSWORD 'use-a-strong-random-64-char-password-here';

# Better yet: don't use postgres for the app. Create a dedicated user:
CREATE USER ipflow_app WITH PASSWORD 'another-strong-password';
GRANT CONNECT ON DATABASE ipflow TO ipflow_app;
GRANT USAGE ON SCHEMA public TO ipflow_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO ipflow_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO ipflow_app;
```

Update `.env`:
```
DB_USERNAME=ipflow_app
DB_PASSWORD=another-strong-password
```

---

### 1.8 Add rate limiting to all API routes

**File:** `backend/routes/api.php` (line 18)

**What happened:**  
Only the login route has `throttle:10,1`. The other 60+ endpoints have nothing.

**Why it matters:**  
Without rate limiting:
- An attacker can brute-force the AI endpoint thousands of times per second (running up your Groq bill)
- Bulk operations can be hammered to DoS your server
- The reports endpoint (which loads entire tables into memory) can be called repeatedly to crash the server
- Data scraping becomes trivial — call `/clients?page=1`, `/clients?page=2`, etc. at full speed

**How to fix:**  
Add Laravel's rate limiting middleware to the authenticated group in `routes/api.php`:

```php
// Apply default rate limiting to all authenticated routes
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // ... all your existing routes ...
});
```

For expensive endpoints, apply stricter limits:
```php
// AI queries: max 10 per minute
Route::post('/ai/query', [AIController::class, 'query'])
    ->middleware('throttle:10,1');

// Reports: max 5 per minute (they load full tables)
Route::get('/reports/data', [ReportsController::class, 'getData'])
    ->middleware('throttle:5,1');

// Bulk operations: max 3 per minute
Route::post('/bulk/execute', [BulkController::class, 'execute'])
    ->middleware('throttle:3,1');

// Client import: max 2 per minute
Route::post('/clients/import', [ClientController::class, 'import'])
    ->middleware('throttle:2,1');
```

Configure the default API rate limit in `app/Providers/RouteServiceProvider.php` (or `bootstrap/app.php` for Laravel 11+):
```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
});
```

---

## Phase 2 — Architecture (2-3 weeks)

Phase 1 stops you from getting hacked. Phase 2 stops the codebase from becoming unmaintainable. These are the issues that make every future feature take 3x longer to build and 5x longer to debug.

---

### 2.1 Extract RBAC into middleware/policy layer

**Files affected:** All 27 controllers in `backend/app/Http/Controllers/`

**The problem:**  
The same `if (!in_array($user->role, ['super_admin', 'partner', ...]))` check is copy-pasted across 20+ controller methods. Each controller has its own slightly different list of allowed roles. The Policies (`app/Policies/ClientPolicy.php`, etc.) were written but are never wired up.

**Why it matters now:**  
Imagine a new role is added, say `"senior_associate"`. You'd need to find and edit every single `in_array()` call across 27 controller files. Miss one? That role is silently blocked from a feature. There's no way to answer "what can a manager do?" without reading every controller.

**How to fix:**

**Option A: Middleware approach (simpler)**

Create `app/Http/Middleware/CheckRole.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        if (! in_array($request->user()?->role, $roles)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return $next($request);
    }
}
```

Register it in `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\CheckRole::class,
    ]);
})
```

Use in routes:
```php
// Before: role check duplicated in every controller method
Route::get('/hrms/employees', [HRMSController::class, 'employees']);

// After: role check in route definition — one place to manage
Route::middleware('role:super_admin,partner,manager,hr')->group(function () {
    Route::get('/hrms/employees', [HRMSController::class, 'employees']);
    Route::get('/hrms/stats', [HRMSController::class, 'stats']);
});
```

**Option B: Policy approach (Laravel-native, more granular)**

Register policies in `app/Providers/AuthServiceProvider.php`:
```php
protected $policies = [
    Client::class  => ClientPolicy::class,
    Project::class => ProjectPolicy::class,
    Task::class    => TaskPolicy::class,
];
```

Use in controllers:
```php
// Before:
public function store(StoreClientRequest $request) {
    $user = $request->user();
    // Manual role check was scattered or missing

// After:
public function store(StoreClientRequest $request) {
    $this->authorize('create', Client::class);
    // Policy handles the role check
```

---

### 2.2 Define TypeScript interfaces for all API entities

**File:** `src/lib/api-client.ts`

**The problem:**  
Every API function returns `Promise<any>`. Every component uses `useState<any>`.

**Why it matters:**  
You're writing TypeScript but getting zero TypeScript benefits. If the backend changes a field name from `project_name` to `name`, TypeScript won't catch it — you'll only find out when the page breaks in production.

**How to fix:**

Create `src/lib/types.ts`:
```typescript
// ── Base types ──────────────────────────────────────────
export interface PaginatedResponse<T> {
  data: T[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
  has_more: boolean;
}

// ── Domain entities ─────────────────────────────────────
export interface Client {
  id: number;
  client_code: string;
  company_name: string;
  legal_name: string;
  client_type: 'individual' | 'organization';
  nationality: string;
  gst_type: 'B2B' | 'B2C' | 'Export' | 'Unregistered';
  status: 'Active' | 'Inactive' | 'Prospect' | 'On Hold';
  contact_name: string | null;
  contact_email: string | null;
  phone: string | null;
  industry: string | null;
  account_manager?: User;
  date_onboarded: string;
}

export interface Project {
  id: number;
  project_code: string;
  project_name: string;
  docket_number: string;
  project_type: string;
  status: string;
  urgency: string;
  hard_deadline: string | null;
  client?: Client;
  partner?: User;
  manager?: User;
  stages?: ProjectStage[];
}

export interface ProjectStage {
  id: number;
  stage_name: string;
  status: 'Pending' | 'In Progress' | 'Completed';
  sequence_order: number;
  due_date: string | null;
}

export interface Task {
  id: number;
  title: string;
  description: string | null;
  status: string;
  priority: string;
  due_date: string | null;
  project?: Project;
  assignee?: User;
}

export interface Invoice {
  id: number;
  invoice_code: string;
  client?: Client;
  project?: Project;
  status: 'Draft' | 'Sent' | 'Paid' | 'Overdue' | 'Partially Paid' | 'Cancelled';
  subtotal: number;
  tax_amount: number;
  total_amount: number;
  balance_due: number;
  issue_date: string;
  due_date: string;
  currency: string;
}

export interface Employee {
  id: number;
  employee_code: string;
  full_name: string;
  work_email: string;
  phone: string | null;
  department?: { id: number; name: string };
  designation?: { id: number; title: string };
  employment_status: string;
  employment_type: string;
  work_location: string;
  date_of_joining: string;
}

export interface DashboardMetrics {
  metrics: {
    active_matters: number;
    clients: number;
    wip_balance: number;
    received_payments: number;
    realization_rate: number;
  };
  charts: unknown;
}
```

Then update `api-client.ts`:
```typescript
import type { Client, Project, Task, Invoice, PaginatedResponse, DashboardMetrics } from './types';

// Before:
async getClients(search?: string): Promise<any[]>

// After:
async getClients(search?: string): Promise<PaginatedResponse<Client>>

// Before:
async createClient(data: any): Promise<any>

// After:
async createClient(data: Omit<Client, 'id' | 'client_code' | 'account_manager'>): Promise<Client>
```

---

### 2.3 Replace useEffect fetching with React Query hooks

**Files affected:** All route components in `src/routes/`

**The problem:**  
You have `@tanstack/react-query` installed and the `QueryClientProvider` wrapping your app, but zero components actually use it. Every route does manual fetching:

```tsx
const [data, setData] = useState<any>(null);
const [loading, setLoading] = useState(true);
useEffect(() => {
  api.getX().then(setData).finally(() => setLoading(false));
}, []);
```

**Why it matters:**
| Feature | useEffect | React Query |
|---------|-----------|-------------|
| Caching | ❌ Refetches every mount | ✅ Cached by key |
| Deduplication | ❌ 2 components = 2 requests | ✅ 1 request shared |
| Background refresh | ❌ Manual | ✅ Automatic |
| Error retry | ❌ Manual | ✅ Automatic (3 retries) |
| Loading states | ❌ Manual booleans | ✅ `isLoading`, `isFetching` |
| Stale data | ❌ Shows nothing while loading | ✅ Shows stale then refreshes |

**How to fix:**

Create `src/hooks/use-clients.ts`:
```tsx
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api-client';
import type { Client, PaginatedResponse } from '@/lib/types';

export function useClients(search?: string) {
  return useQuery<PaginatedResponse<Client>>({
    queryKey: ['clients', search],
    queryFn: () => api.getClients(search),
  });
}

export function useClient(id: number | string) {
  return useQuery<Client>({
    queryKey: ['clients', id],
    queryFn: () => api.getClient(id),
    enabled: !!id,
  });
}

export function useCreateClient() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (data: Partial<Client>) => api.createClient(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['clients'] });
    },
  });
}
```

Use in components:
```tsx
// Before (40+ lines of boilerplate):
function ClientsPage() {
  const [clients, setClients] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  useEffect(() => {
    api.getClients()
      .then(setClients)
      .catch(setError)
      .finally(() => setLoading(false));
  }, []);
  if (loading) return <Spinner />;
  if (error) return <Error />;
  // ...
}

// After (3 lines):
function ClientsPage() {
  const { data, isLoading, error } = useClients();
  if (isLoading) return <Spinner />;
  if (error) return <Error message={error.message} />;
  // data.data is Client[] with full type safety
}
```

---

### 2.4 Remove one of the two AI implementations

**Files:**
- `backend/app/Services/AIQueryService.php` — PHP, calls Groq
- `ai-sidecar/main.py` — Python, calls Gemini/Groq

**The problem:**  
Two services do the same thing with different schemas, different security models, different LLM providers, and different response formats.

| Aspect | PHP Service | Python Sidecar |
|--------|-------------|----------------|
| Auth | Sanctum (verified) | HTTP headers (spoofable) |
| LLM | Groq (llama-4-scout) | Gemini or Groq (mixtral/llama3) |
| SQL Guard | Regex blocklist | `.startswith("SELECT")` |
| Schema | 16 tables described | 5 tables described |
| Deployment | Same server as backend | Separate Python process |

**How to fix:**  
Keep the PHP one. Delete the `ai-sidecar/` directory entirely.

Rationale:
- It's already behind Sanctum auth — no extra work needed
- Same deployment as the backend — no extra infrastructure
- Already has access to the database config — no credential duplication
- If you need Gemini specifically, just call Gemini's API from PHP (it's a simple HTTP POST)

```bash
# Delete the sidecar
rm -rf ai-sidecar/
```

---

### 2.5 Add API Resources (transformers) to all controller responses

**Files affected:** All controllers

**The problem:**  
Controllers return raw Eloquent models:
```php
return response()->json($client->load('accountManager'));
```

This exposes every column on the model — including `created_at`, `updated_at`, `deleted_at`, internal pivot columns. If you add a column to the database (e.g., `internal_notes`), it immediately appears in the API.

**How to fix:**

Create `app/Http/Resources/ClientResource.php`:
```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'client_code'     => $this->client_code,
            'company_name'    => $this->company_name,
            'legal_name'      => $this->legal_name,
            'client_type'     => $this->client_type,
            'nationality'     => $this->nationality,
            'gst_type'        => $this->gst_type,
            'status'          => $this->status,
            'contact_name'    => $this->contact_name,
            'contact_email'   => $this->contact_email,
            'phone'           => $this->phone,
            'industry'        => $this->industry,
            'date_onboarded'  => $this->date_onboarded?->toDateString(),
            'account_manager' => $this->whenLoaded('accountManager', fn() => [
                'id'   => $this->accountManager->id,
                'name' => $this->accountManager->name,
            ]),
            'projects_count'  => $this->whenCounted('projects'),
        ];
    }
}
```

Use in controller:
```php
// Before:
return response()->json($client->load('accountManager'));

// After:
return new ClientResource($client->load('accountManager'));

// For collections:
return ClientResource::collection($clients);
```

---

### 2.6 Parallelize dashboard API calls with Promise.all

**File:** `src/routes/index.tsx` (lines 32-41)

**The problem:**
```tsx
const data = await api.getDashboardMetrics();  // wait ~200ms
const projs = await api.getProjects();          // wait ~200ms
const tsks = await api.getTasks();              // wait ~200ms
const invs = await api.getInvoices();           // wait ~200ms
// Total: ~800ms sequential
```

These four requests are independent — none depends on the result of another.

**How to fix:**
```tsx
// Before: sequential (~800ms)
const data = await api.getDashboardMetrics();
const projs = await api.getProjects();
const tsks = await api.getTasks();
const invs = await api.getInvoices();

// After: parallel (~200ms — limited by the slowest one)
const [data, projs, tsks, invs] = await Promise.all([
    api.getDashboardMetrics(),
    api.getProjects(),
    api.getTasks(),
    api.getInvoices(),
]);
```

This alone cuts dashboard load time by roughly 75%.

---

## Phase 3 — Data Integrity (1-2 weeks)

Phase 2 fixed architecture. Phase 3 fixes correctness — places where the code produces **wrong results** that could cause financial or legal problems.

---

### 3.1 Fix client code generation race condition

**File:** `backend/app/Http/Controllers/ClientController.php` (lines 37-66)

**The problem:**  
`generateClientCode()` queries all existing codes, finds the max, and increments — but **outside** a transaction with **no row locking**. Under concurrent requests, two clients could get the same code.

Compare with `FinancialController::createInvoice()` which correctly uses `lockForUpdate()` inside a `DB::transaction()`.

**How to fix:**  
Move client creation into a transaction with locking:

```php
public function store(StoreClientRequest $request)
{
    $user = $request->user();
    $v = $request->validated();

    $client = DB::transaction(function () use ($v, $user) {
        $nationality = $v['nationality'] ?? 'India';
        $hasGstin    = (bool) ($v['has_gstin'] ?? false);
        $clientType  = $v['client_type'];

        // Lock existing client codes to prevent race condition
        $last = Client::whereNotNull('client_code')
            ->where('client_code', 'REGEXP', '^[C-Z][0-9]{2}[MY]?$')
            ->orderByRaw("LENGTH(client_code) DESC, client_code DESC")
            ->lockForUpdate()
            ->value('client_code');

        $v['client_code']        = $this->computeNextCode($last, $nationality);
        $v['gst_type']           = $this->computeGstType($nationality, $hasGstin, $clientType);
        $v['company_name']       = $v['legal_name'];
        $v['account_manager_id'] = $v['account_manager_id'] ?? $user->id;
        $v['date_onboarded']     = now()->toDateString();
        $v['status']             = $v['status'] ?? 'Active';

        $client = Client::create($v);

        AuditLog::create([
            'user_id'      => $user->id,
            'action'       => 'create',
            'subject_type' => 'Client',
            'subject_id'   => $client->id,
            'metadata'     => ['legal_name' => $client->legal_name],
            'ip_address'   => request()->ip(),
            'user_agent'   => request()->userAgent(),
        ]);

        return $client;
    });

    return response()->json($client->load('accountManager'), 201);
}
```

---

### 3.2 Move audit logging inside transactions

**Files:** ClientController, FinancialController, ProjectController, HRMSController

**The problem:**  
In most controllers:
```php
$project = DB::transaction(function () { ... });  // Transaction ends here
AuditLog::create([...]);  // This is OUTSIDE the transaction
```

**Why it matters:**
- If audit insert fails → business operation succeeded but there's no record of who did it
- If transaction rolled back → audit would NOT be there (which is correct), BUT other controllers where the audit IS inside the transaction would behave differently

**How to fix:**  
Move `AuditLog::create()` inside the transaction closure (as shown in 3.1 above), so the audit and the business operation are atomic.

---

### 3.3 Make tax rates configurable per client/jurisdiction

**File:** `backend/app/Http/Controllers/FinancialController.php` (line 108)

**The problem:**
```php
$taxAmount = round($subtotal * 0.18, 2);  // Always 18%
```

Indian GST has 5 rate tiers: 0%, 5%, 12%, 18%, 28%. For export clients, the rate should be 0%.

**How to fix:**
```php
public function createInvoice(Request $request)
{
    $validated = $request->validate([
        // ... existing rules ...
        'tax_rate' => 'nullable|numeric|min:0|max:100',  // NEW: accept tax rate
    ]);

    $client = Client::findOrFail($validated['client_id']);

    // Auto-determine tax rate if not explicitly provided
    $taxRate = $validated['tax_rate'] ?? match($client->gst_type) {
        'Export'       => 0.0,     // Zero-rated exports
        'B2B'          => 18.0,    // Standard GST
        'B2C'          => 18.0,    // Standard GST
        'Unregistered' => 18.0,    // Standard GST
        default        => 18.0,
    };

    $subtotal   = collect($validated['items'])->sum('amount');
    $taxAmount  = round($subtotal * ($taxRate / 100), 2);
    $totalAmount = $subtotal + $taxAmount;

    // ... rest of invoice creation ...
    // Store tax_rate on each item too:
    foreach ($validated['items'] as $item) {
        InvoiceItem::create([
            'invoice_id'  => $invoice->id,
            'description' => $item['description'],
            'quantity'    => 1,
            'unit_rate'   => $item['amount'],
            'amount'      => $item['amount'],
            'tax_rate'    => $taxRate,  // Use computed rate, not hardcoded 18
        ]);
    }
}
```

---

### 3.4 Implement proper leave day calculation (exclude weekends)

**File:** `backend/app/Http/Controllers/HRMSController.php` (line 331)

**The problem:**
```php
$totalDays = Carbon::parse($request->to_date)->diffInDays(Carbon::parse($request->from_date)) + 1;
```

Employee applies leave from Friday to Monday = 2 working days. But `diffInDays + 1` = 4 (includes Saturday and Sunday).

**How to fix:**
```php
// Before: counts ALL days including weekends
$totalDays = Carbon::parse($request->to_date)->diffInDays(Carbon::parse($request->from_date)) + 1;

// After: counts only weekdays (Mon-Fri)
$fromDate = Carbon::parse($request->from_date);
$toDate   = Carbon::parse($request->to_date);

$totalDays = 0;
$current = $fromDate->copy();
while ($current->lte($toDate)) {
    if ($current->isWeekday()) {
        $totalDays++;
    }
    $current->addDay();
}

// Even better: also exclude company holidays
// $holidays = Holiday::whereBetween('date', [$fromDate, $toDate])->pluck('date');
// Subtract $holidays->count() from $totalDays
```

Or using Carbon's built-in filter:
```php
$totalDays = $fromDate->diffInDaysFiltered(function (Carbon $date) {
    return $date->isWeekday();
}, $toDate) + ($toDate->isWeekday() ? 1 : 0);
```

---

### 3.5 Reverse ledger entry on invoice cancellation

**File:** `backend/app/Http/Controllers/FinancialController.php` (lines 194-203)

**The problem:**  
When an invoice is created, a debit entry is added to `ClientLedger`. When it's cancelled, the ledger entry is NOT reversed:
```php
public function deleteInvoice(Request $request, $id)
{
    $invoice = Invoice::findOrFail($id);
    $invoice->update(['status' => 'Cancelled']);
    // No ledger reversal! Client's balance is now permanently wrong.
}
```

**How to fix:**
```php
public function deleteInvoice(Request $request, $id)
{
    $user = $request->user();
    if (! in_array($user->role, ['super_admin', 'partner'])) {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    $invoice = Invoice::findOrFail($id);

    DB::transaction(function () use ($invoice) {
        $invoice->update(['status' => 'Cancelled', 'balance_due' => 0]);

        // Reverse the ledger entry
        $latestLedger = ClientLedger::where('client_id', $invoice->client_id)
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->first();

        $newBalance = ($latestLedger ? $latestLedger->balance : 0) - $invoice->total_amount;

        ClientLedger::create([
            'client_id'          => $invoice->client_id,
            'transaction_date'   => now()->toDateString(),
            'document_type'      => 'Credit Note',
            'document_reference' => $invoice->invoice_code,
            'debit'              => 0,
            'credit'             => $invoice->total_amount,
            'balance'            => $newBalance,
            'notes'              => 'Invoice cancelled — ledger reversal',
        ]);
    });

    AuditLog::create([
        'user_id' => $request->user()->id,
        'action' => 'cancel_invoice',
        'subject_type' => 'Invoice',
        'subject_id' => $invoice->id,
        'metadata' => ['invoice_code' => $invoice->invoice_code, 'amount' => $invoice->total_amount],
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
    ]);

    return response()->json(['message' => 'Invoice cancelled and ledger reversed']);
}
```

---

## Phase 4 — Testing & DevOps (ongoing)

Phase 3 fixed correctness. Phase 4 prevents regressions and automates quality. This is what separates a project that stays stable from one that breaks with every new feature.

---

### 4.1 Add tests for Financial, Reports, Compliance, Documents

**The problem:**  
Your highest-risk code (money handling) has zero tests. Your most complex code (reports with 10 branches) has zero tests.

**Current test coverage:**

| Area | Has Tests? | Risk Level |
|------|-----------|------------|
| Client CRUD | ✅ Yes (31K bytes) | Medium |
| Project CRUD | ✅ Yes (12K bytes) | Medium |
| Leave Management | ✅ Yes (14K bytes) | Medium |
| Payroll | ✅ Yes (13K bytes) | High |
| **Financial (invoicing, payments)** | **❌ NONE** | **🔴 Critical** |
| **Reports (10 types)** | **❌ NONE** | **🟠 High** |
| **Documents** | **❌ NONE** | **Medium** |
| **Compliance** | **❌ NONE** | **Medium** |
| **Frontend** | **❌ NONE** | **High** |
| **AI Sidecar** | **❌ NONE** | **High** |

**What to test (priority order):**

1. **Invoice creation** — correct totals, tax calculation, ledger entry, sequential code generation
2. **Payment recording** — balance update, overpayment prevention, receipt code generation
3. **Invoice cancellation** — ledger reversal (once you implement fix 3.5)
4. **Each report type** — returns expected columns, respects RBAC
5. **Document upload/download** — permission checks, file handling

Example test structure:
```php
// tests/Feature/FinancialControllerTest.php

class FinancialControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_invoice_calculates_correct_totals()
    {
        $user = User::factory()->create(['role' => 'finance']);
        $client = Client::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/financial/invoices', [
            'client_id' => $client->id,
            'due_date'  => '2026-07-01',
            'items'     => [
                ['description' => 'Patent Filing', 'amount' => 10000],
                ['description' => 'Drafting',      'amount' => 5000],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertEquals(15000, $response->json('subtotal'));
        $this->assertEquals(2700, $response->json('tax_amount'));    // 18%
        $this->assertEquals(17700, $response->json('total_amount'));
    }

    public function test_payment_cannot_exceed_balance()
    {
        // ... test overpayment prevention
    }

    public function test_client_role_can_only_see_own_invoices()
    {
        // ... test RBAC scoping
    }
}
```

---

### 4.2 Add frontend component tests

**The problem:**  
Zero frontend tests exist.

**How to fix:**

Install Vitest + Testing Library:
```bash
npm install -D vitest @testing-library/react @testing-library/jest-dom jsdom
```

Add to `vite.config.ts`:
```typescript
export default defineConfig({
  // ... existing config
  test: {
    environment: 'jsdom',
    setupFiles: './src/test-setup.ts',
  },
});
```

Create `src/test-setup.ts`:
```typescript
import '@testing-library/jest-dom';
```

Write tests:
```tsx
// src/components/__tests__/stat-card.test.tsx
import { render, screen } from '@testing-library/react';
import { StatCard } from '../stat-card';
import { Briefcase } from 'lucide-react';

test('renders label and value', () => {
  render(<StatCard label="Active Matters" value="42" icon={Briefcase} accent="primary" />);
  expect(screen.getByText('Active Matters')).toBeInTheDocument();
  expect(screen.getByText('42')).toBeInTheDocument();
});
```

---

### 4.3 Create CI/CD pipeline (GitHub Actions)

**The problem:**  
You have a `.github` directory but no workflow files. Deployment is via manual shell scripts.

**How to fix:**

Create `.github/workflows/ci.yml`:
```yaml
name: CI

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  backend-tests:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_DB: ipflow_test
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: test_password
        ports: ['5432:5432']
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pgsql, pdo_pgsql

      - name: Install dependencies
        working-directory: backend
        run: composer install --no-interaction --prefer-dist

      - name: Run migrations
        working-directory: backend
        env:
          DB_CONNECTION: pgsql
          DB_HOST: localhost
          DB_DATABASE: ipflow_test
          DB_USERNAME: postgres
          DB_PASSWORD: test_password
        run: php artisan migrate --force

      - name: Run tests
        working-directory: backend
        env:
          DB_CONNECTION: pgsql
          DB_HOST: localhost
          DB_DATABASE: ipflow_test
          DB_USERNAME: postgres
          DB_PASSWORD: test_password
        run: php artisan test --parallel

  frontend-build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - run: npm ci
      - run: npm run lint
      - run: npm run build
      # - run: npm run test  # Enable once you have frontend tests
```

---

### 4.4 Add Dockerfile and docker-compose for local development

**The problem:**  
New developers need to install PHP, Composer, PostgreSQL, Node.js, Python, and configure everything manually.

**How to fix:**

Create `docker-compose.yml` in project root:
```yaml
version: '3.8'

services:
  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: ipflow
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: dev_password
    ports:
      - "5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data

  backend:
    build:
      context: ./backend
      dockerfile: Dockerfile
    ports:
      - "8000:8000"
    depends_on:
      - db
    environment:
      DB_HOST: db
      DB_DATABASE: ipflow
      DB_USERNAME: postgres
      DB_PASSWORD: dev_password
    volumes:
      - ./backend:/app

  frontend:
    build:
      context: .
      dockerfile: Dockerfile.frontend
    ports:
      - "5173:5173"
    volumes:
      - .:/app
      - /app/node_modules
    environment:
      VITE_API_BASE_URL: http://localhost:8000/api

volumes:
  pgdata:
```

Create `backend/Dockerfile`:
```dockerfile
FROM php:8.3-cli

RUN apt-get update && apt-get install -y libpq-dev zip unzip \
    && docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
RUN composer install --no-interaction

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
```

Now: `docker-compose up` gives everyone an identical environment in 30 seconds.

---

### 4.5 Add API versioning (`/api/v1/`)

**File:** `backend/routes/api.php`

**The problem:**  
All routes are at `/api/clients`, `/api/projects`, etc. with no version prefix.

**How to fix:**
```php
// backend/routes/api.php

// Version 1 (current)
Route::prefix('v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        // ... all existing routes ...
    });
});

// Backward compatibility: redirect unversioned to v1
Route::any('/{any}', function ($any) {
    return redirect("/api/v1/{$any}", 307);
})->where('any', '.*');
```

Update the frontend API client:
```typescript
// src/lib/api-client.ts
const BASE_URL = import.meta.env.VITE_API_BASE_URL ?? "/api/v1";
```

---

### 4.6 Generate OpenAPI documentation

**The problem:**  
60+ endpoints with no documentation.

**How to fix:**

Install Scramble (auto-generates OpenAPI from Laravel routes):
```bash
cd backend
composer require dedoc/scramble
```

Publish config:
```bash
php artisan vendor:publish --provider="Dedoc\Scramble\ScrambleServiceProvider"
```

Visit `http://localhost:8000/docs/api` — auto-generated, interactive API documentation.

For better docs, add PHPDoc blocks to controller methods:
```php
/**
 * List all clients with optional search and filtering.
 *
 * @queryParam search string Filter by company name, client code, or PAN. Example: Helios
 * @queryParam status string Filter by status (Active, Inactive, Prospect). Example: Active
 * @queryParam gst_type string Filter by GST classification. Example: B2B
 * @queryParam page int Page number. Example: 1
 * @queryParam per_page int Results per page (max 100). Example: 25
 */
public function index(Request $request) { ... }
```

---

## Time Estimates Summary

| Phase | Effort | Risk if Skipped |
|-------|--------|-----------------|
| **Phase 1 — Security** | 1-2 days | 🔴 Data breach, credential theft, financial fraud |
| **Phase 2 — Architecture** | 2-3 weeks | 🟠 Dev velocity drops, bugs multiply per feature |
| **Phase 3 — Data Integrity** | 1-2 weeks | 🟡 Wrong invoices, wrong leave balances, bad accounting |
| **Phase 4 — Testing & DevOps** | Ongoing | 🔵 Regressions every deploy, "works on my machine" |

> **Phase 1 should be done TODAY.**  
> Phase 2-3 can be interleaved over the next month.  
> Phase 4 is a continuous practice you adopt permanently.

---

*Document generated by code audit on 2026-06-13.*
