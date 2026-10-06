# Zoho production release — 4 October 2026

Target: https://myipstrategy.com, `/var/www/mypl-cms/backend` on `139.59.85.216`.

The production application source, frontend source and scheduler definitions already matched the tested local implementation when inspected. All five Zoho migrations were already recorded as applied. No migration or budget counter was reset.

The activation script creates a PostgreSQL custom-format backup from the application container (`mypl-cms-postgres`, port 5433), validates its archive listing, saves application files/assets and the environment privately on the server, builds the frontend into a staging directory, publishes assets before atomically replacing the manifest, refreshes configuration/routes and restarts the scheduler.

Verified backup: `/var/backups/mypl-cms/zoho-20261004T174913Z`. Keep its environment backup private. For an asset regression, restore `public/build` from `source-and-assets.tar.gz`; for a PHP regression, restore the affected source files from that archive, refresh Laravel caches and reload PHP-FPM. This activation does not change the database schema; a database restore is not part of routine rollback.

The production configuration uses Redis for shared locks. Inspection found 961 mirrored records, all scoped to the configured organization. Live HTTPS login/health endpoints returned 200; unauthenticated Zoho sync status returned 401. Test verification before activation: 73 tests, 306 assertions, TypeScript check and frontend build passed.

## Remaining Zoho account configuration

`ZOHO_WEBHOOK_SECRET` was absent, and no deletion events had been received. Configure the shared signing secret and invoice/estimate Deleted workflows as described in [zoho-sync.md](zoho-sync.md). The browser automation runtime failed to start, so account-side setup could not be completed in this session. Do not report immediate webhook deletion propagation as active until delivery is verified. Scheduled rolling checks provide the budgeted fallback.

Follow-up: the production signing secret has now been generated securely (32 alphanumeric characters), saved in the server environment, and verified against Laravel's refreshed configuration cache. The previous environment was backed up privately. The two Zoho workflows must still be configured with this same secret; end-to-end delivery has not yet been verified.

## Superseding release — 5 October 2026 IST

The user selected API checks without outgoing webhooks or a subscription upgrade. Outgoing workflow setup is no longer a required task. Incoming webhooks are not needed for this read-only deletion detection. The existing optional receiver and saved key may remain unused.

Deployed oldest-first daily checks: 300 priority checks, normal list/detail/PDF work, then checks using remaining automatic capacity. The same detail calls refresh data; checked timestamps provide durable progress. Manual and write pools remain 100 each, with the existing evening borrowing conditions. The portal now displays checked/due record counts rather than requiring webhook setup.

Release backup: `/var/backups/mypl-cms/zoho-polling-20261004T224331Z`. Production build, PHP lint, HTTPS login and health checks passed. The production 03:00 run exposed a missing `zoho_sync_progress.started_at` column from an early schema. A tested, idempotent compatibility migration (`2026_10_07_000006`) restored the column without resetting saved page checkpoints. The failed morning run was then retried under the normal automatic budget.

Live verification also found Books returning HTTP 404/code 1002 with `Resource does not exist.` for a legacy invoice. A currently listed invoice's detail was read successfully to verify the endpoint/organization before enabling this specific response variant for supported detail URLs only. Generic errors and PDF not-found responses never delete invoices. Final suite: 78 Zoho tests, 333 assertions passed. Zoho also uses `Quote does not exist.` on the estimates endpoint; both Quote and Estimate labels are covered by regression tests. The recovered run was confirmed advancing: 12 current records checked and 7 confirmed-missing records removed at the first observation, with both reserved pools protected (three manual calls were used for diagnostics). Initial catch-up continues in the background and is subject to normal pause/resume budgets.

The evening schedule now uses explicit `*/5 23 * * *` cron with `Asia/Kolkata`, avoiding time-filter construction in the server timezone. Five additional scheduler boundary cases passed (10 assertions), for 83 tested cases and 343 assertions across the final checks. Later live observation confirmed 309 current records checked, 48 confirmed-missing records removed, and zero quote writes. Four manual diagnostic reads were consumed. No budget counters or checkpoints were reset.
