import { Head } from "@inertiajs/react";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Check, Copy, KeyRound, Loader2, Plug, ShieldCheck, Trash2 } from "lucide-react";
import { toast } from "sonner";
import AppLayout from "@/layouts/AppLayout";
import { FitPage } from "@/components/modern/fit-page";
import { GlassCard } from "@/components/modern/glass-card";
import { Button } from "@/components/ui/button";
import { ConnectedAppsCard } from "@/components/settings/connected-apps-card";
import { api } from "@/lib/api-client";

interface ApiKey { id: number; name: string; created_at: string | null; last_used_at: string | null; expires_at: string | null }

function CopyBox({ text }: { text: string }) {
  const [done, setDone] = useState(false);
  return (
    <div className="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2 font-mono text-xs">
      <span className="min-w-0 flex-1 truncate">{text}</span>
      <button onClick={() => { void navigator.clipboard.writeText(text); setDone(true); window.setTimeout(() => setDone(false), 1500); }} className="text-muted-foreground hover:text-foreground">
        {done ? <Check className="h-3.5 w-3.5 text-emerald-600" /> : <Copy className="h-3.5 w-3.5" />}
      </button>
    </div>
  );
}

/**
 * Claude & API for a client's login ("Claude, API & integrations"): connect Claude to the portal
 * and make personal API keys. Both are read-only and only see this client's own data; every
 * call is in the client's audit log.
 */
export default function MyIntegrations() {
  const mcpUrl = `${window.location.origin}/mcp`;
  const apiBase = `${window.location.origin}/api/client-api/v1`;
  const queryClient = useQueryClient();
  const { data: keys = [], isLoading } = useQuery<ApiKey[]>({ queryKey: ["my-api-keys"], queryFn: () => api.request<ApiKey[]>("/my-portal/api-keys") });
  const [name, setName] = useState("");
  const [newKey, setNewKey] = useState<string | null>(null);
  const create = useMutation({
    mutationFn: () => api.request<{ key: string }>("/my-portal/api-keys", { method: "POST", body: JSON.stringify({ name: name.trim() }) }),
    onSuccess: (r) => { setNewKey(r.key); setName(""); void queryClient.invalidateQueries({ queryKey: ["my-api-keys"] }); },
    onError: (e: Error) => toast.error(e.message),
  });
  const revoke = useMutation({
    mutationFn: (id: number) => api.request(`/my-portal/api-keys/${id}`, { method: "DELETE" }),
    onSuccess: () => { toast.success("Key revoked"); void queryClient.invalidateQueries({ queryKey: ["my-api-keys"] }); },
    onError: (e: Error) => toast.error(e.message),
  });

  return (
    <AppLayout>
      <Head title="Claude & API" />
      <FitPage fit={false} eyebrow="Integrations" title="Claude & API" summary="Use your portal data in Claude or your own systems: read-only, your own account only">
        <div className="grid gap-4 xl:grid-cols-2">
          <GlassCard className="card-3d" title="Connect Claude" icon={<Plug className="h-4 w-4 text-gold" />} subtitle="Ask Claude about your cases, deadlines, approvals and billing">
            <div className="space-y-3 p-4 pt-1 text-sm">
              <CopyBox text={mcpUrl} />
              <ol className="list-decimal space-y-1.5 pl-5 text-xs">
                <li>In claude.ai or Claude Desktop, open <strong>Settings → Connectors</strong> and choose <strong>Add custom connector</strong>.</li>
                <li>Name it <strong>MyIPStrategy</strong> and paste the address above.</li>
                <li>Click <strong>Connect</strong>, sign in to this portal if asked, check it says <em>read-only</em> and press <strong>Allow</strong>.</li>
                <li>Ask, for example: "What do I owe?" or "Which deadlines are in the next 60 days?".</li>
              </ol>
              <ConnectedAppsCard />
            </div>
          </GlassCard>

          <GlassCard className="card-3d" title="API keys" icon={<KeyRound className="h-4 w-4 text-gold" />} subtitle="For your own systems: read-only, valid one year, max 10">
            <div className="space-y-3 p-4 pt-1 text-xs">
              {newKey && (
                <div className="space-y-1 rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-3">
                  <p className="font-medium text-emerald-700 dark:text-emerald-400">Your new key. Copy it now: it is shown only once.</p>
                  <CopyBox text={newKey} />
                  <button className="text-[11px] text-muted-foreground underline" onClick={() => setNewKey(null)}>I have copied it</button>
                </div>
              )}
              <div className="flex gap-2">
                <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Key name, e.g. Our ERP" maxLength={60}
                  className="h-8 flex-1 rounded border border-border bg-background px-2 focus:outline-none focus:ring-1 focus:ring-gold" />
                <Button size="sm" className="h-8" disabled={!name.trim() || create.isPending} onClick={() => create.mutate()}>
                  {create.isPending ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : "Create key"}
                </Button>
              </div>
              <div className="divide-y divide-border rounded-lg border border-border">
                {isLoading && <div className="flex justify-center py-4"><Loader2 className="h-4 w-4 animate-spin text-gold" /></div>}
                {!isLoading && keys.length === 0 && <p className="py-4 text-center text-muted-foreground">No API keys yet.</p>}
                {keys.map((k) => (
                  <div key={k.id} className="flex items-center gap-2 px-3 py-2">
                    <div className="min-w-0 flex-1">
                      <p className="font-medium">{k.name.replace(/^API: /, "")}</p>
                      <p className="text-[10px] text-muted-foreground">Created {k.created_at ? new Date(k.created_at).toLocaleDateString("en-IN") : "—"} · last used {k.last_used_at ? new Date(k.last_used_at).toLocaleDateString("en-IN") : "never"} · expires {k.expires_at ? new Date(k.expires_at).toLocaleDateString("en-IN") : "—"}</p>
                    </div>
                    <Button size="icon" variant="ghost" className="h-7 w-7" title="Revoke" disabled={revoke.isPending}
                      onClick={() => { if (window.confirm(`Revoke the key "${k.name}"? Systems using it stop working.`)) revoke.mutate(k.id); }}><Trash2 className="h-3.5 w-3.5" /></Button>
                  </div>
                ))}
              </div>
              <div className="space-y-1">
                <p className="font-medium">How to call it</p>
                <CopyBox text={`curl -H "Authorization: Bearer YOUR_KEY" ${apiBase}/cases`} />
                <p className="text-muted-foreground">Endpoints: <span className="font-mono">/cases</span> (search, open_only), <span className="font-mono">/deadlines</span> (days), <span className="font-mono">/billing</span>, <span className="font-mono">/approvals</span> (pending_only). JSON, 60 calls a minute.</p>
              </div>
            </div>
          </GlassCard>
        </div>
        <p className="mt-4 flex items-start gap-2 text-xs text-muted-foreground">
          <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-gold" />
          Claude and API keys only read your own account, within your access. Nothing can be changed through them, and every call is in your Audit Log.
        </p>
      </FitPage>
    </AppLayout>
  );
}
