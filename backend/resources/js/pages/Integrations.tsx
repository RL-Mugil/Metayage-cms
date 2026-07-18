import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState, type JSX } from "react";
import { Plug, Zap, RefreshCw, X, Settings, CheckCircle, Loader2, Activity, ChevronDown, ChevronUp, Copy, Eye, Link2Off } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

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
  hasKey?: boolean;
}

const MANAGE_ROLES = ["super_admin", "manager", "galvanizer"];

// ── Brand SVG logos keyed by integration slug ─────────────────────────────────
function LogoSlack() {
  return (
    <svg viewBox="0 0 24 24" className="h-7 w-7">
      <path d="M5.042 15.165a2.528 2.528 0 0 1-2.52 2.523A2.528 2.528 0 0 1 0 15.165a2.527 2.527 0 0 1 2.522-2.52h2.52v2.52zm1.271 0a2.527 2.527 0 0 1 2.521-2.52 2.527 2.527 0 0 1 2.521 2.52v6.313A2.528 2.528 0 0 1 8.834 24a2.528 2.528 0 0 1-2.521-2.522v-6.313zm2.521-10.123a2.528 2.528 0 0 1-2.521-2.52A2.528 2.528 0 0 1 8.834 0a2.528 2.528 0 0 1 2.521 2.522v2.52H8.834zm0 1.271a2.528 2.528 0 0 1 2.521 2.521 2.528 2.528 0 0 1-2.521 2.521H2.522A2.528 2.528 0 0 1 0 8.834a2.528 2.528 0 0 1 2.522-2.521h6.312zm10.123 2.521a2.528 2.528 0 0 1 2.522-2.521A2.528 2.528 0 0 1 24 8.834a2.528 2.528 0 0 1-2.522 2.521h-2.522V8.834zm-1.271 0a2.528 2.528 0 0 1-2.523 2.521 2.527 2.527 0 0 1-2.52-2.521V2.522A2.527 2.527 0 0 1 15.165 0a2.528 2.528 0 0 1 2.523 2.522v6.312zm-2.523 10.123a2.528 2.528 0 0 1 2.523 2.522A2.528 2.528 0 0 1 15.165 24a2.527 2.527 0 0 1-2.52-2.522v-2.522h2.52zm0-1.271a2.527 2.527 0 0 1-2.52-2.523 2.526 2.526 0 0 1 2.52-2.52h6.313A2.527 2.527 0 0 1 24 15.165a2.528 2.528 0 0 1-2.522 2.523h-6.313z" fill="#4A154B"/>
    </svg>
  );
}

function LogoGoogleCalendar() {
  return (
    <svg viewBox="0 0 24 24" className="h-7 w-7">
      <path d="M18.316 5.684H24v12.632h-5.684V5.684z" fill="#EA4335"/>
      <path d="M5.684 24v-5.684H18.32V24H5.684z" fill="#34A853"/>
      <path d="M0 18.316v-12.63h5.684v12.63H0z" fill="#4285F4"/>
      <path d="M24 5.684H18.32V0H24v5.684z" fill="#188038"/>
      <path d="M0 5.684V0h5.684v5.684H0z" fill="#1967D2"/>
      <path d="M5.684 0h12.632v5.684H5.684V0z" fill="#FBBC04"/>
      <path d="M5.684 5.684h12.632v12.632H5.684V5.684z" fill="#fff"/>
      <text x="12" y="15.5" textAnchor="middle" fontSize="7" fontWeight="bold" fill="#4285F4" fontFamily="Arial">
        31
      </text>
    </svg>
  );
}

function LogoGmail() {
  return (
    <svg viewBox="0 0 24 24" className="h-7 w-7">
      <path d="M24 5.457v13.909c0 .904-.732 1.636-1.636 1.636h-3.819V11.73L12 16.64l-6.545-4.91v9.273H1.636A1.636 1.636 0 0 1 0 19.366V5.457c0-2.023 2.309-3.178 3.927-1.964L5.455 4.64 12 9.548l6.545-4.910 1.528-1.145C21.69 2.28 24 3.434 24 5.457z" fill="#EA4335"/>
      <path d="M12 9.548L5.455 4.64 3.927 3.494C2.31 2.28 0 3.434 0 5.457v.503L12 14.087l12-8.127v-.503c0-2.023-2.309-3.178-3.927-1.964L18.545 4.64 12 9.548z" fill="#C5221F" opacity=".3"/>
    </svg>
  );
}

function LogoMicrosoftTeams() {
  return (
    <svg viewBox="0 0 24 24" className="h-7 w-7">
      <path d="M20.625 5.625h-6.75a.375.375 0 0 0-.375.375v5.625c0 2.071 1.679 3.75 3.75 3.75h.375v1.875c0 .207.168.375.375.375a5.25 5.25 0 0 0 5.25-5.25V6.75a1.125 1.125 0 0 0-2.625-1.125z" fill="#5059C9"/>
      <circle cx="18.375" cy="3" r="2.25" fill="#5059C9"/>
      <circle cx="9" cy="3.375" r="2.625" fill="#7B83EB"/>
      <path d="M13.5 6.375H3.75A1.125 1.125 0 0 0 2.625 7.5v6.375A5.625 5.625 0 0 0 9 19.5a5.625 5.625 0 0 0 6.375-5.625V7.5a1.125 1.125 0 0 0-1.875-1.125z" fill="#7B83EB"/>
      <path d="M9.375 6.375H13.5v7.5A5.625 5.625 0 0 1 7.875 19.3V8.25a1.875 1.875 0 0 1 1.5-1.875z" fill="#fff" opacity=".1"/>
    </svg>
  );
}

function LogoDocuSign() {
  return (
    <svg viewBox="0 0 24 24" className="h-7 w-7">
      <rect width="24" height="24" rx="4" fill="#FFDD00"/>
      <path d="M7 6h5.5a3.5 3.5 0 0 1 0 7H7V6z" fill="#333"/>
      <path d="M7 13h5.5a3.5 3.5 0 0 1 3.5 3.5v.5H7v-4z" fill="#333" opacity=".6"/>
      <path d="M4 18.5c2 .5 5-1 7-3" stroke="#333" strokeWidth="1.2" strokeLinecap="round" fill="none"/>
    </svg>
  );
}

function LogoUSPTO() {
  return (
    <svg viewBox="0 0 24 24" className="h-7 w-7">
      <path d="M12 1L3 5v6c0 5.25 3.75 10.15 9 11.4C17.25 21.15 21 16.25 21 11V5l-9-4z" fill="#003087"/>
      <path d="M9 12l2 2 4-4" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none"/>
      <text x="12" y="19" textAnchor="middle" fontSize="5" fontWeight="bold" fill="#fff" fontFamily="Arial">USPTO</text>
    </svg>
  );
}

function LogoEPO() {
  return (
    <svg viewBox="0 0 24 24" className="h-7 w-7">
      <circle cx="12" cy="12" r="11" fill="#003399"/>
      {/* 12 EU stars */}
      {Array.from({ length: 12 }).map((_, i) => {
        const angle = (i * 30 - 90) * (Math.PI / 180);
        const x = 12 + 7.5 * Math.cos(angle);
        const y = 12 + 7.5 * Math.sin(angle);
        return <circle key={i} cx={x} cy={y} r="1.1" fill="#FFDD00"/>;
      })}
      <text x="12" y="14" textAnchor="middle" fontSize="4.5" fontWeight="bold" fill="#fff" fontFamily="Arial">EPO</text>
    </svg>
  );
}

function LogoZohoBooks() {
  return (
    <svg viewBox="0 0 24 24" className="h-7 w-7">
      <rect width="24" height="24" rx="5" fill="#E42527"/>
      <text x="12" y="16" textAnchor="middle" fontSize="11" fontWeight="bold" fill="#fff" fontFamily="Arial, sans-serif">Z</text>
    </svg>
  );
}

function LogoQuickBooks() {
  return (
    <svg viewBox="0 0 24 24" className="h-7 w-7">
      <circle cx="12" cy="12" r="12" fill="#2CA01C"/>
      <path d="M6 12a6 6 0 1 1 12 0 6 6 0 0 1-12 0zm6-3.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z" fill="#fff"/>
      <circle cx="12" cy="12" r="1.8" fill="#2CA01C"/>
    </svg>
  );
}

const BRAND_LOGOS: Record<string, () => JSX.Element> = {
  slack:    LogoSlack,
  gcal:     LogoGoogleCalendar,
  gmail:    LogoGmail,
  teams:    LogoMicrosoftTeams,
  docusign: LogoDocuSign,
  uspto:    LogoUSPTO,
  epo:      LogoEPO,
  zoho:     LogoZohoBooks,
  qb:       LogoQuickBooks,
};

function IntegrationLogo({ slug, initials, color }: { slug: string; initials: string; color: string }) {
  const Logo = BRAND_LOGOS[slug];
  if (Logo) {
    return (
      <div className="h-12 w-12 rounded-xl bg-white border border-border flex items-center justify-center flex-shrink-0 shadow-sm">
        <Logo />
      </div>
    );
  }
  return (
    <div className={`h-12 w-12 rounded-xl ${color} flex items-center justify-center text-white font-bold text-sm flex-shrink-0`}>
      {initials}
    </div>
  );
}

export default function Integrations() {
  const { props } = usePage() as any;
  const canManage = MANAGE_ROLES.includes(props.auth?.user?.role ?? "");

  const [list, setList] = useState<Integration[]>([]);
  const [loading, setLoading] = useState(true);
  const [configOpen, setConfigOpen] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<Record<string, "ok" | "fail" | null>>({});
  const [apiKeys, setApiKeys] = useState<Record<string, string>>({});
  const [keySaved, setKeySaved] = useState<Record<string, boolean>>({});
  const [busyId, setBusyId] = useState<string | null>(null);
  const [banner, setBanner] = useState<{ kind: "ok" | "err"; text: string } | null>(null);
  const [logsOpen, setLogsOpen] = useState<string | null>(null);
  const [logs, setLogs] = useState<Record<string, any[]>>({});
  const [logsLoading, setLogsLoading] = useState<string | null>(null);
  const webhookBase = `${window.location.origin}/api/webhooks`;

  // Google Tasks / Calendar personal connection state
  const [gcal, setGcal] = useState<{ connected: boolean; email: string | null } | null>(null);
  const [gcalBusy, setGcalBusy] = useState(false);

  useEffect(() => {
    api.request('/integrations/google-calendar/status')
      .then((d: any) => setGcal({ connected: d.connected, email: d.email ?? null }))
      .catch(() => {});

    const params = new URLSearchParams(window.location.search);
    const gcalParam = params.get('gcal');
    if (gcalParam === 'connected') {
      setBanner({ kind: 'ok', text: 'Google account connected! Your project deadlines will now sync as Google Tasks.' });
      window.history.replaceState({}, '', '/integrations');
    } else if (gcalParam === 'error') {
      setBanner({ kind: 'err', text: 'Google connection failed: ' + (params.get('reason') ?? 'unknown error') });
      window.history.replaceState({}, '', '/integrations');
    }
  }, []);

  async function disconnectGcal() {
    setGcalBusy(true);
    try {
      await api.request('/integrations/google-calendar/disconnect', { method: 'POST' });
      setGcal({ connected: false, email: null });
      setBanner({ kind: 'ok', text: 'Google account disconnected.' });
    } catch {
      setBanner({ kind: 'err', text: 'Failed to disconnect.' });
    } finally {
      setGcalBusy(false);
    }
  }

  const load = () => api.getIntegrations()
    .then((d) => setList(d as unknown as Integration[]))
    .catch((error) => setBanner({ kind: "err", text: error instanceof Error ? error.message : "Failed to load integrations." }))
    .finally(() => setLoading(false));
  useEffect(() => { load(); }, []);

  const toggle = async (id: string) => {
    setBusyId(id);
    setBanner(null);
    try {
      const response = await api.toggleIntegration(id);
      setBanner({ kind: "ok", text: response.message });
      load();
    } catch (error) {
      setBanner({ kind: "err", text: error instanceof Error ? error.message : "Failed to update integration." });
    } finally {
      setBusyId(null);
    }
  };

  const testConnection = (id: string) => {
    setTestResult((prev) => ({ ...prev, [id]: null }));
    api.testIntegration(id)
      .then((r) => {
        setTestResult((prev) => ({ ...prev, [id]: r.ok ? "ok" : "fail" }));
        setBanner({ kind: r.ok ? "ok" : "err", text: r.message ?? (r.ok ? "Integration test passed." : "Integration test failed.") });
      })
      .catch((error) => {
        setTestResult((prev) => ({ ...prev, [id]: "fail" }));
        setBanner({ kind: "err", text: error instanceof Error ? error.message : "Integration test failed." });
      });
  };

  const fetchLogs = (id: string) => {
    setLogsOpen(id);
    if (logs[id]) return;
    setLogsLoading(id);
    api.getIntegrationLogs(id)
      .then((d: any) => setLogs((p) => ({ ...p, [id]: Array.isArray(d) ? d : [] })))
      .catch(() => setLogs((p) => ({ ...p, [id]: [] })))
      .finally(() => setLogsLoading(null));
  };

  const saveKey = (id: string) => {
    const key = apiKeys[id];
    if (!key) return;
    api.saveIntegrationConfig(id, key)
      .then(() => {
        setKeySaved((p) => ({ ...p, [id]: true }));
        setApiKeys((p) => ({ ...p, [id]: "" }));
        setList((prev) => prev.map((item) => item.id === id ? { ...item, hasKey: true } : item));
        setBanner({ kind: "ok", text: "Integration credentials saved." });
        setTimeout(() => setKeySaved((p) => ({ ...p, [id]: false })), 3000);
      })
      .catch((error) => setBanner({ kind: "err", text: error instanceof Error ? error.message : "Failed to save integration credentials." }));
  };

  const connected = list.filter((i) => i.connected).length;

  if (loading) return (
    <AppLayout>
      <Head title="Integrations" />
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-gold" />
      </div>
    </AppLayout>
  );

  return (
    <AppLayout>
      <Head title="Integrations" />
      <PageHeader eyebrow="Operations" title="Integrations"
        description={`${connected} of ${list.length} integrations active`}
        actions={!canManage ? (
          <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground border border-border rounded-full px-3 py-1">
            <Eye className="h-3.5 w-3.5" /> View Only
          </span>
        ) : undefined}
      />
      <div className="px-8 py-6 space-y-8">
        {banner && (
          <div className={`rounded-lg border px-4 py-3 text-sm ${banner.kind === "ok" ? "border-green-200 bg-green-50 text-green-700 dark:bg-green-950/30 dark:border-green-800 dark:text-green-400" : "border-red-200 bg-red-50 text-red-700 dark:bg-red-950/30 dark:border-red-800 dark:text-red-400"}`}>
            {banner.text}
          </div>
        )}

        {/* Integration cards */}
        <div className="grid grid-cols-3 gap-4">
          {list.map((intg) => (
            <Card key={intg.id} className={`border-border transition-all ${configOpen === intg.id ? "ring-2 ring-gold" : ""}`}>
              <CardContent className="p-5">
                <div className="flex items-start gap-4">
                  <IntegrationLogo slug={intg.id} initials={intg.initials} color={intg.color} />
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
                  {canManage ? (
                    intg.connected ? (
                      <>
                        <Button size="sm" variant="outline" className="h-7 text-xs flex-1"
                          onClick={() => setConfigOpen(configOpen === intg.id ? null : intg.id)}
                          disabled={busyId === intg.id}>
                          <Settings className="h-3 w-3 mr-1" /> Configure
                        </Button>
                        <Button size="sm" variant="outline" className="h-7 text-xs border-red-200 text-red-600 hover:bg-red-50"
                          onClick={() => toggle(intg.id)}
                          disabled={busyId === intg.id}>
                          <X className="h-3 w-3 mr-1" /> Disconnect
                        </Button>
                      </>
                    ) : (
                      <Button size="sm" className="h-7 text-xs flex-1 bg-gold hover:bg-gold/90 text-black"
                        onClick={() => toggle(intg.id)}
                        disabled={busyId === intg.id}>
                        {busyId === intg.id ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : <Plug className="h-3 w-3 mr-1" />} Connect
                      </Button>
                    )
                  ) : (
                    <span className="text-xs text-muted-foreground italic">
                      {intg.connected ? "Connected — managed by your admin" : "Not connected"}
                    </span>
                  )}
                </div>

                {canManage && configOpen === intg.id && (
                  <div className="mt-4 pt-4 border-t border-border space-y-3">
                    <div>
                      <label className="text-xs font-medium text-muted-foreground">API Key {intg.hasKey && <span className="text-green-600">(saved)</span>}</label>
                      <div className="mt-1 flex items-center gap-2">
                        <input type="password" placeholder="sk-••••••••••••••••"
                          value={apiKeys[intg.id] || ""}
                          onChange={(e) => setApiKeys((p) => ({ ...p, [intg.id]: e.target.value }))}
                          className="flex-1 h-8 rounded border border-border bg-background px-3 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                        <Button size="sm" variant="outline" className="h-8 text-xs" disabled={!apiKeys[intg.id]}
                          onClick={() => saveKey(intg.id)}>Save</Button>
                      </div>
                      {keySaved[intg.id] && <span className="text-xs text-green-600">Key saved securely.</span>}
                    </div>
                    <div>
                      <label className="text-xs font-medium text-muted-foreground">Webhook URL</label>
                      <div className="mt-1 flex items-center gap-2">
                        <input readOnly value={`${webhookBase}/${intg.id}`}
                          className="flex-1 h-8 rounded border border-border bg-muted/30 px-3 text-xs text-muted-foreground font-mono" />
                        <button
                          title="Copy"
                          onClick={() => navigator.clipboard.writeText(`${webhookBase}/${intg.id}`)}
                          className="text-muted-foreground hover:text-foreground">
                          <Copy className="h-3.5 w-3.5" />
                        </button>
                      </div>
                    </div>
                    <div className="flex items-center gap-2 flex-wrap">
                      <Button size="sm" className="h-7 text-xs" onClick={() => testConnection(intg.id)}>
                        <Zap className="h-3 w-3 mr-1" /> Test Connection
                      </Button>
                      <Button size="sm" variant="outline" className="h-7 text-xs"
                        onClick={() => logsOpen === intg.id ? setLogsOpen(null) : fetchLogs(intg.id)}>
                        <Activity className="h-3 w-3 mr-1" /> Logs
                        {logsOpen === intg.id ? <ChevronUp className="h-3 w-3 ml-1" /> : <ChevronDown className="h-3 w-3 ml-1" />}
                      </Button>
                      {testResult[intg.id] === "ok" && <span className="text-xs text-green-600 flex items-center gap-1"><CheckCircle className="h-3 w-3" /> Connected</span>}
                      {testResult[intg.id] === "fail" && <span className="text-xs text-red-600 flex items-center gap-1"><X className="h-3 w-3" /> Failed</span>}
                    </div>
                    {logsOpen === intg.id && (
                      <div className="rounded-md border border-border bg-muted/20 p-3 max-h-40 overflow-y-auto">
                        {logsLoading === intg.id ? (
                          <div className="flex items-center justify-center py-4"><Loader2 className="h-4 w-4 animate-spin text-gold" /></div>
                        ) : (logs[intg.id] ?? []).length === 0 ? (
                          <p className="text-xs text-muted-foreground text-center py-2">No webhook events recorded yet.</p>
                        ) : (
                          <div className="space-y-1.5">
                            {(logs[intg.id] ?? []).map((l: any, i: number) => (
                              <div key={i} className="flex items-center gap-2 text-[11px]">
                                <span className={`w-1.5 h-1.5 rounded-full flex-shrink-0 ${l.status === "ok" ? "bg-green-400" : "bg-red-400"}`} />
                                <span className="text-muted-foreground font-mono">{l.event_type}</span>
                                <span className="flex-1 truncate text-muted-foreground">{l.summary}</span>
                                <span className="font-mono text-muted-foreground/60">{l.created_at ? new Date(l.created_at).toLocaleTimeString("en-IN") : ""}</span>
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                )}
              </CardContent>
            </Card>
          ))}
        </div>

        {/* ── Google Tasks personal sync ─────────────────────────────────────── */}
        <Card className="border-blue-500/20 bg-blue-500/5">
          <CardContent className="p-5">
            <div className="flex items-center gap-4">
              {/* Google Tasks logo */}
              <div className="h-12 w-12 rounded-xl bg-white border border-border flex items-center justify-center flex-shrink-0 shadow-sm">
                <svg viewBox="0 0 24 24" className="h-7 w-7">
                  <path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2z" fill="#4285F4"/>
                  <path d="M7.5 12l3 3 6-6" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" fill="none"/>
                  <path d="M12 2a10 10 0 0 1 10 10" stroke="#EA4335" strokeWidth="2.5" fill="none" strokeLinecap="round"/>
                  <path d="M22 12a10 10 0 0 1-10 10" stroke="#34A853" strokeWidth="2.5" fill="none" strokeLinecap="round"/>
                  <path d="M12 22a10 10 0 0 1-10-10" stroke="#FBBC04" strokeWidth="2.5" fill="none" strokeLinecap="round"/>
                  <path d="M2 12A10 10 0 0 1 12 2" stroke="#4285F4" strokeWidth="2.5" fill="none" strokeLinecap="round"/>
                </svg>
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-0.5">
                  <span className="font-semibold text-sm">Google Tasks</span>
                  {gcal?.connected ? (
                    <Badge className="bg-green-100 text-green-700 border-green-200 text-[10px]">Connected</Badge>
                  ) : (
                    <Badge variant="outline" className="text-[10px]">Not Connected</Badge>
                  )}
                </div>
                <p className="text-xs text-muted-foreground">
                  {gcal?.connected
                    ? `Syncing deadlines to ${gcal.email} — project hard deadlines appear as tasks in your Google Tasks.`
                    : "Sync your project hard deadlines directly to your personal Google Tasks list."}
                </p>
              </div>
              <div className="flex-shrink-0">
                {gcal?.connected ? (
                  <Button size="sm" variant="outline" className="h-8 text-xs border-red-200 text-red-600 hover:bg-red-50"
                    onClick={disconnectGcal} disabled={gcalBusy}>
                    {gcalBusy ? <Loader2 className="h-3.5 w-3.5 mr-1 animate-spin" /> : <Link2Off className="h-3.5 w-3.5 mr-1" />}
                    Disconnect
                  </Button>
                ) : (
                  <Button size="sm" className="h-8 text-xs bg-blue-600 hover:bg-blue-700 text-white"
                    onClick={() => { window.location.href = '/integrations/google/connect'; }}
                    disabled={gcalBusy}>
                    Connect Google Account
                  </Button>
                )}
              </div>
            </div>
            <p className="text-[11px] text-muted-foreground mt-3 border-t border-border pt-2">
              Each user connects their own Google account. Deadlines appear in "My Tasks" — one task per project, updated automatically when the deadline changes.
            </p>
          </CardContent>
        </Card>

        <Card className="border-border">
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="font-display text-base flex items-center gap-2">
              <Activity className="h-4 w-4 text-gold" /> Integration Telemetry
            </CardTitle>
            <p className="text-xs text-muted-foreground">Open "Configure" on any integration to view its webhook event log.</p>
          </CardHeader>
          <CardContent className="p-0">
            {list.filter((i) => i.connected).length === 0 ? (
              <div className="px-6 py-8 text-sm text-muted-foreground text-center">
                No active integrations. Connect one above to start receiving webhook events.
              </div>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Integration</th>
                    <th className="px-4 py-3 text-left">Status</th>
                    <th className="px-4 py-3 text-left">Last Sync</th>
                    <th className="px-4 py-3 text-right">Logs</th>
                  </tr>
                </thead>
                <tbody>
                  {list.filter((i) => i.connected).map((intg) => (
                    <tr key={intg.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2.5">
                          {(() => {
                            const Logo = BRAND_LOGOS[intg.id];
                            return Logo ? (
                              <div className="h-7 w-7 rounded-md bg-white border border-border flex items-center justify-center flex-shrink-0 shadow-sm">
                                <div className="h-4 w-4 [&_svg]:h-4 [&_svg]:w-4"><Logo /></div>
                              </div>
                            ) : (
                              <div className={`h-7 w-7 rounded-md ${intg.color} flex items-center justify-center text-white font-bold text-[10px] flex-shrink-0`}>
                                {intg.initials}
                              </div>
                            );
                          })()}
                          <span className="font-medium">{intg.name}</span>
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <span className="inline-flex items-center gap-1 text-xs text-green-600">
                          <CheckCircle className="h-3 w-3" /> Connected
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">{intg.lastSync ?? "—"}</td>
                      <td className="px-4 py-3 text-right">
                        {canManage ? (
                          <Button size="sm" variant="outline" className="h-7 text-xs"
                            onClick={() => { setConfigOpen(intg.id); fetchLogs(intg.id); }}>
                            <Activity className="h-3 w-3 mr-1" /> View Logs
                          </Button>
                        ) : (
                          <span className="text-xs text-muted-foreground">—</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
