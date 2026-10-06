# Zoho Books daily mirror

**Current production approach: API-based deletion checks, with no outgoing webhook or plan upgrade required.** Incoming webhooks send data into Zoho; they are not needed for discovering records deleted inside Zoho. The optional outgoing-webhook setup below is retained only for accounts that choose to use it later.

The Laravel scheduler runs `zoho:sync --scheduled` at **03:00 Asia/Kolkata**. Server cron must continue invoking `php artisan schedule:run` every minute. Apply the six `2026_10_07_00000*` Zoho migrations (storage, cooldown, separate budgets, quote exports, continuation/deletions, legacy cycle-column repair), and build the frontend before deployment. Migration 000005 assigns existing mirror rows to the currently configured organization; verify that legacy rows belong to that organization before deploying. Migration 000006 repairs installations that applied the early storage schema without `started_at`, preserving saved page numbers.

## Calls and progress

- The CMS reserves at most 2,000 Books requests per organization/region per IST calendar day. The counter is stored in PostgreSQL and locked before each request, including failed requests and connection tests. OAuth token renewal is a separate Accounts request and is cached. Clearing application caches does not reset it.
- Separate daily pools allow **1,800 automatic reads, 100 manual reads, and 100 quote writes**. Before 23:00 no pool borrows from another; combined usage cannot exceed 2,000. Connection tests use manual reads; list/detail/PDF requests in a full sync use automatic reads. Failed attempts consume their respective pool. Another application using the same Zoho organization is outside this counter; its usage must be allowed for separately. Deploy after the daily reset or initialize the counter with calls already spent that day. The migration conservatively charges previously unclassified usage to automatic reads and retains total usage until midnight IST.
- Calls are spaced to avoid bursts. There are no automatic HTTP retries. Authentication/rate-limit/server errors stop the run; unsuccessful runs are recorded as failures, never as complete.
- Request spacing retains microsecond precision. A shared transport lock limits this CMS to one active Zoho request per organization, including connection tests. Shared persistent cache storage is required across scheduler/web workers.
- Zoho HTTP 429 and limit codes 44/45/1070 save a database cooldown, respecting numeric or HTTP-date `Retry-After` headers. Without a header, the fallback is 60 seconds for short-term limits or the next IST midnight for code 45. This fallback does not establish Zoho's actual reset timezone; if its limit remains active, a subsequent rejection pauses the CMS again. Cache clearing cannot remove the cooldown. Manual refresh responses include `Retry-After` where available.
- Books and OAuth calls have a 10-second connection timeout and 30-second total timeout. Network failures, malformed responses and upstream server/authentication errors stop further download attempts. HTTP 401 invalidates the cached access token without retrying the same action. A missing remote record (404) is logged, and other records can proceed.
- Dedicated per-user request limits: manual refresh 10/minute, quote writes 5/minute, connection tests 5/minute, whole-sync requests 1/hour, and combined sync-status/PDF/draft-preview reads 30/minute. They use named counters independent of the general API throttle.
- Each successful page (200 records requested) is committed with its checkpoint. Interrupted scans resume at the next page. A multi-day scan remembers which resource lists already finished. The cycle stays incomplete until all pending details and PDFs are downloaded, so a pause during detail/PDF fetching also resumes without repeating completed list scans. There is no 20,000-record cutoff.
- A 60-minute execution limit and a shared overlap lock protect scheduled and manual runs. Partial progress remains available to readers.

## Data coverage

The mirror enumerates invoices, quotations (estimates), customer payments, credit notes and contacts. Invoice/quotation summaries continue feeding existing CMS views. Full JSON details, including returned line items and payment allocations, are stored separately with `EncryptedSafe`; invoice, quotation and credit-note PDFs are encrypted on the private local disk. This does not create duplicate entries in the CMS accounting ledger or overwrite CMS client records.

The first historical import may take several nights, depending on record count. List summaries have priority; remaining capacity fetches details and PDFs. Subsequent scans compare list fingerprints and fetch changed records. Details are also rechecked after seven days to catch changes not reflected in list responses. The status panel distinguishes pending detail/PDF downloads from a completed import. Large inventories or sustained change volumes can exceed a day's capacity; the hard budget takes priority over completing everything in one night.

Invoice and quotation deletion notifications remove the mirrored record from normal portal views and disable its saved PDF. Soft-deleted identity/audit records prevent delayed list/detail responses from recreating it. The separately authored portal quotation remains, with its export marked **Deleted in Zoho** and further sends blocked. Payments, contacts and credit notes are outside this deletion workflow. A successful scan is not an atomic point-in-time snapshot of an actively edited Zoho organization.

## Evening continuation

`zoho:sync --evening` is scheduled every five minutes from 23:00 through 23:55 IST. It runs only when that day's scheduled morning run exhausted its 1,800 automatic calls and the same cycle remains unfinished. It resumes saved pages/details, borrowing only the unused portion of the two reservations, tracked as evening usage. It never starts a fresh evening import. Completed cycles, manual full-sync exhaustion and unrelated morning failures do not qualify.

Every request checks the window and total budget. New requests stop around 23:58:50 IST, leaving time for OAuth and Books timeouts before midnight. The window is checked again after OAuth. A waiting manual transport request pauses evening work; spent calls cannot be reclaimed. Remaining work continues at the next 03:00 run with fresh daily allowances. Unused calls do not carry forward.

## Deletion checks without webhooks

At 03:00 IST, up to 300 saved invoice/quote IDs receive priority detail checks before normal enumeration and downloads. After those downloads, remaining automatic capacity checks the rest. Successful detail reads also update the mirror, so existence checking and refreshing share one request. Legacy mirror rows without archived details are included.

Checks use the existing 1,800-call automatic pool, not a new allowance. The 100 manual reads and 100 quote writes stay reserved until the existing eligible 23:00 continuation. Successful detail timestamps, ordered oldest first, provide durable progress; records checked today are not rechecked solely for existence. The IST date boundary is converted to UTC for database comparisons. Changed list fingerprints may still require a new detail download. If capacity runs out, remaining checks resume alongside the same import cycle, without restarting its list pages. PDFs invalidated by checks stay pending until downloaded.

The status panel shows how many currently mirrored invoices/quotes have been checked today and how many remain. The target is daily coverage when capacity allows, not a guarantee of same-day detection for inventories larger than the budget or during API failures. Use **Sync this record** for an urgent check: one Books detail request. Only an explicit invoice/estimate not-found response triggers removal; an absent list entry, a generic 404, a PDF error or a failed request does not.

## Optional outgoing deletion notifications

Configure two Zoho Books workflows for the **Deleted** event: invoices and estimates/quotations. Verify those triggers are available in the connected account before activation. Follow Zoho's [workflow rules](https://www.zoho.com/books/help/settings/automation/workflow-rules.html) and [signed webhooks](https://www.zoho.com/books/help/settings/automation/workflow-actions/webhooks.html) instructions.

1. Generate a private 12–50 character alphanumeric secret and set the same value in the webhook signing configuration and backend `ZOHO_WEBHOOK_SECRET`. Refresh Laravel's configuration cache after setting it.
2. POST to the portal's HTTPS `/api/webhooks/zoho/deletions` URL, with no query parameters, using raw `application/json`. Enable the `X-Zoho-Webhook-Signature` header containing Base64-encoded HMAC-SHA256 of the exact raw JSON body. Unsigned requests, wrong organizations and unconfigured receivers are rejected.
3. Use this body contract, inserting the organization's and deleted record's IDs through Zoho's field picker as strings. For quotations, change `resource` to `estimates` and insert the estimate ID:

```json
{"organization_id":"YOUR_ORGANIZATION_ID","resource":"invoices","event":"deleted","remote_id":"DELETED_INVOICE_ID"}
```

The receiver saves a deduplicated event before acknowledging it and makes no Books request. `zoho:process-deletions` runs every minute, applies transactional deletion/audit changes and purges encrypted PDFs. Local failures retry after five minutes, up to five attempts. The sync panel shows pending and exhausted events. After fixing a processing failure, run `php artisan zoho:process-deletions --retry-failed`. For notifications never received by the portal, inspect Zoho's Workflow Logs and replay them there; configure the available failure notifications and monitor the account's separate webhook delivery allowance.

The API checks above operate independently of notifications. Only an invoice/estimate detail response with HTTP 400/404, code 1002 and an explicit Invoice/Estimate/Quote/Resource “does not exist” message is treated as deletion. Generic 404, PDF 404, authentication and rate-limit errors never delete records.

Mirror identity is unique by organization/region, document type and Zoho ID. Re-reading the same ID updates it. Different IDs with the same document number remain separate and are flagged; ambiguous number matches are excluded from automatic number matching.

The OAuth grant must include READ access for invoices, estimates, customerpayments, creditnotes and contacts (and organizations for connectivity checks). Existing narrower grants may need reauthorization. Test calls should not be used to probe records repeatedly when a scope is missing.

## Urgent refresh

In **Financial → Zoho Books**, use **Sync this record** beside an invoice or quotation. This uses exactly one Books data request for that document's detail, with no PDF download, list scan or automatic retry. If the cached access token has expired, authentication requires a separate Accounts request. Updated PDFs are deferred to the nightly sync. It preserves manual case links and invalidates cached client/case summaries. The PDF button reads the private saved copy without calling Zoho. The nightly sync catches up related contacts, standalone payments and credit notes; a document refresh does not refresh those entire lists.

Super admins, partners and finance can refresh records. Managers can refresh records linked to their assigned projects; galvanizers can refresh their scoped projects. Other users cannot invoke refresh or the new private PDF endpoint. Concurrent sync requests receive a busy response. The status panel shows CMS call usage, pending downloads and the latest sync outcome.

## Quote drafts and accountant workflow

Both standard Quotations and IN Quotes have **Send to Zoho** for super admins, partners and finance. Select an existing active Zoho customer from the saved contact mirror and review the preview. A linked client's Zoho customer ID is enforced, and the customer's downloaded currency must match the quotation. Opening the preview consumes no Zoho calls. Contacts must first be downloaded; the portal cannot create customers in Zoho.

Each send creates one estimate with `send=false`, using one Books POST and the write pool. It does not email the client or create/modify a Zoho invoice. Only quote creation is exposed by the writer; there is no generic outbound mutation endpoint. The OAuth grant additionally needs `ZohoBooks.estimates.CREATE`, as documented in the [official estimate API](https://www.zoho.com/books/api/v3/estimates/). Existing read-only grants require reauthorization. Invoice CREATE/UPDATE permissions are not needed.

Draft lines carry pre-tax fees (IN service discounts are applied before export), patent-office fees and other expenses. Internal attorney, consultant and referral costs are excluded. No tax IDs are guessed or tax amounts disguised as service fees. The preview and draft notes show the portal's expected tax and total; the accountant must apply/verify Zoho tax settings and final amounts before issuing the invoice. Standard quotations use their stored pre-tax subtotal, without adding disbursements twice. Inconsistent totals, inactive quotes and mismatched customers/currencies are rejected locally.

Exports have a durable, encrypted payload and a unique source/organization record. Repeat clicks return the confirmed export without another call. Timeouts, server failures, malformed success responses or interrupted completion block resending until reconciliation; they never trigger an automatic write retry. Explicit upstream rejection or an exhausted budget permits a later manual retry. The regular estimate scan reconciles uncertain sends by the portal reference and customer, without a separate manual lookup. An unresolved uncertain send requires operator investigation in Zoho; do not delete its export record or blindly resend.

After export, edit/review the draft in Zoho. Portal edits do not overwrite it. The accountant converts it there and retains the portal reference (`IPFLOW-Q-…` / `IPFLOW-INQ-…`). Invoice sync links via the estimate ID or that reference with the same customer, while preserving manual case links. Invoices still download if no reference is retained, but may need manual case linking. Previously exported quotes cannot be converted into duplicate local invoices. Existing local invoice workflows outside this export flow are unchanged.

## Verification

Run `php artisan test --filter=Zoho` against a dedicated PostgreSQL test database, and `npm run build` from `backend`. The regression tests mock all external HTTP calls and exercise budgeting, IST reset, resume checkpoints, manual authorization, manual-link preservation, encryption, PDF reads, pagination beyond 100 pages and schedule timing.
