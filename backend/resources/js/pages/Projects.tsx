import { Head, Link } from "@inertiajs/react";
import { useEffect, useState, useMemo, useRef } from "react";
import { createPortal } from "react-dom";
import {
  Plus, Search, Loader2, X, Download, Pencil, Trash2, AlertCircle,
  ChevronDown, ChevronUp, Eye,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, downloadCSV } from "@/lib/api-client";
import { statusColor } from "@/lib/mock-data";

// ── Date helper ───────────────────────────────────────────────────────────────

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}-${m}-${y}`;
}

// ── WIPO Patent Office Codes ──────────────────────────────────────────────────

const PATENT_OFFICES = [
  { code: "IN", label: "IN – India (IPO)" },
  { code: "US", label: "US – United States (USPTO)" },
  { code: "EP", label: "EP – European Patent Office (EPO)" },
  { code: "WO", label: "WO – WIPO / PCT (International)" },
  { code: "CN", label: "CN – China (CNIPA)" },
  { code: "JP", label: "JP – Japan (JPO)" },
  { code: "KR", label: "KR – South Korea (KIPO)" },
  { code: "AU", label: "AU – Australia (IP Australia)" },
  { code: "CA", label: "CA – Canada (CIPO)" },
  { code: "GB", label: "GB – United Kingdom (UKIPO)" },
  { code: "DE", label: "DE – Germany (DPMA)" },
  { code: "FR", label: "FR – France (INPI)" },
  { code: "RU", label: "RU – Russia (Rospatent)" },
  { code: "BR", label: "BR – Brazil (INPI-BR)" },
  { code: "MX", label: "MX – Mexico (IMPI)" },
  { code: "SG", label: "SG – Singapore (IPOS)" },
  { code: "HK", label: "HK – Hong Kong (HKIPO)" },
  { code: "MY", label: "MY – Malaysia (MyIPO)" },
  { code: "TH", label: "TH – Thailand (DIP)" },
  { code: "ID", label: "ID – Indonesia (DGIP)" },
  { code: "PH", label: "PH – Philippines (IPOPHL)" },
  { code: "VN", label: "VN – Vietnam (NOIP)" },
  { code: "ZA", label: "ZA – South Africa (CIPC)" },
  { code: "NZ", label: "NZ – New Zealand (IPONZ)" },
  { code: "IL", label: "IL – Israel (ILPO)" },
  { code: "TR", label: "TR – Turkey (TPTO)" },
  { code: "AE", label: "AE – United Arab Emirates (MOIAT)" },
  { code: "SA", label: "SA – Saudi Arabia (SAIP)" },
  { code: "EG", label: "EG – Egypt (EGPO)" },
  { code: "NG", label: "NG – Nigeria (FIPO)" },
  { code: "KE", label: "KE – Kenya (KEIPO)" },
  { code: "MA", label: "MA – Morocco (OMPIC)" },
  { code: "PK", label: "PK – Pakistan (IPO-Pakistan)" },
  { code: "BD", label: "BD – Bangladesh (DPDT)" },
  { code: "LK", label: "LK – Sri Lanka (NIPO)" },
  { code: "CH", label: "CH – Switzerland (IGE/IPI)" },
  { code: "SE", label: "SE – Sweden (PRV)" },
  { code: "NO", label: "NO – Norway (NIPO)" },
  { code: "DK", label: "DK – Denmark (DKPTO)" },
  { code: "FI", label: "FI – Finland (PRH)" },
  { code: "NL", label: "NL – Netherlands (RNLIP)" },
  { code: "BE", label: "BE – Belgium (BOIP)" },
  { code: "AT", label: "AT – Austria (OPA)" },
  { code: "IT", label: "IT – Italy (UIBM)" },
  { code: "ES", label: "ES – Spain (OEPM)" },
  { code: "PT", label: "PT – Portugal (INPI-PT)" },
  { code: "PL", label: "PL – Poland (PPO)" },
  { code: "CZ", label: "CZ – Czech Republic (IPO)" },
  { code: "HU", label: "HU – Hungary (HIPO)" },
  { code: "RO", label: "RO – Romania (OSIM)" },
  { code: "UA", label: "UA – Ukraine (UKRPATENT)" },
  { code: "AR", label: "AR – Argentina (INPI-AR)" },
  { code: "CL", label: "CL – Chile (INAPI)" },
  { code: "CO", label: "CO – Colombia (SIC)" },
  { code: "PE", label: "PE – Peru (INDECOPI)" },
  { code: "QA", label: "QA – Qatar" },
  { code: "KW", label: "KW – Kuwait" },
  { code: "OM", label: "OM – Oman" },
  { code: "BH", label: "BH – Bahrain" },
  { code: "JO", label: "JO – Jordan" },
];

// ── Service Codes ─────────────────────────────────────────────────────────────

const SERVICE_CODES = [
  { code: "DFT",   label: "DFT – Patent Drafting" },
  { code: "PRV",   label: "PRV – Provisional Application Filing" },
  { code: "NPA",   label: "NPA – Non-Provisional Application Filing" },
  { code: "FIL",   label: "FIL – Patent Filing (General)" },
  { code: "PCT",   label: "PCT – PCT International Filing" },
  { code: "NPE",   label: "NPE – National Phase Entry" },
  { code: "DIV",   label: "DIV – Divisional Application" },
  { code: "CIP",   label: "CIP – Continuation-in-Part" },
  { code: "CON",   label: "CON – Continuation Application" },
  { code: "CNV",   label: "CNV – Application Conversion" },
  { code: "EXR",   label: "EXR – Request for Examination" },
  { code: "FER",   label: "FER – First Examination Report Response" },
  { code: "SER",   label: "SER – Second Examination Report Response" },
  { code: "TER",   label: "TER – Third / Subsequent Exam Report Response" },
  { code: "AMD",   label: "AMD – Amendments to Specification / Claims" },
  { code: "CAR",   label: "CAR – Counter-Arguments / Written Submissions" },
  { code: "OBJ",   label: "OBJ – Response to Objections" },
  { code: "HRG",   label: "HRG – Hearing Representation" },
  { code: "PBL",   label: "PBL – Publication Request" },
  { code: "PGO",   label: "PGO – Pre-Grant Opposition Filing" },
  { code: "PGR",   label: "PGR – Pre-Grant Opposition Response" },
  { code: "GRT",   label: "GRT – Grant / Acceptance" },
  { code: "POG",   label: "POG – Post-Grant Opposition Filing" },
  { code: "POR",   label: "POR – Post-Grant Opposition Response" },
  { code: "APL",   label: "APL – Appeal Filing" },
  { code: "APR",   label: "APR – Appeal Response / Arguments" },
  { code: "REI",   label: "REI – Reinstatement of Lapsed Patent" },
  { code: "WTH",   label: "WTH – Withdrawal / Abandonment" },
  { code: "REN",   label: "REN – Patent Renewal / Maintenance Fee" },
  { code: "ANN",   label: "ANN – Annual Fee / Annuity Management" },
  { code: "LAP",   label: "LAP – Revival of Lapsed Application" },
  { code: "SRH",   label: "SRH – Prior Art Search" },
  { code: "PAT",   label: "PAT – Patentability Opinion" },
  { code: "FTO",   label: "FTO – Freedom to Operate Study" },
  { code: "VAL",   label: "VAL – Validity / Invalidity Opinion" },
  { code: "INF",   label: "INF – Infringement Analysis" },
  { code: "LND",   label: "LND – Patent Landscape Study" },
  { code: "AUD",   label: "AUD – IP Portfolio Audit" },
  { code: "INV",   label: "INV – Invention Disclosure Review" },
  { code: "LIC",   label: "LIC – Licensing Agreement Drafting" },
  { code: "ASG",   label: "ASG – Assignment / Transfer Registration" },
  { code: "CDL",   label: "CDL – Compulsory Licence / Revocation" },
  { code: "TRN",   label: "TRN – Technology Transfer Agreement" },
  { code: "IPR",   label: "IPR – Inter Partes Review (USPTO)" },
  { code: "RCE",   label: "RCE – Request for Continued Examination" },
  { code: "TMS",   label: "TMS – Trademark Search" },
  { code: "TMD",   label: "TMD – Trademark Drafting / Advice" },
  { code: "TMF",   label: "TMF – Trademark Filing" },
  { code: "TMC",   label: "TMC – Trademark Multi-Class Filing" },
  { code: "TME",   label: "TME – TM Examination Report Response" },
  { code: "TMJ",   label: "TMJ – TM Journal / Gazette Monitoring" },
  { code: "TMO",   label: "TMO – TM Opposition Filing" },
  { code: "TMOR",  label: "TMOR – TM Opposition Reply / Counter-Statement" },
  { code: "TMH",   label: "TMH – TM Hearing Representation" },
  { code: "TMG",   label: "TMG – TM Grant / Registration Certificate" },
  { code: "TMRN",  label: "TMRN – Trademark Renewal" },
  { code: "TMA",   label: "TMA – TM Assignment / Transfer" },
  { code: "TMWT",  label: "TMWT – Trademark Watch Service" },
  { code: "TMINF", label: "TMINF – TM Infringement Notice / Action" },
  { code: "TMMD",  label: "TMMD – Madrid Protocol / International TM Filing" },
  { code: "DSF",   label: "DSF – Design Registration Filing" },
  { code: "DSE",   label: "DSE – Design Examination Response" },
  { code: "DSR",   label: "DSR – Design Registration Certificate" },
  { code: "DSN",   label: "DSN – Design Renewal" },
  { code: "DSA",   label: "DSA – Design Assignment" },
  { code: "CRF",   label: "CRF – Copyright Registration Filing" },
  { code: "CRL",   label: "CRL – Copyright Licence Drafting" },
  { code: "CRA",   label: "CRA – Copyright Assignment" },
  { code: "GIF",   label: "GIF – GI Tag Application Filing" },
  { code: "GIE",   label: "GIE – GI Examination Response" },
  { code: "GIR",   label: "GIR – GI Registration" },
  { code: "PVF",   label: "PVF – Plant Variety Protection Filing" },
  { code: "PVR",   label: "PVR – Plant Variety Certificate" },
  { code: "POA",   label: "POA – Power of Attorney" },
  { code: "NOT",   label: "NOT – Notarization" },
  { code: "LEG",   label: "LEG – Legalization / Apostille" },
  { code: "TRL",   label: "TRL – Translation Services" },
  { code: "CSL",   label: "CSL – Legal Consultation / Advisory" },
  { code: "STR",   label: "STR – IP Strategy / Portfolio Review" },
  { code: "RPT",   label: "RPT – Status Report / Reporting" },
  { code: "MED",   label: "MED – Mediation Assistance" },
  { code: "ARB",   label: "ARB – Arbitration Support" },
  { code: "LTG",   label: "LTG – IP Litigation Support" },
  { code: "CRT",   label: "CRT – Court Filing / Representation" },
  { code: "DUE",   label: "DUE – Due Diligence" },
  { code: "VAR",   label: "VAR – Various / Miscellaneous" },
];

const CASE_TYPES = [
  "Patent – Utility", "Patent – Design", "Patent – PCT",
  "Trademark", "Copyright", "Geographical Indication",
  "Plant Variety", "Semiconductor Layout Design",
  "Trade Secret", "IP Litigation", "IP Licensing",
  "IP Audit", "Technology Transfer", "General Advisory",
];

const URGENCIES = ["Low", "Normal", "High", "Critical"];
const STATUSES  = ["Open", "In Progress", "On Hold", "Closed", "Completed"];
const FEE_TYPES = ["Fixed Fee", "Hourly", "Retainer", "Contingency", "Pro Bono"];
const PIPELINE_STAGES = ["Intake", "Drafting", "Filing", "Examination", "Object received", "Granted", "Renewal"];

// ── Shared UI ─────────────────────────────────────────────────────────────────

const ic = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";
const tc = "w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none";

const Lbl = ({ children, req }: { children: React.ReactNode; req?: boolean }) => (
  <label className="block text-xs text-muted-foreground mb-1">
    {children}{req && <span className="text-destructive ml-0.5">*</span>}
  </label>
);

// Section — resizable when expanded (drag bottom-right corner), portalled dropdowns never clipped
function Section({ title, children, open: defaultOpen = true }: {
  title: string; children: React.ReactNode; open?: boolean;
}) {
  const [expanded, setExpanded] = useState(defaultOpen);
  return (
    <div
      className="border border-border rounded-lg"
      style={expanded ? { resize: "vertical", overflow: "auto", minHeight: 80 } : undefined}
    >
      <button type="button" onClick={() => setExpanded(!expanded)}
        className={`w-full flex items-center justify-between px-4 py-2.5 bg-muted/30 text-xs font-semibold uppercase tracking-wider text-muted-foreground hover:bg-muted/50 ${expanded ? "rounded-t-lg" : "rounded-lg"}`}>
        {title}
        {expanded ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
      </button>
      {expanded && <div className="p-4 space-y-3">{children}</div>}
    </div>
  );
}

// Portal-based combobox — dropdown renders in document.body, never clipped
function Combobox({ value, options, onSelect, placeholder }: {
  value: string;
  options: { id: string; label: string }[];
  onSelect: (id: string) => void;
  placeholder?: string;
}) {
  const [q, setQ]       = useState("");
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const inputRef        = useRef<HTMLInputElement>(null);

  const selected = options.find((o) => o.id === value);
  const filtered = options
    .filter((o) => !q || o.label.toLowerCase().includes(q.toLowerCase()))
    .slice(0, 25);

  // Reposition dropdown on scroll so it follows the input instead of closing
  useEffect(() => {
    if (!open) return;
    const updatePos = () => {
      if (inputRef.current) setRect(inputRef.current.getBoundingClientRect());
    };
    window.addEventListener("scroll", updatePos, true);
    return () => window.removeEventListener("scroll", updatePos, true);
  }, [open]);

  function handleFocus() {
    if (inputRef.current) setRect(inputRef.current.getBoundingClientRect());
    setOpen(true);
    setQ("");
  }

  const dropdown = open && rect
    ? createPortal(
        <div
          style={{ position: "fixed", top: rect.bottom + 2, left: rect.left, width: rect.width, zIndex: 9999 }}
          className="bg-background border border-border rounded-md shadow-2xl max-h-52 overflow-y-auto"
        >
          {filtered.map((o) => (
            <button key={o.id} type="button"
              onMouseDown={(e) => { e.preventDefault(); onSelect(o.id); setOpen(false); }}
              className={`w-full text-left px-3 py-2 text-sm hover:bg-muted/40 ${o.id === value ? "bg-gold/10 text-gold font-medium" : ""}`}>
              {o.label}
            </button>
          ))}
          {filtered.length === 0 && <p className="px-3 py-2 text-xs text-muted-foreground">No matches</p>}
        </div>,
        document.body
      )
    : null;

  return (
    <div>
      <input
        ref={inputRef}
        value={open ? q : (selected?.label ?? "")}
        onFocus={handleFocus}
        onBlur={() => setTimeout(() => setOpen(false), 200)}
        onChange={(e) => setQ(e.target.value)}
        placeholder={placeholder}
        className={ic}
      />
      {dropdown}
    </div>
  );
}

// ── Form state ────────────────────────────────────────────────────────────────

interface PF {
  project_name: string; client_id: string; case_type: string;
  invention_title: string; technology_field: string;
  application_number: string; patent_office_code: string; service_code: string;
  filing_date: string; target_filing_date: string; hard_deadline: string;
  urgency: string; status: string; fee_arrangement: string;
  confidentiality_level: string;
  assigned_partner_id: string; assigned_manager_id: string;
  secondary_manager_id: string; patent_engineer_id: string;
  notes: string;
}

const BLANK: PF = {
  project_name: "", client_id: "", case_type: "Patent – Utility",
  invention_title: "", technology_field: "",
  application_number: "", patent_office_code: "IN", service_code: "FIL",
  filing_date: "", target_filing_date: "", hard_deadline: "",
  urgency: "Normal", status: "Open", fee_arrangement: "Fixed Fee",
  confidentiality_level: "Standard",
  assigned_partner_id: "", assigned_manager_id: "",
  secondary_manager_id: "", patent_engineer_id: "",
  notes: "",
};

// ── Main ──────────────────────────────────────────────────────────────────────

export default function Projects() {
  const [projects, setProjects]  = useState<any[]>([]);
  const [clients, setClients]    = useState<any[]>([]);
  const [users, setUsers]        = useState<any[]>([]);
  const [loading, setLoading]    = useState(true);
  const [search, setSearch]      = useState("");
  const [filterStatus, setFilter] = useState("All");
  const [page, setPage]           = useState(1);
  const [perPage, setPerPage]     = useState(25);

  const [showModal, setShowModal] = useState(false);
  const [editProj, setEditProj]  = useState<any>(null);
  const [form, setForm]          = useState<PF>(BLANK);
  const [saving, setSaving]      = useState(false);
  const [saveErr, setSaveErr]    = useState("");

  const [delTarget, setDelTarget] = useState<any>(null);
  const [deleting, setDeleting]  = useState(false);

  const [stageMenu, setStageMenu] = useState<{ projectId: number; stages: string[]; rect: DOMRect } | null>(null);

  useEffect(() => {
    if (!stageMenu) return;
    const close = () => setStageMenu(null);
    window.addEventListener("click", close);
    return () => window.removeEventListener("click", close);
  }, [stageMenu]);

  useEffect(() => {
    Promise.all([api.getProjects(), api.getClients(), api.getUsers()])
      .then(([p, c, u]) => {
        setProjects(Array.isArray(p) ? p : (p as any).data || []);
        setClients(Array.isArray(c) ? c : (c as any).data || []);
        setUsers(u);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const sf = (f: keyof PF, v: string) => setForm((p) => ({ ...p, [f]: v }));

  const clientOptions = useMemo(() =>
    clients.map((c) => ({
      id: String(c.id),
      label: `${c.client_code ?? ""} – ${c.legal_name ?? c.company_name ?? ""}`.replace(/^–\s/, ""),
    })), [clients]);

  const staffOptions = useMemo(() =>
    users.map((u) => ({ id: String(u.id), label: `${u.name} (${u.role})` })), [users]);

  const officeOptions = PATENT_OFFICES.map((o) => ({ id: o.code, label: o.label }));
  const serviceOptions = SERVICE_CODES.map((s) => ({ id: s.code, label: s.label }));

  const docketPreview = useMemo(() => {
    if (!form.client_id) return "—";
    const cl = clients.find((c) => String(c.id) === form.client_id);
    if (!cl?.client_code) return "—";
    const cc = cl.client_code;
    let maxSeq = 0;
    for (const p of projects) {
      if (String(p.client_id) === form.client_id && p.docket_number?.startsWith(cc)) {
        const seq = parseInt(p.docket_number.slice(cc.length, cc.length + 3), 10);
        if (!isNaN(seq) && seq > maxSeq) maxSeq = seq;
      }
    }
    const seq = String(maxSeq + 1).padStart(3, "0");
    return `${cc}${seq}${form.patent_office_code.toUpperCase()}${form.service_code.toUpperCase()}`;
  }, [form.client_id, form.patent_office_code, form.service_code, clients, projects]);

  const filtered = useMemo(() => {
    setPage(1);
    return projects.filter((p) => {
      const q = search.toLowerCase();
      if (q &&
        !p.project_name?.toLowerCase().includes(q) &&
        !p.docket_number?.toLowerCase().includes(q) &&
        !p.project_code?.toLowerCase().includes(q) &&
        !(p.client?.legal_name ?? p.client?.company_name ?? "").toLowerCase().includes(q) &&
        !p.application_number?.toLowerCase().includes(q)) return false;
      if (filterStatus !== "All" && p.status !== filterStatus) return false;
      return true;
    });
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projects, search, filterStatus]);

  const totalPages  = Math.max(1, Math.ceil(filtered.length / perPage));
  const paginated   = filtered.slice((page - 1) * perPage, page * perPage);

  function openCreate() { setForm(BLANK); setEditProj(null); setSaveErr(""); setShowModal(true); }

  function openEdit(p: any) {
    setForm({
      project_name:        p.project_name         ?? "",
      client_id:           p.client_id             ? String(p.client_id) : "",
      case_type:           p.case_type             ?? p.project_type ?? "Patent – Utility",
      invention_title:     p.invention_title       ?? "",
      technology_field:    p.technology_field      ?? "",
      application_number:  p.application_number    ?? "",
      patent_office_code:  p.patent_office_code    ?? "IN",
      service_code:        p.service_code          ?? "FIL",
      filing_date:         p.filing_date           ? p.filing_date.split("T")[0] : "",
      target_filing_date:  p.target_filing_date    ? p.target_filing_date.split("T")[0] : "",
      hard_deadline:       p.hard_deadline         ? p.hard_deadline.split("T")[0] : "",
      urgency:             p.urgency               ?? "Normal",
      status:              p.status                ?? "Open",
      fee_arrangement:     p.fee_arrangement       ?? "Fixed Fee",
      confidentiality_level: p.confidentiality_level ?? "Standard",
      assigned_partner_id:   p.assigned_partner_id  ? String(p.assigned_partner_id) : "",
      assigned_manager_id:   p.assigned_manager_id  ? String(p.assigned_manager_id) : "",
      secondary_manager_id:  p.secondary_manager_id ? String(p.secondary_manager_id) : "",
      patent_engineer_id:    p.patent_engineer_id   ? String(p.patent_engineer_id) : "",
      notes:               p.notes                 ?? "",
    });
    setEditProj(p); setSaveErr(""); setShowModal(true);
  }

  async function handleSave() {
    if (!form.project_name.trim() || !form.client_id) {
      setSaveErr("Patent title and client are required."); return;
    }
    setSaving(true); setSaveErr("");
    try {
      const payload = {
        ...form,
        client_id:            parseInt(form.client_id),
        assigned_partner_id:  form.assigned_partner_id  ? parseInt(form.assigned_partner_id)  : null,
        assigned_manager_id:  form.assigned_manager_id  ? parseInt(form.assigned_manager_id)  : null,
        secondary_manager_id: form.secondary_manager_id ? parseInt(form.secondary_manager_id) : null,
        patent_engineer_id:   form.patent_engineer_id   ? parseInt(form.patent_engineer_id)   : null,
        project_type: form.case_type,
      };
      if (editProj) {
        const updated = await api.updateProject(editProj.id, payload);
        setProjects((prev) => prev.map((p) => p.id === editProj.id
          ? { ...p, ...updated, client: p.client } : p));
      } else {
        const created = await api.createProject(payload);
        const clientObj = clients.find((c) => c.id === parseInt(form.client_id));
        setProjects((prev) => [{ ...created, client: clientObj ?? null }, ...prev]);
      }
      setShowModal(false);
    } catch (e: any) { setSaveErr(e.message || "Failed to save case."); }
    finally { setSaving(false); }
  }

  async function handleDelete() {
    if (!delTarget) return;
    setDeleting(true);
    try {
      await api.deleteProject(delTarget.id);
      setProjects((prev) => prev.filter((p) => p.id !== delTarget.id));
      setDelTarget(null);
    } finally { setDeleting(false); }
  }

  const statuses     = ["All", "Open", "In Progress", "On Hold", "Closed", "Completed"];
  const userName     = (id: any) => users.find((u) => u.id === id)?.name ?? "—";
  const managerName  = (p: any) => p.manager?.name   ?? userName(p.assigned_manager_id);
  const partnerName  = (p: any) => p.partner?.name   ?? userName(p.assigned_partner_id);
  const activeStage  = (p: any) =>
    p.stages?.find((s: any) => s.status === "In Progress")?.stage_name
    ?? p.stages?.[0]?.stage_name
    ?? "—";

  return (
    <AppLayout>
      <Head title="Cases" />
      <PageHeader eyebrow="Practice" title="Cases"
        description={`${projects.length} cases in portfolio`}
        actions={
          <>
            <Button variant="outline" onClick={() =>
              downloadCSV(`cases-${new Date().toISOString().slice(0, 10)}.csv`,
                filtered.map((p) => ({
                  "Docket #":       p.docket_number ?? "",
                  "Patent Title":   p.project_name,
                  "Country":        p.patent_office_code ?? "",
                  "Case Type":      p.case_type ?? p.project_type ?? "",
                  "Filed":          fmtDate(p.filing_date),
                  "Deadline":       fmtDate(p.hard_deadline),
                  "Status":         p.status,
                  "Workflow Stage": activeStage(p),
                  "Client Manager": managerName(p),
                  "Person Responsible": partnerName(p),
                }))
              )}>
              <Download className="h-4 w-4 mr-2" />Export CSV
            </Button>
            <Button className="bg-gold hover:bg-gold/90 text-black" onClick={openCreate}>
              <Plus className="h-4 w-4 mr-2" />New Case
            </Button>
          </>
        }
      />

      {/* ── Case Form Modal ─────────────────────────────────────────────────── */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <div>
                <h2 className="font-display text-lg font-semibold">
                  {editProj ? "Edit Case" : "New Case"}
                </h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  {editProj
                    ? <>Docket: <span className="font-mono text-gold font-semibold">{editProj.docket_number ?? editProj.project_code}</span></>
                    : <>Docket preview: <span className="font-mono text-gold font-semibold">{docketPreview}</span></>}
                </p>
              </div>
              <button onClick={() => setShowModal(false)}>
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
              {saveErr && (
                <div className="flex items-center gap-2 p-3 rounded-md bg-destructive/10 border border-destructive/20 text-xs text-destructive">
                  <AlertCircle className="h-4 w-4 flex-shrink-0" />{saveErr}
                </div>
              )}

              {/* ── 1. Case Identification ───────────────────────────── */}
              <Section title="Case Identification">
                <div className="grid grid-cols-2 gap-3">
                  <div className="col-span-2">
                    <Lbl req>Patent Title / Case Name</Lbl>
                    <input value={form.project_name}
                      onChange={(e) => sf("project_name", e.target.value)}
                      placeholder="e.g. Compact Lithium Cell with Enhanced Electrolyte"
                      className={ic} />
                  </div>
                  <div>
                    <Lbl req>Client Code</Lbl>
                    <Combobox
                      value={form.client_id}
                      options={clientOptions}
                      onSelect={(v) => sf("client_id", v)}
                      placeholder="Search client code or name…"
                    />
                  </div>
                  <div>
                    <Lbl>Case Type</Lbl>
                    <select value={form.case_type} onChange={(e) => sf("case_type", e.target.value)} className={ic}>
                      {CASE_TYPES.map((t) => <option key={t}>{t}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Application Number</Lbl>
                    <input value={form.application_number}
                      onChange={(e) => sf("application_number", e.target.value)}
                      placeholder="e.g. 202341001234"
                      className={ic} />
                  </div>
                  {editProj && (
                    <div>
                      <Lbl>Status</Lbl>
                      <select value={form.status} onChange={(e) => sf("status", e.target.value)} className={ic}>
                        {STATUSES.map((s) => <option key={s}>{s}</option>)}
                      </select>
                    </div>
                  )}
                </div>
              </Section>

              {/* ── 2. IP Details ────────────────────────────────────── */}
              <Section title="IP Details">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <Lbl>Patent Office (Country)</Lbl>
                    <Combobox
                      value={form.patent_office_code}
                      options={officeOptions}
                      onSelect={(v) => sf("patent_office_code", v)}
                      placeholder="Search office…"
                    />
                  </div>
                  <div>
                    <Lbl>Service Code</Lbl>
                    <Combobox
                      value={form.service_code}
                      options={serviceOptions}
                      onSelect={(v) => sf("service_code", v)}
                      placeholder="Search service code…"
                    />
                  </div>
                  <div>
                    <Lbl>Inventor Name</Lbl>
                    <input value={form.invention_title}
                      onChange={(e) => sf("invention_title", e.target.value)}
                      placeholder="e.g. Rajesh Kumar, Priya Sharma"
                      className={ic} />
                  </div>
                  <div>
                    <Lbl>Technology Field</Lbl>
                    <input value={form.technology_field}
                      onChange={(e) => sf("technology_field", e.target.value)}
                      placeholder="e.g. Biotechnology, Semiconductors"
                      className={ic} />
                  </div>
                </div>
              </Section>

              {/* ── 3. Dates & Status ────────────────────────────────── */}
              <Section title="Dates & Status">
                <div className="grid grid-cols-3 gap-3">
                  <div>
                    <Lbl>Filing Date</Lbl>
                    <input type="date" value={form.filing_date}
                      onChange={(e) => sf("filing_date", e.target.value)} className={ic} />
                  </div>
                  <div>
                    <Lbl>Target Filing Date</Lbl>
                    <input type="date" value={form.target_filing_date}
                      onChange={(e) => sf("target_filing_date", e.target.value)} className={ic} />
                  </div>
                  <div>
                    <Lbl>Hard Deadline</Lbl>
                    <input type="date" value={form.hard_deadline}
                      onChange={(e) => sf("hard_deadline", e.target.value)} className={ic} />
                  </div>
                  <div>
                    <Lbl>Urgency</Lbl>
                    <select value={form.urgency} onChange={(e) => sf("urgency", e.target.value)} className={ic}>
                      {URGENCIES.map((u) => <option key={u}>{u}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Fee Arrangement</Lbl>
                    <select value={form.fee_arrangement} onChange={(e) => sf("fee_arrangement", e.target.value)} className={ic}>
                      {FEE_TYPES.map((f) => <option key={f}>{f}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Confidentiality</Lbl>
                    <select value={form.confidentiality_level} onChange={(e) => sf("confidentiality_level", e.target.value)} className={ic}>
                      {["Standard", "Confidential", "Strictly Confidential", "NDA Required"].map((c) => <option key={c}>{c}</option>)}
                    </select>
                  </div>
                </div>
              </Section>

              {/* ── 4. Team Assignment ───────────────────────────────── */}
              <Section title="Team Assignment">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <Lbl>Attorney (Partner)</Lbl>
                    <Combobox
                      value={form.assigned_partner_id}
                      options={staffOptions}
                      onSelect={(v) => sf("assigned_partner_id", v)}
                      placeholder="Select attorney…"
                    />
                  </div>
                  <div>
                    <Lbl>Client Manager</Lbl>
                    <Combobox
                      value={form.assigned_manager_id}
                      options={staffOptions}
                      onSelect={(v) => sf("assigned_manager_id", v)}
                      placeholder="Select client manager…"
                    />
                  </div>
                  <div>
                    <Lbl>Secondary Client Manager</Lbl>
                    <Combobox
                      value={form.secondary_manager_id}
                      options={staffOptions}
                      onSelect={(v) => sf("secondary_manager_id", v)}
                      placeholder="Select secondary manager…"
                    />
                  </div>
                  <div>
                    <Lbl>Patent Engineer</Lbl>
                    <Combobox
                      value={form.patent_engineer_id}
                      options={staffOptions}
                      onSelect={(v) => sf("patent_engineer_id", v)}
                      placeholder="Select patent engineer…"
                    />
                  </div>
                </div>
              </Section>

              {/* ── 5. Notes ─────────────────────────────────────────── */}
              <Section title="Notes" open={false}>
                <textarea
                  value={form.notes}
                  onChange={(e) => sf("notes", e.target.value)}
                  rows={4} className={tc}
                  placeholder="Internal notes, client instructions, special requirements…"
                />
              </Section>
            </div>

            <div className="flex gap-2 px-6 py-4 border-t border-border flex-shrink-0 justify-end">
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
              <Button className="bg-gold hover:bg-gold/90 text-black min-w-[140px]" onClick={handleSave} disabled={saving}>
                {saving ? <><Loader2 className="h-4 w-4 animate-spin mr-2" />Saving…</> : editProj ? "Save Changes" : "Create Case"}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* ── Stage Picker Portal ────────────────────────────────────────────── */}
      {stageMenu && createPortal(
        <div
          style={{ position: "fixed", top: stageMenu.rect.bottom + 4, left: stageMenu.rect.left, zIndex: 9999, minWidth: 160 }}
          className="bg-background border border-border rounded-md shadow-2xl overflow-hidden"
          onClick={(e) => e.stopPropagation()}
        >
          <div className="px-3 py-1.5 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider border-b border-border bg-muted/30">
            Set Workflow Stage
          </div>
          {stageMenu.stages.map((s) => (
            <button
              key={s}
              className="w-full text-left px-3 py-2 text-sm hover:bg-muted/40 transition-colors"
              onClick={async () => {
                const pid = stageMenu.projectId;
                setStageMenu(null);
                try {
                  await api.updateProjectStage(pid, s);
                } catch {}
                setProjects((prev) => prev.map((p) => {
                  if (p.id !== pid) return p;
                  const existing = p.stages ?? PIPELINE_STAGES.map((name: string, i: number) => ({
                    stage_name: name, status: "Pending", sequence_order: i,
                  }));
                  return {
                    ...p,
                    stages: existing.map((st: any) => ({
                      ...st,
                      status: st.stage_name === s ? "In Progress" : "Pending",
                    })),
                  };
                }));
              }}
            >
              {s}
            </button>
          ))}
        </div>,
        document.body
      )}

      {/* ── Delete Confirm ─────────────────────────────────────────────────── */}
      {delTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center gap-3 mb-4">
              <div className="h-10 w-10 rounded-full bg-destructive/10 flex items-center justify-center flex-shrink-0">
                <Trash2 className="h-5 w-5 text-destructive" />
              </div>
              <div>
                <h3 className="font-semibold">Delete Case</h3>
                <p className="text-sm text-muted-foreground mt-0.5">
                  Delete <strong>{delTarget.project_name}</strong> ({delTarget.docket_number ?? delTarget.project_code})?
                  All stages and tasks will be removed.
                </p>
              </div>
            </div>
            <div className="flex gap-2 justify-end">
              <Button variant="outline" onClick={() => setDelTarget(null)}>Cancel</Button>
              <Button variant="destructive" onClick={handleDelete} disabled={deleting}>
                {deleting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Delete"}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* ── Table ─────────────────────────────────────────────────────────── */}
      <div className="px-8 py-6 space-y-4">
        <div className="flex flex-wrap items-center gap-3">
          <div className="relative max-w-sm flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search docket, title, application #, client…"
              value={search} onChange={(e) => setSearch(e.target.value)} className="pl-9" />
          </div>
          <div className="flex items-center gap-1.5 flex-wrap">
            {statuses.map((s) => (
              <button key={s} onClick={() => setFilter(s)}
                className={`px-3 h-8 text-xs rounded-md border transition-colors
                  ${filterStatus === s ? "bg-gold text-black border-gold font-semibold" : "border-border text-muted-foreground hover:text-foreground"}`}>
                {s}
              </button>
            ))}
          </div>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
          </div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full text-sm min-w-[1100px]">
                  <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-4 py-3 text-left">Case ID</th>
                      <th className="px-4 py-3 text-left">Patent Title</th>
                      <th className="px-4 py-3 text-left">Country</th>
                      <th className="px-4 py-3 text-left">Filed</th>
                      <th className="px-4 py-3 text-left">Status</th>
                      <th className="px-4 py-3 text-left">Workflow Stage</th>
                      <th className="px-4 py-3 text-left">Deadline</th>
                      <th className="px-4 py-3 text-left">Client Manager</th>
                      <th className="px-4 py-3 text-left">Person Responsible</th>
                      <th className="px-4 py-3 text-left">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {paginated.map((p) => {
                      const stage = activeStage(p);
                      const isOverdue = p.hard_deadline && new Date(p.hard_deadline) < new Date();
                      return (
                        <tr key={p.id} className="border-t border-border hover:bg-muted/30">
                          <td className="px-4 py-3">
                            <div className="font-mono text-xs text-gold font-semibold">
                              {p.docket_number ?? p.project_code ?? "—"}
                            </div>
                            {p.docket_number && p.project_code && (
                              <div className="text-[10px] text-muted-foreground font-mono">{p.project_code}</div>
                            )}
                          </td>
                          <td className="px-4 py-3 max-w-[200px]">
                            <div className="font-medium truncate">{p.project_name}</div>
                            {p.invention_title && (
                              <div className="text-xs text-muted-foreground truncate">{p.invention_title}</div>
                            )}
                          </td>
                          <td className="px-4 py-3">
                            {p.patent_office_code
                              ? <Badge variant="outline" className="text-[10px] font-mono">{p.patent_office_code}</Badge>
                              : <span className="text-muted-foreground text-xs">—</span>}
                          </td>
                          <td className="px-4 py-3 text-xs text-muted-foreground font-mono whitespace-nowrap">
                            {fmtDate(p.filing_date)}
                          </td>
                          <td className="px-4 py-3">
                            <Badge variant={statusColor(p.status)} className="text-[10px] whitespace-nowrap">
                              {p.status}
                            </Badge>
                          </td>
                          <td className="px-4 py-3">
                            <button
                              title="Click to change stage"
                              onClick={(e) => {
                                e.stopPropagation();
                                const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
                                const stages = p.stages?.map((s: any) => s.stage_name) ?? PIPELINE_STAGES;
                                setStageMenu({ projectId: p.id, stages, rect });
                              }}
                            >
                              <Badge variant="secondary" className="text-[10px] whitespace-nowrap cursor-pointer hover:bg-gold/20 hover:text-gold transition-colors">
                                {stage}
                              </Badge>
                            </button>
                          </td>
                          <td className={`px-4 py-3 text-xs font-mono whitespace-nowrap ${isOverdue ? "text-destructive font-semibold" : "text-muted-foreground"}`}>
                            {fmtDate(p.hard_deadline)}
                          </td>
                          <td className="px-4 py-3 text-xs text-muted-foreground whitespace-nowrap">
                            {managerName(p)}
                          </td>
                          <td className="px-4 py-3 text-xs text-muted-foreground whitespace-nowrap">
                            {partnerName(p)}
                          </td>
                          <td className="px-4 py-3">
                            <div className="flex gap-1">
                              <Button asChild variant="ghost" size="sm" className="h-7 w-7 p-0" title="View">
                                <Link href={`/projects/${p.id}`}><Eye className="h-3.5 w-3.5" /></Link>
                              </Button>
                              <Button size="sm" variant="outline" className="h-7 w-7 p-0" title="Edit" onClick={() => openEdit(p)}>
                                <Pencil className="h-3 w-3" />
                              </Button>
                              <Button size="sm" variant="outline" title="Delete"
                                className="h-7 w-7 p-0 text-destructive border-destructive/30 hover:bg-destructive/10"
                                onClick={() => setDelTarget(p)}>
                                <Trash2 className="h-3 w-3" />
                              </Button>
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                    {paginated.length === 0 && (
                      <tr>
                        <td colSpan={10} className="px-4 py-12 text-center text-muted-foreground">
                          {projects.length === 0 ? "No cases yet. Create your first case above." : "No cases match your search."}
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
                {/* Pagination bar */}
                {filtered.length > 0 && (
                  <div className="flex items-center justify-between px-4 py-3 border-t border-border text-xs text-muted-foreground">
                    <span>Showing {Math.min((page-1)*perPage+1, filtered.length)}–{Math.min(page*perPage, filtered.length)} of {filtered.length}</span>
                    <div className="flex items-center gap-3">
                      <span>Rows per page:</span>
                      {[10,25,50].map(n=>(
                        <button key={n} onClick={()=>{setPerPage(n);setPage(1);}}
                          className={`px-2 py-0.5 rounded border text-xs transition-colors ${perPage===n?"border-gold text-gold bg-gold/10":"border-border hover:border-gold/40"}`}>
                          {n}
                        </button>
                      ))}
                      <div className="flex items-center gap-1 ml-2">
                        <button disabled={page===1} onClick={()=>setPage(p=>p-1)}
                          className="px-2 py-0.5 rounded border border-border disabled:opacity-40 hover:bg-muted/40">‹</button>
                        {Array.from({length:Math.min(5,totalPages)},(_,i)=>{
                          const pg = totalPages<=5 ? i+1 : page<=3 ? i+1 : page>=totalPages-2 ? totalPages-4+i : page-2+i;
                          return <button key={pg} onClick={()=>setPage(pg)}
                            className={`px-2 py-0.5 rounded border text-xs ${pg===page?"border-gold bg-gold/10 text-gold":"border-border hover:bg-muted/40"}`}>{pg}</button>;
                        })}
                        <button disabled={page===totalPages} onClick={()=>setPage(p=>p+1)}
                          className="px-2 py-0.5 rounded border border-border disabled:opacity-40 hover:bg-muted/40">›</button>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
