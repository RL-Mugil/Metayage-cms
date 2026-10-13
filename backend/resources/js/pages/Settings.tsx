import { Head, usePage, router } from "@inertiajs/react";
import { useState, useEffect, useRef, useCallback } from "react";
import { createPortal } from "react-dom";
import { User, Bell, Shield, Palette, Settings as SettingsIcon, Key, Building, Loader2, Camera, Trash2, ZoomIn, ZoomOut, RotateCcw, Plus, X, Landmark, Check, Hash, Pencil, ArrowRightLeft, LayoutGrid, Wallet, ChevronUp, ChevronDown, Globe } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { SiteAdminPanel } from "@/components/site-admin-panel";
import type { ProjectTab, CodeRow, CodeRenameImpact, CodeMergeAvailable, CodeMergeImpact } from "@/types";

function loadPrefs<T>(key: string, fallback: T): T {
  if (typeof window === "undefined") return fallback;
  try {
    const raw = localStorage.getItem(key);
    return raw ? { ...fallback, ...JSON.parse(raw) } : fallback;
  } catch {
    return fallback;
  }
}

const TIMEZONES = [
  "Asia/Kolkata", "Asia/Dubai", "Asia/Singapore", "Asia/Tokyo",
  "Europe/London", "Europe/Paris", "America/New_York", "America/Chicago",
  "America/Los_Angeles", "Australia/Sydney", "UTC",
];

const LANGUAGES = ["English", "Hindi", "Tamil", "Telugu"];

type Tab = "profile" | "notifications" | "security" | "appearance" | "system" | "codes" | "projectTabs" | "finance" | "payroll" | "site";

const tabs: { id: Tab; label: string; icon: React.ElementType }[] = [
  { id: "profile",       label: "Profile",       icon: User },
  { id: "notifications", label: "Notifications", icon: Bell },
  { id: "security",      label: "Security",      icon: Shield },
  { id: "appearance",    label: "Appearance",    icon: Palette },
  { id: "system",        label: "System",        icon: Building },
  { id: "codes",         label: "Codes",         icon: Hash },
  { id: "projectTabs",   label: "Project Tabs",  icon: LayoutGrid },
  { id: "finance",       label: "Finance",       icon: Landmark },
  { id: "payroll",       label: "Payroll",       icon: Wallet },
  { id: "site",          label: "Site",          icon: Globe },
];

const BLANK_SALARY_COMPONENT = { name: "", code: "", calc_type: "flat", category: "earning", value: "", percentage_of: "" };
const BLANK_REIMBURSEMENT_TYPE = { name: "", code: "", monthly_cap: "" };

/**
 * Mirrors PayrollService::computeComponents()'s math (employee_field / flat /
 * percentage) purely client-side, for the admin preview below — no round-trip.
 * Gross is now a derived total (the sum of earning rows), not an input, so an
 * earning component can't be "a percentage of Gross" (only a ctc_addon row
 * can, once the earning pass is done) — matches the backend's own rule in
 * SalaryComponentValidator. "Remainder" always previews as ₹0 — there's
 * nothing left for it to be the remainder of in the real structure.
 * Must stay in sync with the PHP engine if the compute rules ever change.
 */
function computeComponentPreview(components: any[], basePay: number, hra: number): { name: string; amount: number }[] {
  const active = [...components].filter((c) => c.active).sort((a, b) => a.sequence - b.sequence);
  const amounts: Record<string, number> = {};
  const rows: { name: string; amount: number }[] = [];
  let grossSum = 0;

  for (const c of active.filter((c) => (c.category || "earning") === "earning")) {
    if (c.calc_type === "remainder") { rows.push({ name: c.name, amount: 0 }); continue; }
    const base = c.calc_type === "flat" ? Number(c.value || 0)
      : c.calc_type === "employee_field" ? (c.percentage_of === "hra_monthly" ? hra : basePay)
      : (amounts[c.percentage_of] ?? 0) * (Number(c.value || 0) / 100);
    const amount = Math.round(base * 100) / 100;
    amounts[c.code] = amount;
    grossSum += amount;
    rows.push({ name: c.name, amount });
  }

  for (const c of active.filter((c) => c.category === "ctc_addon")) {
    const of = c.percentage_of === "_GROSS_" ? grossSum : (amounts[c.percentage_of] ?? 0);
    const amount = Math.round((c.calc_type === "flat" ? Number(c.value || 0) : of * (Number(c.value || 0) / 100)) * 100) / 100;
    amounts[c.code] = amount;
    rows.push({ name: c.name, amount });
  }

  return rows;
}

const FEE_JURISDICTIONS = ["IN", "US", "EP", "WO"] as const;
const BLANK_FEE_ROW = {
  jurisdiction: "IN", service_code: "", entity_tier: "", year_from: "", year_to: "",
  validation_country: "", govt_fee_amount: "", govt_fee_currency: "INR",
  professional_fee_amount: "", professional_fee_currency: "INR", professional_fee_max_amount: "",
  professional_fee_charge_basis: "per_unit", notes: "", is_active: true,
};

function Toggle({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <button onClick={() => onChange(!checked)}
      className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${checked ? "bg-gold" : "bg-muted"}`}>
      <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform`}
        style={{ transform: checked ? "translateX(18px)" : "translateX(2px)" }} />
    </button>
  );
}

function AvatarDisplay({ src, name, size = "lg" }: { src?: string | null; name: string; size?: "sm" | "lg" }) {
  const dim = size === "lg" ? "h-16 w-16 text-xl" : "h-8 w-8 text-xs";
  if (src) return <img src={src} alt={name} className={`${dim} rounded-full object-cover ring-2 ring-gold/30`} />;
  return (
    <div className={`${dim} rounded-full bg-gold/20 flex items-center justify-center text-gold font-bold`}>
      {name.charAt(0).toUpperCase()}
    </div>
  );
}

/** One tabular Code editor (used for both Service Codes and Country Codes). Label edits and
 *  add/delete are lightweight (whole-array replace via the parent's onSave). Changing the CODE
 *  VALUE itself only happens through the "Rename" action, which routes to a separate
 *  impact-preview + confirm flow (CodeRenameDialog) since that cascades into live data. */
function CodeTable({
  type, items, setItems, usage, newCode, setNewCode, newLabel, setNewLabel,
  search, setSearch, addErr, setAddErr, saving, onSave, onRenameClick, canManage,
}: {
  type: "service" | "country";
  items: { code: string; label: string }[];
  setItems: (fn: (prev: { code: string; label: string }[]) => { code: string; label: string }[]) => void;
  usage: CodeRow[];
  newCode: string; setNewCode: (v: string) => void;
  newLabel: string; setNewLabel: (v: string) => void;
  search: string; setSearch: (v: string) => void;
  addErr: string; setAddErr: (v: string) => void;
  saving: boolean;
  onSave: () => void;
  onRenameClick: (code: string, label: string) => void;
  canManage: boolean;
}) {
  const [editingCode, setEditingCode] = useState<string | null>(null);
  const [editLabel, setEditLabel] = useState("");

  const usageFor = (code: string) => usage.find((u) => u.code.toUpperCase() === code.toUpperCase())?.projects_count ?? 0;
  const stripPrefix = (label: string) => label.replace(/^[A-Z0-9\-]+\s[–-]\s/, "");

  const q = search.trim().toLowerCase();
  const filtered = q ? items.filter((x) => x.code.toLowerCase().includes(q) || x.label.toLowerCase().includes(q)) : items;

  return (
    <Card className="border-border">
      <CardHeader>
        <CardTitle className="font-display">{type === "service" ? "Service Codes" : "Country / Patent Office Codes"}</CardTitle>
        <p className="text-xs text-muted-foreground mt-1">
          {type === "service"
            ? "All codes listed here appear in the Service Code dropdown across the portal."
            : "Codes listed here appear in the Patent Office dropdown when creating a case."} {items.length} total.
        </p>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="relative">
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search codes or descriptions…"
            className="w-full h-8 rounded border border-border bg-background pl-3 pr-8 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
          />
          {search && (
            <button onClick={() => setSearch("")} className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
              <X className="h-3 w-3" />
            </button>
          )}
        </div>

        <div className="overflow-x-auto rounded-md border border-border">
          <table className="w-full text-xs">
            <thead className="bg-muted/40 text-[10px] uppercase tracking-wider text-muted-foreground">
              <tr>
                <th className="px-3 py-2 text-left">Code</th>
                <th className="px-3 py-2 text-left">Description</th>
                <th className="px-3 py-2 text-right"># Projects</th>
                {canManage && <th className="px-3 py-2 text-right">Actions</th>}
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 && (
                <tr><td colSpan={canManage ? 4 : 3} className="px-3 py-4 text-center text-muted-foreground italic">
                  {q ? `No codes match "${search}".` : "No codes yet."}
                </td></tr>
              )}
              {filtered.map((item) => {
                const count = usageFor(item.code);
                const isEditing = editingCode === item.code;
                return (
                  <tr key={item.code} className="border-t border-border">
                    <td className="px-3 py-2 font-mono font-semibold text-gold align-top whitespace-nowrap">{item.code}</td>
                    <td className="px-3 py-2 align-top">
                      {isEditing ? (
                        <div className="flex items-center gap-1">
                          <input
                            autoFocus
                            value={editLabel}
                            onChange={(e) => setEditLabel(e.target.value)}
                            className="flex-1 h-7 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
                          />
                          <button className="text-green-600 hover:text-green-700" title="Save"
                            onClick={() => {
                              setItems((prev) => prev.map((x) => x.code === item.code ? { ...x, label: `${item.code} – ${editLabel.trim()}` } : x));
                              setEditingCode(null);
                            }}>
                            <Check className="h-3.5 w-3.5" />
                          </button>
                          <button className="text-muted-foreground hover:text-destructive" title="Cancel" onClick={() => setEditingCode(null)}>
                            <X className="h-3.5 w-3.5" />
                          </button>
                        </div>
                      ) : (
                        <span>{stripPrefix(item.label)}</span>
                      )}
                    </td>
                    <td className="px-3 py-2 text-right align-top">{count}</td>
                    {canManage && (
                      <td className="px-3 py-2 align-top">
                        <div className="flex items-center justify-end gap-2">
                          {!isEditing && (
                            <button title="Edit description" className="text-muted-foreground hover:text-foreground"
                              onClick={() => { setEditingCode(item.code); setEditLabel(stripPrefix(item.label)); }}>
                              <Pencil className="h-3.5 w-3.5" />
                            </button>
                          )}
                          <button title="Rename code (updates every project that uses it)" className="text-muted-foreground hover:text-foreground"
                            onClick={() => onRenameClick(item.code, item.label)}>
                            <ArrowRightLeft className="h-3.5 w-3.5" />
                          </button>
                          <button
                            title={count > 0 ? `Still used by ${count} project(s) — cannot delete` : "Delete"}
                            disabled={count > 0}
                            className={count > 0 ? "text-muted-foreground/40 cursor-not-allowed" : "text-muted-foreground hover:text-destructive"}
                            onClick={() => { if (count === 0) setItems((prev) => prev.filter((x) => x.code !== item.code)); }}>
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        </div>
                      </td>
                    )}
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {canManage && (
          <>
            {/* Add new */}
            <div className="border-t border-border pt-3 space-y-2">
              <p className="text-xs font-medium text-muted-foreground">Add new code</p>
              <div className="flex gap-2">
                <input value={newCode}
                  onChange={(e) => { setNewCode(e.target.value.toUpperCase()); setAddErr(""); }}
                  placeholder={type === "service" ? "CODE" : "XX"} maxLength={type === "service" ? 10 : 4}
                  className="w-24 h-8 rounded border border-border bg-background px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-gold" />
                <input value={newLabel} onChange={(e) => setNewLabel(e.target.value)}
                  placeholder="Description"
                  className="flex-1 h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                <Button size="sm" variant="outline" className="h-8 px-3 shrink-0"
                  onClick={() => {
                    const code = newCode.trim().toUpperCase();
                    const lbl = newLabel.trim();
                    if (!code) { setAddErr("Enter a code."); return; }
                    if (!lbl) { setAddErr("Enter a description."); return; }
                    const dup = items.find((x) => x.code.toUpperCase() === code);
                    if (dup) { setAddErr(`"${code}" already exists: "${dup.label}"`); return; }
                    setItems((prev) => [...prev, { code, label: `${code} – ${lbl}` }]);
                    setNewCode(""); setNewLabel(""); setAddErr("");
                  }}>
                  <Plus className="h-3 w-3" />
                </Button>
              </div>
              {addErr && <p className="text-xs text-destructive">{addErr}</p>}
            </div>

            <Button size="sm" onClick={onSave} disabled={saving} className="bg-gold hover:bg-gold/90 text-black">
              {saving ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : null}Save {type === "service" ? "Service" : "Country"} Codes
            </Button>
          </>
        )}
      </CardContent>
    </Card>
  );
}

/** Impact-preview + explicit-confirm flow for a cascading code rename — see CodeRenameService.
 *  If the new code already exists as a separate catalog entry, previewCodeRename() signals
 *  merge_available instead of erroring, and this dialog branches into the merge preview/confirm
 *  flow (previewCodeMerge/executeCodeMerge) rather than dead-ending. */
function CodeRenameDialog({ type, oldCode, oldLabel, onClose, onRenamed, onMerged }: {
  type: "service" | "country";
  oldCode: string;
  oldLabel: string;
  onClose: () => void;
  onRenamed: (type: "service" | "country", oldCode: string, newCode: string) => void;
  onMerged: (type: "service" | "country", oldCode: string, newCode: string) => void;
}) {
  const [newCode, setNewCode] = useState("");
  const [step, setStep] = useState<"input" | "confirm" | "merge-ask" | "merge-confirm">("input");
  const [impact, setImpact] = useState<CodeRenameImpact | null>(null);
  const [mergeInfo, setMergeInfo] = useState<CodeMergeAvailable | null>(null);
  const [mergeImpact, setMergeImpact] = useState<CodeMergeImpact | null>(null);
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState("");

  async function handlePreview() {
    const code = newCode.trim().toUpperCase();
    if (!code) { setErr("Enter a new code."); return; }
    setLoading(true); setErr("");
    try {
      const result = await api.previewCodeRename(type, oldCode, code);
      if ("merge_available" in result) {
        setMergeInfo(result);
        setStep("merge-ask");
      } else {
        setImpact(result);
        setStep("confirm");
      }
    } catch (e: any) { setErr(e.message || "Could not preview this rename."); }
    finally { setLoading(false); }
  }

  async function handleConfirm() {
    setLoading(true); setErr("");
    try {
      const result = await api.executeCodeRename(type, oldCode, newCode.trim().toUpperCase());
      onRenamed(type, oldCode, result.new_code);
    } catch (e: any) { setErr(e.message || "Rename failed."); }
    finally { setLoading(false); }
  }

  async function handlePreviewMerge() {
    if (!mergeInfo) return;
    setLoading(true); setErr("");
    try {
      const result = await api.previewCodeMerge(type, oldCode, mergeInfo.new_code);
      setMergeImpact(result);
      setStep("merge-confirm");
    } catch (e: any) { setErr(e.message || "Could not preview this merge."); }
    finally { setLoading(false); }
  }

  async function handleConfirmMerge() {
    if (!mergeInfo) return;
    setLoading(true); setErr("");
    try {
      const result = await api.executeCodeMerge(type, oldCode, mergeInfo.new_code);
      onMerged(type, oldCode, result.new_code);
    } catch (e: any) { setErr(e.message || "Merge failed."); }
    finally { setLoading(false); }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6">
        <h3 className="font-semibold font-display mb-1">Rename Code</h3>
        <p className="text-xs text-muted-foreground mb-4">
          <span className="font-mono text-gold">{oldCode}</span> — {oldLabel.replace(/^[A-Z0-9\-]+\s[–-]\s/, "")}
        </p>

        {step === "input" && (
          <>
            <label className="block text-xs font-medium text-muted-foreground mb-1">New code (must be {oldCode.length} characters)</label>
            <input
              value={newCode}
              onChange={(e) => { setNewCode(e.target.value.toUpperCase()); setErr(""); }}
              placeholder={oldCode}
              maxLength={20}
              autoFocus
              className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-gold"
            />
            {err && <p className="text-xs text-destructive mt-2">{err}</p>}
            <div className="flex gap-2 justify-end mt-5">
              <Button variant="outline" onClick={onClose} disabled={loading}>Cancel</Button>
              <Button onClick={handlePreview} disabled={loading} className="bg-gold hover:bg-gold/90 text-black">
                {loading ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : null}Preview Impact
              </Button>
            </div>
          </>
        )}

        {step === "confirm" && impact && (
          <>
            <div className="rounded-md border border-border bg-muted/30 p-3 text-sm space-y-1.5 mb-4">
              <p>This will rename <span className="font-mono text-gold">{oldCode}</span> → <span className="font-mono text-gold">{impact.new_code}</span>:</p>
              <ul className="text-xs text-muted-foreground space-y-1 mt-2">
                <li>• {impact.projects.updated} case{impact.projects.updated === 1 ? "" : "s"} (docket number/UIN + service/office code)</li>
                {impact.projects.irregular > 0 && (
                  <li className="text-amber-600">
                    • {impact.projects.irregular} case{impact.projects.irregular === 1 ? "" : "s"} with an irregular docket format — only the code field updates, docket text stays as-is (needs manual review)
                  </li>
                )}
                <li>
                  • {impact.fee_rate_cards.updated} fee rate card row{impact.fee_rate_cards.updated === 1 ? "" : "s"}
                  {impact.fee_rate_cards.skipped > 0 ? ` (${impact.fee_rate_cards.skipped} skipped — conflicting existing entry)` : ""}
                </li>
                <li>
                  • {impact.service_transition_rules.updated} workflow transition rule{impact.service_transition_rules.updated === 1 ? "" : "s"}
                  {impact.service_transition_rules.skipped > 0 ? ` (${impact.service_transition_rules.skipped} skipped)` : ""}
                </li>
                <li>
                  • {impact.jurisdiction_lifecycle_templates.updated} lifecycle template{impact.jurisdiction_lifecycle_templates.updated === 1 ? "" : "s"}
                  {impact.jurisdiction_lifecycle_templates.skipped > 0 ? ` (${impact.jurisdiction_lifecycle_templates.skipped} skipped)` : ""}
                </li>
                {impact.project_tabs_hits > 0 && (
                  <li>• {impact.project_tabs_hits} Project Tab mapping{impact.project_tabs_hits === 1 ? "" : "s"} updated</li>
                )}
                {Object.entries(impact.jurisdiction_tables).filter(([, n]) => n > 0).map(([table, n]) => (
                  <li key={table}>• {n} {table.replace(/_/g, " ")} row{n === 1 ? "" : "s"}</li>
                ))}
              </ul>
              <p className="text-xs text-muted-foreground pt-1">Invoices already issued are never changed.</p>
            </div>
            {err && <p className="text-xs text-destructive mb-3">{err}</p>}
            <div className="flex gap-2 justify-end">
              <Button variant="outline" onClick={() => setStep("input")} disabled={loading}>Back</Button>
              <Button onClick={handleConfirm} disabled={loading} className="bg-destructive hover:bg-destructive/90 text-white">
                {loading ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : null}Confirm Rename
              </Button>
            </div>
          </>
        )}

        {step === "merge-ask" && mergeInfo && (
          <>
            <div className="rounded-md border border-amber-500/30 bg-amber-500/10 p-3 text-sm space-y-1.5 mb-4">
              <p>
                <span className="font-mono text-gold">{mergeInfo.new_code}</span> already exists as a separate code
                ({mergeInfo.existing_label.replace(/^[A-Z0-9\-]+\s[–-]\s/, "")}, used by {mergeInfo.existing_count} case{mergeInfo.existing_count === 1 ? "" : "s"}).
              </p>
              <p className="text-xs text-muted-foreground">
                Merge <span className="font-mono text-gold">{oldCode}</span> into <span className="font-mono text-gold">{mergeInfo.new_code}</span> instead?
                This combines both codes' cases and configuration under <span className="font-mono">{mergeInfo.new_code}</span> and cannot be undone.
              </p>
            </div>
            {err && <p className="text-xs text-destructive mb-3">{err}</p>}
            <div className="flex gap-2 justify-end">
              <Button variant="outline" onClick={onClose} disabled={loading}>Cancel</Button>
              <Button onClick={handlePreviewMerge} disabled={loading} className="bg-gold hover:bg-gold/90 text-black">
                {loading ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : null}Continue — Preview Merge
              </Button>
            </div>
          </>
        )}

        {step === "merge-confirm" && mergeImpact && (
          <>
            <div className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-sm space-y-1.5 mb-4">
              <p>This will merge <span className="font-mono text-gold">{oldCode}</span> into <span className="font-mono text-gold">{mergeImpact.new_code}</span> and retire <span className="font-mono">{oldCode}</span>:</p>
              <ul className="text-xs text-muted-foreground space-y-1 mt-2">
                <li>• {mergeImpact.projects.updated} case{mergeImpact.projects.updated === 1 ? "" : "s"} reassigned (docket number/UIN + service/office code)</li>
                {mergeImpact.projects.irregular > 0 && (
                  <li className="text-amber-600">
                    • {mergeImpact.projects.irregular} case{mergeImpact.projects.irregular === 1 ? "" : "s"} with an irregular docket format — only the code field updates, docket text stays as-is (needs manual review)
                  </li>
                )}
                <li>
                  • {mergeImpact.fee_rate_cards.updated} fee rate card row{mergeImpact.fee_rate_cards.updated === 1 ? "" : "s"} moved
                  {mergeImpact.fee_rate_cards.discarded > 0 ? (
                    <span className="text-destructive"> ({mergeImpact.fee_rate_cards.discarded} discarded — {mergeImpact.new_code} already had a rate for that combination)</span>
                  ) : ""}
                </li>
                <li>
                  • {mergeImpact.service_transition_rules.updated} workflow transition rule{mergeImpact.service_transition_rules.updated === 1 ? "" : "s"} moved
                  {mergeImpact.service_transition_rules.discarded > 0 ? (
                    <span className="text-destructive"> ({mergeImpact.service_transition_rules.discarded} discarded — conflicting existing rule)</span>
                  ) : ""}
                </li>
                <li>
                  • {mergeImpact.jurisdiction_lifecycle_templates.updated} lifecycle template{mergeImpact.jurisdiction_lifecycle_templates.updated === 1 ? "" : "s"} moved
                  {mergeImpact.jurisdiction_lifecycle_templates.discarded > 0 ? (
                    <span className="text-destructive"> ({mergeImpact.jurisdiction_lifecycle_templates.discarded} discarded — conflicting existing template)</span>
                  ) : ""}
                </li>
                {mergeImpact.project_tabs_hits > 0 && (
                  <li>• {mergeImpact.project_tabs_hits} Project Tab mapping{mergeImpact.project_tabs_hits === 1 ? "" : "s"} updated</li>
                )}
                {Object.entries(mergeImpact.jurisdiction_tables).filter(([, n]) => n > 0).map(([table, n]) => (
                  <li key={table}>• {n} {table.replace(/_/g, " ")} row{n === 1 ? "" : "s"}</li>
                ))}
                <li>• <span className="font-mono">{oldCode}</span> is removed from the code list — {mergeImpact.new_code}'s existing label is kept</li>
              </ul>
              <p className="text-xs text-muted-foreground pt-1">Invoices already issued are never changed.</p>
            </div>
            {err && <p className="text-xs text-destructive mb-3">{err}</p>}
            <div className="flex gap-2 justify-end">
              <Button variant="outline" onClick={() => setStep("merge-ask")} disabled={loading}>Back</Button>
              <Button onClick={handleConfirmMerge} disabled={loading} className="bg-destructive hover:bg-destructive/90 text-white">
                {loading ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : null}Confirm Merge
              </Button>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

/** Searchable combobox for picking a service code to add to a Project Tab. `options` is
 *  expected to already exclude codes assigned to any tab (this one included) — selecting
 *  immediately assigns the code, no separate "Add" click needed. */
function TabCodeCombobox({ options, onSelect, placeholder }: {
  options: { code: string; label: string }[];
  onSelect: (code: string) => void;
  placeholder?: string;
}) {
  const [q, setQ] = useState("");
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const filtered = options
    .filter((o) => !q || o.code.toLowerCase().includes(q.toLowerCase()) || o.label.toLowerCase().includes(q.toLowerCase()))
    .slice(0, 50);

  useEffect(() => {
    if (!open) return;
    const update = () => { if (inputRef.current) setRect(inputRef.current.getBoundingClientRect()); };
    window.addEventListener("scroll", update, true);
    return () => window.removeEventListener("scroll", update, true);
  }, [open]);

  function handleFocus() {
    if (inputRef.current) setRect(inputRef.current.getBoundingClientRect());
    setOpen(true);
  }

  const dropdown = open && rect
    ? createPortal(
        <div
          style={{ position: "fixed", top: rect.bottom + 2, left: rect.left, width: Math.max(rect.width, 220), zIndex: 9999 }}
          className="bg-background border border-border rounded-md shadow-2xl max-h-56 overflow-y-auto"
        >
          {filtered.map((o) => (
            <button key={o.code} type="button"
              onMouseDown={(e) => { e.preventDefault(); onSelect(o.code); setQ(""); setOpen(false); }}
              className="w-full text-left px-3 py-2 text-xs hover:bg-muted/40">
              <span className="font-mono font-semibold text-gold">{o.code}</span>
              <span className="text-muted-foreground ml-1.5">– {o.label.replace(/^[A-Z0-9\-]+\s[–-]\s/, "")}</span>
            </button>
          ))}
          {filtered.length === 0 && (
            <p className="px-3 py-2 text-xs text-muted-foreground">
              {options.length === 0 ? "No codes available — all codes are already assigned to a tab." : "No matches."}
            </p>
          )}
        </div>,
        document.body
      )
    : null;

  return (
    <div className="relative flex-1">
      <input
        ref={inputRef}
        value={q}
        onFocus={handleFocus}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        onChange={(e) => { setQ(e.target.value); setOpen(true); }}
        placeholder={placeholder}
        className="w-full h-8 rounded border border-border bg-background px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-gold"
      />
      {dropdown}
    </div>
  );
}

export default function Settings() {
  const { props } = usePage() as any;
  const user = props.auth?.user;
  const canManageSystem = ["super_admin", "partner"].includes(user?.role ?? "");
  // Salary/payroll data — director (partner), accountant (finance), and
  // system admin only. Mirrors App\Models\User::PAYROLL_ACCESS_ROLES.
  const canManagePayroll = ["super_admin", "partner", "finance"].includes(user?.role ?? "");
  const [tab, setTab] = useState<Tab>("profile");
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [avatarUploading, setAvatarUploading] = useState(false);
  const [avatarRemoving, setAvatarRemoving] = useState(false);
  const [localAvatar, setLocalAvatar] = useState<string | null>(user?.avatar_url ?? null);
  const avatarInputRef = useRef<HTMLInputElement>(null);

  // ── Crop modal state ──
  const [cropSrc, setCropSrc] = useState<string | null>(null);
  const cropCanvasRef = useRef<HTMLCanvasElement>(null);
  const cropImgRef = useRef<HTMLImageElement | null>(null);
  const [cropScale, setCropScale] = useState(1);
  const [cropOffset, setCropOffset] = useState({ x: 0, y: 0 });
  const dragState = useRef<{ active: boolean; startX: number; startY: number; startOX: number; startOY: number }>({ active: false, startX: 0, startY: 0, startOX: 0, startOY: 0 });
  const CANVAS_SIZE = 300;
  const EXPORT_SIZE = 400;
  const [profile, setProfile] = useState({
    name: user?.name || "",
    email: user?.email || "",
    timezone: user?.timezone || "Asia/Kolkata",
    language: user?.language || "English",
  });
  const [notifs, setNotifs] = useState({ taskAssigned: true, deadlineEmail: true, paymentReceived: true, pushNotif: false, weeklyDigest: true, monthlyReport: true });
  const [theme, setTheme] = useState<"light" | "dark" | "system">(() => loadPrefs("myipstrategy.appearance", { theme: "dark" as const }).theme);
  const [accentColor, setAccentColor] = useState(() => loadPrefs("myipstrategy.appearance", { accent: "gold" }).accent);
  const [pwForm, setPwForm] = useState({ current: "", next: "", confirm: "" });
  const [signaturePin, setSignaturePin] = useState("0000");
  const [savingSignaturePin, setSavingSignaturePin] = useState(false);
  const [system, setSystem] = useState({ company: "My IP Law Firm", currency: "INR", fiscalMonth: "April", maxUploadMB: "50" });

  const isSuperAdmin = user?.role === "super_admin";
  const sysSettings = (props.systemSettings ?? {}) as any;

  const [flags, setFlags] = useState({
    feature_link_predecessor:    sysSettings.feature_link_predecessor    ?? true,
    feature_legacy_case:         sysSettings.feature_legacy_case         ?? true,
    feature_existing_client:     sysSettings.feature_existing_client     ?? true,
    feature_lock_code_dropdowns: sysSettings.feature_lock_code_dropdowns ?? true,
  });
  const [savingFlags, setSavingFlags] = useState(false);

  const [renewalFees, setRenewalFees] = useState({
    government_fee: String(sysSettings.renewal_fee_rates?.government_fee ?? 0),
    professional_fee: String(sysSettings.renewal_fee_rates?.professional_fee ?? 0),
    currency: sysSettings.renewal_fee_rates?.currency ?? "INR",
  });
  const [savingRenewalFees, setSavingRenewalFees] = useState(false);

  const [svcCodes, setSvcCodes]   = useState<{code:string;label:string}[]>(sysSettings.dropdown_service_codes ?? []);
  const [ctyCodes, setCtyCodes]   = useState<{code:string;label:string}[]>(sysSettings.dropdown_country_codes ?? []);
  const [newSvcCode,  setNewSvcCode]  = useState("");
  const [newSvcLabel, setNewSvcLabel] = useState("");
  const [newCtyCode,  setNewCtyCode]  = useState("");
  const [newCtyLabel, setNewCtyLabel] = useState("");
  const [svcSearch, setSvcSearch] = useState("");
  const [ctySearch, setCtySearch] = useState("");
  const [svcAddErr, setSvcAddErr] = useState("");
  const [ctyAddErr, setCtyAddErr] = useState("");
  const [savingDropdown, setSavingDropdown] = useState<"service"|"country"|null>(null);

  // Live "# projects using this code" counts for the Codes tab table + delete/rename gating.
  const [codeUsage, setCodeUsage] = useState<{ service: CodeRow[]; country: CodeRow[] }>({ service: [], country: [] });
  const [renameDialog, setRenameDialog] = useState<{ type: "service" | "country"; code: string; label: string } | null>(null);
  const [codesSubTab, setCodesSubTab] = useState<"service" | "country">("service");

  // Projects-list tabs (Cases page segmentation by service code) — see updateProjectTabs.
  const [projectTabs, setProjectTabs] = useState<ProjectTab[]>(sysSettings.project_tabs ?? []);
  const [newTabLabel, setNewTabLabel] = useState("");
  const [tabAddErr, setTabAddErr] = useState("");
  const [savingTabs, setSavingTabs] = useState(false);

  const visibleTabs = tabs.filter((item) => {
    if (item.id === "system" || item.id === "finance" || item.id === "codes") return canManageSystem;
    if (item.id === "projectTabs" || item.id === "site") return isSuperAdmin;
    if (item.id === "payroll") return canManagePayroll;
    return true;
  });

  useEffect(() => {
    api.getSettings().then((data) => {
      if (data.profile) setProfile((p) => ({ ...p, ...(data.profile as object) }));
      if (data.notifications) setNotifs((n) => ({ ...n, ...(data.notifications as object) }));
      if (data.system) setSystem((s) => ({ ...s, ...(data.system as object) }));
    }).catch(() => {});
  }, []);

  useEffect(() => {
    if (!canManageSystem && (tab === "system" || tab === "finance" || tab === "codes")) setTab("profile");
    if (!isSuperAdmin && (tab === "projectTabs" || tab === "site")) setTab("profile");
    if (!canManagePayroll && tab === "payroll") setTab("profile");
  }, [canManageSystem, isSuperAdmin, canManagePayroll, tab]);

  const [feeRows, setFeeRows] = useState<any[]>([]);
  const [feeLoading, setFeeLoading] = useState(false);
  const [feeJurisdiction, setFeeJurisdiction] = useState<typeof FEE_JURISDICTIONS[number]>("IN");
  const [feeEdits, setFeeEdits] = useState<Record<number, any>>({});
  const [feeSavingId, setFeeSavingId] = useState<number | null>(null);
  const [newFeeRow, setNewFeeRow] = useState({ ...BLANK_FEE_ROW });
  const [feeAddErr, setFeeAddErr] = useState("");

  const loadFeeRows = useCallback(() => {
    setFeeLoading(true);
    api.getFeeRateCards({ jurisdiction: feeJurisdiction, includeInactive: true })
      .then((rows) => { setFeeRows(rows); setFeeEdits({}); })
      .catch(() => setFeeRows([]))
      .finally(() => setFeeLoading(false));
  }, [feeJurisdiction]);

  useEffect(() => {
    if (tab === "finance" && isSuperAdmin) loadFeeRows();
  }, [tab, isSuperAdmin, loadFeeRows]);

  // ── Payroll tab: salary component template, reimbursement types, Professional Tax ──
  const [components, setComponents] = useState<any[]>([]);
  const [componentsLoading, setComponentsLoading] = useState(false);
  const [componentEdits, setComponentEdits] = useState<Record<number, any>>({});
  const [componentSavingId, setComponentSavingId] = useState<number | null>(null);
  const [newComponent, setNewComponent] = useState({ ...BLANK_SALARY_COMPONENT });
  const [newComponentErr, setNewComponentErr] = useState("");
  const [componentErr, setComponentErr] = useState("");
  const [previewBasePay, setPreviewBasePay] = useState("50000");
  const [previewHra, setPreviewHra] = useState("10000");

  const [reimbTypes, setReimbTypes] = useState<any[]>([]);
  const [reimbTypesLoading, setReimbTypesLoading] = useState(false);
  const [reimbTypeEdits, setReimbTypeEdits] = useState<Record<number, any>>({});
  const [reimbTypeSavingId, setReimbTypeSavingId] = useState<number | null>(null);
  const [newReimbType, setNewReimbType] = useState({ ...BLANK_REIMBURSEMENT_TYPE });
  const [newReimbTypeErr, setNewReimbTypeErr] = useState("");

  const [ptNilThreshold, setPtNilThreshold] = useState(String(sysSettings.payroll_pt_nil_threshold ?? 25000));
  const [ptMonthlyAmount, setPtMonthlyAmount] = useState(String(sysSettings.payroll_pt_monthly_amount ?? 200));
  const [ptFebruaryAmount, setPtFebruaryAmount] = useState(String(sysSettings.payroll_pt_february_amount ?? 300));
  const [pfEnabled, setPfEnabled] = useState(sysSettings.payroll_pf_enabled ?? true);
  const [esiEnabled, setEsiEnabled] = useState(sysSettings.payroll_esi_enabled ?? true);
  const [savingPt, setSavingPt] = useState(false);

  const [companyName, setCompanyName] = useState(String(sysSettings.payroll_company_name ?? ""));
  const [companyAddress, setCompanyAddress] = useState(String(sysSettings.payroll_company_address ?? ""));
  const [companyLogoUrl, setCompanyLogoUrl] = useState(String(sysSettings.payroll_company_logo_url ?? ""));
  const [companyTan, setCompanyTan] = useState(String(sysSettings.payroll_company_tan ?? ""));
  const [signatoryName, setSignatoryName] = useState(String(sysSettings.payroll_signatory_name ?? ""));
  const [signatoryDesignation, setSignatoryDesignation] = useState(String(sysSettings.payroll_signatory_designation ?? ""));
  const [savingLetterhead, setSavingLetterhead] = useState(false);

  const [payDay, setPayDay] = useState(String(sysSettings.payroll_pay_day ?? 1));

  const [taxSlabConfigs, setTaxSlabConfigs] = useState<any[]>([]);
  const [taxSlabConfigsLoading, setTaxSlabConfigsLoading] = useState(false);
  const [taxSlabEdits, setTaxSlabEdits] = useState<Record<number, any>>({});
  const [taxSlabSavingId, setTaxSlabSavingId] = useState<number | null>(null);
  const [taxSlabErr, setTaxSlabErr] = useState("");

  const loadTaxSlabConfigs = useCallback(() => {
    setTaxSlabConfigsLoading(true);
    api.getTaxSlabConfigs()
      .then((rows) => { setTaxSlabConfigs(rows); setTaxSlabEdits({}); })
      .catch(() => setTaxSlabConfigs([]))
      .finally(() => setTaxSlabConfigsLoading(false));
  }, []);

  function taxSlabField(row: any, key: string) {
    return taxSlabEdits[row.id]?.[key] ?? row[key] ?? "";
  }
  function setTaxSlabField(row: any, key: string, value: any) {
    setTaxSlabEdits((p) => ({ ...p, [row.id]: { ...(p[row.id] ?? {}), [key]: value } }));
  }
  async function saveTaxSlabRow(row: any) {
    const edits = taxSlabEdits[row.id];
    if (!edits) return;
    setTaxSlabSavingId(row.id);
    setTaxSlabErr("");
    try {
      const payload = { ...edits };
      if (payload.slabs_json_text !== undefined) {
        try { payload.slabs_json = JSON.parse(payload.slabs_json_text); }
        catch { throw new Error("Slabs JSON is not valid — check the format."); }
        delete payload.slabs_json_text;
      }
      const updated = await api.updateTaxSlabConfig(row.id, payload);
      setTaxSlabConfigs((rs) => rs.map((r) => (r.id === row.id ? updated : r)));
      setTaxSlabEdits((p) => { const n = { ...p }; delete n[row.id]; return n; });
    } catch (e: any) { setTaxSlabErr(e?.message || "Failed to save tax slab config."); }
    finally { setTaxSlabSavingId(null); }
  }

  const [poiWindows, setPoiWindows] = useState<any[]>([]);
  const [poiWindowsLoading, setPoiWindowsLoading] = useState(false);
  const [poiWindowForm, setPoiWindowForm] = useState({
    financial_year: String(new Date().getMonth() + 1 >= 4 ? new Date().getFullYear() : new Date().getFullYear() - 1),
    deadline_at: "", effective_payroll_month: "",
  });
  const [poiWindowSaving, setPoiWindowSaving] = useState(false);
  const [poiWindowErr, setPoiWindowErr] = useState("");

  const loadPoiWindows = useCallback(() => {
    setPoiWindowsLoading(true);
    api.getPoiWindows()
      .then(setPoiWindows)
      .catch(() => setPoiWindows([]))
      .finally(() => setPoiWindowsLoading(false));
  }, []);

  async function savePoiWindowRow() {
    setPoiWindowSaving(true);
    setPoiWindowErr("");
    try {
      if (!poiWindowForm.deadline_at || !poiWindowForm.effective_payroll_month) {
        throw new Error("Deadline and effective payroll month are both required.");
      }
      await api.savePoiWindow({
        financial_year: parseInt(poiWindowForm.financial_year, 10),
        deadline_at: poiWindowForm.deadline_at,
        effective_payroll_month: poiWindowForm.effective_payroll_month,
      });
      loadPoiWindows();
    } catch (e: any) {
      setPoiWindowErr(e?.message || "Failed to save the window.");
    } finally {
      setPoiWindowSaving(false);
    }
  }

  const loadComponents = useCallback(() => {
    setComponentsLoading(true);
    api.getSalaryComponents()
      .then((rows) => { setComponents(rows); setComponentEdits({}); })
      .catch(() => setComponents([]))
      .finally(() => setComponentsLoading(false));
  }, []);

  const loadReimbTypes = useCallback(() => {
    setReimbTypesLoading(true);
    api.getReimbursementTypes(true)
      .then((rows) => { setReimbTypes(rows); setReimbTypeEdits({}); })
      .catch(() => setReimbTypes([]))
      .finally(() => setReimbTypesLoading(false));
  }, []);

  useEffect(() => {
    if (tab === "payroll" && canManagePayroll) { loadComponents(); loadReimbTypes(); loadTaxSlabConfigs(); loadPoiWindows(); }
  }, [tab, canManagePayroll, loadComponents, loadReimbTypes, loadTaxSlabConfigs, loadPoiWindows]);

  function componentField(row: any, key: string) {
    return componentEdits[row.id]?.[key] ?? row[key] ?? "";
  }
  function setComponentField(row: any, key: string, value: any) {
    setComponentEdits((p) => ({ ...p, [row.id]: { ...(p[row.id] ?? {}), [key]: value } }));
  }
  async function saveComponentRow(row: any) {
    const edits = componentEdits[row.id];
    if (!edits) return;
    setComponentSavingId(row.id);
    setComponentErr("");
    try {
      const payload = { ...edits };
      if (payload.calc_type && payload.calc_type !== "percentage" && payload.calc_type !== "employee_field") payload.percentage_of = null;
      const updated = await api.updateSalaryComponent(row.id, payload);
      setComponents((rs) => rs.map((r) => (r.id === row.id ? updated : r)).sort((a, b) => a.sequence - b.sequence));
      setComponentEdits((p) => { const n = { ...p }; delete n[row.id]; return n; });
    } catch (e: any) { setComponentErr(e?.message || "Failed to save component."); }
    finally { setComponentSavingId(null); }
  }
  async function deleteComponentRow(id: number) {
    if (!confirm("Delete this salary component?")) return;
    setComponentErr("");
    try { await api.deleteSalaryComponent(id); setComponents((rs) => rs.filter((r) => r.id !== id)); }
    catch (e: any) { setComponentErr(e?.message || "Failed to delete — it may still be referenced."); }
  }
  async function addComponentRow() {
    if (!newComponent.name.trim() || !newComponent.code.trim()) { setNewComponentErr("Name and code are required."); return; }
    setNewComponentErr("");
    try {
      const payload: any = {
        ...newComponent,
        value: (newComponent.calc_type === "remainder" || newComponent.calc_type === "employee_field") ? null : (newComponent.value === "" ? null : Number(newComponent.value)),
        percentage_of: (newComponent.calc_type === "percentage" || newComponent.calc_type === "employee_field") ? (newComponent.percentage_of || null) : null,
        sequence: components.length + 1,
      };
      const created = await api.createSalaryComponent(payload);
      setComponents((rs) => [...rs, created]);
      setNewComponent({ ...BLANK_SALARY_COMPONENT });
    } catch (e: any) { setNewComponentErr(e?.message || "Failed to add component."); }
  }
  async function moveComponent(id: number, direction: -1 | 1) {
    const idx = components.findIndex((c) => c.id === id);
    const swapIdx = idx + direction;
    if (idx < 0 || swapIdx < 0 || swapIdx >= components.length) return;
    const reordered = [...components];
    [reordered[idx], reordered[swapIdx]] = [reordered[swapIdx], reordered[idx]];
    setComponents(reordered);
    try { await api.reorderSalaryComponents(reordered.map((c) => c.id)); loadComponents(); }
    catch { setComponentErr("Failed to reorder."); loadComponents(); }
  }

  function reimbTypeField(row: any, key: string) {
    return reimbTypeEdits[row.id]?.[key] ?? row[key] ?? "";
  }
  function setReimbTypeField(row: any, key: string, value: any) {
    setReimbTypeEdits((p) => ({ ...p, [row.id]: { ...(p[row.id] ?? {}), [key]: value } }));
  }
  async function saveReimbTypeRow(row: any) {
    const edits = reimbTypeEdits[row.id];
    if (!edits) return;
    setReimbTypeSavingId(row.id);
    try {
      const payload = { ...edits };
      if (payload.monthly_cap === "") payload.monthly_cap = null;
      const updated = await api.updateReimbursementType(row.id, payload);
      setReimbTypes((rs) => rs.map((r) => (r.id === row.id ? updated : r)));
      setReimbTypeEdits((p) => { const n = { ...p }; delete n[row.id]; return n; });
    } catch { setError("Failed to save reimbursement type."); }
    finally { setReimbTypeSavingId(null); }
  }
  async function deleteReimbTypeRow(id: number) {
    if (!confirm("Delete this reimbursement type?")) return;
    try { await api.deleteReimbursementType(id); setReimbTypes((rs) => rs.filter((r) => r.id !== id)); }
    catch (e: any) { setError(e?.message || "Failed to delete — it may have existing claims; try deactivating instead."); }
  }
  async function addReimbTypeRow() {
    if (!newReimbType.name.trim() || !newReimbType.code.trim()) { setNewReimbTypeErr("Name and code are required."); return; }
    setNewReimbTypeErr("");
    try {
      const payload: any = { ...newReimbType, monthly_cap: newReimbType.monthly_cap === "" ? null : Number(newReimbType.monthly_cap) };
      const created = await api.createReimbursementType(payload);
      setReimbTypes((rs) => [...rs, created]);
      setNewReimbType({ ...BLANK_REIMBURSEMENT_TYPE });
    } catch (e: any) { setNewReimbTypeErr(e?.message || "Failed to add type."); }
  }

  const loadCodeUsage = useCallback(() => {
    if (!canManageSystem) return;
    api.getCodes().then(setCodeUsage).catch(() => {});
  }, [canManageSystem]);

  useEffect(() => {
    if (tab === "codes" && canManageSystem) loadCodeUsage();
  }, [tab, canManageSystem, loadCodeUsage]);

  async function saveDropdown(type: "service" | "country") {
    setSavingDropdown(type);
    try {
      const key = type === "service" ? "dropdown_service_codes" : "dropdown_country_codes";
      await api.updateDropdown(key, type === "service" ? svcCodes : ctyCodes);
      flashSaved();
      loadCodeUsage();
    } catch (e: any) { setError(e.message || "Failed to save."); }
    finally { setSavingDropdown(null); }
  }

  // After a cascading rename succeeds, patch the local code lists in place (rather than
  // waiting on an Inertia prop reload) and refresh usage counts + Project Tabs mapping.
  function handleCodeRenamed(type: "service" | "country", oldCode: string, newCode: string) {
    if (type === "service") {
      setSvcCodes((prev) => prev.map((x) => (x.code === oldCode ? { ...x, code: newCode } : x)));
      setProjectTabs((prev) => prev.map((t) => ({
        ...t,
        codes: t.codes.map((c) => (c === oldCode ? newCode : c)),
      })));
    } else {
      setCtyCodes((prev) => prev.map((x) => (x.code === oldCode ? { ...x, code: newCode } : x)));
    }
    setRenameDialog(null);
    loadCodeUsage();
  }

  function handleCodeMerged(type: "service" | "country", oldCode: string, _newCode: string) {
    // Unlike rename (which relabels the old entry in place), a merge retires the old code
    // entirely — remove it locally, then re-pull authoritative state (project_tabs dedup,
    // dropdown catalog) from the server rather than hand-reconciling every derived piece of
    // local state, matching the router.reload({ only: [] }) pattern used elsewhere in this file.
    if (type === "service") {
      setSvcCodes((prev) => prev.filter((x) => x.code !== oldCode));
    } else {
      setCtyCodes((prev) => prev.filter((x) => x.code !== oldCode));
    }
    setRenameDialog(null);
    loadCodeUsage();
    router.reload({ only: [] });
  }

  function feeField(row: any, key: string) {
    return feeEdits[row.id]?.[key] ?? row[key] ?? "";
  }
  function setFeeField(row: any, key: string, value: any) {
    setFeeEdits((p) => ({ ...p, [row.id]: { ...(p[row.id] ?? {}), [key]: value } }));
  }
  async function saveFeeRow(row: any) {
    const edits = feeEdits[row.id];
    if (!edits) return;
    setFeeSavingId(row.id);
    try {
      const updated = await api.updateFeeRateCard(row.id, edits);
      setFeeRows((rs) => rs.map((r) => r.id === row.id ? updated : r));
      setFeeEdits((p) => { const n = { ...p }; delete n[row.id]; return n; });
      flashSaved();
    } catch { setError("Failed to save fee rate row."); }
    finally { setFeeSavingId(null); }
  }
  async function deleteFeeRow(id: number) {
    if (!confirm("Delete this fee rate row?")) return;
    try { await api.deleteFeeRateCard(id); setFeeRows((rs) => rs.filter((r) => r.id !== id)); } catch { setError("Failed to delete."); }
  }
  async function addFeeRow() {
    if (!newFeeRow.service_code.trim()) { setFeeAddErr("Service code is required."); return; }
    setFeeAddErr("");
    try {
      const payload: any = { ...newFeeRow, jurisdiction: feeJurisdiction };
      ["entity_tier", "year_from", "year_to", "validation_country", "govt_fee_amount", "govt_fee_currency",
        "professional_fee_amount", "professional_fee_currency", "professional_fee_max_amount", "notes"]
        .forEach((k) => { if (payload[k] === "") payload[k] = null; });
      const created = await api.createFeeRateCard(payload);
      setFeeRows((rs) => [...rs, created]);
      setNewFeeRow({ ...BLANK_FEE_ROW });
    } catch (e: any) { setFeeAddErr(e?.message || "Failed to add row."); }
  }

  const flashSaved = () => { setError(""); setSaved(true); setTimeout(() => setSaved(false), 2500); };

  // Compress image to under 5 MB using canvas if needed
  async function compressIfNeeded(file: File): Promise<File> {
    if (file.size <= 5 * 1024 * 1024) return file;
    return new Promise((resolve) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => {
        URL.revokeObjectURL(url);
        const MAX_DIM = 2400;
        const ratio = Math.min(1, MAX_DIM / Math.max(img.naturalWidth, img.naturalHeight));
        const canvas = document.createElement("canvas");
        canvas.width = Math.round(img.naturalWidth * ratio);
        canvas.height = Math.round(img.naturalHeight * ratio);
        canvas.getContext("2d")!.drawImage(img, 0, 0, canvas.width, canvas.height);
        const tryQ = (q: number) => {
          canvas.toBlob((blob) => {
            if (!blob || blob.size <= 5 * 1024 * 1024 || q <= 0.3) {
              resolve(new File([blob ?? file], "avatar.jpg", { type: "image/jpeg" }));
            } else {
              tryQ(q - 0.1);
            }
          }, "image/jpeg", q);
        };
        tryQ(0.85);
      };
      img.src = url;
    });
  }

  async function handleAvatarChange(e: React.ChangeEvent<HTMLInputElement>) {
    const raw = e.target.files?.[0];
    e.target.value = "";
    if (!raw) return;
    setError("");
    const file = await compressIfNeeded(raw);
    const url = URL.createObjectURL(file);
    // Store file ref for later upload after crop
    (avatarInputRef as any)._pendingFile = file;
    setCropSrc(url);
    setCropScale(1);
    setCropOffset({ x: 0, y: 0 });
  }

  // Draw crop preview on canvas
  const drawCrop = useCallback(() => {
    const canvas = cropCanvasRef.current;
    const img = cropImgRef.current;
    if (!canvas || !img || !img.complete) return;
    const ctx = canvas.getContext("2d")!;
    ctx.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

    // Fit image to fill the circle (cover), then apply user scale + offset
    const fitScale = Math.max(CANVAS_SIZE / img.naturalWidth, CANVAS_SIZE / img.naturalHeight);
    const drawW = img.naturalWidth * fitScale * cropScale;
    const drawH = img.naturalHeight * fitScale * cropScale;
    const drawX = (CANVAS_SIZE - drawW) / 2 + cropOffset.x;
    const drawY = (CANVAS_SIZE - drawH) / 2 + cropOffset.y;
    ctx.drawImage(img, drawX, drawY, drawW, drawH);

    // Dark overlay outside circle
    ctx.save();
    ctx.fillStyle = "rgba(0,0,0,0.55)";
    ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
    ctx.globalCompositeOperation = "destination-out";
    ctx.beginPath();
    ctx.arc(CANVAS_SIZE / 2, CANVAS_SIZE / 2, CANVAS_SIZE / 2 - 2, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    // Gold circle border
    ctx.strokeStyle = "#C8971D";
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.arc(CANVAS_SIZE / 2, CANVAS_SIZE / 2, CANVAS_SIZE / 2 - 2, 0, Math.PI * 2);
    ctx.stroke();
  }, [cropScale, cropOffset]);

  useEffect(() => { drawCrop(); }, [drawCrop]);

  function onCropMouseDown(e: React.MouseEvent) {
    dragState.current = { active: true, startX: e.clientX, startY: e.clientY, startOX: cropOffset.x, startOY: cropOffset.y };
  }
  function onCropMouseMove(e: React.MouseEvent) {
    if (!dragState.current.active) return;
    setCropOffset({ x: dragState.current.startOX + e.clientX - dragState.current.startX, y: dragState.current.startOY + e.clientY - dragState.current.startY });
  }
  function onCropMouseUp() { dragState.current.active = false; }

  function onCropWheel(e: React.WheelEvent) {
    e.preventDefault();
    setCropScale(s => Math.min(4, Math.max(0.5, s - e.deltaY * 0.001)));
  }

  async function applyCrop() {
    const img = cropImgRef.current;
    if (!img) return;
    const offscreen = document.createElement("canvas");
    offscreen.width = EXPORT_SIZE;
    offscreen.height = EXPORT_SIZE;
    const ctx = offscreen.getContext("2d")!;

    const fitScale = Math.max(CANVAS_SIZE / img.naturalWidth, CANVAS_SIZE / img.naturalHeight);
    const drawW = img.naturalWidth * fitScale * cropScale;
    const drawH = img.naturalHeight * fitScale * cropScale;
    const drawX = (CANVAS_SIZE - drawW) / 2 + cropOffset.x;
    const drawY = (CANVAS_SIZE - drawH) / 2 + cropOffset.y;
    const ex = EXPORT_SIZE / CANVAS_SIZE;

    ctx.beginPath();
    ctx.arc(EXPORT_SIZE / 2, EXPORT_SIZE / 2, EXPORT_SIZE / 2, 0, Math.PI * 2);
    ctx.clip();
    ctx.drawImage(img, drawX * ex, drawY * ex, drawW * ex, drawH * ex);

    offscreen.toBlob(async (blob) => {
      if (!blob) return;
      const file = new File([blob], "avatar.jpg", { type: "image/jpeg" });
      setCropSrc(null);
      if (cropSrc) URL.revokeObjectURL(cropSrc);
      setAvatarUploading(true);
      setError("");
      try {
        const res = await api.uploadAvatar(file);
        setLocalAvatar(res.avatar_url);
        flashSaved();
        router.reload({ only: [] });
      } catch (e: any) {
        setError(e.message || "Avatar upload failed.");
      } finally {
        setAvatarUploading(false);
      }
    }, "image/jpeg", 0.92);
  }

  async function handleRemoveAvatar() {
    if (!confirm("Remove your profile photo?")) return;
    setAvatarRemoving(true);
    setError("");
    try {
      await api.removeAvatar();
      setLocalAvatar(null);
      flashSaved();
      router.reload({ only: [] });
    } catch (e: any) {
      setError(e.message || "Failed to remove photo.");
    } finally {
      setAvatarRemoving(false);
    }
  }

  const saveProfile = async () => {
    setSaving(true);
    setError("");
    try {
      await api.updateProfile({ name: profile.name, email: profile.email, timezone: profile.timezone, language: profile.language });
      flashSaved();
      // Reload to propagate language/timezone to Inertia shared props
      router.reload({ only: [] });
    } catch (e: any) {
      setError(e.message || "Failed to save profile.");
    } finally {
      setSaving(false);
    }
  };

  const savePassword = async () => {
    if (!pwForm.current || !pwForm.next) { setError("Fill in all password fields."); return; }
    if (pwForm.next !== pwForm.confirm) { setError("New passwords do not match."); return; }
    setSaving(true);
    setError("");
    try {
      await api.updatePassword({ current_password: pwForm.current, password: pwForm.next, password_confirmation: pwForm.confirm });
      setPwForm({ current: "", next: "", confirm: "" });
      flashSaved();
    } catch (e: any) {
      setError(e.message || "Failed to change password.");
    } finally {
      setSaving(false);
    }
  };

  const saveSignaturePin = async () => {
    if (!/^\d{4}$/.test(signaturePin)) { setError("Signature PIN must contain exactly 4 digits."); return; }
    setSavingSignaturePin(true);
    setError("");
    try {
      await api.resetInternalSignaturePin(signaturePin);
      flashSaved();
    } catch (e: any) {
      setError(e.message || "Failed to reset signature PIN.");
    } finally {
      setSavingSignaturePin(false);
    }
  };

  const saveNotifs = async () => {
    setSaving(true);
    setError("");
    try {
      await api.updateNotifications(notifs);
      flashSaved();
    } catch (e: any) {
      setError(e.message || "Failed to save notification preferences.");
    } finally {
      setSaving(false);
    }
  };

  const saveAppearance = () => {
    localStorage.setItem("myipstrategy.appearance", JSON.stringify({ theme, accent: accentColor }));
    const root = document.documentElement;
    if (theme === "dark") root.classList.add("dark");
    else if (theme === "light") root.classList.remove("dark");
    else root.classList.toggle("dark", window.matchMedia("(prefers-color-scheme: dark)").matches);
    flashSaved();
  };

  const saveSystem = async () => {
    setSaving(true);
    setError("");
    try {
      await api.updateSystemSettings(system);
      flashSaved();
    } catch (e: any) {
      setError(e.message || "Failed to save system settings.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <AppLayout>
      <Head title="Settings" />
      <PageHeader eyebrow="Operations" title="Settings" description="Manage your account, preferences, and system configuration" />
      <div className="flex min-h-[calc(100vh-180px)]">
        {/* Sidebar */}
        <div className="w-56 border-r border-border flex-shrink-0 py-4">
          {visibleTabs.map(({ id, label, icon: Icon }) => (
            <button key={id} onClick={() => setTab(id)}
              className={`w-full flex items-center gap-3 px-6 py-2.5 text-sm transition-colors ${tab === id ? "text-gold font-semibold bg-gold/10 border-r-2 border-gold" : "text-muted-foreground hover:text-foreground hover:bg-muted/30"}`}>
              <Icon className="h-4 w-4" />{label}
            </button>
          ))}
        </div>

        {/* Content */}
        <div className="flex-1 p-8 space-y-6 max-w-2xl">
          {saved && (
            <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 font-medium">
              ✓ Settings saved successfully
            </div>
          )}
          {error && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 font-medium">
              {error}
            </div>
          )}

          {/* ── Profile ── */}
          {tab === "profile" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display">Profile Information</CardTitle></CardHeader>
              <CardContent className="space-y-4">
                {/* Avatar upload */}
                <div className="flex items-center gap-4 mb-6">
                  <div className="relative group">
                    <AvatarDisplay src={localAvatar} name={profile.name} size="lg" />
                    <button
                      onClick={() => avatarInputRef.current?.click()}
                      disabled={avatarUploading || avatarRemoving}
                      className="absolute inset-0 rounded-full bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"
                      title="Change photo"
                    >
                      {avatarUploading
                        ? <Loader2 className="h-5 w-5 text-white animate-spin" />
                        : <Camera className="h-5 w-5 text-white" />}
                    </button>
                    <input
                      ref={avatarInputRef}
                      type="file"
                      accept="image/jpeg,image/png,image/gif,image/webp"
                      className="hidden"
                      onChange={handleAvatarChange}
                    />
                  </div>
                  <div className="space-y-1.5">
                    <div className="font-semibold">{profile.name}</div>
                    <Badge variant="outline" className="text-xs">{user?.role || "Admin"}</Badge>
                    <div className="flex items-center gap-2 pt-0.5">
                      <button
                        onClick={() => avatarInputRef.current?.click()}
                        disabled={avatarUploading || avatarRemoving}
                        className="text-xs text-gold hover:text-gold/80 underline underline-offset-2 disabled:opacity-50"
                      >
                        {avatarUploading ? "Uploading…" : localAvatar ? "Change photo" : "Upload photo"}
                      </button>
                      {localAvatar && (
                        <>
                          <span className="text-muted-foreground text-xs">·</span>
                          <button
                            onClick={handleRemoveAvatar}
                            disabled={avatarRemoving || avatarUploading}
                            className="text-xs text-destructive hover:text-destructive/80 flex items-center gap-1 disabled:opacity-50"
                          >
                            {avatarRemoving ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />}
                            Remove
                          </button>
                        </>
                      )}
                    </div>
                    <p className="text-xs text-muted-foreground">Any size — large images are auto-compressed</p>
                  </div>
                </div>

                {/* Name + Email */}
                {(["name", "email"] as const).map((key) => (
                  <div key={key}>
                    <label className="block text-xs font-medium text-muted-foreground mb-1 capitalize">
                      {key === "name" ? "Full Name" : "Email Address"}
                    </label>
                    <input
                      type={key === "email" ? "email" : "text"}
                      value={profile[key]}
                      onChange={(e) => setProfile((p) => ({ ...p, [key]: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    />
                  </div>
                ))}

                {/* Timezone + Language */}
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">Timezone</label>
                    <select
                      value={profile.timezone}
                      onChange={(e) => setProfile((p) => ({ ...p, timezone: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    >
                      {TIMEZONES.map((tz) => <option key={tz}>{tz}</option>)}
                    </select>
                    <p className="text-xs text-muted-foreground mt-1">Applies to date/time display throughout the portal.</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">Language</label>
                    <select
                      value={profile.language}
                      onChange={(e) => setProfile((p) => ({ ...p, language: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    >
                      {LANGUAGES.map((l) => <option key={l}>{l}</option>)}
                    </select>
                    <p className="text-xs text-muted-foreground mt-1">Portal UI language. Takes effect after save.</p>
                  </div>
                </div>

                <Button onClick={saveProfile} disabled={saving} className="bg-gold hover:bg-gold/90 text-black">
                  {saving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}Save Changes
                </Button>
              </CardContent>
            </Card>
          )}

          {/* ── Notifications ── */}
          {tab === "notifications" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display">Notification Preferences</CardTitle></CardHeader>
              <CardContent className="divide-y divide-border">
                {([
                  { key: "taskAssigned",    label: "Task Assigned",   desc: "In-app alert when a task is assigned to you" },
                  { key: "deadlineEmail",   label: "Deadline Alerts", desc: "In-app alert 3 days before IP deadlines" },
                  { key: "paymentReceived", label: "Payment Events",  desc: "In-app alert on invoice paid or overdue" },
                  { key: "pushNotif",       label: "Push Notifications", desc: "Browser push notifications" },
                  { key: "weeklyDigest",    label: "Weekly Digest",   desc: "Summary every Monday" },
                  { key: "monthlyReport",   label: "Monthly Report",  desc: "Auto-generate monthly report" },
                ] as { key: keyof typeof notifs; label: string; desc: string }[]).map(({ key, label, desc }) => (
                  <div key={key} className="flex items-center justify-between py-4 first:pt-0 last:pb-0">
                    <div>
                      <div className="text-sm font-medium">{label}</div>
                      <div className="text-xs text-muted-foreground mt-0.5">{desc}</div>
                    </div>
                    <Toggle checked={notifs[key]} onChange={(v) => setNotifs((p) => ({ ...p, [key]: v }))} />
                  </div>
                ))}
                <div className="pt-4">
                  <Button onClick={saveNotifs} disabled={saving} className="bg-gold hover:bg-gold/90 text-black">
                    {saving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}Save Preferences
                  </Button>
                </div>
              </CardContent>
            </Card>
          )}

          {/* ── Security ── */}
          {tab === "security" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display text-base">Change Password</CardTitle></CardHeader>
              <CardContent className="space-y-3">
                {[
                  { label: "Current Password", key: "current" },
                  { label: "New Password",     key: "next" },
                  { label: "Confirm New Password", key: "confirm" },
                ].map(({ label, key }) => (
                  <div key={key}>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">{label}</label>
                    <input type="password" value={(pwForm as any)[key]}
                      onChange={(e) => setPwForm((p) => ({ ...p, [key]: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                ))}
                <Button onClick={savePassword} disabled={saving} className="bg-gold hover:bg-gold/90 text-black mt-2">
                  {saving ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Key className="h-4 w-4 mr-2" />}Update Password
                </Button>
                <p className="text-xs text-muted-foreground pt-1">Minimum 8 characters. You stay signed in after changing your password.</p>
                <div className="mt-6 border-t border-border pt-5">
                  <h3 className="font-display text-base font-semibold">Internal signature PIN</h3>
                  <p className="text-xs text-muted-foreground mt-1 mb-3">Used to verify your employee row before Bank Advice is signed. Default PIN: 0000.</p>
                  <div className="flex max-w-xs gap-2">
                    <input inputMode="numeric" maxLength={4} value={signaturePin} onChange={(e) => setSignaturePin(e.target.value.replace(/\D/g, "").slice(0, 4))}
                      className="h-9 flex-1 rounded-md border border-border bg-background px-3 text-sm tracking-[0.35em] focus:outline-none focus:ring-1 focus:ring-gold" aria-label="Internal signature PIN" />
                    <Button onClick={saveSignaturePin} disabled={savingSignaturePin || signaturePin.length !== 4} variant="outline" className="h-9">
                      {savingSignaturePin && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}Reset PIN
                    </Button>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}

          {/* ── Appearance ── */}
          {tab === "appearance" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display">Appearance</CardTitle></CardHeader>
              <CardContent className="space-y-6">
                <div>
                  <label className="block text-sm font-medium mb-3">Theme</label>
                  <div className="grid grid-cols-3 gap-3">
                    {(["light", "dark", "system"] as const).map((t) => (
                      <button key={t} onClick={() => setTheme(t)}
                        className={`p-4 rounded-xl border-2 capitalize text-sm font-medium transition-all ${theme === t ? "border-gold bg-gold/10 text-gold" : "border-border hover:border-gold/50"}`}>
                        {t === "light" ? "☀️" : t === "dark" ? "🌙" : "💻"} {t}
                      </button>
                    ))}
                  </div>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-3">Accent Color</label>
                  <div className="flex gap-3">
                    {[
                      { name: "gold",   color: "bg-[#C8971D]" },
                      { name: "blue",   color: "bg-blue-500" },
                      { name: "green",  color: "bg-green-500" },
                      { name: "purple", color: "bg-purple-500" },
                      { name: "rose",   color: "bg-rose-500" },
                    ].map(({ name, color }) => (
                      <button key={name} onClick={() => setAccentColor(name)}
                        className={`h-8 w-8 rounded-full ${color} transition-all ${accentColor === name ? "ring-2 ring-offset-2 ring-foreground scale-110" : "hover:scale-105"}`} />
                    ))}
                  </div>
                </div>
                <Button onClick={saveAppearance} className="bg-gold hover:bg-gold/90 text-black">Save Appearance</Button>
              </CardContent>
            </Card>
          )}

          {/* ── System ── */}
          {tab === "system" && (
            <div className="space-y-6">
              {/* General config */}
              <Card className="border-border">
                <CardHeader><CardTitle className="font-display">System Configuration</CardTitle></CardHeader>
                <CardContent className="space-y-4">
                  {[
                    { label: "Company / Firm Name",      key: "company" },
                    { label: "Currency",                 key: "currency" },
                    { label: "Fiscal Year Start Month",  key: "fiscalMonth" },
                    { label: "Max File Upload (MB)",     key: "maxUploadMB" },
                  ].map(({ label, key }) => (
                    <div key={key}>
                      <label className="block text-xs font-medium text-muted-foreground mb-1">{label}</label>
                      <input value={(system as any)[key]}
                        onChange={(e) => setSystem((p) => ({ ...p, [key]: e.target.value }))}
                        className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                  ))}
                  <Button onClick={saveSystem} disabled={saving} className="bg-gold hover:bg-gold/90 text-black mt-2">
                    {saving ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <SettingsIcon className="h-4 w-4 mr-2" />}Save System Settings
                  </Button>
                </CardContent>
              </Card>

              {/* Feature Flags — super_admin only */}
              {isSuperAdmin && (
                <Card className="border-border">
                  <CardHeader>
                    <CardTitle className="font-display">Feature Visibility</CardTitle>
                    <p className="text-xs text-muted-foreground mt-1">Toggle off to hide these options for all users portal-wide.</p>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {([
                      { key: "feature_link_predecessor",    label: "Link Predecessor",             desc: "Show 'Link Predecessor' button on cases" },
                      { key: "feature_legacy_case",         label: "Existing / Legacy Case",       desc: "Show the 'Existing / Legacy' tab when creating a new case" },
                      { key: "feature_existing_client",     label: "Existing Client",              desc: "Show the 'Existing' tab when creating a new client" },
                      { key: "feature_lock_code_dropdowns", label: "Lock Code Dropdowns",          desc: "Prevent users from adding custom country/service codes from the project form (manage codes only via Settings)" },
                    ] as const).map(({ key, label, desc }) => (
                      <div key={key} className="flex items-center justify-between gap-4 py-1">
                        <div>
                          <p className="text-sm font-medium">{label}</p>
                          <p className="text-xs text-muted-foreground">{desc}</p>
                        </div>
                        <Toggle checked={flags[key]} onChange={(v) => setFlags((f) => ({ ...f, [key]: v }))} />
                      </div>
                    ))}
                    <Button
                      onClick={async () => {
                        setSavingFlags(true);
                        try { await api.updateFeatureFlags(flags); flashSaved(); } catch { setError("Failed to save flags."); }
                        finally { setSavingFlags(false); }
                      }}
                      disabled={savingFlags}
                      className="bg-gold hover:bg-gold/90 text-black mt-2"
                    >
                      {savingFlags ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Shield className="h-4 w-4 mr-2" />}Save Feature Flags
                    </Button>
                  </CardContent>
                </Card>
              )}

              {/* Renewal fee rates — super_admin only. A plain lookup the client
                  portal's "approve renewal" flow multiplies by years selected —
                  update these here whenever government/professional fees change,
                  no code deploy needed. */}
              {isSuperAdmin && (
                <Card className="border-border">
                  <CardHeader>
                    <CardTitle className="font-display">Renewal Fee Rates</CardTitle>
                    <p className="text-xs text-muted-foreground mt-1">
                      Used by the client portal's renewal approval flow: total payable = years selected × (government fee + professional fee).
                    </p>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">Government Fee (per year)</label>
                        <input type="number" min={0} value={renewalFees.government_fee}
                          onChange={(e) => setRenewalFees((p) => ({ ...p, government_fee: e.target.value }))}
                          className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">Professional Fee (per year)</label>
                        <input type="number" min={0} value={renewalFees.professional_fee}
                          onChange={(e) => setRenewalFees((p) => ({ ...p, professional_fee: e.target.value }))}
                          className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">Currency</label>
                        <input value={renewalFees.currency}
                          onChange={(e) => setRenewalFees((p) => ({ ...p, currency: e.target.value.toUpperCase() }))}
                          maxLength={10}
                          className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                    </div>
                    <Button
                      onClick={async () => {
                        setSavingRenewalFees(true);
                        try {
                          await api.updateRenewalFeeRates({
                            government_fee: parseFloat(renewalFees.government_fee) || 0,
                            professional_fee: parseFloat(renewalFees.professional_fee) || 0,
                            currency: renewalFees.currency,
                          });
                          flashSaved();
                        } catch { setError("Failed to save renewal fee rates."); }
                        finally { setSavingRenewalFees(false); }
                      }}
                      disabled={savingRenewalFees}
                      className="bg-gold hover:bg-gold/90 text-black"
                    >
                      {savingRenewalFees ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <SettingsIcon className="h-4 w-4 mr-2" />}Save Renewal Fee Rates
                    </Button>
                  </CardContent>
                </Card>
              )}

            </div>
          )}

          {tab === "codes" && canManageSystem && (
            <div className="space-y-6">
              {/* Service / Country sub-tabs — same underline-tab style as the Project Tracker's Circle A/B switcher */}
              <div className="flex items-center gap-0 border-b border-border bg-background">
                {(["service", "country"] as const).map((t) => (
                  <button key={t} onClick={() => setCodesSubTab(t)}
                    className={`px-6 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                      codesSubTab === t ? "border-gold text-gold" : "border-transparent text-muted-foreground hover:text-foreground"
                    }`}>
                    {t === "service" ? "Service Codes" : "Country Codes"}
                    <span className="ml-1.5 text-[10px] text-muted-foreground">({(t === "service" ? svcCodes : ctyCodes).length})</span>
                  </button>
                ))}
              </div>

              {codesSubTab === "service" ? (
                <CodeTable
                  type="service" items={svcCodes} setItems={setSvcCodes} usage={codeUsage.service}
                  newCode={newSvcCode} setNewCode={setNewSvcCode} newLabel={newSvcLabel} setNewLabel={setNewSvcLabel}
                  search={svcSearch} setSearch={setSvcSearch} addErr={svcAddErr} setAddErr={setSvcAddErr}
                  saving={savingDropdown === "service"} onSave={() => saveDropdown("service")}
                  onRenameClick={(code, label) => setRenameDialog({ type: "service", code, label })}
                  canManage={isSuperAdmin}
                />
              ) : (
                <CodeTable
                  type="country" items={ctyCodes} setItems={setCtyCodes} usage={codeUsage.country}
                  newCode={newCtyCode} setNewCode={setNewCtyCode} newLabel={newCtyLabel} setNewLabel={setNewCtyLabel}
                  search={ctySearch} setSearch={setCtySearch} addErr={ctyAddErr} setAddErr={setCtyAddErr}
                  saving={savingDropdown === "country"} onSave={() => saveDropdown("country")}
                  onRenameClick={(code, label) => setRenameDialog({ type: "country", code, label })}
                  canManage={isSuperAdmin}
                />
              )}
              {renameDialog && (
                <CodeRenameDialog
                  type={renameDialog.type}
                  oldCode={renameDialog.code}
                  oldLabel={renameDialog.label}
                  onClose={() => setRenameDialog(null)}
                  onRenamed={handleCodeRenamed}
                  onMerged={handleCodeMerged}
                />
              )}
            </div>
          )}

          {tab === "site" && isSuperAdmin && <SiteAdminPanel />}

          {tab === "projectTabs" && isSuperAdmin && (
            <div className="space-y-6">
              {/* Project Tabs — segments the Cases (/projects) list by service code */}
              <Card className="border-border">
                <CardHeader>
                  <CardTitle className="font-display">Project Tabs</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Splits the Cases list into tabs by service code. Each code can belong to at most one tab;
                    anything not explicitly assigned falls into "Others" automatically.
                  </p>
                </CardHeader>
                <CardContent className="space-y-3">
                  {tabAddErr && <p className="text-xs text-destructive">{tabAddErr}</p>}

                  <div className="space-y-3">
                    {(() => {
                      // A code assigned to any tab (including this one) is dropped from every
                      // combobox's options — structurally prevents assigning the same service
                      // code to two tabs, rather than just erroring after the fact.
                      const assignedElsewhere = new Set(projectTabs.flatMap((x) => x.codes));
                      return projectTabs.map((t, ti) => (
                      <div key={t.key} className="rounded-md border border-border p-3 space-y-2">
                        <div className="flex items-center gap-2">
                          <input
                            value={t.label}
                            onChange={(e) => setProjectTabs(projectTabs.map((x, i) => i === ti ? { ...x, label: e.target.value } : x))}
                            className="flex-1 h-8 rounded border border-border bg-background px-2 text-sm font-medium focus:outline-none focus:ring-1 focus:ring-gold"
                          />
                          {t.is_system ? (
                            <span className="text-[10px] uppercase tracking-wider text-muted-foreground px-2 py-1 rounded bg-muted shrink-0">System (auto)</span>
                          ) : (
                            <button
                              onClick={() => setProjectTabs(projectTabs.filter((_, i) => i !== ti))}
                              className="text-muted-foreground hover:text-destructive shrink-0" title="Delete tab">
                              <Trash2 className="h-3.5 w-3.5" />
                            </button>
                          )}
                        </div>

                        {t.is_system ? (
                          <p className="text-xs text-muted-foreground italic">
                            Catches every case whose service code isn't assigned to another tab — codes can't be added here directly.
                          </p>
                        ) : (
                          <>
                            <div className="flex flex-wrap gap-2">
                              {t.codes.length === 0 && <p className="text-xs text-muted-foreground italic">No codes assigned yet.</p>}
                              {t.codes.map((code) => (
                                <span key={code} className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-muted text-xs font-mono">
                                  <span className="font-semibold text-gold">{code}</span>
                                  <button
                                    onClick={() => setProjectTabs(projectTabs.map((x, i) => i === ti ? { ...x, codes: x.codes.filter((c) => c !== code) } : x))}
                                    className="ml-1 text-muted-foreground hover:text-destructive"><X className="h-3 w-3" /></button>
                                </span>
                              ))}
                            </div>
                            <div className="flex gap-2">
                              <TabCodeCombobox
                                options={svcCodes.filter((c) => !assignedElsewhere.has(c.code))}
                                onSelect={(code) => {
                                  setProjectTabs(projectTabs.map((x, i) => i === ti ? { ...x, codes: [...x.codes, code] } : x));
                                  setTabAddErr("");
                                }}
                                placeholder="Search & add a service code…"
                              />
                            </div>
                          </>
                        )}
                      </div>
                      ));
                    })()}
                  </div>

                  {/* Add new tab */}
                  <div className="border-t border-border pt-3 space-y-2">
                    <p className="text-xs font-medium text-muted-foreground">Add new tab</p>
                    <div className="flex gap-2">
                      <input
                        value={newTabLabel}
                        onChange={(e) => { setNewTabLabel(e.target.value); setTabAddErr(""); }}
                        placeholder="Tab name (e.g. Renewals)"
                        className="flex-1 h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
                      />
                      <Button size="sm" variant="outline" className="h-8 px-3 shrink-0"
                        onClick={() => {
                          const label = newTabLabel.trim();
                          if (!label) { setTabAddErr("Enter a tab name."); return; }
                          const baseKey = label.toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "") || "tab";
                          let key = baseKey, n = 2;
                          while (projectTabs.some((x) => x.key === key)) key = `${baseKey}_${n++}`;
                          // Keep the system "Others" tab last for a predictable, catch-all-at-the-end order.
                          const withoutSystem = projectTabs.filter((x) => !x.is_system);
                          const systemTabs = projectTabs.filter((x) => x.is_system);
                          setProjectTabs([...withoutSystem, { key, label, codes: [], is_system: false }, ...systemTabs]);
                          setNewTabLabel(""); setTabAddErr("");
                        }}>
                        <Plus className="h-3 w-3" />
                      </Button>
                    </div>
                  </div>

                  <Button size="sm" onClick={async () => {
                    setSavingTabs(true);
                    try {
                      const res = await api.updateProjectTabs(projectTabs);
                      setProjectTabs(res.tabs);
                      flashSaved();
                    } catch (e: any) { setError(e.message || "Failed to save project tabs."); }
                    finally { setSavingTabs(false); }
                  }} disabled={savingTabs} className="bg-gold hover:bg-gold/90 text-black">
                    {savingTabs ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : null}Save Project Tabs
                  </Button>
                </CardContent>
              </Card>
            </div>
          )}

          {tab === "finance" && isSuperAdmin && (
            <div className="space-y-6">
              <Card className="border-border">
                <CardHeader>
                  <CardTitle className="font-display">Fee Rate Card</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Government + professional fees by jurisdiction, service, and client entity tier — drives
                    auto-populated amounts on quotes/invoices and the renewal-approval flow. Editable after auto-fill either way.
                  </p>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="flex gap-1.5">
                    {FEE_JURISDICTIONS.map((j) => (
                      <button key={j} onClick={() => setFeeJurisdiction(j)}
                        className={`h-8 rounded-md border px-3 text-xs font-medium ${feeJurisdiction === j ? "border-gold bg-gold/10 text-foreground" : "border-border text-muted-foreground hover:border-gold/40"}`}>
                        {j}
                      </button>
                    ))}
                  </div>

                  {feeLoading ? (
                    <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
                  ) : (
                    <div className="overflow-x-auto rounded-md border border-border">
                      <table className="w-full min-w-[1100px] text-xs">
                        <thead className="bg-muted/40 text-left text-muted-foreground">
                          <tr>
                            <th className="p-2">Service</th><th className="p-2">Tier</th>
                            <th className="p-2">Yr from</th><th className="p-2">Yr to</th>
                            <th className="p-2">Validation Country</th>
                            <th className="p-2">Govt Fee</th><th className="p-2">Govt Ccy</th>
                            <th className="p-2">Prof Fee</th><th className="p-2">Prof Ccy</th><th className="p-2">Prof Max</th>
                            <th className="p-2">Charge Basis</th><th className="p-2">Notes</th>
                            <th className="p-2">Active</th><th className="p-2"></th>
                          </tr>
                        </thead>
                        <tbody>
                          {feeRows.map((row) => {
                            const dirty = !!feeEdits[row.id];
                            return (
                              <tr key={row.id} className={`border-t border-border ${dirty ? "bg-gold/5" : ""}`}>
                                <td className="p-1"><input value={feeField(row, "service_code")} onChange={(e) => setFeeField(row, "service_code", e.target.value.toUpperCase())} className="w-16 h-7 rounded border border-border bg-background px-1 font-mono" /></td>
                                <td className="p-1">
                                  <select value={feeField(row, "entity_tier") ?? ""} onChange={(e) => setFeeField(row, "entity_tier", e.target.value || null)} className="h-7 rounded border border-border bg-background px-1">
                                    <option value="">Universal</option>
                                    <option value="discounted">Discounted</option>
                                    <option value="standard">Standard</option>
                                  </select>
                                </td>
                                <td className="p-1"><input value={feeField(row, "year_from") ?? ""} onChange={(e) => setFeeField(row, "year_from", e.target.value)} className="w-14 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "year_to") ?? ""} onChange={(e) => setFeeField(row, "year_to", e.target.value)} className="w-14 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "validation_country") ?? ""} onChange={(e) => setFeeField(row, "validation_country", e.target.value)} className="w-28 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "govt_fee_amount") ?? ""} onChange={(e) => setFeeField(row, "govt_fee_amount", e.target.value)} className="w-20 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "govt_fee_currency") ?? ""} onChange={(e) => setFeeField(row, "govt_fee_currency", e.target.value.toUpperCase())} className="w-14 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "professional_fee_amount") ?? ""} onChange={(e) => setFeeField(row, "professional_fee_amount", e.target.value)} className="w-20 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "professional_fee_currency") ?? ""} onChange={(e) => setFeeField(row, "professional_fee_currency", e.target.value.toUpperCase())} className="w-14 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "professional_fee_max_amount") ?? ""} onChange={(e) => setFeeField(row, "professional_fee_max_amount", e.target.value)} className="w-20 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1">
                                  <select value={feeField(row, "professional_fee_charge_basis")} onChange={(e) => setFeeField(row, "professional_fee_charge_basis", e.target.value)} className="h-7 rounded border border-border bg-background px-1">
                                    <option value="per_unit">Per year</option>
                                    <option value="flat_per_transaction">Flat/txn</option>
                                  </select>
                                </td>
                                <td className="p-1"><input value={feeField(row, "notes") ?? ""} onChange={(e) => setFeeField(row, "notes", e.target.value)} className="w-40 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1 text-center"><Toggle checked={!!feeField(row, "is_active")} onChange={(v) => setFeeField(row, "is_active", v)} /></td>
                                <td className="p-1 whitespace-nowrap">
                                  {dirty && (
                                    <button onClick={() => saveFeeRow(row)} disabled={feeSavingId === row.id} className="mr-1 rounded p-1 text-green-600 hover:bg-green-500/10">
                                      {feeSavingId === row.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
                                    </button>
                                  )}
                                  <button onClick={() => deleteFeeRow(row.id)} className="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"><Trash2 className="h-3.5 w-3.5" /></button>
                                </td>
                              </tr>
                            );
                          })}
                          {feeRows.length === 0 && (
                            <tr><td colSpan={14} className="p-4 text-center text-muted-foreground">No rate rows for {feeJurisdiction} yet.</td></tr>
                          )}
                        </tbody>
                      </table>
                    </div>
                  )}

                  <div className="rounded-md border border-border bg-muted/20 p-3 space-y-2">
                    <p className="text-xs font-medium text-muted-foreground">Add a new {feeJurisdiction} row</p>
                    <div className="grid grid-cols-6 gap-2">
                      <input value={newFeeRow.service_code} onChange={(e) => setNewFeeRow((p) => ({ ...p, service_code: e.target.value.toUpperCase() }))} placeholder="Service code" className="h-8 rounded border border-border bg-background px-2 text-xs font-mono" />
                      <select value={newFeeRow.entity_tier} onChange={(e) => setNewFeeRow((p) => ({ ...p, entity_tier: e.target.value }))} className="h-8 rounded border border-border bg-background px-2 text-xs">
                        <option value="">Universal</option>
                        <option value="discounted">Discounted</option>
                        <option value="standard">Standard</option>
                      </select>
                      <input value={newFeeRow.year_from} onChange={(e) => setNewFeeRow((p) => ({ ...p, year_from: e.target.value }))} placeholder="Yr from" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                      <input value={newFeeRow.year_to} onChange={(e) => setNewFeeRow((p) => ({ ...p, year_to: e.target.value }))} placeholder="Yr to" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                      <input value={newFeeRow.govt_fee_amount} onChange={(e) => setNewFeeRow((p) => ({ ...p, govt_fee_amount: e.target.value }))} placeholder="Govt fee" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                      <input value={newFeeRow.professional_fee_amount} onChange={(e) => setNewFeeRow((p) => ({ ...p, professional_fee_amount: e.target.value }))} placeholder="Professional fee" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                    </div>
                    {feeAddErr && <p className="text-xs text-destructive">{feeAddErr}</p>}
                    <Button size="sm" onClick={addFeeRow}><Plus className="h-3 w-3 mr-1" />Add Row</Button>
                  </div>
                </CardContent>
              </Card>
            </div>
          )}

          {tab === "payroll" && canManagePayroll && (
            <div className="space-y-6">
              {/* Salary Component Template */}
              <Card className="border-border">
                <CardHeader>
                  <CardTitle className="font-display">Salary Component Template</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Applied to every employee from their own declared Base Pay/HRA ("Employee Field" rows) plus
                    whatever percentages/flat amounts you configure on top — replaces a hardcoded formula with one
                    you control. Exactly one active row must keep the code <code className="font-mono">BASIC</code> (Provident
                    Fund is computed from it, and every percentage ultimately chains back to it). Gross is simply
                    the sum of the active Earning rows now, so an Earning row can no longer be "a percentage of
                    Gross" — only a CTC Addon row can (NPS/Gratuity Provision, which never reduce Net Pay).
                  </p>
                </CardHeader>
                <CardContent className="space-y-4">
                  {componentsLoading ? (
                    <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
                  ) : (
                    <div className="overflow-x-auto rounded-md border border-border">
                      <table className="w-full min-w-[760px] text-xs">
                        <thead className="bg-muted/40 text-left text-muted-foreground">
                          <tr>
                            <th className="p-2">Order</th><th className="p-2">Name</th><th className="p-2">Code</th>
                            <th className="p-2">Category</th><th className="p-2">Type</th><th className="p-2">Value</th><th className="p-2">% of</th>
                            <th className="p-2">Active</th><th className="p-2"></th>
                          </tr>
                        </thead>
                        <tbody>
                          {components.map((row, idx) => {
                            const dirty = !!componentEdits[row.id];
                            const calcType = componentField(row, "calc_type");
                            return (
                              <tr key={row.id} className={`border-t border-border ${dirty ? "bg-gold/5" : ""}`}>
                                <td className="p-1 whitespace-nowrap">
                                  <button disabled={idx === 0} onClick={() => moveComponent(row.id, -1)} className="p-0.5 disabled:opacity-30 hover:text-gold"><ChevronUp className="h-3 w-3" /></button>
                                  <button disabled={idx === components.length - 1} onClick={() => moveComponent(row.id, 1)} className="p-0.5 disabled:opacity-30 hover:text-gold"><ChevronDown className="h-3 w-3" /></button>
                                </td>
                                <td className="p-1"><input value={componentField(row, "name")} onChange={(e) => setComponentField(row, "name", e.target.value)} className="w-32 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={componentField(row, "code")} onChange={(e) => setComponentField(row, "code", e.target.value.toUpperCase())} className="w-24 h-7 rounded border border-border bg-background px-1 font-mono" /></td>
                                <td className="p-1">
                                  <select value={componentField(row, "category") || "earning"} onChange={(e) => setComponentField(row, "category", e.target.value)} className="h-7 rounded border border-border bg-background px-1">
                                    <option value="earning">Earning</option>
                                    <option value="ctc_addon">CTC Addon</option>
                                  </select>
                                </td>
                                <td className="p-1">
                                  <select value={calcType} onChange={(e) => setComponentField(row, "calc_type", e.target.value)} className="h-7 rounded border border-border bg-background px-1">
                                    <option value="employee_field">Employee Field</option>
                                    <option value="flat">Flat ₹</option>
                                    <option value="percentage">Percentage</option>
                                    {componentField(row, "category") !== "ctc_addon" && <option value="remainder">Remainder</option>}
                                  </select>
                                </td>
                                <td className="p-1">
                                  {calcType !== "remainder" && calcType !== "employee_field" && (
                                    <input value={componentField(row, "value") ?? ""} onChange={(e) => setComponentField(row, "value", e.target.value)} className="w-20 h-7 rounded border border-border bg-background px-1" />
                                  )}
                                </td>
                                <td className="p-1">
                                  {calcType === "percentage" && (
                                    <select value={componentField(row, "percentage_of") ?? ""} onChange={(e) => setComponentField(row, "percentage_of", e.target.value)} className="h-7 rounded border border-border bg-background px-1">
                                      <option value="">Select…</option>
                                      {componentField(row, "category") === "ctc_addon" && <option value="_GROSS_">Gross</option>}
                                      {components.filter((c) => c.id !== row.id && c.sequence < row.sequence).map((c) => (
                                        <option key={c.code} value={c.code}>{c.name}</option>
                                      ))}
                                    </select>
                                  )}
                                  {calcType === "employee_field" && (
                                    <select value={componentField(row, "percentage_of") ?? ""} onChange={(e) => setComponentField(row, "percentage_of", e.target.value)} className="h-7 rounded border border-border bg-background px-1">
                                      <option value="">Select…</option>
                                      <option value="base_pay_monthly">Base Pay</option>
                                      <option value="hra_monthly">HRA</option>
                                    </select>
                                  )}
                                </td>
                                <td className="p-1 text-center"><Toggle checked={!!componentField(row, "active")} onChange={(v) => setComponentField(row, "active", v)} /></td>
                                <td className="p-1 whitespace-nowrap">
                                  {dirty && (
                                    <button onClick={() => saveComponentRow(row)} disabled={componentSavingId === row.id} className="mr-1 rounded p-1 text-green-600 hover:bg-green-500/10">
                                      {componentSavingId === row.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
                                    </button>
                                  )}
                                  <button onClick={() => deleteComponentRow(row.id)} className="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"><Trash2 className="h-3.5 w-3.5" /></button>
                                </td>
                              </tr>
                            );
                          })}
                          {components.length === 0 && (
                            <tr><td colSpan={9} className="p-4 text-center text-muted-foreground">No components yet.</td></tr>
                          )}
                        </tbody>
                      </table>
                    </div>
                  )}

                  {componentErr && <p className="text-xs text-destructive">{componentErr}</p>}

                  <div className="rounded-md border border-border bg-muted/20 p-3 space-y-2">
                    <p className="text-xs font-medium text-muted-foreground">Add a new component</p>
                    <div className="grid grid-cols-6 gap-2">
                      <input value={newComponent.name} onChange={(e) => setNewComponent((p) => ({ ...p, name: e.target.value }))} placeholder="Name" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                      <input value={newComponent.code} onChange={(e) => setNewComponent((p) => ({ ...p, code: e.target.value.toUpperCase() }))} placeholder="CODE" className="h-8 rounded border border-border bg-background px-2 text-xs font-mono" />
                      <select value={newComponent.category} onChange={(e) => setNewComponent((p) => ({ ...p, category: e.target.value, calc_type: e.target.value === "ctc_addon" && p.calc_type === "remainder" ? "flat" : p.calc_type }))} className="h-8 rounded border border-border bg-background px-2 text-xs">
                        <option value="earning">Earning</option>
                        <option value="ctc_addon">CTC Addon</option>
                      </select>
                      <select value={newComponent.calc_type} onChange={(e) => setNewComponent((p) => ({ ...p, calc_type: e.target.value }))} className="h-8 rounded border border-border bg-background px-2 text-xs">
                        <option value="employee_field">Employee Field</option>
                        <option value="flat">Flat ₹</option>
                        <option value="percentage">Percentage</option>
                        {newComponent.category !== "ctc_addon" && <option value="remainder">Remainder</option>}
                      </select>
                      <input value={newComponent.value} onChange={(e) => setNewComponent((p) => ({ ...p, value: e.target.value }))} placeholder="Value" disabled={newComponent.calc_type === "remainder" || newComponent.calc_type === "employee_field"}
                        className="h-8 rounded border border-border bg-background px-2 text-xs disabled:opacity-40" />
                      {newComponent.calc_type === "percentage" ? (
                        <select value={newComponent.percentage_of} onChange={(e) => setNewComponent((p) => ({ ...p, percentage_of: e.target.value }))} className="h-8 rounded border border-border bg-background px-2 text-xs">
                          <option value="">% of…</option>
                          {newComponent.category === "ctc_addon" && <option value="_GROSS_">Gross</option>}
                          {components.map((c) => <option key={c.code} value={c.code}>{c.name}</option>)}
                        </select>
                      ) : newComponent.calc_type === "employee_field" ? (
                        <select value={newComponent.percentage_of} onChange={(e) => setNewComponent((p) => ({ ...p, percentage_of: e.target.value }))} className="h-8 rounded border border-border bg-background px-2 text-xs">
                          <option value="">Field…</option>
                          <option value="base_pay_monthly">Base Pay</option>
                          <option value="hra_monthly">HRA</option>
                        </select>
                      ) : <div />}
                    </div>
                    {newComponentErr && <p className="text-xs text-destructive">{newComponentErr}</p>}
                    <Button size="sm" onClick={addComponentRow}><Plus className="h-3 w-3 mr-1" />Add Component</Button>
                  </div>

                  <div className="rounded-md border border-border bg-muted/10 p-3">
                    <div className="flex items-center gap-2 mb-2 flex-wrap">
                      <p className="text-xs font-medium text-muted-foreground">Preview for a sample Base Pay of ₹</p>
                      <input type="number" value={previewBasePay} onChange={(e) => setPreviewBasePay(e.target.value)}
                        className="w-28 h-7 rounded border border-border bg-background px-2 text-xs" />
                      <p className="text-xs font-medium text-muted-foreground">and HRA of ₹</p>
                      <input type="number" value={previewHra} onChange={(e) => setPreviewHra(e.target.value)}
                        className="w-28 h-7 rounded border border-border bg-background px-2 text-xs" />
                    </div>
                    <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs">
                      {computeComponentPreview(components, Number(previewBasePay) || 0, Number(previewHra) || 0).map((r) => (
                        <span key={r.name}><span className="text-foreground font-medium">{r.name}:</span> ₹{r.amount.toLocaleString("en-IN")}</span>
                      ))}
                      {components.length === 0 && <span className="text-muted-foreground">No active components to preview.</span>}
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* Reimbursement Types */}
              <Card className="border-border">
                <CardHeader>
                  <CardTitle className="font-display">Reimbursement Types</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Categories employees can submit claims against from the Payroll page — each optionally capped per calendar month.
                  </p>
                </CardHeader>
                <CardContent className="space-y-4">
                  {reimbTypesLoading ? (
                    <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
                  ) : (
                    <div className="overflow-x-auto rounded-md border border-border">
                      <table className="w-full min-w-[520px] text-xs">
                        <thead className="bg-muted/40 text-left text-muted-foreground">
                          <tr>
                            <th className="p-2">Name</th><th className="p-2">Code</th>
                            <th className="p-2">Monthly Cap (₹)</th><th className="p-2">Active</th><th className="p-2"></th>
                          </tr>
                        </thead>
                        <tbody>
                          {reimbTypes.map((row) => {
                            const dirty = !!reimbTypeEdits[row.id];
                            return (
                              <tr key={row.id} className={`border-t border-border ${dirty ? "bg-gold/5" : ""}`}>
                                <td className="p-1"><input value={reimbTypeField(row, "name")} onChange={(e) => setReimbTypeField(row, "name", e.target.value)} className="w-36 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={reimbTypeField(row, "code")} onChange={(e) => setReimbTypeField(row, "code", e.target.value.toUpperCase())} className="w-24 h-7 rounded border border-border bg-background px-1 font-mono" /></td>
                                <td className="p-1"><input value={reimbTypeField(row, "monthly_cap") ?? ""} onChange={(e) => setReimbTypeField(row, "monthly_cap", e.target.value)} placeholder="No cap" className="w-24 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1 text-center"><Toggle checked={!!reimbTypeField(row, "active")} onChange={(v) => setReimbTypeField(row, "active", v)} /></td>
                                <td className="p-1 whitespace-nowrap">
                                  {dirty && (
                                    <button onClick={() => saveReimbTypeRow(row)} disabled={reimbTypeSavingId === row.id} className="mr-1 rounded p-1 text-green-600 hover:bg-green-500/10">
                                      {reimbTypeSavingId === row.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
                                    </button>
                                  )}
                                  <button onClick={() => deleteReimbTypeRow(row.id)} className="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"><Trash2 className="h-3.5 w-3.5" /></button>
                                </td>
                              </tr>
                            );
                          })}
                          {reimbTypes.length === 0 && (
                            <tr><td colSpan={5} className="p-4 text-center text-muted-foreground">No reimbursement types yet.</td></tr>
                          )}
                        </tbody>
                      </table>
                    </div>
                  )}

                  <div className="rounded-md border border-border bg-muted/20 p-3 space-y-2">
                    <p className="text-xs font-medium text-muted-foreground">Add a new type</p>
                    <div className="grid grid-cols-3 gap-2">
                      <input value={newReimbType.name} onChange={(e) => setNewReimbType((p) => ({ ...p, name: e.target.value }))} placeholder="Name (e.g. Travel)" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                      <input value={newReimbType.code} onChange={(e) => setNewReimbType((p) => ({ ...p, code: e.target.value.toUpperCase() }))} placeholder="CODE" className="h-8 rounded border border-border bg-background px-2 text-xs font-mono" />
                      <input value={newReimbType.monthly_cap} onChange={(e) => setNewReimbType((p) => ({ ...p, monthly_cap: e.target.value }))} placeholder="Monthly cap (optional)" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                    </div>
                    {newReimbTypeErr && <p className="text-xs text-destructive">{newReimbTypeErr}</p>}
                    <Button size="sm" onClick={addReimbTypeRow}><Plus className="h-3 w-3 mr-1" />Add Type</Button>
                  </div>
                </CardContent>
              </Card>

              {/* Statutory Deductions */}
              <Card className="border-border">
                <CardHeader>
                  <CardTitle className="font-display">Statutory Deductions</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Professional Tax, PF, and ESI — editable here instead of requiring a code deploy. Disabling
                    PF/ESI computes them as ₹0 on every future payslip rather than omitting the line. Professional
                    Tax follows Karnataka law (this firm's legal entity/TAN/ROC registration is Karnataka/Bangalore,
                    so this one slab applies to every employee regardless of where they physically work).
                  </p>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 max-w-2xl">
                    <div>
                      <label className="block text-xs font-medium text-muted-foreground mb-1">PT Nil Threshold (₹ gross/month)</label>
                      <input type="number" min={0} value={ptNilThreshold} onChange={(e) => setPtNilThreshold(e.target.value)}
                        className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-muted-foreground mb-1">PT Amount (₹/month)</label>
                      <input type="number" min={0} value={ptMonthlyAmount} onChange={(e) => setPtMonthlyAmount(e.target.value)}
                        className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-muted-foreground mb-1">PT Amount in February (₹)</label>
                      <input type="number" min={0} value={ptFebruaryAmount} onChange={(e) => setPtFebruaryAmount(e.target.value)}
                        className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                  </div>
                  <div className="flex items-center gap-3">
                    <Toggle checked={pfEnabled} onChange={setPfEnabled} />
                    <span className="text-sm">Provident Fund (PF) enabled</span>
                  </div>
                  <div className="flex items-center gap-3">
                    <Toggle checked={esiEnabled} onChange={setEsiEnabled} />
                    <span className="text-sm">ESI enabled</span>
                  </div>
                  <div className="max-w-xs">
                    <label className="block text-xs font-medium text-muted-foreground mb-1">Pay Day of Month</label>
                    <input type="number" min={1} max={31} value={payDay} onChange={(e) => setPayDay(e.target.value)}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                  <Button
                    onClick={async () => {
                      setSavingPt(true);
                      try {
                        await api.updatePayrollSettings({
                          pt_nil_threshold: parseFloat(ptNilThreshold) || 0,
                          pt_monthly_amount: parseFloat(ptMonthlyAmount) || 0,
                          pt_february_amount: parseFloat(ptFebruaryAmount) || 0,
                          pf_enabled: pfEnabled,
                          esi_enabled: esiEnabled,
                          pay_day: parseInt(payDay, 10) || 1,
                        });
                        flashSaved();
                      }
                      catch { setError("Failed to save statutory deductions."); }
                      finally { setSavingPt(false); }
                    }}
                    disabled={savingPt}
                    className="bg-gold hover:bg-gold/90 text-black"
                  >
                    {savingPt ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <SettingsIcon className="h-4 w-4 mr-2" />}Save Statutory Deductions
                  </Button>
                </CardContent>
              </Card>

              {/* Payslip Letterhead */}
              <Card className="border-border">
                <CardHeader>
                  <CardTitle className="font-display">Payslip Letterhead</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Company name and address printed on every downloadable payslip PDF.
                  </p>
                </CardHeader>
                <CardContent className="space-y-3">
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <div className="text-[10px] text-muted-foreground mb-0.5">Company Name</div>
                      <input value={companyName} onChange={(e) => setCompanyName(e.target.value)} placeholder="e.g. Metayage Private Limited"
                        className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <div>
                      <div className="text-[10px] text-muted-foreground mb-0.5">Logo URL (optional)</div>
                      <input value={companyLogoUrl} onChange={(e) => setCompanyLogoUrl(e.target.value)} placeholder="https://…"
                        className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <div className="col-span-2">
                      <div className="text-[10px] text-muted-foreground mb-0.5">Registered Address</div>
                      <textarea value={companyAddress} onChange={(e) => setCompanyAddress(e.target.value)} rows={2}
                        className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <div>
                      <div className="text-[10px] text-muted-foreground mb-0.5">TAN (Tax Deduction Account Number)</div>
                      <p className="text-[10px] text-muted-foreground mb-0.5">Printed on the Form 24Q header — required before filing.</p>
                      <input value={companyTan} onChange={(e) => setCompanyTan(e.target.value.toUpperCase())} placeholder="e.g. BLRM12345N" maxLength={20}
                        className="w-full h-8 rounded border border-border bg-background px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <div>
                      <div className="text-[10px] text-muted-foreground mb-0.5">Form 16 Signatory Name</div>
                      <p className="text-[10px] text-muted-foreground mb-0.5">Printed in the Verification block of Form 16 Part B.</p>
                      <input value={signatoryName} onChange={(e) => setSignatoryName(e.target.value)} placeholder="e.g. Rajesh Kumar"
                        className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <div>
                      <div className="text-[10px] text-muted-foreground mb-0.5">Signatory Designation</div>
                      <p className="text-[10px] text-muted-foreground mb-0.5">e.g. Director, HR Head</p>
                      <input value={signatoryDesignation} onChange={(e) => setSignatoryDesignation(e.target.value)} placeholder="e.g. Director"
                        className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                  </div>
                  <Button
                    onClick={async () => {
                      setSavingLetterhead(true);
                      try {
                        await api.updatePayrollSettings({
                          pt_nil_threshold: parseFloat(ptNilThreshold) || 0,
                          pt_monthly_amount: parseFloat(ptMonthlyAmount) || 0,
                          pt_february_amount: parseFloat(ptFebruaryAmount) || 0,
                          company_name: companyName,
                          company_address: companyAddress,
                          company_logo_url: companyLogoUrl,
                          company_tan: companyTan,
                          signatory_name: signatoryName,
                          signatory_designation: signatoryDesignation,
                        });
                        flashSaved();
                      }
                      catch { setError("Failed to save letterhead."); }
                      finally { setSavingLetterhead(false); }
                    }}
                    disabled={savingLetterhead}
                    className="bg-gold hover:bg-gold/90 text-black"
                  >
                    {savingLetterhead ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <SettingsIcon className="h-4 w-4 mr-2" />}Save Letterhead
                  </Button>
                </CardContent>
              </Card>

              {/* Income Tax Slabs */}
              <Card className="border-border">
                <CardHeader>
                  <CardTitle className="font-display">Income Tax Slabs</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Drives the annual tax computation behind Form 16 Part B. Admin-editable since slabs change every
                    Union Budget — not hardcoded. Slabs JSON is an ordered list of <code className="font-mono">{"{upto, rate}"}</code> brackets; the last bracket's <code className="font-mono">upto</code> should be <code className="font-mono">null</code> for the unbounded top rate.
                  </p>
                </CardHeader>
                <CardContent className="space-y-4">
                  {taxSlabConfigsLoading ? (
                    <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
                  ) : (
                    <div className="space-y-4">
                      {taxSlabConfigs.map((row) => {
                        const dirty = !!taxSlabEdits[row.id];
                        const slabsText = taxSlabEdits[row.id]?.slabs_json_text ?? JSON.stringify(row.slabs_json, null, 2);
                        return (
                          <div key={row.id} className={`rounded-md border border-border p-3 space-y-2 ${dirty ? "bg-gold/5" : ""}`}>
                            <div className="flex items-center justify-between">
                              <p className="text-xs font-semibold">FY {row.financial_year}-{String(row.financial_year + 1).slice(-2)} — {row.regime === "old" ? "Old Regime" : "New Regime"}</p>
                              {dirty && (
                                <button onClick={() => saveTaxSlabRow(row)} disabled={taxSlabSavingId === row.id} className="rounded p-1 text-green-600 hover:bg-green-500/10">
                                  {taxSlabSavingId === row.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
                                </button>
                              )}
                            </div>
                            <div className="grid grid-cols-4 gap-2">
                              <div>
                                <label className="block text-[10px] text-muted-foreground mb-1">Standard Deduction</label>
                                <input value={taxSlabField(row, "standard_deduction")} onChange={(e) => setTaxSlabField(row, "standard_deduction", e.target.value)} className="w-full h-7 rounded border border-border bg-background px-1 text-xs" />
                              </div>
                              <div>
                                <label className="block text-[10px] text-muted-foreground mb-1">87A Rebate Limit</label>
                                <input value={taxSlabField(row, "rebate_87a_limit")} onChange={(e) => setTaxSlabField(row, "rebate_87a_limit", e.target.value)} className="w-full h-7 rounded border border-border bg-background px-1 text-xs" />
                              </div>
                              <div>
                                <label className="block text-[10px] text-muted-foreground mb-1">87A Rebate Amount</label>
                                <input value={taxSlabField(row, "rebate_87a_amount")} onChange={(e) => setTaxSlabField(row, "rebate_87a_amount", e.target.value)} className="w-full h-7 rounded border border-border bg-background px-1 text-xs" />
                              </div>
                              <div>
                                <label className="block text-[10px] text-muted-foreground mb-1">Cess Rate (%)</label>
                                <input value={taxSlabField(row, "cess_rate")} onChange={(e) => setTaxSlabField(row, "cess_rate", e.target.value)} className="w-full h-7 rounded border border-border bg-background px-1 text-xs" />
                              </div>
                            </div>
                            <div>
                              <label className="block text-[10px] text-muted-foreground mb-1">Slabs JSON</label>
                              <textarea value={slabsText} rows={6} onChange={(e) => setTaxSlabField(row, "slabs_json_text", e.target.value)}
                                className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs font-mono" />
                            </div>
                          </div>
                        );
                      })}
                      {taxSlabConfigs.length === 0 && <p className="text-xs text-muted-foreground text-center py-4">No tax slab configurations yet.</p>}
                    </div>
                  )}
                  {taxSlabErr && <p className="text-xs text-destructive">{taxSlabErr}</p>}
                </CardContent>
              </Card>

              {/* Proof of Investments Window */}
              <Card className="border-border">
                <CardHeader>
                  <CardTitle className="font-display">Proof of Investments Window</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Controls when employees can upload proof documents for their investment declaration (HRMS → Payroll → Proof of Investments). Without a window for a financial year, that tab tells employees it hasn't been configured yet.
                  </p>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="grid grid-cols-4 gap-2 items-end">
                    <div>
                      <label className="block text-[10px] text-muted-foreground mb-1">Financial Year</label>
                      <input value={poiWindowForm.financial_year} onChange={(e) => setPoiWindowForm((p) => ({ ...p, financial_year: e.target.value }))}
                        className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" placeholder="e.g. 2026" />
                    </div>
                    <div>
                      <label className="block text-[10px] text-muted-foreground mb-1">Deadline</label>
                      <input type="date" value={poiWindowForm.deadline_at} onChange={(e) => setPoiWindowForm((p) => ({ ...p, deadline_at: e.target.value }))}
                        className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <div>
                      <label className="block text-[10px] text-muted-foreground mb-1">Effective Payroll Month</label>
                      <input type="date" value={poiWindowForm.effective_payroll_month} onChange={(e) => setPoiWindowForm((p) => ({ ...p, effective_payroll_month: e.target.value }))}
                        className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                    <Button size="sm" className="bg-gold hover:bg-gold/90 text-black" disabled={poiWindowSaving} onClick={savePoiWindowRow}>
                      {poiWindowSaving ? <Loader2 className="h-3.5 w-3.5 animate-spin mr-1.5" /> : null}Save
                    </Button>
                  </div>
                  {poiWindowErr && <p className="text-xs text-destructive">{poiWindowErr}</p>}

                  {poiWindowsLoading ? (
                    <div className="flex justify-center py-6"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
                  ) : poiWindows.length === 0 ? (
                    <p className="text-xs text-muted-foreground text-center py-4">No windows configured yet.</p>
                  ) : (
                    <table className="w-full text-xs">
                      <thead className="text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr>
                          <th className="text-left py-1.5">Financial Year</th>
                          <th className="text-left py-1.5">Deadline</th>
                          <th className="text-left py-1.5">Effective Payroll Month</th>
                        </tr>
                      </thead>
                      <tbody>
                        {poiWindows.map((w: any) => (
                          <tr key={w.id} className="border-t border-border">
                            <td className="py-1.5">FY {w.financial_year}-{String(w.financial_year + 1).slice(-2)}</td>
                            <td className="py-1.5">{new Date(w.deadline_at).toLocaleDateString("en-IN")}</td>
                            <td className="py-1.5">{new Date(w.effective_payroll_month).toLocaleDateString("en-IN", { month: "long", year: "numeric" })}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </CardContent>
              </Card>
            </div>
          )}
        </div>
      </div>
      {/* ── Crop / Zoom Modal ── */}
      {cropSrc && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-2xl shadow-2xl w-full max-w-sm p-6 flex flex-col items-center gap-5">
            <div className="text-center">
              <h2 className="font-display text-base font-semibold">Adjust Photo</h2>
              <p className="text-xs text-muted-foreground mt-0.5">Drag to reposition · Scroll or use buttons to zoom</p>
            </div>

            {/* Canvas preview */}
            <div className="relative select-none" style={{ width: CANVAS_SIZE, height: CANVAS_SIZE }}>
              <canvas
                ref={cropCanvasRef}
                width={CANVAS_SIZE}
                height={CANVAS_SIZE}
                className="rounded-full cursor-grab active:cursor-grabbing"
                style={{ width: CANVAS_SIZE, height: CANVAS_SIZE }}
                onMouseDown={onCropMouseDown}
                onMouseMove={onCropMouseMove}
                onMouseUp={onCropMouseUp}
                onMouseLeave={onCropMouseUp}
                onWheel={onCropWheel}
              />
              {/* Hidden img element to hold the decoded image */}
              <img
                src={cropSrc}
                className="hidden"
                ref={(el) => {
                  cropImgRef.current = el;
                  if (el) el.onload = () => drawCrop();
                }}
                alt=""
              />
            </div>

            {/* Zoom controls */}
            <div className="flex items-center gap-3 w-full">
              <button
                onClick={() => setCropScale(s => Math.max(0.5, s - 0.1))}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-border hover:bg-muted transition-colors"
              >
                <ZoomOut className="h-4 w-4" />
              </button>
              <input
                type="range"
                min={50} max={400} step={1}
                value={Math.round(cropScale * 100)}
                onChange={e => setCropScale(Number(e.target.value) / 100)}
                className="flex-1 accent-[#C8971D] h-1.5 rounded-full cursor-pointer"
              />
              <button
                onClick={() => setCropScale(s => Math.min(4, s + 0.1))}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-border hover:bg-muted transition-colors"
              >
                <ZoomIn className="h-4 w-4" />
              </button>
              <button
                onClick={() => { setCropScale(1); setCropOffset({ x: 0, y: 0 }); }}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-border hover:bg-muted transition-colors"
                title="Reset"
              >
                <RotateCcw className="h-3.5 w-3.5" />
              </button>
            </div>

            <div className="text-xs text-muted-foreground">{Math.round(cropScale * 100)}%</div>

            {/* Action buttons */}
            <div className="flex gap-3 w-full">
              <Button
                variant="outline"
                className="flex-1"
                onClick={() => { setCropSrc(null); if (cropSrc) URL.revokeObjectURL(cropSrc); }}
              >
                Cancel
              </Button>
              <Button
                className="flex-1 bg-gold hover:bg-gold/90 text-black font-semibold"
                onClick={applyCrop}
                disabled={avatarUploading}
              >
                {avatarUploading ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                Apply
              </Button>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
