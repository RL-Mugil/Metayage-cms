import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState, useMemo, useCallback } from "react";
import {
  Plus, Search, LayoutGrid, List, Pencil, Trash2, X, ChevronDown, ChevronUp,
  Building2, User, AlertCircle, Globe, Loader2, Download, ChevronLeft, ChevronRight,
  ArrowUpDown, ArrowUp, ArrowDown, Upload, FileSpreadsheet, Link, CheckCircle2,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, downloadCSV } from "@/lib/api-client";
import { ClientDetailPanel } from "@/components/client-detail-panel";

// ── Constants ────────────────────────────────────────────────────────────────

const INDIAN_STATES = [
  "Andhra Pradesh","Arunachal Pradesh","Assam","Bihar","Chhattisgarh","Goa",
  "Gujarat","Haryana","Himachal Pradesh","Jharkhand","Karnataka","Kerala",
  "Madhya Pradesh","Maharashtra","Manipur","Meghalaya","Mizoram","Nagaland",
  "Odisha","Punjab","Rajasthan","Sikkim","Tamil Nadu","Telangana","Tripura",
  "Uttar Pradesh","Uttarakhand","West Bengal",
  "Andaman & Nicobar Islands","Chandigarh","Dadra & Nagar Haveli","Daman & Diu",
  "Delhi","Jammu & Kashmir","Ladakh","Lakshadweep","Puducherry",
];

const ORG_SUBTYPES = [
  "Private Limited (Pvt Ltd)","Public Limited","LLP","Partnership Firm",
  "Proprietorship / Sole Trader","One Person Company (OPC)","Trust / Society / NGO",
  "Government Body / PSU","Foreign Company","HUF","Section 8 Company",
];

const INDUSTRIES = [
  "Pharmaceuticals","Biotechnology","Information Technology","Semiconductors",
  "Automotive","Aerospace & Defence","Consumer Electronics","FMCG","Manufacturing",
  "Energy & Cleantech","Healthcare","Finance & Banking","Legal","Consulting",
  "Education","Media & Entertainment","Other",
];

const PAY_TERMS = ["Immediate","Net 15","Net 30","Net 45","Net 60","Advance","Retainer"];

const GST_META: Record<string,{label:string;color:string}> = {
  "B2B":          { label:"B2B",          color:"bg-blue-50 text-blue-700 border-blue-200"     },
  "B2C":          { label:"B2C",          color:"bg-green-50 text-green-700 border-green-200"   },
  "Export":       { label:"Export",       color:"bg-purple-50 text-purple-700 border-purple-200"},
  "Unregistered": { label:"Unregistered", color:"bg-amber-50 text-amber-700 border-amber-200"  },
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function computeGstType(nationality: string, hasGstin: boolean, clientType: string): string {
  if (nationality.toLowerCase() !== "india") return "Export";
  if (hasGstin) return "B2B";
  return clientType === "individual" ? "B2C" : "Unregistered";
}

function nextClientCode(existing: any[], nationality: string): string {
  // Strip optional M/Y suffix, sort on the 3-char base (letter + 2 digits)
  const bases = existing
    .map((c: any) => c.client_code)
    .filter((c) => c && /^[C-Z][0-9]{2}[MY]?$/.test(c))
    .map((c) => c.slice(0, 3))
    .sort();

  let base: string;
  if (!bases.length) {
    base = "C00";
  } else {
    const last   = bases[bases.length - 1];
    const letter = last[0];
    const num    = parseInt(last.slice(1), 10);
    if (num < 99) base = letter + String(num + 1).padStart(2, "0");
    else {
      const nxt = String.fromCharCode(letter.charCodeAt(0) + 1);
      base = (nxt > "Z" ? "C" : nxt) + "00";
    }
  }

  const suffix = nationality.toLowerCase() === "india" ? "M" : "Y";
  return base + suffix;
}

// ── Sub-components ────────────────────────────────────────────────────────────

function Section({ title, children, open: defaultOpen = true }: {
  title: string; children: React.ReactNode; open?: boolean;
}) {
  const [expanded, setExpanded] = useState(defaultOpen);
  return (
    <div className="border border-border rounded-lg">
      <button type="button" onClick={() => setExpanded(!expanded)}
        className={`w-full flex items-center justify-between px-4 py-2.5 bg-muted/30 text-xs font-semibold uppercase tracking-wider text-muted-foreground hover:bg-muted/50 ${expanded ? "rounded-t-lg" : "rounded-lg"}`}>
        {title}
        {expanded ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
      </button>
      {expanded && <div className="p-4 space-y-3">{children}</div>}
    </div>
  );
}

const Lbl = ({ children, req }: { children: React.ReactNode; req?: boolean }) => (
  <label className="block text-xs text-muted-foreground mb-1">
    {children}{req && <span className="text-destructive ml-0.5">*</span>}
  </label>
);

const ic = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";
const tc = "w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none";

// ── Types ─────────────────────────────────────────────────────────────────────

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}-${m}-${y}`;
}

type CType = "individual" | "organization";
interface CF {
  record_mode: "new" | "existing"; client_code: string;
  client_type: CType; nationality: string; has_gstin: boolean; gstin: string;
  legal_name: string; entity_subtype: string; fee_entity_tier: string; pan_number: string; cin_number: string;
  trade_name: string; website: string; contact_name: string; contact_email: string;
  phone: string; address: string; state: string;
  industry: string; payment_terms: string;
  account_manager_id: string; bank_name: string; bank_account: string; bank_ifsc: string;
  referred_by_code: string; accounts_person: string; remarks: string; status: string;
  // Reminders — UI-friendly text, transformed to reminder_cadence_override
  // (number[]) / payment_clearance_pattern ({lead_days}) on save. See
  // ReminderThresholdResolver::thresholdsFor()/Client.php.
  reminder_extra_days: string; payment_clearance_lead_days: string;
}
const BLANK: CF = {
  record_mode:"new", client_code:"",
  client_type:"organization", nationality:"India", has_gstin:false, gstin:"",
  legal_name:"", entity_subtype:"", fee_entity_tier:"", pan_number:"", cin_number:"", trade_name:"", website:"",
  contact_name:"", contact_email:"", phone:"", address:"", state:"",
  industry:"", payment_terms:"Net 30",
  account_manager_id:"", bank_name:"", bank_account:"", bank_ifsc:"",
  referred_by_code:"", accounts_person:"", remarks:"", status:"Active",
  reminder_extra_days:"", payment_clearance_lead_days:"",
};

// ── KPI Drill-down Modal ─────────────────────────────────────────────────────

type KpiKey = "total" | "active" | "b2b" | "b2c" | "export";

interface KpiDef {
  label: string; key: KpiKey; color: string;
  filterParams: Record<string, string>;
}

const KPI_DEFS: KpiDef[] = [
  { label: "Total Clients", key: "total",  color: "text-gold",        filterParams: {}                     },
  { label: "Active",        key: "active", color: "text-green-500",   filterParams: { status: "Active" }   },
  { label: "B2B (GST Reg)", key: "b2b",   color: "text-blue-500",    filterParams: { gst_type: "B2B" }    },
  { label: "B2C",           key: "b2c",   color: "text-emerald-500", filterParams: { gst_type: "B2C" }    },
  { label: "Export",        key: "export", color: "text-purple-500",  filterParams: { gst_type: "Export" } },
];

type SortField = "company_name" | "client_code" | "status" | "gst_type" | "date_onboarded";

function KpiModal({ kpi, onClose, onSelectClient }: { kpi: KpiDef; onClose: () => void; onSelectClient?: (id: number) => void }) {
  const [result, setResult]   = useState<any>({ data: [], total: 0, per_page: 25, current_page: 1, last_page: 1 });
  const [search, setSearch]   = useState("");
  const [loading, setLoading] = useState(true);
  const [page, setPage]       = useState(1);
  const [sortBy, setSortBy]   = useState<SortField>("company_name");
  const [sortDir, setSortDir] = useState<"asc"|"desc">("asc");

  const fetchPage = useCallback((p: number, sq: string, sb: SortField, sd: "asc"|"desc") => {
    setLoading(true);
    const params = new URLSearchParams({ page: String(p), per_page: "25", sort_by: sb, sort_dir: sd });
    if (sq) params.set("search", sq);
    Object.entries(kpi.filterParams).forEach(([k, v]) => params.set(k, v));
    api.getClients(params)
      .then(setResult)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [kpi]);

  useEffect(() => { fetchPage(1, "", "company_name", "asc"); }, [fetchPage]);

  const handleSearch = (v: string) => { setSearch(v); setPage(1); fetchPage(1, v, sortBy, sortDir); };
  const handleSort = (field: SortField) => {
    const nd = sortBy === field && sortDir === "asc" ? "desc" : "asc";
    setSortBy(field); setSortDir(nd); setPage(1); fetchPage(1, search, field, nd);
  };
  const handlePage = (p: number) => { setPage(p); fetchPage(p, search, sortBy, sortDir); };

  const SortIcon = ({ field }: { field: SortField }) => {
    if (sortBy !== field) return <ArrowUpDown className="h-3 w-3 ml-1 opacity-40" />;
    return sortDir === "asc" ? <ArrowUp className="h-3 w-3 ml-1 text-gold" /> : <ArrowDown className="h-3 w-3 ml-1 text-gold" />;
  };

  const th = "px-3 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-muted-foreground cursor-pointer hover:text-foreground select-none";

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-5xl max-h-[88vh] flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-border flex-shrink-0">
          <div>
            <h2 className="font-display text-base font-semibold">{kpi.label}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{result.total} clients</p>
          </div>
          <button onClick={onClose} className="p-1 rounded hover:bg-muted/40"><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>

        {/* Search */}
        <div className="px-5 py-3 border-b border-border flex-shrink-0">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
            <input
              value={search}
              onChange={(e) => handleSearch(e.target.value)}
              placeholder="Search name, code, PAN…"
              className="w-full h-8 rounded-md border border-border bg-background pl-8 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
            />
          </div>
        </div>

        {/* Table */}
        <div className="flex-1 overflow-auto">
          {loading ? (
            <div className="flex items-center justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-muted/40 sticky top-0">
                <tr>
                  <th className={th} onClick={() => handleSort("client_code")}>
                    <span className="flex items-center">Code<SortIcon field="client_code" /></span>
                  </th>
                  <th className={th} onClick={() => handleSort("company_name")}>
                    <span className="flex items-center">Legal Name<SortIcon field="company_name" /></span>
                  </th>
                  <th className="px-3 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Type</th>
                  <th className={th} onClick={() => handleSort("gst_type")}>
                    <span className="flex items-center">GST<SortIcon field="gst_type" /></span>
                  </th>
                  <th className="px-3 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Contact</th>
                  <th className="px-3 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">State</th>
                  <th className={th} onClick={() => handleSort("date_onboarded")}>
                    <span className="flex items-center">Onboarded<SortIcon field="date_onboarded" /></span>
                  </th>
                  <th className={th} onClick={() => handleSort("status")}>
                    <span className="flex items-center">Status<SortIcon field="status" /></span>
                  </th>
                  <th className="px-3 py-2.5 text-left text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Circle</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((c: any) => {
                  const gm = GST_META[c.gst_type ?? ""] ?? null;
                  const name = c.legal_name ?? c.company_name ?? "—";
                  return (
                    <tr key={c.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-3 py-2.5">
                        <button
                          onClick={() => onSelectClient?.(c.id)}
                          className="font-mono text-xs font-semibold text-gold hover:underline"
                        >
                          {c.client_code ?? "—"}
                        </button>
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="font-medium text-sm">{name}</div>
                        {c.pan_number && <div className="text-[10px] text-muted-foreground font-mono">PAN: {c.pan_number}</div>}
                      </td>
                      <td className="px-3 py-2.5 text-xs text-muted-foreground">
                        <span className="flex items-center gap-1">
                          {c.client_type === "individual" ? <User className="h-3 w-3" /> : <Building2 className="h-3 w-3" />}
                          {c.entity_subtype ?? (c.client_type === "individual" ? "Individual" : "Org")}
                        </span>
                      </td>
                      <td className="px-3 py-2.5">
                        {gm ? <Badge variant="outline" className={`text-[10px] ${gm.color}`}>{gm.label}</Badge> : "—"}
                      </td>
                      <td className="px-3 py-2.5 text-xs text-muted-foreground">
                        <div>{c.contact_name ?? c.contact_email ?? "—"}</div>
                        {c.phone && <div className="text-[10px]">{c.phone}</div>}
                      </td>
                      <td className="px-3 py-2.5 text-xs text-muted-foreground">{c.state ?? "—"}</td>
                      <td className="px-3 py-2.5 text-xs text-muted-foreground font-mono">{fmtDate(c.date_onboarded)}</td>
                      <td className="px-3 py-2.5">
                        <Badge variant="outline" className={c.status === "Active" ? "text-green-600 border-green-200 bg-green-50 text-[10px]" : "text-[10px]"}>
                          {c.status ?? "Active"}
                        </Badge>
                      </td>
                      <td className="px-3 py-2.5">
                        <button
                          title="Toggle circle (A / B)"
                          onClick={async (e) => {
                            e.stopPropagation();
                            const next = c.circle === "A" ? "B" : c.circle === "B" ? null : "A";
                            setResult((prev: any) => ({ ...prev, data: prev.data.map((x: any) => x.id === c.id ? { ...x, circle: next } : x) }));
                            try { await api.updateClient(c.id, { circle: next } as any); } catch {}
                          }}
                          className={`text-[11px] font-bold w-7 h-7 rounded-full border-2 flex items-center justify-center transition-colors ${
                            c.circle === "A" ? "bg-blue-600 text-white border-blue-600 hover:bg-blue-700" :
                            c.circle === "B" ? "bg-violet-600 text-white border-violet-600 hover:bg-violet-700" :
                            "border-dashed border-border text-muted-foreground hover:border-blue-400 hover:text-blue-500"
                          }`}
                        >
                          {c.circle ?? "—"}
                        </button>
                      </td>
                    </tr>
                  );
                })}
                {result.data.length === 0 && (
                  <tr><td colSpan={9} className="px-4 py-10 text-center text-sm text-muted-foreground">No clients found.</td></tr>
                )}
              </tbody>
            </table>
          )}
        </div>

        {/* Pagination */}
        {result.total > 0 && (
          <div className="flex items-center justify-between px-5 py-3 border-t border-border flex-shrink-0 text-xs text-muted-foreground">
            <span>
              Showing {((result.current_page - 1) * result.per_page) + 1}–{Math.min(result.current_page * result.per_page, result.total)} of {result.total}
            </span>
            <div className="flex items-center gap-1">
              <Button size="sm" variant="outline" className="h-7 w-7 p-0" disabled={result.current_page === 1} onClick={() => handlePage(result.current_page - 1)}>
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <span className="px-2">Page {result.current_page} of {result.last_page}</span>
              <Button size="sm" variant="outline" className="h-7 w-7 p-0" disabled={!result.has_more} onClick={() => handlePage(result.current_page + 1)}>
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// ── Import ────────────────────────────────────────────────────────────────────

const IMPORT_HEADERS = [
  "legal_name","client_type","nationality","has_gstin","gstin","pan_number","cin_number",
  "entity_subtype","trade_name","website","contact_name","contact_email","phone",
  "address","state","industry","payment_terms","bank_name","status","remarks",
];
const IMPORT_EXAMPLE = [
  "Acme Corp Pvt Ltd","organization","India","true","27AAPFU0939F1ZV","AAPFU0939F",
  "U12345MH2020PTC123456","Private Limited (Pvt Ltd)","Acme","https://acme.com",
  "John Doe","john@acme.com","+91 98765 43210","123 Main St Mumbai 400001",
  "Maharashtra","Information Technology","Net 30","HDFC Bank","Active","Optional notes",
];

function downloadImportTemplate() {
  const csv = [IMPORT_HEADERS.join(","), IMPORT_EXAMPLE.join(",")].join("\n");
  const blob = new Blob([csv], { type: "text/csv" });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement("a");
  a.href = url; a.download = "clients-import-template.csv"; a.click();
  URL.revokeObjectURL(url);
}

type ImportTab = "file" | "sheet";
interface ImportResult { imported: number; skipped: number; errors: string[] }
interface ImportDuplicate { line: number; name: string; reason: string }

function ImportModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const [tab, setTab]             = useState<ImportTab>("file");
  const [file, setFile]           = useState<File | null>(null);
  const [sheetUrl, setSheetUrl]   = useState("");
  const [busy, setBusy]           = useState(false);
  const [result, setResult]       = useState<ImportResult | null>(null);
  const [duplicates, setDuplicates] = useState<ImportDuplicate[] | null>(null);
  const [err, setErr]             = useState("");

  function buildFormData(skipDuplicates?: boolean) {
    const fd = new FormData();
    if (tab === "file") { if (file) fd.append("file", file); }
    else { fd.append("google_sheet_url", sheetUrl.trim()); }
    if (skipDuplicates !== undefined) fd.append("skip_duplicates", String(skipDuplicates));
    return fd;
  }

  async function handleImport(skipDuplicates?: boolean) {
    setErr(""); setBusy(true);
    try {
      if (tab === "file" && !file) { setErr("Please select a file."); setBusy(false); return; }
      if (tab === "sheet" && !sheetUrl.trim()) { setErr("Please enter a Google Sheet URL."); setBusy(false); return; }
      const res = await api.importClients(buildFormData(skipDuplicates)) as any;
      if (res.requires_confirmation) {
        setDuplicates(res.duplicates);
      } else {
        setResult(res);
        onDone();
      }
    } catch (e: any) {
      setErr(e.message || "Import failed.");
    } finally { setBusy(false); }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-lg flex flex-col">

        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-border">
          <div>
            <h2 className="font-display text-lg font-semibold">Import Clients</h2>
            <p className="text-xs text-muted-foreground mt-0.5">Add multiple clients at once from a file or Google Sheet</p>
          </div>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>

        {result ? (
          /* ── Result screen ── */
          <div className="px-6 py-6 space-y-4">
            <div className="flex items-center gap-3 p-4 rounded-lg bg-green-50 border border-green-200">
              <CheckCircle2 className="h-6 w-6 text-green-600 flex-shrink-0" />
              <div>
                <div className="font-semibold text-green-800">Import Complete</div>
                <div className="text-sm text-green-700 mt-0.5">
                  {result.imported} client{result.imported !== 1 ? "s" : ""} imported
                  {result.skipped > 0 && <>, {result.skipped} row{result.skipped !== 1 ? "s" : ""} skipped</>}
                </div>
              </div>
            </div>
            {result.errors.length > 0 && (
              <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 max-h-36 overflow-y-auto">
                <div className="text-xs font-semibold text-amber-800 mb-1">Row errors:</div>
                {result.errors.map((e, i) => <div key={i} className="text-xs text-amber-700">{e}</div>)}
              </div>
            )}
            <Button className="w-full" variant="outline" onClick={onClose}>Close</Button>
          </div>
        ) : duplicates ? (
          /* ── Duplicate confirmation ── */
          <div className="px-6 py-4 space-y-4">
            <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4">
              <div className="flex items-start gap-3">
                <AlertCircle className="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" />
                <div>
                  <p className="text-sm font-semibold text-amber-700">
                    {duplicates.length} duplicate {duplicates.length === 1 ? "client" : "clients"} detected
                  </p>
                  <p className="text-xs text-amber-600 mt-0.5">These names already exist. Choose how to proceed:</p>
                </div>
              </div>
            </div>
            <div className="rounded-lg border border-border max-h-48 overflow-y-auto">
              <table className="w-full text-xs">
                <thead className="bg-muted/40 sticky top-0">
                  <tr>
                    <th className="text-left px-3 py-2 text-muted-foreground font-medium">Row</th>
                    <th className="text-left px-3 py-2 text-muted-foreground font-medium">Client Name</th>
                    <th className="text-left px-3 py-2 text-muted-foreground font-medium">Reason</th>
                  </tr>
                </thead>
                <tbody>
                  {duplicates.map((d, i) => (
                    <tr key={i} className="border-t border-border">
                      <td className="px-3 py-2 font-mono text-muted-foreground">{d.line}</td>
                      <td className="px-3 py-2 font-semibold">{d.name}</td>
                      <td className="px-3 py-2 text-amber-600">{d.reason}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {err && <div className="text-xs text-destructive">{err}</div>}
            <div className="flex gap-2">
              <Button className="flex-1 bg-gold hover:bg-gold/90 text-black" disabled={busy}
                onClick={() => handleImport(true)}>
                {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : "Skip duplicates"}
              </Button>
              <Button variant="outline" className="flex-1" disabled={busy}
                onClick={() => handleImport(false)}>
                {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : "Import all anyway"}
              </Button>
            </div>
            <button className="text-xs text-muted-foreground underline w-full text-center"
              onClick={() => { setDuplicates(null); setErr(""); }}>
              ← Back
            </button>
          </div>
        ) : (
          /* ── Import form ── */
          <div className="px-6 py-4 space-y-4">
            {/* Tab toggle */}
            <div className="flex gap-1 p-1 bg-muted/30 rounded-lg border border-border">
              {([["file", "Upload File", FileSpreadsheet], ["sheet", "Google Sheet", Link]] as [ImportTab, string, any][]).map(([key, label, Icon]) => (
                <button key={key} onClick={() => { setTab(key as ImportTab); setErr(""); }}
                  className={`flex-1 flex items-center justify-center gap-2 py-2 rounded-md text-sm font-medium transition-all
                    ${tab === key ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"}`}>
                  <Icon className="h-4 w-4" />{label}
                </button>
              ))}
            </div>

            {tab === "file" ? (
              <div className="space-y-3">
                <div className={`border-2 border-dashed rounded-lg p-6 text-center transition-colors ${file ? "border-gold/60 bg-gold/5" : "border-border hover:border-gold/40"}`}>
                  <Upload className="h-8 w-8 text-muted-foreground mx-auto mb-2" />
                  <p className="text-sm text-muted-foreground mb-1">
                    {file ? <span className="font-medium text-foreground">{file.name}</span> : "Click to select or drag and drop"}
                  </p>
                  <p className="text-xs text-muted-foreground mb-3">.csv or .xlsx, max 5 MB</p>
                  <input type="file" accept=".csv,.xlsx,.xls" className="hidden" id="import-file-input"
                    onChange={(e) => { setFile(e.target.files?.[0] ?? null); setErr(""); }} />
                  <label htmlFor="import-file-input">
                    <Button variant="outline" size="sm" type="button" onClick={() => document.getElementById("import-file-input")?.click()}>
                      Browse File
                    </Button>
                  </label>
                </div>
                <button onClick={downloadImportTemplate}
                  className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-gold transition-colors">
                  <Download className="h-3 w-3" />Download template (.csv)
                </button>
              </div>
            ) : (
              <div className="space-y-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1.5">Google Sheet URL</label>
                  <input
                    value={sheetUrl}
                    onChange={(e) => { setSheetUrl(e.target.value); setErr(""); }}
                    placeholder="https://docs.google.com/spreadsheets/d/…"
                    className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                  />
                </div>
                <div className="rounded-lg bg-blue-50 border border-blue-200 p-3 text-xs text-blue-700 space-y-1">
                  <div className="font-semibold">Requirements:</div>
                  <div>• Sheet must be set to "Anyone with the link can view"</div>
                  <div>• First row must be column headers (e.g. <code className="font-mono">legal_name, client_type, ...</code>)</div>
                  <div>• <code className="font-mono">legal_name</code> column is required</div>
                </div>
                <button onClick={downloadImportTemplate}
                  className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-gold transition-colors">
                  <Download className="h-3 w-3" />Download template to see expected columns
                </button>
              </div>
            )}

            {err && (
              <div className="flex items-center gap-2 p-3 rounded-lg bg-destructive/10 border border-destructive/20 text-sm text-destructive">
                <AlertCircle className="h-4 w-4 flex-shrink-0" />{err}
              </div>
            )}
          </div>
        )}

        {/* Footer — only shown on the upload form screen */}
        {!result && !duplicates && (
          <div className="flex gap-2 px-6 py-4 border-t border-border">
            <Button variant="outline" onClick={onClose} className="flex-1">Cancel</Button>
            <Button className="flex-1 bg-gold hover:bg-gold/90 text-black" onClick={() => handleImport()} disabled={busy}>
              {busy ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Checking…</> : <><Upload className="h-4 w-4 mr-2" />Import</>}
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}

// ── Main ──────────────────────────────────────────────────────────────────────

export default function Clients() {
  const { props: pageProps } = usePage() as any;
  const featExistingClient = (pageProps.systemSettings?.feature_existing_client) ?? true;

  const [paginatedResult, setPaginatedResult] = useState<any>({ data: [], total: 0, per_page: 25, current_page: 1, last_page: 1, has_more: false });
  const [stats, setStats]       = useState<Record<string, number>>({ total: 0, active: 0, b2b: 0, b2c: 0, export: 0 });
  const [users, setUsers]       = useState<any[]>([]);
  const [loading, setLoading]   = useState(true);
  const [search, setSearch]     = useState("");
  const [view, setView]         = useState<"list"|"grid">("list");
  const [statusF, setStatusF]   = useState("All");
  const [kpiModal, setKpiModal] = useState<KpiDef | null>(null);

  const [showImport, setShowImport] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [editC, setEditC]       = useState<any>(null);
  const [form, setForm]         = useState<CF>(BLANK);
  const [saving, setSaving]     = useState(false);
  const [fErr, setFErr]         = useState("");
  const [delTarget, setDelTarget] = useState<any>(null);
  const [deleting, setDeleting] = useState(false);
  const [refQ, setRefQ]         = useState("");
  const [detailClientId, setDetailClientId] = useState<number | null>(null);

  const fetchClients = (p: number = 1) => {
    const params = new URLSearchParams();
    params.set('page', String(p));
    if (search) params.set('search', search);
    if (statusF !== 'All') params.set('status', statusF);
    api.getClients(params)
      .then(setPaginatedResult)
      .catch(() => setLoading(false))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    api.getClientStats().then(setStats).catch(() => {});
    const params = new URLSearchParams(window.location.search);
    const openId = params.get("open");
    if (openId) {
      api.getClient(openId).then((c) => { if (c) openEdit(c); }).catch(() => {});
      params.delete("open");
      window.history.replaceState({}, "", window.location.pathname + (params.toString() ? "?" + params.toString() : ""));
    }
  }, []);

  useEffect(() => {
    setLoading(true);
    fetchClients(1);
    api.getUsers().then(setUsers).catch(() => {});
  }, [search, statusF]);

  const isOrg    = form.client_type === "organization";
  const isIndian = form.nationality.toLowerCase() === "india";
  const gstType  = computeGstType(form.nationality, form.has_gstin, form.client_type);
  const previewCode = editC?.client_code ?? nextClientCode(paginatedResult.data, form.nationality);

  const set = (f: keyof CF, v: any) => setForm((p) => ({ ...p, [f]: v }));

  const refOptions = useMemo(() =>
    paginatedResult.data
      .filter((c: any) => c.client_code && /^[C-Z][0-9]{2}[MY]?$/.test(c.client_code))
      .filter((c: any) => !refQ || (c.client_code+"|"+(c.legal_name??c.company_name??"")).toLowerCase().includes(refQ.toLowerCase()))
      .slice(0, 10),
    [paginatedResult.data, refQ]);

  function openCreate() { setForm(BLANK); setEditC(null); setFErr(""); setShowForm(true); }

  function openEdit(c: any) {
    setForm({
      record_mode:      "existing",
      client_code:      c.client_code        ?? "",
      client_type:      c.client_type       ?? "organization",
      nationality:      c.nationality       ?? "India",
      has_gstin:        !!c.has_gstin,
      gstin:            c.gstin             ?? "",
      legal_name:       c.legal_name        ?? c.company_name ?? "",
      entity_subtype:   c.entity_subtype    ?? "",
      fee_entity_tier:  c.fee_entity_tier   ?? "",
      pan_number:       c.pan_number        ?? "",
      cin_number:       c.cin_number        ?? "",
      trade_name:       c.trade_name        ?? "",
      website:          c.website           ?? "",
      contact_name:     c.contact_name      ?? "",
      contact_email:    c.contact_email     ?? "",
      phone:            c.phone             ?? "",
      address:          c.address           ?? "",
      state:            c.state             ?? "",
      industry:         c.industry          ?? "",
      payment_terms:    c.payment_terms     ?? "Net 30",
      account_manager_id: c.account_manager_id ? String(c.account_manager_id) : "",
      bank_name:        c.bank_name         ?? "",
      bank_account:     c.bank_account      ?? "",
      bank_ifsc:        c.bank_ifsc         ?? "",
      referred_by_code: c.referred_by_code  ?? "",
      accounts_person:  c.accounts_person   ?? "",
      remarks:          c.remarks           ?? "",
      status:           c.status            ?? "Active",
      reminder_extra_days: Array.isArray(c.reminder_cadence_override) ? c.reminder_cadence_override.join(", ") : "",
      payment_clearance_lead_days: c.payment_clearance_pattern?.lead_days != null ? String(c.payment_clearance_pattern.lead_days) : "",
    });
    setEditC(c); setFErr(""); setShowForm(true);
  }

  async function handleSave() {
    if (!form.legal_name.trim()) { setFErr("Legal name is required."); return; }
    if (!editC && form.record_mode === "existing" && !form.client_code.trim()) {
      setFErr("Existing client code is required for legacy clients."); return;
    }
    setSaving(true); setFErr("");
    try {
      const { reminder_extra_days, payment_clearance_lead_days, ...formRest } = form;
      const reminderDays = reminder_extra_days
        .split(",").map((s) => parseInt(s.trim(), 10)).filter((n) => Number.isInteger(n) && n >= 0);
      const clearanceLead = payment_clearance_lead_days.trim() ? parseInt(payment_clearance_lead_days, 10) : null;

      const payload = {
        ...formRest,
        client_code: form.client_code.trim().toUpperCase() || null,
        account_manager_id: form.account_manager_id ? parseInt(form.account_manager_id) : null,
        reminder_cadence_override: reminderDays.length ? reminderDays : null,
        payment_clearance_pattern: clearanceLead != null && !Number.isNaN(clearanceLead) ? { lead_days: clearanceLead } : null,
      };
      if (editC) {
        const { record_mode, client_code, ...updatePayload } = payload;
        await api.updateClient(editC.id, updatePayload as any);
      } else {
        await api.createClient(payload as any);
      }
      setShowForm(false);
      fetchClients(paginatedResult.current_page);
    } catch (e: any) {
      setFErr(e.message ?? "Failed to save client.");
    } finally { setSaving(false); }
  }

  async function handleDelete() {
    if (!delTarget) return;
    setDeleting(true);
    try {
      await api.deleteClient(delTarget.id);
      setDelTarget(null);
      fetchClients(paginatedResult.current_page);
    } finally { setDeleting(false); }
  }

  const dname = (c: any) => c.legal_name ?? c.company_name ?? "—";
  const clients = paginatedResult.data;

  return (
    <AppLayout>
      <Head title="Clients" />
      <PageHeader eyebrow="CRM" title="Clients"
        description="Client portfolio, GST classification, and contact management."
        actions={
          <>
            <Button variant="outline" size="sm" onClick={() => setShowImport(true)}>
              <Upload className="h-4 w-4 mr-2" />Import
            </Button>
            <Button variant="outline" size="sm" onClick={() =>
              downloadCSV(`clients-${new Date().toISOString().slice(0,10)}.csv`,
                clients.map((c: any) => ({
                  Code: c.client_code ?? "", "Legal Name": dname(c),
                  Type: c.client_type ?? "", "GST Type": c.gst_type ?? "",
                  GSTIN: c.gstin ?? "", PAN: c.pan_number ?? "",
                  Phone: c.phone ?? "", Email: c.contact_email ?? "",
                  State: c.state ?? "", "Onboarded": fmtDate(c.date_onboarded), Status: c.status ?? "",
                }))
              )}>
              <Download className="h-4 w-4 mr-2" />Export
            </Button>
            <Button className="bg-gold hover:bg-gold/90 text-black" onClick={openCreate}>
              <Plus className="h-4 w-4 mr-2" />New Client
            </Button>
          </>
        }
      />

      <div className="px-8 py-6 space-y-4">
        {/* Stats — click any card to drill down */}
        <div className="grid grid-cols-2 lg:grid-cols-5 gap-3">
          {KPI_DEFS.map((kpi) => (
            <Card
              key={kpi.key}
              className="border-border cursor-pointer hover:border-gold/40 hover:shadow-md transition-all group"
              onClick={() => setKpiModal(kpi)}
            >
              <CardContent className="p-3 text-center">
                <div className={`text-2xl font-bold ${kpi.color}`}>{stats[kpi.key] ?? 0}</div>
                <div className="text-xs text-muted-foreground mt-0.5">{kpi.label}</div>
                <div className="text-[10px] text-muted-foreground/50 mt-1 group-hover:text-gold/60 transition-colors">Click to view →</div>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* Toolbar */}
        <div className="flex flex-wrap items-center gap-3">
          <div className="relative flex-1 min-w-[200px]">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
            <Input className="pl-9 h-9" placeholder="Search name, code, PAN…" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
          <div className="flex gap-1">
            {["All","Active","Inactive","Prospect"].map((s) => (
              <button key={s} onClick={() => setStatusF(s)}
                className={`px-3 h-9 text-xs rounded-md border transition-colors
                  ${statusF===s?"bg-gold/10 border-gold text-gold font-semibold":"border-border text-muted-foreground hover:bg-muted/40"}`}>
                {s}
              </button>
            ))}
          </div>
          <div className="flex rounded-md border border-border overflow-hidden">
            <button onClick={()=>setView("list")} className={`px-2.5 py-1.5 ${view==="list"?"bg-gold/10 text-gold":"text-muted-foreground hover:bg-muted/40"}`}>
              <List className="h-4 w-4" />
            </button>
            <button onClick={()=>setView("grid")} className={`px-2.5 py-1.5 ${view==="grid"?"bg-gold/10 text-gold":"text-muted-foreground hover:bg-muted/40"}`}>
              <LayoutGrid className="h-4 w-4" />
            </button>
          </div>
        </div>

        {/* List view */}
        {view === "list" && (
          <>
            <Card className="border-border">
              <CardContent className="p-0">
                <table className="w-full text-sm">
                  <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-4 py-3 text-left">Code</th>
                      <th className="px-4 py-3 text-left">Legal Name</th>
                      <th className="px-4 py-3 text-left">Type</th>
                      <th className="px-4 py-3 text-left">GST</th>
                      <th className="px-4 py-3 text-left">Contact</th>
                      <th className="px-4 py-3 text-left">State</th>
                      <th className="px-4 py-3 text-left">Onboarded</th>
                      <th className="px-4 py-3 text-left">Status</th>
                      <th className="px-4 py-3 text-left">Circle</th>
                      <th className="px-4 py-3 text-left">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {loading && (
                      <tr>
                        <td colSpan={9} className="px-4 py-12 text-center">
                          <Loader2 className="h-6 w-6 animate-spin text-gold mx-auto" />
                        </td>
                      </tr>
                    )}
                    {!loading && clients.map((c: any) => {
                      const gm = GST_META[c.gst_type ?? ""] ?? null;
                      return (
                        <tr key={c.id} className="border-t border-border hover:bg-muted/20">
                          <td className="px-4 py-3">
                            <button
                              onClick={() => setDetailClientId(c.id)}
                              className="font-mono text-xs font-semibold text-gold hover:underline"
                            >{c.client_code ?? "—"}</button>
                          </td>
                          <td className="px-4 py-3">
                            <div className="font-medium">{dname(c)}</div>
                            {c.pan_number && <div className="text-xs text-muted-foreground font-mono">PAN: {c.pan_number}</div>}
                          </td>
                          <td className="px-4 py-3">
                            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                              {c.client_type==="individual"?<User className="h-3 w-3"/>:<Building2 className="h-3 w-3"/>}
                              {c.entity_subtype ?? (c.client_type==="individual"?"Individual":"Organization")}
                            </span>
                          </td>
                          <td className="px-4 py-3">
                            {gm ? <Badge variant="outline" className={`text-[10px] ${gm.color}`}>{gm.label}</Badge> : "—"}
                          </td>
                          <td className="px-4 py-3 text-xs text-muted-foreground">
                            <div>{c.contact_name ?? c.contact_email ?? "—"}</div>
                            {c.phone && <div>{c.phone}</div>}
                          </td>
                          <td className="px-4 py-3 text-xs text-muted-foreground">{c.state ?? "—"}</td>
                          <td className="px-4 py-3 text-xs text-muted-foreground font-mono">{fmtDate(c.date_onboarded)}</td>
                          <td className="px-4 py-3">
                            <Badge variant="outline" className={c.status==="Active"?"text-green-600 border-green-200 bg-green-50 text-[10px]":"text-[10px]"}>
                              {c.status ?? "Active"}
                            </Badge>
                          </td>
                          <td className="px-4 py-3">
                            <button
                              title="Toggle circle (A / B)"
                              onClick={async (e) => {
                                e.stopPropagation();
                                const next = c.circle === "A" ? "B" : c.circle === "B" ? null : "A";
                                setPaginatedResult((prev: any) => ({ ...prev, data: prev.data.map((x: any) => x.id === c.id ? { ...x, circle: next } : x) }));
                                try { await api.updateClient(c.id, { circle: next } as any); } catch {}
                              }}
                              className={`text-[11px] font-bold w-7 h-7 rounded-full border-2 flex items-center justify-center transition-colors ${
                                c.circle === "A" ? "bg-blue-600 text-white border-blue-600 hover:bg-blue-700" :
                                c.circle === "B" ? "bg-violet-600 text-white border-violet-600 hover:bg-violet-700" :
                                "border-dashed border-border text-muted-foreground hover:border-blue-400 hover:text-blue-500"
                              }`}
                            >
                              {c.circle ?? "—"}
                            </button>
                          </td>
                          <td className="px-4 py-3">
                            <div className="flex gap-1">
                              <Button size="sm" variant="outline" className="h-7 w-7 p-0" onClick={()=>openEdit(c)}><Pencil className="h-3 w-3"/></Button>
                              <Button size="sm" variant="outline" className="h-7 w-7 p-0 text-destructive border-destructive/30" onClick={()=>setDelTarget(c)}>
                                <Trash2 className="h-3 w-3"/>
                              </Button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                    {!loading && clients.length===0 && <tr><td colSpan={10} className="px-4 py-10 text-center text-sm text-muted-foreground">No clients found.</td></tr>}
                  </tbody>
                </table>
              </CardContent>
            </Card>
            {paginatedResult.total > 0 && (
              <div className="mt-4 flex items-center justify-between text-xs text-muted-foreground">
                <span>Showing {((paginatedResult.current_page - 1) * paginatedResult.per_page) + 1}–{Math.min(paginatedResult.current_page * paginatedResult.per_page, paginatedResult.total)} of {paginatedResult.total}</span>
                <div className="flex items-center gap-1">
                  <Button variant="outline" size="sm" disabled={paginatedResult.current_page === 1} onClick={() => fetchClients(paginatedResult.current_page - 1)}>Prev</Button>
                  <span>Page {paginatedResult.current_page} of {paginatedResult.last_page}</span>
                  <Button variant="outline" size="sm" disabled={!paginatedResult.has_more} onClick={() => fetchClients(paginatedResult.current_page + 1)}>Next</Button>
                </div>
              </div>
            )}
          </>
        )}

        {/* Grid view */}
        {view === "grid" && (
          <div className="grid grid-cols-2 xl:grid-cols-3 gap-4">
            {clients.map((c: any) => {
              const gm = GST_META[c.gst_type ?? ""] ?? null;
              return (
                <Card key={c.id} className="border-border hover:border-gold/30 transition-colors">
                  <CardContent className="p-4">
                    <div className="flex items-start justify-between mb-2">
                      <div>
                        <button
                          onClick={() => setDetailClientId(c.id)}
                          className="font-mono text-xs text-gold font-semibold hover:underline"
                        >{c.client_code ?? "—"}</button>
                        <div className="font-semibold text-sm mt-0.5 leading-tight">{dname(c)}</div>
                        <div className="text-xs text-muted-foreground">{c.entity_subtype ?? (c.client_type==="individual"?"Individual":"Organization")}</div>
                      </div>
                      <div className="flex gap-1">
                        <button onClick={()=>openEdit(c)} className="p-1 rounded hover:bg-muted/40"><Pencil className="h-3.5 w-3.5 text-muted-foreground"/></button>
                        <button onClick={()=>setDelTarget(c)} className="p-1 rounded hover:bg-destructive/10"><Trash2 className="h-3.5 w-3.5 text-destructive/70"/></button>
                      </div>
                    </div>
                    <div className="space-y-0.5 text-xs text-muted-foreground">
                      {c.pan_number && <div>PAN: <span className="font-mono text-foreground">{c.pan_number}</span></div>}
                      {c.phone && <div>📞 {c.phone}</div>}
                      {c.contact_email && <div>✉ {c.contact_email}</div>}
                      {c.state && <div>📍 {c.state}{c.nationality!=="India"?`, ${c.nationality}`:""}</div>}
                    </div>
                    <div className="flex gap-1.5 mt-3 flex-wrap">
                      {gm && <Badge variant="outline" className={`text-[10px] ${gm.color}`}>{gm.label}</Badge>}
                      <Badge variant="outline" className={c.status==="Active"?"text-green-600 border-green-200 bg-green-50 text-[10px]":"text-[10px]"}>{c.status ?? "Active"}</Badge>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
            {clients.length===0 && <div className="col-span-3 py-12 text-center text-sm text-muted-foreground">No clients found.</div>}
          </div>
        )}
      </div>

      {/* ── Client Form Modal ──────────────────────────────────────────────────── */}
      {showForm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <div>
                <h2 className="font-display text-lg font-semibold">{editC ? "Edit Client" : "New Client"}</h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  {editC ? <>Code: <span className="font-mono text-gold font-semibold">{editC.client_code}</span></>
                         : form.record_mode === "existing"
                           ? <>Legacy client: enter the existing client code manually.</>
                           : <>Auto-assigned code: <span className="font-mono text-gold font-semibold">{previewCode}</span></>}
                </p>
              </div>
              <button onClick={() => setShowForm(false)}><X className="h-5 w-5 text-muted-foreground"/></button>
            </div>

            {/* Scrollable body */}
            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">

              {/* ── 0. Client Type Toggle ─────────────────────────────────── */}
              {!editC && (
                <Section title="Record Type">
                  <div className={`grid gap-3 ${featExistingClient ? "grid-cols-2" : "grid-cols-1"}`}>
                    {([
                      { key: "new",      label: "New Client",             hint: "Use the current auto-generation rules.", always: true },
                      { key: "existing", label: "Existing / Legacy Client", hint: "Enter the existing client code manually.", always: false },
                    ] as const).filter((o) => o.always || featExistingClient).map((option) => (
                      <button
                        key={option.key}
                        type="button"
                        onClick={() => set("record_mode", option.key)}
                        className={`rounded-lg border-2 px-4 py-3 text-left transition-all ${
                          form.record_mode === option.key
                            ? "border-gold bg-gold/10 text-foreground"
                            : "border-border text-muted-foreground hover:border-gold/40"
                        }`}
                      >
                        <div className="text-sm font-medium">{option.label}</div>
                        <div className="mt-1 text-xs">{option.hint}</div>
                      </button>
                    ))}
                  </div>
                  {form.record_mode === "existing" && (
                    <div>
                      <Lbl req>Existing Client Code</Lbl>
                      <input
                        value={form.client_code}
                        onChange={(e)=>set("client_code", e.target.value.toUpperCase())}
                        className={ic}
                        placeholder="e.g. A97M"
                      />
                    </div>
                  )}
                </Section>
              )}

              <div className="flex gap-2">
                {(["organization","individual"] as CType[]).map((t) => (
                  <button key={t} type="button" onClick={() => set("client_type", t)}
                    className={`flex-1 py-3 rounded-lg border-2 flex items-center justify-center gap-2 text-sm font-medium transition-all
                      ${form.client_type===t?"border-gold bg-gold/10 text-foreground":"border-border text-muted-foreground hover:border-gold/40"}`}>
                    {t==="organization"?<Building2 className="h-4 w-4"/>:<User className="h-4 w-4"/>}
                    {t==="organization"?"Organization":"Individual"}
                  </button>
                ))}
              </div>

              {/* ── 1. Identity ───────────────────────────────────────────── */}
              <Section title="Identity & Classification">
                <div className="grid grid-cols-2 gap-3">
                  <div className="col-span-2">
                    <Lbl req>{isOrg ? "Registered Legal Name" : "Full Name (no title)"}</Lbl>
                    <input value={form.legal_name} onChange={(e)=>set("legal_name",e.target.value)}
                      placeholder={isOrg?"e.g. Acme Technologies Pvt Ltd":"e.g. Rajesh Kumar (no Mr.)"}
                      className={ic}/>
                  </div>
                  {isOrg && <>
                    <div>
                      <Lbl>Entity Sub-type</Lbl>
                      <select value={form.entity_subtype} onChange={(e)=>set("entity_subtype",e.target.value)} className={ic}>
                        <option value="">Select…</option>
                        {ORG_SUBTYPES.map((t)=><option key={t}>{t}</option>)}
                      </select>
                    </div>
                    <div>
                      <Lbl>Trade / Brand Name</Lbl>
                      <input value={form.trade_name} onChange={(e)=>set("trade_name",e.target.value)} className={ic} placeholder="Brand name if different"/>
                    </div>
                    <div>
                      <Lbl>CIN Number</Lbl>
                      <input value={form.cin_number} onChange={(e)=>set("cin_number",e.target.value.toUpperCase())} className={ic} placeholder="U12345MH2020PTC123456" maxLength={21}/>
                    </div>
                    <div>
                      <Lbl>Website</Lbl>
                      <input value={form.website} onChange={(e)=>set("website",e.target.value)} className={ic} placeholder="https://company.com"/>
                    </div>
                  </>}
                  <div>
                    <Lbl>PAN Number</Lbl>
                    <input value={form.pan_number} onChange={(e)=>set("pan_number",e.target.value.toUpperCase())} className={ic} placeholder="ABCDE1234F" maxLength={10}/>
                  </div>
                  <div>
                    <Lbl>Nationality</Lbl>
                    <select value={form.nationality} onChange={(e)=>set("nationality",e.target.value)} className={ic}>
                      {["India","USA","UK","Germany","Japan","China","Singapore","Australia","Canada",
                        "France","Netherlands","South Korea","UAE","Switzerland","Other (Foreign)"]
                        .map((n)=><option key={n}>{n}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Fee Entity Tier</Lbl>
                    <select value={form.fee_entity_tier} onChange={(e)=>set("fee_entity_tier",e.target.value)} className={ic}>
                      <option value="">Not set</option>
                      <option value="individual_startup_msme">Individual / Startup / MSME</option>
                      <option value="large_entity_standard">Large Entity / Standard</option>
                    </select>
                    <p className="mt-1 text-[10px] text-muted-foreground">Drives auto-populated government/professional fees on quotes &amp; invoices for this client.</p>
                  </div>
                </div>
              </Section>

              {/* ── 2. GST Details ────────────────────────────────────────── */}
              <Section title="GST Details">
                <div className="grid grid-cols-2 gap-3">
                  <div className="col-span-2 flex items-center justify-between">
                    <label className="flex items-center gap-2 cursor-pointer select-none">
                      <input type="checkbox" checked={form.has_gstin} onChange={(e)=>set("has_gstin",e.target.checked)} className="h-4 w-4 accent-current rounded"/>
                      <span className="text-sm">Registered under GST — has GSTIN</span>
                    </label>
                    <div className="flex items-center gap-2">
                      <span className="text-xs text-muted-foreground">Classification:</span>
                      {(() => { const gm = GST_META[gstType]; return (
                        <Badge variant="outline" className={`${gm.color} text-xs font-semibold`}>{gm.label}</Badge>
                      );})()}
                    </div>
                  </div>

                  {form.has_gstin && (
                    <div className="col-span-2">
                      <Lbl>GSTIN</Lbl>
                      <input value={form.gstin} onChange={(e)=>set("gstin",e.target.value.toUpperCase())} className={ic} placeholder="27AAPFU0939F1ZV" maxLength={15}/>
                      {form.gstin && form.gstin.length!==15 && <p className="text-xs text-amber-500 mt-0.5">GSTIN must be exactly 15 characters</p>}
                    </div>
                  )}

                  {!isIndian && (
                    <div className="col-span-2 flex items-center gap-2 p-3 rounded-lg bg-purple-50 border border-purple-200 text-xs text-purple-700">
                      <Globe className="h-4 w-4 flex-shrink-0"/>
                      Foreign client — classified as <strong className="mx-1">Export</strong> (zero-rated / LUT under GST).
                    </div>
                  )}
                  {isIndian && !form.has_gstin && !isOrg && (
                    <div className="col-span-2 flex items-center gap-2 p-3 rounded-lg bg-green-50 border border-green-200 text-xs text-green-700">
                      <AlertCircle className="h-4 w-4 flex-shrink-0"/>
                      Indian individual without GSTIN — classified as <strong className="mx-1">B2C</strong>.
                    </div>
                  )}
                  {isIndian && !form.has_gstin && isOrg && (
                    <div className="col-span-2 flex items-center gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200 text-xs text-amber-700">
                      <AlertCircle className="h-4 w-4 flex-shrink-0"/>
                      Indian organization without GSTIN — classified as <strong className="mx-1">Unregistered</strong>.
                    </div>
                  )}
                </div>
              </Section>

              {/* ── 3. Contact ────────────────────────────────────────────── */}
              <Section title="Contact Details">
                <div className="grid grid-cols-2 gap-3">
                  {isOrg && (
                    <div className="col-span-2">
                      <Lbl>Contact Person Name (if different from legal name)</Lbl>
                      <input value={form.contact_name} onChange={(e)=>set("contact_name",e.target.value)} className={ic} placeholder="Primary contact at organization"/>
                    </div>
                  )}
                  <div>
                    <Lbl>Phone</Lbl>
                    <input value={form.phone} onChange={(e)=>set("phone",e.target.value)} className={ic} placeholder="+91 98765 43210"/>
                  </div>
                  <div>
                    <Lbl>Email</Lbl>
                    <input type="email" value={form.contact_email} onChange={(e)=>set("contact_email",e.target.value)} className={ic} placeholder="contact@company.com"/>
                  </div>
                  {isIndian ? (
                    <div>
                      <Lbl>State</Lbl>
                      <select value={form.state} onChange={(e)=>set("state",e.target.value)} className={ic}>
                        <option value="">Select state…</option>
                        {INDIAN_STATES.map((s)=><option key={s}>{s}</option>)}
                      </select>
                    </div>
                  ) : (
                    <div>
                      <Lbl>State / Region</Lbl>
                      <input value={form.state} onChange={(e)=>set("state",e.target.value)} className={ic} placeholder="State or region"/>
                    </div>
                  )}
                  <div className={isOrg ? "" : "col-span-1"}>
                    {/* spacer for alignment */}
                  </div>
                  <div className="col-span-2">
                    <Lbl>Address</Lbl>
                    <textarea value={form.address} onChange={(e)=>set("address",e.target.value)} rows={2} className={tc} placeholder="Full address including city, PIN code…"/>
                  </div>
                </div>
              </Section>

              {/* ── 4. Business ───────────────────────────────────────────── */}
              <Section title="Business Details">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <Lbl>Industry</Lbl>
                    <select value={form.industry} onChange={(e)=>set("industry",e.target.value)} className={ic}>
                      <option value="">Select…</option>
                      {INDUSTRIES.map((i)=><option key={i}>{i}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Payment Terms</Lbl>
                    <select value={form.payment_terms} onChange={(e)=>set("payment_terms",e.target.value)} className={ic}>
                      {PAY_TERMS.map((t)=><option key={t}>{t}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Client Manager</Lbl>
                    <select value={form.account_manager_id} onChange={(e)=>set("account_manager_id",e.target.value)} className={ic}>
                      <option value="">Select manager…</option>
                      {users.map((u)=><option key={u.id} value={u.id}>{u.name}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Accounts Person</Lbl>
                    <input value={form.accounts_person} onChange={(e)=>set("accounts_person",e.target.value)} className={ic} placeholder="Finance/accounts contact"/>
                  </div>
                  <div>
                    <Lbl>Status</Lbl>
                    <select value={form.status} onChange={(e)=>set("status",e.target.value)} className={ic}>
                      {["Active","Inactive","Prospect","On Hold"].map((s)=><option key={s}>{s}</option>)}
                    </select>
                  </div>
                </div>
              </Section>

              {/* ── 4b. Reminders ─────────────────────────────────────────── */}
              <Section title="Reminders" open={false}>
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <Lbl>Extra reminder days (before due date)</Lbl>
                    <input value={form.reminder_extra_days} onChange={(e)=>set("reminder_extra_days",e.target.value)} className={ic}
                      placeholder="e.g. 30 for an extra 1-month reminder"/>
                    <p className="mt-1 text-[11px] text-muted-foreground">Comma-separated day counts, added on top of the standard 6-month/3-month renewal reminders.</p>
                  </div>
                  <div>
                    <Lbl>Typical payment lead time (days before due date)</Lbl>
                    <input type="number" min={0} value={form.payment_clearance_lead_days} onChange={(e)=>set("payment_clearance_lead_days",e.target.value)} className={ic}
                      placeholder="e.g. 14 if they usually pay 2 weeks early"/>
                    <p className="mt-1 text-[11px] text-muted-foreground">If this client is still unpaid well past their usual pattern, they'll be escalated early.</p>
                  </div>
                </div>
              </Section>

              {/* ── 5. Banking ────────────────────────────────────────────── */}
              <Section title="Banking Details" open={false}>
                <div className="grid grid-cols-2 gap-3">
                  <div className="col-span-2">
                    <Lbl>Bank Name</Lbl>
                    <input value={form.bank_name} onChange={(e)=>set("bank_name",e.target.value)} className={ic} placeholder="e.g. HDFC Bank, SBI, ICICI"/>
                  </div>
                  <div>
                    <Lbl>Account Number</Lbl>
                    <input value={form.bank_account} onChange={(e)=>set("bank_account",e.target.value)} className={ic} placeholder="Account number"/>
                  </div>
                  <div>
                    <Lbl>IFSC Code</Lbl>
                    <input value={form.bank_ifsc} onChange={(e)=>set("bank_ifsc",e.target.value.toUpperCase())} className={ic} placeholder="HDFC0001234" maxLength={11}/>
                  </div>
                </div>
              </Section>

              {/* ── 6. Referral & Remarks ─────────────────────────────────── */}
              <Section title="Referral & Remarks" open={false}>
                <div className="space-y-3">
                  <div>
                    <Lbl>Referred by (Client Code)</Lbl>
                    <div className="relative">
                      <input
                        value={refQ || form.referred_by_code}
                        onChange={(e)=>{ setRefQ(e.target.value); if(!e.target.value) set("referred_by_code",""); }}
                        className={ic} placeholder="Search code or name…"/>
                      {refQ && (
                        <div className="absolute z-10 top-full mt-1 w-full bg-background border border-border rounded-md shadow-lg max-h-40 overflow-y-auto">
                          {refOptions.map((c: any) => (
                            <button key={c.id} type="button"
                              onClick={()=>{ set("referred_by_code",c.client_code); setRefQ(""); }}
                              className="w-full text-left px-3 py-2 text-sm hover:bg-muted/40 flex items-center gap-2">
                              <span className="font-mono text-gold text-xs font-semibold">{c.client_code}</span>
                              <span className="text-muted-foreground">{c.legal_name ?? c.company_name}</span>
                            </button>
                          ))}
                          {refOptions.length===0 && <p className="px-3 py-2 text-xs text-muted-foreground">No matches</p>}
                        </div>
                      )}
                    </div>
                    {form.referred_by_code && !refQ && (
                      <div className="flex items-center gap-2 mt-1.5 px-1">
                        <span className="font-mono text-gold text-xs font-semibold">{form.referred_by_code}</span>
                        <span className="text-xs text-muted-foreground">{clients.find((c: any)=>c.client_code===form.referred_by_code)?.legal_name ?? ""}</span>
                        <button type="button" onClick={()=>set("referred_by_code","")} className="ml-auto text-muted-foreground"><X className="h-3 w-3"/></button>
                      </div>
                    )}
                  </div>
                  <div>
                    <Lbl>Remarks</Lbl>
                    <textarea value={form.remarks} onChange={(e)=>set("remarks",e.target.value)} rows={3} className={tc} placeholder="Internal notes, special instructions…"/>
                  </div>
                </div>
              </Section>

              {fErr && (
                <div className="flex items-center gap-2 p-3 rounded-lg bg-destructive/10 border border-destructive/20 text-sm text-destructive">
                  <AlertCircle className="h-4 w-4 flex-shrink-0"/>{fErr}
                </div>
              )}
            </div>

            {/* Footer */}
            <div className="flex items-center justify-between px-6 py-4 border-t border-border flex-shrink-0">
              <div className="text-xs text-muted-foreground">
                GST: <strong>{gstType}</strong>
                {editC && <span className="ml-3">Code: <strong className="text-gold">{editC.client_code}</strong></span>}
              </div>
              <div className="flex gap-2">
                <Button variant="outline" onClick={()=>setShowForm(false)}>Cancel</Button>
                <Button className="bg-gold hover:bg-gold/90 text-black min-w-[120px]" onClick={handleSave} disabled={saving}>
                  {saving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin"/>Saving…</> : editC ? "Save Changes" : "Create Client"}
                </Button>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ── Import Modal ─────────────────────────────────────────────────────── */}
      {showImport && (
        <ImportModal
          onClose={() => setShowImport(false)}
          onDone={() => { fetchClients(1); api.getClientStats().then(setStats).catch(() => {}); }}
        />
      )}

      {/* ── KPI Drill-down Modal ─────────────────────────────────────────────── */}
      {kpiModal && <KpiModal kpi={kpiModal} onClose={() => setKpiModal(null)} onSelectClient={(id) => { setKpiModal(null); setDetailClientId(id); }} />}

      {/* ── Delete Confirm ────────────────────────────────────────────────────── */}
      {delTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center gap-3 mb-4">
              <div className="h-10 w-10 rounded-full bg-destructive/10 flex items-center justify-center flex-shrink-0">
                <Trash2 className="h-5 w-5 text-destructive"/>
              </div>
              <div>
                <h3 className="font-semibold">Delete Client</h3>
                <p className="text-sm text-muted-foreground mt-0.5">
                  Delete <strong>{dname(delTarget)}</strong> ({delTarget.client_code})? This cannot be undone.
                </p>
              </div>
            </div>
            <div className="flex gap-2 justify-end">
              <Button variant="outline" onClick={()=>setDelTarget(null)}>Cancel</Button>
              <Button variant="destructive" onClick={handleDelete} disabled={deleting}>
                {deleting?<Loader2 className="h-4 w-4 animate-spin"/>:"Delete"}
              </Button>
            </div>
          </div>
        </div>
      )}

      {detailClientId !== null && (
        <ClientDetailPanel clientId={detailClientId} onClose={() => setDetailClientId(null)} />
      )}
    </AppLayout>
  );
}
