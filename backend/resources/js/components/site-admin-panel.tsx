import { useEffect, useState } from "react";
import { Loader2, Wrench, FileText, ExternalLink, AlertTriangle, Check } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { RichTextEditor } from "@/components/rich-text-editor";
import { api } from "@/lib/api-client";
import type { LegalSlug, MaintenanceState, SiteAdminState } from "@/lib/api-client";

const PAGE_LABELS: Record<LegalSlug, { label: string; url: string }> = {
  privacy: { label: "Privacy Policy", url: "https://myipstrategy.com/privacy" },
  terms: { label: "Terms & Conditions", url: "https://myipstrategy.com/terms" },
};

function MaintenanceCard({ initial }: { initial: MaintenanceState }) {
  const [form, setForm] = useState<MaintenanceState>(initial);
  const [liveEnabled, setLiveEnabled] = useState(initial.enabled);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  const set = <K extends keyof MaintenanceState>(k: K, v: MaintenanceState[K]) => setForm((f) => ({ ...f, [k]: v }));

  const save = async (enabled: boolean) => {
    setSaving(true);
    setMsg(null);
    try {
      const res = await api.updateMaintenance({ ...form, enabled });
      setForm(res.maintenance);
      setLiveEnabled(res.maintenance.enabled);
      setMsg({ kind: "ok", text: res.maintenance.enabled ? "Maintenance mode is ON. Everyone except system admins now sees the maintenance page." : "Maintenance mode is OFF. The portal is open to everyone." });
    } catch (e) {
      setMsg({ kind: "err", text: e instanceof Error ? e.message : "Could not save." });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="border-border">
      <CardHeader>
        <CardTitle className="font-display flex items-center gap-2"><Wrench className="h-4 w-4 text-gold" />Maintenance Mode</CardTitle>
        <p className="text-xs text-muted-foreground mt-1">
          While on, every signed-in user except system admins sees the maintenance page. Sign-in still works, and you keep full access.
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className={`flex items-center justify-between gap-4 rounded-lg border p-3 ${liveEnabled ? "border-gold/60 bg-gold/10" : "border-border"}`}>
          <div className="flex items-center gap-2">
            {liveEnabled && <AlertTriangle className="h-4 w-4 text-gold" aria-hidden="true" />}
            <div>
              <p className="text-sm font-medium">{liveEnabled ? "Maintenance mode is ON" : "Maintenance mode is OFF"}</p>
              <p className="text-xs text-muted-foreground">{liveEnabled ? "Other users are locked out of the portal." : "Everyone can use the portal."}</p>
            </div>
          </div>
          <Button
            type="button"
            onClick={() => save(!liveEnabled)}
            disabled={saving}
            className={liveEnabled ? "bg-muted text-foreground hover:bg-muted/80" : "bg-gold hover:bg-gold/90 text-black"}
          >
            {saving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
            {liveEnabled ? "Turn off" : "Turn on"}
          </Button>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <label htmlFor="mt-title" className="text-xs font-medium">Headline</label>
            <Input id="mt-title" value={form.title} maxLength={80} onChange={(e) => set("title", e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <label htmlFor="mt-tagline" className="text-xs font-medium">Tagline</label>
            <Input id="mt-tagline" value={form.tagline} maxLength={120} onChange={(e) => set("tagline", e.target.value)} />
          </div>
        </div>
        <div className="space-y-1.5">
          <label htmlFor="mt-message" className="text-xs font-medium">Message</label>
          <Textarea id="mt-message" rows={3} value={form.message} maxLength={600} onChange={(e) => set("message", e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <label htmlFor="mt-contact" className="text-xs font-medium">Contact email shown for assistance</label>
          <Input id="mt-contact" type="email" value={form.contact} maxLength={150} onChange={(e) => set("contact", e.target.value)} />
        </div>

        {msg && (
          <p role="status" className={`text-xs ${msg.kind === "ok" ? "text-emerald-500" : "text-destructive"}`}>{msg.text}</p>
        )}

        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" onClick={() => save(liveEnabled)} disabled={saving}>
            {saving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}Save wording
          </Button>
          <Button type="button" variant="ghost" asChild>
            <a href="/maintenance-preview" target="_blank" rel="noreferrer"><ExternalLink className="h-4 w-4 mr-2" />Preview page</a>
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function LegalPageCard({ slug, initial }: { slug: LegalSlug; initial: SiteAdminState["pages"][LegalSlug] }) {
  const [html, setHtml] = useState(initial.html);
  const [resetKey, setResetKey] = useState(0);
  const [customized, setCustomized] = useState(initial.customized);
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);
  const [confirmReset, setConfirmReset] = useState(false);
  const [msg, setMsg] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  const save = async () => {
    setSaving(true);
    setMsg(null);
    try {
      const res = await api.updateLegalPage(slug, html);
      setHtml(res.html);
      setResetKey((k) => k + 1);
      setCustomized(true);
      setDirty(false);
      setMsg({ kind: "ok", text: "Published. The public page shows this version now." });
    } catch (e) {
      setMsg({ kind: "err", text: e instanceof Error ? e.message : "Could not save." });
    } finally {
      setSaving(false);
    }
  };

  const reset = async () => {
    setSaving(true);
    setMsg(null);
    try {
      const res = await api.resetLegalPage(slug);
      setHtml(res.html);
      setResetKey((k) => k + 1);
      setCustomized(false);
      setDirty(false);
      setConfirmReset(false);
      setMsg({ kind: "ok", text: "Restored the built-in text." });
    } catch (e) {
      setMsg({ kind: "err", text: e instanceof Error ? e.message : "Could not reset." });
    } finally {
      setSaving(false);
    }
  };

  const { label, url } = PAGE_LABELS[slug];

  return (
    <Card className="border-border">
      <CardHeader>
        <CardTitle className="font-display flex items-center gap-2"><FileText className="h-4 w-4 text-gold" />{label}</CardTitle>
        <p className="text-xs text-muted-foreground mt-1">
          {customized ? "Showing your edited version." : "Showing the built-in text."} Edits go live on{" "}
          <a href={url} target="_blank" rel="noreferrer" className="underline">{url.replace("https://", "")}</a> as soon as you publish.
        </p>
      </CardHeader>
      <CardContent className="space-y-3">
        <RichTextEditor value={html} resetKey={resetKey} onChange={(h) => { setHtml(h); setDirty(true); }} />
        {msg && <p role="status" className={`text-xs ${msg.kind === "ok" ? "text-emerald-500" : "text-destructive"}`}>{msg.text}</p>}
        <div className="flex flex-wrap items-center gap-2">
          <Button type="button" onClick={save} disabled={saving || !dirty} className="bg-gold hover:bg-gold/90 text-black">
            {saving ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Check className="h-4 w-4 mr-2" />}Publish changes
          </Button>
          {customized && !confirmReset && (
            <Button type="button" variant="ghost" onClick={() => setConfirmReset(true)} disabled={saving}>Restore built-in text</Button>
          )}
          {confirmReset && (
            <>
              <span className="text-xs text-muted-foreground">Discard your version?</span>
              <Button type="button" variant="destructive" size="sm" onClick={reset} disabled={saving}>Yes, restore</Button>
              <Button type="button" variant="ghost" size="sm" onClick={() => setConfirmReset(false)}>Cancel</Button>
            </>
          )}
          {dirty && <span className="text-xs text-muted-foreground">Unpublished changes</span>}
        </div>
      </CardContent>
    </Card>
  );
}

/** Settings → Site tab (system admin only): maintenance mode and the editable public pages. */
export function SiteAdminPanel() {
  const [state, setState] = useState<SiteAdminState | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api.getSiteAdmin().then(setState).catch((e) => setError(e instanceof Error ? e.message : "Could not load."));
  }, []);

  if (error) return <p className="text-sm text-destructive">{error}</p>;
  if (!state) return <div className="flex items-center gap-2 text-sm text-muted-foreground"><Loader2 className="h-4 w-4 animate-spin" />Loading…</div>;

  return (
    <div className="space-y-6">
      <MaintenanceCard initial={state.maintenance} />
      <LegalPageCard slug="privacy" initial={state.pages.privacy} />
      <LegalPageCard slug="terms" initial={state.pages.terms} />
    </div>
  );
}
