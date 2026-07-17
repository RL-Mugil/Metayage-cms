# Tenant Data Ownership Strategy

## Permanent Business Model

- A firm is a SaaS tenant. MYPL is the first firm and remains a full operating IP practice.
- A client belongs to exactly one firm. A client is never treated as a SaaS tenant.
- A project or matter belongs to the same firm as its client.
- A portal user is a user identity linked to a client inside a firm.
- Staff and portal access is represented by `firm_user`; existing `users`, client contacts, and portal links remain valid.
- MYPL can continue adding clients, matters, staff, and portal users without a firm selector.

## Compatibility Invariants

1. Existing users, passwords, sessions, roles, and URLs are not replaced.
2. Existing `clients.portal_user_id` and contact-email portal links are not rewritten.
3. Existing records are backfilled to the `legacy-firm` MYPL tenant.
4. Ownership columns remain nullable during expand and validation releases.
5. Query filtering is introduced only after every write path assigns ownership and cross-firm tests pass.
6. No second firm is onboarded until user roles are read from `firm_user` rather than only `users.role`.
7. No second firm is onboarded until global client, matter, docket, and contact uniqueness rules are converted to firm-aware constraints.

## Ownership Waves

| Wave | Ownership roots | Strategy |
|---|---|---|
| 1 | `clients`, `projects`, `patent_applications`, `docket_events` | Add/backfill `firm_id`; assign ownership on create; retain existing query behavior. |
| 2 | tracker, documents, invoices, quotations, payments, ledgers, patent invoice intake | Derive ownership from client/project and reject conflicting parent ownership. |
| 3 | HRMS, discussions, approvals, reminders, compliance, integrations, reports, audit logs | Add module ownership and firm-scoped administration. |
| 4 | tasks, stages, deadlines, renewals, document versions, invoice items and other children | Prefer parent-derived scope; denormalize `firm_id` only where security or indexed reporting requires it. |

## Rollout Sequence

1. Expand schema with nullable ownership and foreign keys.
2. Backfill MYPL and verify row counts, parent consistency, and portal links.
3. Assign ownership on every new write while reads remain unchanged.
4. Add firm-scoped policies, validation rules, imports, jobs, searches, reports, and AI read models.
5. Add negative cross-firm tests for read, write, export, search, and portal access.
6. Enable scoped reads for one module at a time behind a feature flag.
7. Enforce non-null ownership only after production null-count monitoring remains at zero.
8. Add firm switching and SaaS onboarding after membership-scoped RBAC is complete.

## DocketTrak And MCP Boundary

- Every import run is owned by the authenticated firm and uses external source identifiers plus idempotency keys.
- Imported clients and matters may update only records owned by that firm.
- MCP tools call firm-scoped application services; they do not execute unrestricted SQL.
- MCP reads are the default. Mutations require authorization, validation, idempotency, and an audit record.
