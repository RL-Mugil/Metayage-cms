import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Plug, Zap, RefreshCw, X, Settings, Globe, CheckCircle, Loader2 } from "lucide-react";
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

export default function Integrations() {
  const [list, setList] = useState<Integration[]>([]);
  const [loading, setLoading] = useState(true);
  const [configOpen, setConfigOpen] = useState<string | null>(null);
  const [testResult, setTestResult] = useState<Record<string, "ok" | "fail" | null>>({});
  const [apiKeys, setApiKeys] = useState<Record<string, string>>({});
  const [keySaved, setKeySaved] = useState<Record<string, boolean>>({});
  const [busyId, setBusyId] = useState<string | null>(null);
  const [banner, setBanner] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

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
        description={`${connected} of ${list.length} integrations active`} />
      <div className="px-8 py-6 space-y-8">
        {banner && (
          <div className={`rounded-lg border px-4 py-3 text-sm ${banner.kind === "ok" ? "border-green-200 bg-green-50 text-green-700" : "border-red-200 bg-red-50 text-red-700"}`}>
            {banner.text}
          </div>
        )}

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
                  )}
                </div>

                {/* Config panel */}
                {configOpen === intg.id && (
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

        <Card className="border-border">
          <CardHeader><CardTitle className="font-display text-base">Integration Telemetry</CardTitle></CardHeader>
          <CardContent className="text-sm text-muted-foreground">
            Live webhook and sync logs are not wired to a database table yet, so this screen only shows actual integration configuration state from the `integrations` table.
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
