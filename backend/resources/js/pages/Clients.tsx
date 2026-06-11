import { Head } from "@inertiajs/react";
import { useEffect, useState, useMemo } from "react";
import {
  Plus, Search, LayoutGrid, List, Pencil, Trash2, X, ChevronDown, ChevronUp,
  Building2, User, AlertCircle, Globe, Loader2, Download,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, downloadCSV } from "@/lib/api-client";

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
    .map((c) => c.client_code)
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
  client_type: CType; nationality: string; has_gstin: boolean; gstin: string;
  legal_name: string; entity_subtype: string; pan_number: string; cin_number: string;
  trade_name: string; website: string; contact_name: string; contact_email: string;
  phone: string; address: string; state: string;
  industry: string; payment_terms: string;
  account_manager_id: string; bank_name: string; bank_account: string; bank_ifsc: string;
  referred_by_code: string; accounts_person: string; remarks: string; status: string;
}
const BLANK: CF = {
  client_type:"organization", nationality:"India", has_gstin:false, gstin:"",
  legal_name:"", entity_subtype:"", pan_number:"", cin_number:"", trade_name:"", website:"",
  contact_name:"", contact_email:"", phone:"", address:"", state:"",
  industry:"", payment_terms:"Net 30",
  account_manager_id:"", bank_name:"", bank_account:"", bank_ifsc:"",
  referred_by_code:"", accounts_person:"", remarks:"", status:"Active",
};

// ── Main ──────────────────────────────────────────────────────────────────────

export default function Clients() {
  const [paginatedResult, setPaginatedResult] = useState<any>({ data: [], total: 0, per_page: 25, current_page: 1, last_page: 1, has_more: false });
  const [users, setUsers]       = useState<any[]>([]);
  const [loading, setLoading]   = useState(true);
  const [search, setSearch]     = useState("");
  const [view, setView]         = useState<"list"|"grid">("list");
  const [statusF, setStatusF]   = useState("All");

  const [showForm, setShowForm] = useState(false);
  const [editC, setEditC]       = useState<any>(null);
  const [form, setForm]         = useState<CF>(BLANK);
  const [saving, setSaving]     = useState(false);
  const [fErr, setFErr]         = useState("");
  const [delTarget, setDelTarget] = useState<any>(null);
  const [deleting, setDeleting] = useState(false);
  const [refQ, setRefQ]         = useState("");

  const fetchClients = (p: number = 1) => {
    const params = new URLSearchParams();
    params.set('page', String(p));
    if (search) params.set('search', search);
    api.getClients(params)
      .then(setPaginatedResult)
      .catch(() => setLoading(false))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    setLoading(true);
    fetchClients(1);
    api.getUsers().then(setUsers).catch(() => {});
  }, [search]);

  const isOrg    = form.client_type === "organization";
  const isIndian = form.nationality.toLowerCase() === "india";
  const gstType  = computeGstType(form.nationality, form.has_gstin, form.client_type);
  const previewCode = editC?.client_code ?? nextClientCode(paginatedResult.data, form.nationality);

  const set = (f: keyof CF, v: any) => setForm((p) => ({ ...p, [f]: v }));

  const refOptions = useMemo(() =>
    paginatedResult.data
      .filter((c) => c.client_code && /^[C-Z][0-9]{2}[MY]?$/.test(c.client_code))
      .filter((c) => !refQ || (c.client_code+"|"+(c.legal_name??c.company_name??"")).toLowerCase().includes(refQ.toLowerCase()))
      .slice(0, 10),
    [paginatedResult.data, refQ]);

  function openCreate() { setForm(BLANK); setEditC(null); setFErr(""); setShowForm(true); }

  function openEdit(c: any) {
    setForm({
      client_type:      c.client_type       ?? "organization",
      nationality:      c.nationality       ?? "India",
      has_gstin:        !!c.has_gstin,
      gstin:            c.gstin             ?? "",
      legal_name:       c.legal_name        ?? c.company_name ?? "",
      entity_subtype:   c.entity_subtype    ?? "",
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
    });
    setEditC(c); setFErr(""); setShowForm(true);
  }

  async function handleSave() {
    if (!form.legal_name.trim()) { setFErr("Legal name is required."); return; }
    setSaving(true); setFErr("");
    try {
      const payload = {
        ...form,
        account_manager_id: form.account_manager_id ? parseInt(form.account_manager_id) : null,
      };
      if (editC) {
        await api.updateClient(editC.id, payload);
      } else {
        await api.createClient(payload);
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

  if (loading) return (
    <AppLayout>
      <Head title="Clients" />
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-gold" />
      </div>
    </AppLayout>
  );

  return (
    <AppLayout>
      <Head title="Clients" />
      <PageHeader eyebrow="CRM" title="Clients"
        description="Client portfolio, GST classification, and contact management."
        actions={
          <>
            <Button variant="outline" size="sm" onClick={() =>
              downloadCSV(`clients-${new Date().toISOString().slice(0,10)}.csv`,
                filtered.map((c) => ({
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
        {/* Stats */}
        <div className="grid grid-cols-4 gap-3">
          {[
            { l:"Total Clients", v:clients.length,                                  c:"text-gold"        },
            { l:"Active",        v:clients.filter((c)=>c.status==="Active").length,  c:"text-green-500"   },
            { l:"B2B (GST Reg)", v:clients.filter((c)=>c.gst_type==="B2B").length,  c:"text-blue-500"    },
            { l:"Export",        v:clients.filter((c)=>c.gst_type==="Export").length,c:"text-purple-500" },
          ].map(({l,v,c}) => (
            <Card key={l} className="border-border">
              <CardContent className="p-3 text-center">
                <div className={`text-2xl font-bold ${c}`}>{v}</div>
                <div className="text-xs text-muted-foreground mt-0.5">{l}</div>
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
                      <th className="px-4 py-3 text-left">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {clients.map((c) => {
                      const gm = GST_META[c.gst_type ?? ""] ?? null;
                      return (
                        <tr key={c.id} className="border-t border-border hover:bg-muted/20">
                          <td className="px-4 py-3 font-mono text-xs font-semibold text-gold">{c.client_code ?? "—"}</td>
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
                    {clients.length===0 && <tr><td colSpan={9} className="px-4 py-10 text-center text-sm text-muted-foreground">No clients found.</td></tr>}
                  </tbody>
                </table>
              </CardContent>
              {paginatedResult.total > 0 && (
                <CardContent className="px-4 py-3 border-t border-border text-xs text-muted-foreground flex items-center justify-between">
                  <span>Showing {((paginatedResult.current_page - 1) * paginatedResult.per_page) + 1}–{Math.min(paginatedResult.current_page * paginatedResult.per_page, paginatedResult.total)} of {paginatedResult.total}</span>
                  <div className="flex items-center gap-1">
                    <Button variant="outline" size="sm" disabled={paginatedResult.current_page === 1} onClick={() => fetchClients(paginatedResult.current_page - 1)}>‹</Button>
                    <span>Page {paginatedResult.current_page} of {paginatedResult.last_page}</span>
                    <Button variant="outline" size="sm" disabled={!paginatedResult.has_more} onClick={() => fetchClients(paginatedResult.current_page + 1)}>›</Button>
                  </div>
                </CardContent>
              )}
            </Card>
        )}

        {/* Grid view */}
        {view === "grid" && (
          <div className="grid grid-cols-2 xl:grid-cols-3 gap-4">
            {clients.map((c) => {
              const gm = GST_META[c.gst_type ?? ""] ?? null;
              return (
                <Card key={c.id} className="border-border hover:border-gold/30 transition-colors">
                  <CardContent className="p-4">
                    <div className="flex items-start justify-between mb-2">
                      <div>
                        <span className="font-mono text-xs text-gold font-semibold">{c.client_code ?? "—"}</span>
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
                         : <>Auto-assigned code: <span className="font-mono text-gold font-semibold">{previewCode}</span></>}
                </p>
              </div>
              <button onClick={() => setShowForm(false)}><X className="h-5 w-5 text-muted-foreground"/></button>
            </div>

            {/* Scrollable body */}
            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">

              {/* ── 0. Client Type Toggle ─────────────────────────────────── */}
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
                      {users.map((u)=><option key={u.id} value={u.id}>{u.name} ({u.role})</option>)}
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
                          {refOptions.map((c)=>(
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
                        <span className="text-xs text-muted-foreground">{clients.find((c)=>c.client_code===form.referred_by_code)?.legal_name ?? ""}</span>
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
    </AppLayout>
  );
}
