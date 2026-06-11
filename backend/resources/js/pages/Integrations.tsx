import { Head } from "@inertiajs/react";
import { useState } from "react";
import { Plug, Zap, RefreshCw, Check, X, Settings, Globe, Mail, CheckCircle } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

interface Integration {
  id: string;
  name: string;
  description: string;
  category: string;
  initials: string;
  color: string;
  connected: boolean;
  lastSync?: string;
  syncFreq?: string;
}

const integrations: Integration[] = [
  { id: "gcal", name: "Google Calendar", description: "Sync IP deadlines and meetings", category: "Productivity", initials: "GC", color: "bg-blue-500", connected: true, lastSync: "5 min ago", syncFreq: "Every 15 min" },
  { id: "slack", name: "Slack", description: "Send deadline alerts and notifications", category: "Communication", initials: "SL", color: "bg-purple-600", connected: true, lastSync: "Real-time", syncFreq: "Real-time" },
  { id: "gmail", name: "Gmail / SMTP", description: "Send emails and client communications", category: "Email", initials: "GM", color: "bg-red-500", connected: true, lastSync: "2 hours ago", syncFreq: "On demand" },
  { id: "qb", name: "QuickBooks", description: "Sync invoices and financial records", category: "Accounting", initials: "QB", color: "bg-green-600", connected: false },
  { id: "uspto", name: "USPTO API", description: "Fetch patent filing and examination status", category: "IP Office", initials: "US", color: "bg-blue-800", connected: true, lastSync: "1 hour ago", syncFreq: "Hourly" },
  { id: "epo", name: "EPO OPS", description: "European patent data and family information", category: "IP Office", initials: "EP", color: "bg-indigo-600", connected: true, lastSync: "2 hours ago", syncFreq: "Every 6 hours" },
  { id: "docusign", name: "DocuSign", description: "E-signature for contracts and agreements", category: "Legal", initials: "DS", color: "bg-amber-600", connected: false },
  { id: "teams", name: "Microsoft Teams", description: "Meeting scheduling and notifications", category: "Communication", initials: "MT", color: "bg-blue-700", connected: false },
];

const webhookLogs = [
  { id: 1, source: "USPTO API", event: "patent.status_update", status: 200, time: "2026-06-04 14:32:01", size: "1.2 KB" },
  { id: 2, source: "Slack", event: "notification.sent", status: 200, time: "2026-06-04 14:28:45", size: "0.4 KB" },
  { id: 3, source: "Google Calendar", event: "event.created", status: 200, time: "2026-06-04 14:15:12", size: "2.1 KB" },
  { id: 4, source: "EPO OPS", event: "patent.family_fetch", status: 200, time: "2026-06-04 13:00:00", size: "8.7 KB" },
  { id: 5, source: "Gmail", event: "email.sent", status: 200, time: "2026-06-04 12:45:33", size: "3.2 KB" },
  { id: 6, source: "USPTO API", event: "patent.status_update", status: 429, time: "2026-06-04 11:00:15", size: "0.2 KB" },
  { id: 7, source: "Google Calendar", event: "event.sync", status: 200, time: "2026-06-04 10:30:00", size: "4.5 KB" },
  { id: 8, source: "Slack", event: "notification.sent", status: 200, time: "2026-06-04 09:15:22", size: "0.3 KB" },
  { id: 9, source: "EPO OPS", event: "patent.search", status: 200, time: "2026-06-04 08:00:00", size: "12.4 KB" },
  { id: 10, source: "Gmail", event: "email.bounced", status: 422, time: "2026-06-04 07:45:01", size: "0.8 KB" },
];

export default function Integrations() {
  const [list, setList] = useState(integrations);
  const [configOpen, setConfigOpen] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<Record<string, "ok" | "fail" | null>>({});
  const [apiKeys, setApiKeys] = useState<Record<string, string>>({});

  const toggle = (id: string) => {
    setList((prev) => prev.map((i) => i.id === id ? { ...i, connected: !i.connected, lastSync: !i.connected ? "just now" : undefined } : i));
  };

  const testConnection = (id: string) => {
    setTimeout(() => setTestResult((prev) => ({ ...prev, [id]: Math.random() > 0.2 ? "ok" : "fail" })), 800);
  };

  const connected = list.filter((i) => i.connected).length;

  return (
    <AppLayout>
      <Head title="Integrations" />
      <PageHeader eyebrow="Operations" title="Integrations"
        description={`${connected} of ${list.length} integrations active`} />
      <div className="px-8 py-6 space-y-8">
        {/* Integration cards */}
        <div className="grid grid-cols-3 gap-4">
          {list.map((intg) => (
            <Card key={intg.id} className={`border-border transition-all ${configOpen === intg.id ? "ring-2 ring-gold" : ""}`}>
              <CardContent className="p-5">
                <div className="flex items-start gap-4">
                  <div className={`h-12 w-12 rounded-xl ${intg.color} flex items-center justify-center text-white font-bold text-sm flex-shrink-0`}>
                    {intg.initials}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between">
                      <span className="font-semibold text-sm">{intg.name}</span>
                      {intg.connected ? (
                        <Badge className="bg-green-100 text-green-700 border-green-200 text-[10px]">Connected</Badge>
                      ) : (
                        <Badge variant="outline" className="text-[10px]">Not Connected</Badge>
                      )}
                    </div>
                    <p className="text-xs text-muted-foreground mt-0.5">{intg.description}</p>
                    <Badge variant="outline" className="mt-1.5 text-[10px]">{intg.category}</Badge>
                    {intg.connected && intg.lastSync && (
                      <div className="text-[10px] text-muted-foreground mt-1.5 flex items-center gap-1">
                        <RefreshCw className="h-2.5 w-2.5" /> Last sync: {intg.lastSync} · {intg.syncFreq}
                      </div>
                    )}
                  </div>
                </div>
                <div className="flex gap-2 mt-4">
                  {intg.connected ? (
                    <>
                      <Button size="sm" variant="outline" className="h-7 text-xs flex-1"
                        onClick={() => setConfigOpen(configOpen === intg.id ? null : intg.id)}>
                        <Settings className="h-3 w-3 mr-1" /> Configure
                      </Button>
                      <Button size="sm" variant="outline" className="h-7 text-xs border-red-200 text-red-600 hover:bg-red-50"
                        onClick={() => toggle(intg.id)}>
                        <X className="h-3 w-3 mr-1" /> Disconnect
                      </Button>
                    </>
                  ) : (
                    <Button size="sm" className="h-7 text-xs flex-1 bg-gold hover:bg-gold/90 text-black"
                      onClick={() => toggle(intg.id)}>
                      <Plug className="h-3 w-3 mr-1" /> Connect
                    </Button>
                  )}
                </div>

                {/* Config panel */}
                {configOpen === intg.id && (
                  <div className="mt-4 pt-4 border-t border-border space-y-3">
                    <div>
                      <label className="text-xs font-medium text-muted-foreground">API Key</label>
                      <input type="password" placeholder="sk-••••••••••••••••"
                        value={apiKeys[intg.id] || ""}
                        onChange={(e) => setApiKeys((p) => ({ ...p, [intg.id]: e.target.value }))}
                        className="mt-1 w-full h-8 rounded border border-border bg-background px-3 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <div>
                      <label className="text-xs font-medium text-muted-foreground">Webhook URL</label>
                      <div className="mt-1 flex items-center gap-2">
                        <input readOnly value={`https://mypl-cms.139-59-85-216.sslip.io/api/webhooks/${intg.id}`}
                          className="flex-1 h-8 rounded border border-border bg-muted/30 px-3 text-xs text-muted-foreground" />
                        <Globe className="h-4 w-4 text-muted-foreground" />
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <Button size="sm" className="h-7 text-xs" onClick={() => testConnection(intg.id)}>
                        <Zap className="h-3 w-3 mr-1" /> Test Connection
                      </Button>
                      {testResult[intg.id] === "ok" && <span className="text-xs text-green-600 flex items-center gap-1"><CheckCircle className="h-3 w-3" /> Connected</span>}
                      {testResult[intg.id] === "fail" && <span className="text-xs text-red-600 flex items-center gap-1"><X className="h-3 w-3" /> Failed</span>}
                    </div>
                  </div>
                )}
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Webhook logs */}
        <Card className="border-border">
          <CardHeader><CardTitle className="font-display text-base">Webhook Logs</CardTitle></CardHeader>
          <CardContent className="p-0">
            <table className="w-full text-xs">
              <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-2.5 text-left">Source</th>
                  <th className="px-4 py-2.5 text-left">Event</th>
                  <th className="px-4 py-2.5 text-left">Status</th>
                  <th className="px-4 py-2.5 text-left">Time</th>
                  <th className="px-4 py-2.5 text-left">Size</th>
                </tr>
              </thead>
              <tbody>
                {webhookLogs.map((log) => (
                  <tr key={log.id} className="border-t border-border hover:bg-muted/20">
                    <td className="px-4 py-2 font-medium">{log.source}</td>
                    <td className="px-4 py-2 font-mono text-muted-foreground">{log.event}</td>
                    <td className="px-4 py-2">
                      <span className={`px-1.5 py-0.5 rounded font-mono font-bold ${log.status === 200 ? "text-green-600 bg-green-50" : "text-red-600 bg-red-50"}`}>
                        {log.status}
                      </span>
                    </td>
                    <td className="px-4 py-2 text-muted-foreground">{log.time}</td>
                    <td className="px-4 py-2 text-muted-foreground">{log.size}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
