#!/usr/bin/env bash
# Step 1 (run from the repo root): copy the files for the daily health report to the server's /tmp. Nothing is installed yet.
set -euo pipefail
FILES="database/migrations/2026_10_16_000001_create_ops_incidents_table.php app/Models/OpsIncident.php app/Mail/OpsReportMail.php
app/Services/OpsAlertService.php app/Services/OpsDailyReport.php app/Services/OpsIncidentService.php app/Services/OpsPlainEnglish.php
app/Console/Commands/OpsDailyReportCommand.php app/Console/Commands/OpsIncidentSweepCommand.php app/Console/Commands/PruneOperationalData.php
app/Providers/AppServiceProvider.php routes/console.php"
ssh root@139.59.85.216 'rm -rf /tmp/dr && mkdir -p /tmp/dr'
(cd backend && tar czf - $FILES) | ssh root@139.59.85.216 'tar xzf - -C /tmp/dr'
scp -q ops/ipflow_alert.py ops/ipflow_monitor.sh root@139.59.85.216:/tmp/dr/
echo "pushed: $(ssh root@139.59.85.216 'find /tmp/dr -type f | wc -l') files in /tmp/dr"
