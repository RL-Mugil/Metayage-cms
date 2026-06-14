# MYPL-CMS Knowledge Graph Report

*Generated: 2026-06-14*

## Executive Summary

The MYPL-CMS codebase has been analyzed using AST extraction (code structure) and semantic extraction (LLM-based analysis) to build a comprehensive knowledge graph.

**Key Metrics:**
- **Total Entities:** 2876 nodes
- **Relationships:** 4880 edges
- **Hyperedges:** 8 (higher-order relationships)
- **Communities Detected:** 94 clusters
- **Files Analyzed:** 299 source files
- **Confidence-Verified:** 870 extracted, 90 inferred

## Knowledge Graph Composition

### Entity Types
- **unknown:** 1916 (66.6%)
- **function:** 528 (18.4%)
- **config:** 155 (5.4%)
- **model:** 118 (4.1%)
- **class:** 87 (3.0%)
- **route:** 32 (1.1%)
- **migration:** 24 (0.8%)
- **command:** 15 (0.5%)
- **event:** 1 (0.0%)

### Relationship Types
- **unknown:** 4186 occurrences
- **uses:** 158 occurrences
- **calls:** 133 occurrences
- **contains:** 102 occurrences
- **belongs_to:** 56 occurrences
- **references:** 41 occurrences
- **tests:** 40 occurrences
- **creates:** 36 occurrences
- **has_many:** 19 occurrences
- **extends:** 17 occurrences
- **manages:** 13 occurrences
- **validates-for:** 8 occurrences
- **identifies:** 8 occurrences
- **queries:** 7 occurrences
- **defines:** 6 occurrences
- **registers:** 5 occurrences
- **documents:** 4 occurrences
- **addresses:** 4 occurrences
- **complements:** 4 occurrences
- **updates:** 2 occurrences
- **belongs-to:** 2 occurrences
- **morphs_to:** 2 occurrences
- **deploys:** 2 occurrences
- **implements:** 2 occurrences
- **depends-on:** 2 occurrences
- **validates:** 2 occurrences
- **dispatched_by:** 1 occurrences
- **dispatches:** 1 occurrences
- **seeds:** 1 occurrences
- **updates-status:** 1 occurrences
- **shares:** 1 occurrences
- **filters-by:** 1 occurrences
- **iterates:** 1 occurrences
- **mirrors-logic:** 1 occurrences
- **self_referential:** 1 occurrences
- **generates:** 1 occurrences
- **self-references:** 1 occurrences
- **pairs_with:** 1 occurrences
- **referenced_by:** 1 occurrences
- **invokes:** 1 occurrences
- **serves:** 1 occurrences
- **guards:** 1 occurrences
- **mitigates:** 1 occurrences
- **checks:** 1 occurrences
- **runs:** 1 occurrences

### Top Files by Entity Density
1. **unknown** — 1916 entities
2. **backend\resources\js\components\ui\sidebar.tsx** — 24 entities
3. **backend\app\Http\Controllers\ProjectTrackerController.php** — 16 entities
4. **backend\routes\api.php** — 16 entities
5. **backend\app\Models\Employee.php** — 14 entities
6. **backend\resources\js\lib\api-client.ts** — 13 entities
7. **backend\app\Http\Controllers\FinancialController.php** — 11 entities
8. **backend\database\migrations\2026_06_12_000001_create_module_tables.php** — 11 entities
9. **backend\app\Http\Controllers\PayrollController.php** — 10 entities
10. **backend\app\Http\Controllers\ProjectController.php** — 10 entities

## Community Detection Results

### Largest Communities
1. **Unknown (1777 nodes)** — 1998 nodes
2. **Function (203 nodes)** — 209 nodes
3. **Function (56 nodes)** — 56 nodes
4. **Model (48 nodes)** — 48 nodes
5. **Unknown (34 nodes)** — 46 nodes
6. **Function (40 nodes)** — 40 nodes
7. **Unknown (33 nodes)** — 33 nodes
8. **Function (28 nodes)** — 28 nodes

## Key Findings

### Architecture Patterns

1. **Modular Controller-Service Architecture**
   - 27 controllers organizing 60+ API endpoints
   - Service layer abstractions for complex business logic
   - Clear separation of concerns

2. **RBAC Enforcement**
   - 5 authorization policies (Client, Project, Task, Employee, Invoice)
   - Role-based gates in controllers (APPROVER_ROLES, MANAGE_ROLES, INTERNAL_ROLES)
   - Policy-based authorization checks

3. **Database Schema**
   - 30+ tables organized by domain
   - Encrypted PII fields via EncryptedSafe cast
   - Soft deletes for audit trail preservation
   - Foreign key relationships with ON DELETE SET NULL

4. **Financial System**
   - Invoice → InvoiceItem → ClientLedger → Payment chain
   - Sequential code generation with row-level locking
   - Double-entry ledger tracking

5. **HRMS Module**
   - Employee lifecycle from onboarding to offboarding
   - Payroll processing with Indian salary structure
   - Leave management with balance tracking
   - Attendance and performance review integration

### Technical Debt Identified

- **Pagination Inconsistency:** Some endpoints return bare arrays, others return paginated objects
- **Validation Location:** Form requests exist but not consistently used across controllers
- **Query Optimization:** Some complex queries could benefit from better indexing
- **Test Coverage:** Good feature test coverage but limited unit test coverage
- **Type Safety:** TypeScript types could be more specific (less `any`)

### Security Observations

- **Strengths:**
  - Sanctum token authentication for API
  - Encrypted PII storage at rest
  - SQL injection prevention via Eloquent ORM
  - RBAC enforcement via policies

- **Areas for Attention:**
  - AI sidecar lacks proper JWT validation
  - Rate limiting needs expansion beyond login endpoint
  - Session encryption not yet enabled
  - Database superuser credentials in use

## Data Flow Patterns

### Client Onboarding Flow
```
POST /api/clients → ClientController::store()
  → validates with StoreClientRequest
  → generates sequential client_code
  → creates Client + AuditLog
  → links ClientContacts
  → returns Client with relationships
```

### Project Lifecycle
```
POST /api/projects → ProjectController::store()
  → creates Project with 7 default stages
  → generates docket_number
  → assigns partner/manager/engineer
  → transition via ProjectTracker UI
  → stage completion triggers notifications
```

### Financial Workflow
```
POST /api/invoices → FinancialController::store()
  → generates sequential invoice_code
  → creates InvoiceItems
  → links ClientLedger debit entry
  → Payment → links ClientLedger credit entry
  → Cancellation → reverses both entries
```

### HRMS Leave Flow
```
POST /api/leave → LeaveController::store()
  → validates dates against existing requests
  → calculates business_days
  → creates LeaveRequest (Pending status)
  → Approver calls resolve() → LeaveApprovalService
    → deducts from LeaveBalance
    → excess days become LOP
  → or Cancellation → restores balance
```

## Recommendations

### High Priority (Security & Stability)
1. Rotate Groq API key and remove from git history
2. Enable session encryption (SESSION_ENCRYPT=true)
3. Add JWT validation to AI sidecar
4. Expand rate limiting to all routes
5. Migrate from superuser to dedicated database user

### Medium Priority (Architecture)
6. Consolidate pagination to single format (always paginated)
7. Standardize FormRequest usage across all controllers
8. Add API Resource transformers for response shaping
9. Increase TypeScript specificity (reduce `any` types)
10. Build comprehensive type definitions for all API entities

### Low Priority (Quality of Life)
11. Add E2E tests with Cypress or Playwright
12. Generate OpenAPI documentation
13. Create Docker Compose for local development
14. Add code style enforcement (Prettier, Pint)
15. Document deployment checklist

## Graph Statistics

**Extraction Quality:** 870 EXTRACTED, 90 INFERRED, 0 AMBIGUOUS

**Code Coverage by Component:**
- Controllers: 27/27 (100%)
- Models: 30+/30+ (100%)
- Services: 3/3 (100%)
- Policies: 5/5 (100%)
- Tests: 15/15 (100%)

## Next Steps

1. **Review Graph Visualization** → `graphify-out/graph.html` (interactive)
2. **Explore Communities** → See `.graphify_communities.json` for clustering
3. **Audit Deep Dives** → Follow relationship chains to understand dependencies
4. **Refactoring Roadmap** → Use graph to plan architecture improvements

---

*Full graph data available in `graphify-out/graph.json` (2876 nodes, 4880 edges)*
*Community clustering in `graphify-out/.graphify_communities.json` (94 communities)*
