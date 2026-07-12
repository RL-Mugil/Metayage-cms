import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState, useCallback } from "react";
import { Loader2, Plus, X, Trash2, Download, DollarSign, Clock, CheckCircle, AlertCircle, Search, ChevronLeft, ChevronRight, CheckSquare, Square, FileText, Pencil, IndianRupee } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api, downloadCSV } from "@/lib/api-client";
import type { Quotation, PatentInvoiceIn } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { fmtDate } from "@/lib/date-utils";

function fmt(n: number) {
  if (n >= 100000) return `₹${(n / 100000).toFixed(1)}L`;
  return `₹${n.toLocaleString("en-IN")}`;
}

const blankItem = { description: "", amount: "" };

// GST computation — mirrors backend computeGst()
function computeGstInfo(client: any | null | undefined, subtotal: number) {
  if (!client || client.gst_type === "Export" || (client.nationality ?? "india").toLowerCase() !== "india") {
    return { type: "export" as const, lines: [] as { label: string; amount: number }[], taxAmount: 0, total: subtotal };
  }
  const isKarnataka = (client.state ?? "").toLowerCase() === "karnataka";
  const taxAmount = Math.round(subtotal * 0.18 * 100) / 100;
  if (isKarnataka) {
    const half = Math.round(subtotal * 0.09 * 100) / 100;
    return {
      type: "cgst_sgst" as const,
      lines: [{ label: "CGST 9%", amount: half }, { label: "SGST 9%", amount: half }],
      taxAmount,
      total: subtotal + taxAmount,
    };
  }
  return {
    type: "igst" as const,
    lines: [{ label: "IGST 18%", amount: taxAmount }],
    taxAmount,
    total: subtotal + taxAmount,
  };
}

// ── KPI ───────────────────────────────────────────────────────────────────────

interface KpiDef {
  label: string;
  key: string;
  color: string;
  isCurrency: boolean;
  filterParams: Record<string, string>;
  icon: React.ElementType;
}

const KPI_DEFS: KpiDef[] = [
  { label: "Total Billed",   key: "total_billed",      color: "text-gold",         isCurrency: true,  filterParams: {},                    icon: DollarSign   },
  { label: "Received",       key: "total_received",    color: "text-green-500",    isCurrency: true,  filterParams: { status: "Paid" },    icon: CheckCircle  },
  { label: "Outstanding",    key: "total_outstanding", color: "text-blue-500",     isCurrency: true,  filterParams: { outstanding: "1" },  icon: Clock        },
  { label: "Overdue",        key: "overdue_count",     color: "text-destructive",  isCurrency: false, filterParams: { status: "Overdue" }, icon: AlertCircle  },
];

function FinancialKpiModal({ kpi, onClose }: { kpi: KpiDef; onClose: () => void }) {
  const [result, setResult]   = useState<{ data: any[]; total: number }>({ data: [], total: 0 });
  const [search, setSearch]   = useState("");
  const [loading, setLoading] = useState(false);
  const [page, setPage]       = useState(1);
  const PER_PAGE = 15;

  const fetchPage = useCallback(async (pg: number, q: string) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(pg), ...kpi.filterParams });
      if (q) params.set("search", q);
      const res: any = await api.getInvoicesPaged(params);
      setResult({ data: Array.isArray(res) ? res : (res?.data ?? []), total: res?.total ?? 0 });
    } finally { setLoading(false); }
  }, [kpi]);

  useEffect(() => { fetchPage(1, ""); }, [fetchPage]);

  function handleSearch(q: string) { setSearch(q); setPage(1); fetchPage(1, q); }
  function goPage(pg: number) { setPage(pg); fetchPage(pg, search); }
  const totalPages = Math.max(1, Math.ceil(result.total / PER_PAGE));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl max-h-[88vh] flex flex-col">
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <h2 className="font-display text-lg font-semibold">{kpi.label}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{result.total} invoice{result.total !== 1 ? "s" : ""}</p>
          </div>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>
        <div className="px-6 py-3 border-b border-border flex-shrink-0">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold"
              placeholder="Search invoice #, client…" value={search} onChange={(e) => handleSearch(e.target.value)} />
          </div>
        </div>
        <div className="flex-1 overflow-auto">
          {loading ? (
            <div className="flex items-center justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
          ) : (
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-muted/60 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left">Invoice #</th>
                  <th className="px-4 py-3 text-left">Client</th>
                  <th className="px-4 py-3 text-right">Total</th>
                  <th className="px-4 py-3 text-right">Balance Due</th>
                  <th className="px-4 py-3 text-left">Status</th>
                  <th className="px-4 py-3 text-left">Due Date</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((inv) => (
                  <tr key={inv.id} className="border-t border-border hover:bg-muted/30">
                    <td className="px-4 py-2.5 font-mono text-xs text-gold font-semibold">{inv.invoice_code ?? "—"}</td>
                    <td className="px-4 py-2.5 text-sm">{inv.client?.company_name ?? "—"}</td>
                    <td className="px-4 py-2.5 text-sm font-medium text-right">{fmt(parseFloat(inv.total_amount ?? 0))}</td>
                    <td className="px-4 py-2.5 text-sm text-right text-muted-foreground">{fmt(parseFloat(inv.balance_due ?? 0))}</td>
                    <td className="px-4 py-2.5"><span className={`text-xs font-medium ${inv.status === "Overdue" ? "text-destructive" : "text-muted-foreground"}`}>{inv.status}</span></td>
                    <td className={`px-4 py-2.5 text-xs font-mono ${inv.status === "Overdue" ? "text-destructive font-semibold" : "text-muted-foreground"}`}>{fmtDate(inv.due_date)}</td>
                  </tr>
                ))}
                {result.data.length === 0 && (
                  <tr><td colSpan={6} className="px-4 py-10 text-center text-muted-foreground">No invoices found.</td></tr>
                )}
              </tbody>
            </table>
          )}
        </div>
        <div className="flex items-center justify-between px-6 py-3 border-t border-border flex-shrink-0 text-xs text-muted-foreground">
          <span>Showing {result.data.length} of {result.total}</span>
          <div className="flex items-center gap-1">
            <button onClick={() => goPage(page - 1)} disabled={page === 1} className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40"><ChevronLeft className="h-4 w-4" /></button>
            <span className="px-2">{page} / {totalPages}</span>
            <button onClick={() => goPage(page + 1)} disabled={page >= totalPages} className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40"><ChevronRight className="h-4 w-4" /></button>
          </div>
        </div>
      </div>
    </div>
  );
}

const QUOTE_STATUS_COLOR: Record<string, string> = {
  Draft: "bg-muted/60 text-muted-foreground",
  "Internal Pending": "bg-blue-500/10 text-blue-400",
  Sent: "bg-sky-500/10 text-sky-400",
  Accepted: "bg-green-500/10 text-green-400",
  Expired: "bg-orange-500/10 text-orange-400",
  Cancelled: "bg-red-500/10 text-red-400",
};

export default function Financial() {
  const { props: pageProps } = usePage() as any;
  const role = pageProps.auth?.user?.role;
  const isClientUser = ["client", "client_admin"].includes(role);

  // Tab
  const [activeTab, setActiveTab] = useState("india");

  // Invoices state
  const [invoices, setInvoices] = useState<any[]>([]);
  const [clients, setClients] = useState<any[]>([]);
  const [projects, setProjects] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatus, setFilterStatus] = useState("All");
  const [stats, setStats] = useState({ total_billed: 0, total_received: 0, total_outstanding: 0, overdue_count: 0 });
  const [kpiModal, setKpiModal] = useState<KpiDef | null>(null);

  // New invoice modal
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState({ client_id: "", project_id: "", due_date: "", payment_terms: "Net 30", notes: "" });
  const [items, setItems] = useState([{ ...blankItem }]);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");

  // Payment modal
  const [showPayment, setShowPayment] = useState<any | null>(null);
  const [payForm, setPayForm] = useState({ amount: "", payment_method: "Bank Transfer", transaction_reference: "" });
  const [paying, setPaying] = useState(false);

  // Batch selection
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [batching, setBatching] = useState(false);
  const [showBatchPayModal, setShowBatchPayModal] = useState(false);
  const [batchPayMethod, setBatchPayMethod] = useState("Bank Transfer");

  // Quotations state
  const [quotations, setQuotations] = useState<Quotation[]>([]);
  const [quotLoading, setQuotLoading] = useState(false);
  const [showQuotModal, setShowQuotModal] = useState(false);
  const [quotForm, setQuotForm] = useState({ client_id: "", project_id: "", valid_until: "", fee_structure: "Fixed Fee" as const, estimated_hours: "", estimated_disbursements: "", buffer_percentage: "", total_amount: "", currency: "INR" });
  const [quotSaving, setQuotSaving] = useState(false);
  const [quotError, setQuotError] = useState("");
  const [converting, setConverting] = useState<number | null>(null);

  // ── Indian Patent Invoices state ─────────────────────────────────────────────
  const isInternal = !["client", "client_admin", "associate", "paralegal"].includes(role);
  const [indiaRecords, setIndiaRecords]     = useState<PatentInvoiceIn[]>([]);
  const [indiaLoading, setIndiaLoading]     = useState(false);
  const [indiaSearch, setIndiaSearch]       = useState("");
  const [indiaTypeFilter, setIndiaType]     = useState<"all" | "invoice" | "quote">("all");
  const [indiaStatusFilter, setIndiaStatus] = useState("all");
  const [indiaSelectedIds, setIndiaSelIds]  = useState<Set<number>>(new Set());
  const [indiaBatching, setIndiaBatching]   = useState(false);
  const [showIndiaModal, setShowIndiaModal] = useState(false);
  const [editIndiaRec, setEditIndiaRec]     = useState<PatentInvoiceIn | null>(null);
  const [indiaSaving, setIndiaSaving]       = useState(false);
  const [indiaErr, setIndiaErr]             = useState("");

  const blankIndia = () => ({
    type: "invoice" as "invoice" | "quote",
    project_id: "", client_id: "",
    docket_number: "", invoice_uin: "",
    invoice_date: new Date().toISOString().split("T")[0],
    tax_invoice_date: "", tax_serial_number: "",
    client_code_prefix: "", invention_number: "", patent_office_code: "",
    first_inventor_name: "", invention_title: "", service_code: "",
    client_name: "", client_reference: "", state_of_supply: "",
    entity_status: "", patent_office_application_number: "",
    additional_information: "", patent_office_acknowledgement: "",
    remarks: "", uin_old: "", uin_old_2: "",
    patent_office_fees: "0", service_fees: "0", other_expenses: "0",
    attorney_fees: "0", consultant_fees: "0", referral_fees: "0",
  });
  const [indiaForm, setIndiaForm] = useState(blankIndia());
  const sif = (f: string, v: string) => setIndiaForm(p => ({ ...p, [f]: v }));

  // Auto-fill from project selection
  function fillFromProject(projectId: string) {
    const proj = projects.find(p => String(p.id) === projectId);
    if (!proj) return;
    // Use eager-loaded client on the project; fall back to clients state
    const client = (proj as any).client ?? clients.find(c => c.id === proj.client_id);
    const uin = proj.docket_number ?? proj.project_code ?? "";
    setIndiaForm(prev => ({
      ...prev,
      project_id: projectId,
      client_id: String(proj.client_id ?? ""),
      docket_number: uin,
      client_code_prefix: uin.slice(0, 4),
      invention_number: uin.slice(4, 7),
      patent_office_code: proj.patent_office_code || uin.slice(7, 9) || "",
      first_inventor_name: proj.invention_title ?? "",
      invention_title: proj.project_name ?? "",
      service_code: proj.service_code ?? "",
      client_name: client?.legal_name ?? client?.company_name ?? "",
      client_reference: client?.referred_by_code ?? "",
      state_of_supply: client?.state ?? "",
    }));
  }

  // Live GST and totals from form
  function computeIndiaGst(form: typeof indiaForm) {
    const svc    = parseFloat(form.service_fees)  || 0;
    const pof    = parseFloat(form.patent_office_fees) || 0;
    const other  = parseFloat(form.other_expenses) || 0;
    const isKarnataka = (form.state_of_supply ?? "").toLowerCase() === "karnataka";
    const igst   = isKarnataka ? 0 : Math.round(svc * 0.18 * 100) / 100;
    const cgst   = isKarnataka ? Math.round(svc * 0.09 * 100) / 100 : 0;
    const sgst   = isKarnataka ? Math.round(svc * 0.09 * 100) / 100 : 0;
    const total  = pof + svc + igst + cgst + sgst + other;
    const atty   = parseFloat(form.attorney_fees)   || 0;
    const cons   = parseFloat(form.consultant_fees) || 0;
    const ref    = parseFloat(form.referral_fees)   || 0;
    return { igst, cgst, sgst, total, net: Math.round((total - atty - cons - ref) * 100) / 100 };
  }

  useEffect(() => {
    api.getFinancialStats().then(setStats).catch(() => {});
    Promise.all([api.getInvoices(), api.getClients(), api.getProjects()])
      .then(([i, c, p]) => {
        const loadedClients  = Array.isArray(c) ? c : (c as any).data || [];
        const loadedProjects = Array.isArray(p) ? p : (p as any).data || [];
        setInvoices(Array.isArray(i) ? i : (i as any).data || []);
        setClients(loadedClients);
        setProjects(loadedProjects);

        // Auto-open India modal when navigated from Projects quick-raise
        const qp          = new URLSearchParams(window.location.search);
        const indiaType   = qp.get("india") as "invoice" | "quote" | null;
        const projectId   = qp.get("project_id");
        if (indiaType === "invoice" || indiaType === "quote") {
          const proj   = loadedProjects.find((pr: any) => String(pr.id) === projectId);
          const client = proj ? (proj.client ?? loadedClients.find((cl: any) => cl.id === proj.client_id)) : null;
          const uin    = proj ? (proj.docket_number ?? proj.project_code ?? "") : "";
          setEditIndiaRec(null);
          setIndiaErr("");
          setShowIndiaModal(true);
          setIndiaForm({
            ...blankIndia(),
            type: indiaType,
            ...(projectId ? { project_id: projectId } : {}),
            ...(proj ? {
              client_id:            String(proj.client_id ?? ""),
              docket_number:        uin,
              client_code_prefix:   uin.slice(0, 4),
              invention_number:     uin.slice(4, 7),
              patent_office_code:   proj.patent_office_code || uin.slice(7, 9) || "",
              first_inventor_name:  proj.invention_title ?? "",
              invention_title:      proj.project_name ?? "",
              service_code:         proj.service_code ?? "",
            } : {}),
            ...(client ? {
              client_name:      client.legal_name ?? client.company_name ?? "",
              client_reference: client.referred_by_code ?? "",
              state_of_supply:  client.state ?? "",
            } : {}),
          });
        }
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    if (activeTab !== "quotations") return;
    setQuotLoading(true);
    api.getQuotations().then(res => setQuotations(res.data ?? [])).catch(() => {}).finally(() => setQuotLoading(false));
  }, [activeTab]);

  useEffect(() => {
    if (activeTab !== "india") return;
    loadIndia();
  }, [activeTab]);

  function loadIndia(overrides: { type?: string; status?: string } = {}) {
    setIndiaLoading(true);
    const type   = overrides.type   ?? indiaTypeFilter;
    const status = overrides.status ?? indiaStatusFilter;
    const params = new URLSearchParams({ per_page: "200" });
    if (type !== "all")   params.set("type",   type);
    if (status !== "all") params.set("status", status);
    if (indiaSearch.trim()) params.set("search", indiaSearch.trim());
    api.getPatentInvoicesIn(params).then(res => { setIndiaRecords(res.data ?? []); setIndiaSelIds(new Set()); }).catch(() => {}).finally(() => setIndiaLoading(false));
  }

  const allIndiaSelected = indiaRecords.length > 0 && indiaRecords.every(r => indiaSelectedIds.has(r.id));
  function toggleSelectAllIndia() {
    setIndiaSelIds(allIndiaSelected ? new Set() : new Set(indiaRecords.map(r => r.id)));
  }
  function toggleSelectIndia(id: number) {
    setIndiaSelIds(prev => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); return n; });
  }

  async function changeIndiaStatus(id: number, status: string) {
    try {
      await api.updatePatentInvoiceIn(id, { status } as any);
      setIndiaRecords(prev => prev.map(r => r.id === id ? { ...r, status: status as any } : r));
    } catch (e: any) { alert(e.message || "Status change failed."); }
  }

  async function cancelIndiaRecord(id: number) {
    if (!confirm("Cancel this record?")) return;
    try {
      await api.deletePatentInvoiceIn(id);
      setIndiaRecords(prev => prev.map(r => r.id === id ? { ...r, status: "Cancelled" as any } : r));
    } catch (e: any) { alert(e.message || "Failed to cancel."); }
  }

  async function convertIndia(id: number) {
    if (!confirm("Convert this quotation to an invoice?")) return;
    try {
      const inv = await api.convertPatentQuoteToInvoice(id);
      setIndiaRecords(prev => [inv, ...prev.map(r => r.id === id ? { ...r, status: "Accepted" as any } : r)]);
    } catch (e: any) { alert(e.message || "Conversion failed."); }
  }

  async function handleBatchIndia(action: string) {
    if (indiaSelectedIds.size === 0) return;
    setIndiaBatching(true);
    try {
      await api.batchUpdatePatentInvoicesIn(Array.from(indiaSelectedIds), action);
      loadIndia();
    } catch (e: any) { alert(e.message || "Batch action failed."); }
    finally { setIndiaBatching(false); }
  }

  function exportIndiaCSV(selected = false) {
    const rows = (selected && indiaSelectedIds.size > 0 ? indiaRecords.filter(r => indiaSelectedIds.has(r.id)) : indiaRecords)
      .map(r => ({
        Type: r.type, UIN: r.invoice_uin ?? r.docket_number, Status: r.status,
        "Invoice Date": r.invoice_date ?? "", Client: r.client_name ?? "",
        "PO Fees": r.patent_office_fees, "Service Fees": r.service_fees,
        IGST: r.igst_amount, CGST: r.cgst_amount, SGST: r.sgst_amount,
        "Other Exp": r.other_expenses, "Invoice Amount": r.invoice_amount,
        ...(isInternal ? { "Net Revenue": r.net_revenue } : {}),
        Remarks: r.remarks ?? "",
      }));
    downloadCSV(`india-patents-${new Date().toISOString().slice(0, 10)}.csv`, rows);
    if (selected) setIndiaSelIds(new Set());
  }

  const filtered = invoices.filter(i => filterStatus === "All" || i.status === filterStatus);
  const allVisibleIds = filtered.map(i => i.id);
  const allSelected = allVisibleIds.length > 0 && allVisibleIds.every(id => selectedIds.has(id));

  function toggleSelectAll() {
    if (allSelected) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(allVisibleIds));
    }
  }

  function toggleSelect(id: number) {
    setSelectedIds(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id); else next.add(id);
      return next;
    });
  }

  function addItem() { setItems(prev => [...prev, { ...blankItem }]); }
  function removeItem(idx: number) { setItems(prev => prev.filter((_, i) => i !== idx)); }
  function updateItem(idx: number, field: string, value: string) {
    setItems(prev => prev.map((item, i) => i === idx ? { ...item, [field]: value } : item));
  }

  const subtotal = items.reduce((s, item) => s + (parseFloat(item.amount) || 0), 0);
  const selectedInvoiceClient = clients.find((c: any) => c.id === parseInt(form.client_id));
  const invoiceGst = computeGstInfo(selectedInvoiceClient, subtotal);

  async function handleCreate() {
    if (!form.client_id || !form.due_date) { setSaveError("Client and due date are required."); return; }
    const validItems = items.filter(i => i.description && i.amount);
    if (validItems.length === 0) { setSaveError("At least one line item with description and amount is required."); return; }
    setSaving(true); setSaveError("");
    try {
      const created = await api.createInvoice({
        ...form,
        client_id: parseInt(form.client_id),
        project_id: form.project_id ? parseInt(form.project_id) : null,
        items: validItems.map(i => ({ description: i.description, amount: parseFloat(i.amount) })) as unknown as import('@/types').InvoiceItem[],
      });
      setInvoices(prev => [created, ...prev]);
      setShowModal(false);
      setForm({ client_id: "", project_id: "", due_date: "", payment_terms: "Net 30", notes: "" });
      setItems([{ ...blankItem }]);
    } catch (e: any) { setSaveError(e.message || "Failed to create invoice."); }
    finally { setSaving(false); }
  }

  async function handlePayment() {
    if (!payForm.amount) return;
    setPaying(true);
    try {
      await api.recordPayment({ invoice_id: showPayment.id, amount: parseFloat(payForm.amount), payment_method: payForm.payment_method, transaction_reference: payForm.transaction_reference });
      const updated = await api.getInvoices();
      setInvoices(Array.isArray(updated) ? updated : (updated as any).data || []);
      setShowPayment(null);
    } catch (e: any) { alert(e.message || "Payment failed."); }
    finally { setPaying(false); }
  }

  function handleExport() {
    const rows = filtered.map(i => ({ Invoice: i.invoice_code, Client: i.client?.company_name, Amount: i.total_amount, Balance: i.balance_due, Status: i.status, Due: i.due_date || "" }));
    downloadCSV(`invoices-${new Date().toISOString().slice(0,10)}.csv`, rows);
  }

  async function handleBatchAction(action: string, extra?: Record<string, unknown>) {
    if (selectedIds.size === 0) return;
    setBatching(true);
    try {
      const result = await api.batchUpdateInvoices(Array.from(selectedIds), action, extra);
      const updated = await api.getInvoices();
      setInvoices(Array.isArray(updated) ? updated : (updated as any).data || []);
      api.getFinancialStats().then(setStats).catch(() => {});
      setSelectedIds(new Set());
      if (result.errors.length > 0) alert(`Completed with errors:\n${result.errors.join("\n")}`);
    } catch (e: any) { alert(e.message || "Batch action failed."); }
    finally { setBatching(false); setShowBatchPayModal(false); }
  }

  async function handleExportSelected() {
    const sel = filtered.filter(i => selectedIds.has(i.id));
    const rows = sel.map(i => ({ Invoice: i.invoice_code, Client: i.client?.company_name, Amount: i.total_amount, Balance: i.balance_due, Status: i.status, Due: i.due_date || "" }));
    downloadCSV(`invoices-selected-${new Date().toISOString().slice(0,10)}.csv`, rows);
    setSelectedIds(new Set());
  }

  async function handleCreateQuotation() {
    if (!quotForm.client_id || !quotForm.valid_until || !quotForm.total_amount) { setQuotError("Client, valid until, and total amount are required."); return; }
    setQuotSaving(true); setQuotError("");
    try {
      const created = await api.createQuotation({
        client_id: parseInt(quotForm.client_id),
        project_id: quotForm.project_id ? parseInt(quotForm.project_id) : undefined,
        valid_until: quotForm.valid_until,
        fee_structure: quotForm.fee_structure,
        estimated_hours: quotForm.estimated_hours ? parseFloat(quotForm.estimated_hours) : 0,
        estimated_disbursements: quotForm.estimated_disbursements ? parseFloat(quotForm.estimated_disbursements) : 0,
        buffer_percentage: quotForm.buffer_percentage ? parseFloat(quotForm.buffer_percentage) : 0,
        total_amount: parseFloat(quotForm.total_amount),
        currency: quotForm.currency,
      });
      setQuotations(prev => [created, ...prev]);
      setShowQuotModal(false);
      setQuotForm({ client_id: "", project_id: "", valid_until: "", fee_structure: "Fixed Fee", estimated_hours: "", estimated_disbursements: "", buffer_percentage: "", total_amount: "", currency: "INR" });
    } catch (e: any) { setQuotError(e.message || "Failed to create quotation."); }
    finally { setQuotSaving(false); }
  }

  async function handleConvert(id: number) {
    if (!confirm("Convert this quotation to an invoice?")) return;
    setConverting(id);
    try {
      const inv = await api.convertQuotationToInvoice(id);
      setInvoices(prev => [inv, ...prev]);
      setQuotations(prev => prev.map(q => q.id === id ? { ...q, status: "Accepted" as const } : q));
      setActiveTab("invoices");
    } catch (e: any) { alert(e.message || "Conversion failed."); }
    finally { setConverting(null); }
  }

  async function handleDeleteQuotation(id: number) {
    if (!confirm("Cancel this quotation?")) return;
    try {
      await api.deleteQuotation(id);
      setQuotations(prev => prev.map(q => q.id === id ? { ...q, status: "Cancelled" as const } : q));
    } catch (e: any) { alert(e.message || "Failed to cancel."); }
  }

  async function handleSaveIndia() {
    if (!indiaForm.project_id || !indiaForm.docket_number || !indiaForm.invoice_date) {
      setIndiaErr("Project, docket number and invoice date are required."); return;
    }
    setIndiaSaving(true); setIndiaErr("");
    const gst = computeIndiaGst(indiaForm);
    const payload: Partial<PatentInvoiceIn> = {
      type:                             indiaForm.type,
      project_id:                       parseInt(indiaForm.project_id),
      docket_number:                    indiaForm.docket_number,
      invoice_date:                     indiaForm.invoice_date || undefined,
      tax_invoice_date:                 indiaForm.tax_invoice_date || undefined,
      tax_serial_number:                indiaForm.tax_serial_number || undefined,
      client_code_prefix:               indiaForm.client_code_prefix || undefined,
      invention_number:                 indiaForm.invention_number || undefined,
      patent_office_code:               indiaForm.patent_office_code || undefined,
      first_inventor_name:              indiaForm.first_inventor_name || undefined,
      invention_title:                  indiaForm.invention_title || undefined,
      service_code:                     indiaForm.service_code || undefined,
      client_name:                      indiaForm.client_name || undefined,
      client_reference:                 indiaForm.client_reference || undefined,
      state_of_supply:                  indiaForm.state_of_supply || undefined,
      entity_status:                    indiaForm.entity_status || undefined,
      patent_office_application_number: indiaForm.patent_office_application_number || undefined,
      additional_information:           indiaForm.additional_information || undefined,
      patent_office_acknowledgement:    indiaForm.patent_office_acknowledgement || undefined,
      remarks:                          indiaForm.remarks || undefined,
      uin_old:                          indiaForm.uin_old || undefined,
      uin_old_2:                        indiaForm.uin_old_2 || undefined,
      patent_office_fees:               parseFloat(indiaForm.patent_office_fees) || 0,
      service_fees:                     parseFloat(indiaForm.service_fees) || 0,
      other_expenses:                   parseFloat(indiaForm.other_expenses) || 0,
      attorney_fees:                    parseFloat(indiaForm.attorney_fees) || 0,
      consultant_fees:                  parseFloat(indiaForm.consultant_fees) || 0,
      referral_fees:                    parseFloat(indiaForm.referral_fees) || 0,
      igst_amount:                      gst.igst,
      cgst_amount:                      gst.cgst,
      sgst_amount:                      gst.sgst,
      invoice_amount:                   gst.total,
      net_revenue:                      gst.net,
      currency:                         "INR",
    };
    try {
      if (editIndiaRec) {
        const updated = await api.updatePatentInvoiceIn(editIndiaRec.id, payload);
        setIndiaRecords(prev => prev.map(r => r.id === editIndiaRec.id ? updated : r));
      } else {
        const created = await api.createPatentInvoiceIn(payload);
        setIndiaRecords(prev => [created, ...prev]);
      }
      setShowIndiaModal(false);
    } catch (e: any) { setIndiaErr(e.message || "Failed to save."); }
    finally { setIndiaSaving(false); }
  }

  function openCreateIndia(prefill?: Partial<typeof indiaForm>) {
    setEditIndiaRec(null);
    setIndiaForm({ ...blankIndia(), ...prefill });
    setIndiaErr("");
    setShowIndiaModal(true);
  }

  function openEditIndia(r: PatentInvoiceIn) {
    setEditIndiaRec(r);
    setIndiaForm({
      type: r.type, project_id: String(r.project_id), client_id: String(r.client_id),
      docket_number: r.docket_number ?? "",
      invoice_uin: r.invoice_uin ?? "",
      invoice_date: r.invoice_date ? String(r.invoice_date).split("T")[0] : "",
      tax_invoice_date: r.tax_invoice_date ? String(r.tax_invoice_date).split("T")[0] : "",
      tax_serial_number: r.tax_serial_number ?? "",
      client_code_prefix: r.client_code_prefix ?? "",
      invention_number: r.invention_number ?? "",
      patent_office_code: r.patent_office_code ?? "",
      first_inventor_name: r.first_inventor_name ?? "",
      invention_title: r.invention_title ?? "",
      service_code: r.service_code ?? "",
      client_name: r.client_name ?? "",
      client_reference: r.client_reference ?? "",
      state_of_supply: r.state_of_supply ?? "",
      entity_status: r.entity_status ?? "",
      patent_office_application_number: r.patent_office_application_number ?? "",
      additional_information: r.additional_information ?? "",
      patent_office_acknowledgement: r.patent_office_acknowledgement ?? "",
      remarks: r.remarks ?? "",
      uin_old: r.uin_old ?? "",
      uin_old_2: r.uin_old_2 ?? "",
      patent_office_fees: String(r.patent_office_fees ?? 0),
      service_fees: String(r.service_fees ?? 0),
      other_expenses: String(r.other_expenses ?? 0),
      attorney_fees: String(r.attorney_fees ?? 0),
      consultant_fees: String(r.consultant_fees ?? 0),
      referral_fees: String(r.referral_fees ?? 0),
    });
    setIndiaErr("");
    setShowIndiaModal(true);
  }

  const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";
  const statuses = ["All", "Draft", "Sent", "Paid", "Overdue", "Partially Paid", "Cancelled"];

  return (
    <AppLayout>
      <Head title="Financial Suite" />
      <PageHeader
        eyebrow="Finance"
        title="Financial Suite"
        description="Invoices, payments, quotations, and revenue analytics"
        actions={
          <>
            {!isClientUser && (
              <>
                <Button variant="outline" onClick={() => openCreateIndia({ type: "quote" })}><Plus className="h-4 w-4 mr-2" />New IN Quote</Button>
                <Button onClick={() => openCreateIndia({ type: "invoice" })}><Plus className="h-4 w-4 mr-2" />New IN Invoice</Button>
              </>
            )}
          </>
        }
      />

      {/* New Invoice Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm overflow-y-auto py-8">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">New Invoice</h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {saveError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{saveError}</div>}
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Client *</label>
                  <select value={form.client_id} onChange={e => setForm(p => ({ ...p, client_id: e.target.value }))} className={inputCls}>
                    <option value="">Select client</option>
                    {clients.map(c => <option key={c.id} value={c.id}>{c.company_name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Case (optional)</label>
                  <select value={form.project_id} onChange={e => setForm(p => ({ ...p, project_id: e.target.value }))} className={inputCls}>
                    <option value="">None</option>
                    {projects.map(p => <option key={p.id} value={p.id}>{p.project_code} — {p.project_name}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Due Date *</label>
                  <input type="date" value={form.due_date} onChange={e => setForm(p => ({ ...p, due_date: e.target.value }))} className={inputCls} />
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Payment Terms</label>
                  <select value={form.payment_terms} onChange={e => setForm(p => ({ ...p, payment_terms: e.target.value }))} className={inputCls}>
                    {["Net 15", "Net 30", "Net 45", "Net 60", "Due on Receipt"].map(t => <option key={t}>{t}</option>)}
                  </select>
                </div>
              </div>
              <div>
                <div className="flex items-center justify-between mb-2">
                  <label className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Line Items</label>
                  <button onClick={addItem} className="text-xs text-gold hover:text-gold/80 flex items-center gap-1"><Plus className="h-3 w-3" />Add Line</button>
                </div>
                <div className="space-y-2">
                  {items.map((item, idx) => (
                    <div key={idx} className="flex gap-2">
                      <input value={item.description} onChange={e => updateItem(idx, "description", e.target.value)} placeholder="Description of service"
                        className="flex-1 h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      <input type="number" value={item.amount} onChange={e => updateItem(idx, "amount", e.target.value)} placeholder="Amount (₹)"
                        className="w-32 h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      {items.length > 1 && (
                        <button onClick={() => removeItem(idx)} className="p-1.5 rounded hover:bg-destructive/10 text-muted-foreground hover:text-destructive"><Trash2 className="h-4 w-4" /></button>
                      )}
                    </div>
                  ))}
                </div>
                <div className="mt-3 text-right text-sm space-y-0.5">
                  <div className="text-muted-foreground">Subtotal: <span className="font-mono">₹{subtotal.toLocaleString("en-IN")}</span></div>
                  {invoiceGst.type === "export" ? (
                    <div className="text-muted-foreground text-xs">No GST <span className="text-purple-400">(Export — Zero Rated)</span></div>
                  ) : invoiceGst.lines.map(line => (
                    <div key={line.label} className="text-muted-foreground">{line.label}: <span className="font-mono">₹{line.amount.toFixed(2)}</span></div>
                  ))}
                  <div className="font-semibold border-t border-border pt-0.5 mt-0.5">Total: <span className="font-mono text-gold">₹{invoiceGst.total.toFixed(2)}</span></div>
                </div>
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Notes</label>
                <textarea value={form.notes} onChange={e => setForm(p => ({ ...p, notes: e.target.value }))}
                  rows={2} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleCreate} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Create Invoice
              </Button>
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {/* New Quotation Modal */}
      {showQuotModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm overflow-y-auto py-8">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">New Quotation</h2>
              <button onClick={() => setShowQuotModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {quotError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{quotError}</div>}
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Client *</label>
                  <select value={quotForm.client_id} onChange={e => setQuotForm(p => ({ ...p, client_id: e.target.value }))} className={inputCls}>
                    <option value="">Select client</option>
                    {clients.map(c => <option key={c.id} value={c.id}>{c.company_name}</option>)}
                  </select>
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Case (optional)</label>
                  <select value={quotForm.project_id} onChange={e => setQuotForm(p => ({ ...p, project_id: e.target.value }))} className={inputCls}>
                    <option value="">None</option>
                    {projects.map(p => <option key={p.id} value={p.id}>{p.project_code} — {p.project_name}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Valid Until *</label>
                  <input type="date" value={quotForm.valid_until} onChange={e => setQuotForm(p => ({ ...p, valid_until: e.target.value }))} className={inputCls} />
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Fee Structure</label>
                  <select value={quotForm.fee_structure} onChange={e => setQuotForm(p => ({ ...p, fee_structure: e.target.value as typeof quotForm.fee_structure }))} className={inputCls}>
                    {["Fixed Fee", "Hourly", "Blended"].map(t => <option key={t}>{t}</option>)}
                  </select>
                </div>
              </div>
              <div className="grid grid-cols-3 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Est. Hours</label>
                  <input type="number" value={quotForm.estimated_hours} onChange={e => setQuotForm(p => ({ ...p, estimated_hours: e.target.value }))} placeholder="0" className={inputCls} />
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Disbursements</label>
                  <input type="number" value={quotForm.estimated_disbursements} onChange={e => setQuotForm(p => ({ ...p, estimated_disbursements: e.target.value }))} placeholder="0" className={inputCls} />
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Buffer %</label>
                  <input type="number" value={quotForm.buffer_percentage} onChange={e => setQuotForm(p => ({ ...p, buffer_percentage: e.target.value }))} placeholder="0" className={inputCls} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Fee Amount (excl. GST) *</label>
                  <input type="number" value={quotForm.total_amount} onChange={e => setQuotForm(p => ({ ...p, total_amount: e.target.value }))} placeholder="0.00" className={inputCls} />
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Currency</label>
                  <select value={quotForm.currency} onChange={e => setQuotForm(p => ({ ...p, currency: e.target.value }))} className={inputCls}>
                    {["INR", "USD", "EUR", "GBP", "AED"].map(c => <option key={c}>{c}</option>)}
                  </select>
                </div>
              </div>
              {quotForm.total_amount && parseFloat(quotForm.total_amount) > 0 && (() => {
                const qClient = clients.find((c: any) => c.id === parseInt(quotForm.client_id));
                const qGst = computeGstInfo(qClient, parseFloat(quotForm.total_amount));
                return (
                  <div className="text-right text-sm space-y-0.5 bg-muted/30 rounded-md px-3 py-2">
                    <div className="text-muted-foreground">Fee (excl. GST): <span className="font-mono">₹{parseFloat(quotForm.total_amount).toLocaleString("en-IN")}</span></div>
                    {qGst.type === "export" ? (
                      <div className="text-xs text-muted-foreground">No GST <span className="text-purple-400">(Export — Zero Rated)</span></div>
                    ) : qGst.lines.map(line => (
                      <div key={line.label} className="text-muted-foreground">{line.label}: <span className="font-mono">₹{line.amount.toFixed(2)}</span></div>
                    ))}
                    <div className="font-semibold border-t border-border pt-0.5">Total: <span className="font-mono text-gold">₹{qGst.total.toFixed(2)}</span></div>
                  </div>
                );
              })()}
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleCreateQuotation} disabled={quotSaving}>
                {quotSaving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Create Quotation
              </Button>
              <Button variant="outline" onClick={() => setShowQuotModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {/* Record Payment Modal */}
      {showPayment && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Record Payment</h2>
              <button onClick={() => setShowPayment(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <p className="text-sm text-muted-foreground mb-4">Invoice {showPayment.invoice_code} · Balance due: <strong>₹{parseFloat(showPayment.balance_due).toLocaleString("en-IN")}</strong></p>
            <div className="space-y-3">
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Amount (₹) *</label>
                <input type="number" value={payForm.amount} onChange={e => setPayForm(p => ({ ...p, amount: e.target.value }))} placeholder={showPayment.balance_due} className={inputCls} />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Payment Method</label>
                <select value={payForm.payment_method} onChange={e => setPayForm(p => ({ ...p, payment_method: e.target.value }))} className={inputCls}>
                  {["Bank Transfer", "NEFT", "RTGS", "UPI", "Cheque", "Cash"].map(m => <option key={m}>{m}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Transaction Reference</label>
                <input value={payForm.transaction_reference} onChange={e => setPayForm(p => ({ ...p, transaction_reference: e.target.value }))} placeholder="UTR / reference number" className={inputCls} />
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handlePayment} disabled={paying}>
                {paying ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Record Payment
              </Button>
              <Button variant="outline" onClick={() => setShowPayment(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {/* Batch Pay Method Modal */}
      {showBatchPayModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Batch Mark Paid</h2>
              <button onClick={() => setShowBatchPayModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <p className="text-sm text-muted-foreground mb-4">Marking {selectedIds.size} invoice{selectedIds.size !== 1 ? "s" : ""} as fully paid.</p>
            <div>
              <label className="block text-xs text-muted-foreground mb-1">Payment Method</label>
              <select value={batchPayMethod} onChange={e => setBatchPayMethod(e.target.value)} className={inputCls}>
                {["Bank Transfer", "NEFT", "RTGS", "UPI", "Cheque", "Cash"].map(m => <option key={m}>{m}</option>)}
              </select>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={() => handleBatchAction("mark_paid", { payment_method: batchPayMethod })} disabled={batching}>
                {batching ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Confirm
              </Button>
              <Button variant="outline" onClick={() => setShowBatchPayModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {kpiModal && <FinancialKpiModal kpi={kpiModal} onClose={() => setKpiModal(null)} />}

      <div className="px-8 py-6 space-y-6">
        {/* KPI Cards */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {KPI_DEFS.map((kpi) => {
            const raw = stats[kpi.key as keyof typeof stats] ?? 0;
            const display = kpi.isCurrency ? fmt(raw as number) : `${raw}`;
            const suffix  = !kpi.isCurrency ? " invoices" : "";
            const Icon    = kpi.icon;
            return (
              <button key={kpi.key} onClick={() => setKpiModal(kpi)}
                className="rounded-xl border border-border bg-card p-5 text-left transition-all hover:shadow-md hover:border-gold/40 cursor-pointer">
                <div className="flex items-center gap-2 mb-2">
                  <Icon className={`h-4 w-4 ${kpi.color}`} />
                  <span className="text-xs text-muted-foreground">{kpi.label}</span>
                </div>
                <div className={`text-xl font-bold ${kpi.color}`}>{display}{suffix}</div>
                <div className="mt-1 text-xs text-muted-foreground">Click to view</div>
              </button>
            );
          })}
        </div>

        {/* Tab bar */}
        <div className="flex items-center gap-1 border-b border-border">
          <button className="px-4 py-2 text-sm font-medium border-b-2 border-gold text-gold flex items-center gap-1.5">
            <IndianRupee className="h-3.5 w-3.5" />Indian Patents
          </button>
        </div>

        {/* Invoices Tab — removed; use Indian Patents tab */}
        {false && (
          <>
            <div className="flex items-center gap-2 flex-wrap">
              {statuses.map(s => (
                <button key={s} onClick={() => { setFilterStatus(s); setSelectedIds(new Set()); }}
                  className={`px-3 py-1 rounded text-xs font-medium border transition-colors ${filterStatus === s ? "bg-gold text-black border-gold" : "border-border text-muted-foreground hover:text-foreground"}`}>
                  {s}
                </button>
              ))}
            </div>

            {loading ? (
              <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
            ) : (
              <div className="relative">
                <Card className="border-border">
                  <CardHeader><CardTitle className="font-display">Invoices</CardTitle></CardHeader>
                  <CardContent className="p-0">
                    <table className="w-full text-sm">
                      <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                        <tr>
                          {!isClientUser && (
                            <th className="px-4 py-3 w-8">
                              <button onClick={toggleSelectAll}>
                                {allSelected ? <CheckSquare className="h-4 w-4 text-gold" /> : <Square className="h-4 w-4" />}
                              </button>
                            </th>
                          )}
                          <th className="px-4 py-3 text-left">Invoice</th>
                          <th className="px-4 py-3 text-left">Client</th>
                          <th className="px-4 py-3 text-left">Total</th>
                          <th className="px-4 py-3 text-left">Balance</th>
                          <th className="px-4 py-3 text-left">Status</th>
                          <th className="px-4 py-3 text-left">Due</th>
                          <th className="px-4 py-3 text-left">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {filtered.map((i) => (
                          <tr key={i.id} className={`border-t border-border hover:bg-muted/30 ${selectedIds.has(i.id) ? "bg-gold/5" : ""}`}>
                            {!isClientUser && (
                              <td className="px-4 py-3">
                                <button onClick={() => toggleSelect(i.id)}>
                                  {selectedIds.has(i.id) ? <CheckSquare className="h-4 w-4 text-gold" /> : <Square className="h-4 w-4 text-muted-foreground" />}
                                </button>
                              </td>
                            )}
                            <td className="px-4 py-3 font-mono text-xs font-medium">{i.invoice_code}</td>
                            <td className="px-4 py-3">{i.client?.company_name}</td>
                            <td className="px-4 py-3 font-medium">{fmt(parseFloat(i.total_amount))}</td>
                            <td className="px-4 py-3 text-muted-foreground">{fmt(parseFloat(i.balance_due || 0))}</td>
                            <td className="px-4 py-3"><Badge variant={statusColor(i.status)}>{i.status}</Badge></td>
                            <td className="px-4 py-3 text-muted-foreground text-xs">{fmtDate(i.due_date)}</td>
                            <td className="px-4 py-3">
                              {["Sent", "Overdue", "Partially Paid"].includes(i.status) && (
                                <Button size="sm" variant="outline" className="h-7 text-xs"
                                  onClick={() => { setShowPayment(i); setPayForm({ amount: i.balance_due, payment_method: "Bank Transfer", transaction_reference: "" }); }}>
                                  Record Payment
                                </Button>
                              )}
                            </td>
                          </tr>
                        ))}
                        {filtered.length === 0 && (
                          <tr><td colSpan={isClientUser ? 7 : 8} className="px-4 py-8 text-center text-muted-foreground text-sm">No invoices found</td></tr>
                        )}
                      </tbody>
                    </table>
                  </CardContent>
                </Card>

                {/* Floating batch action bar */}
                {selectedIds.size > 0 && (
                  <div className="sticky bottom-4 mt-4 mx-auto max-w-2xl">
                    <div className="rounded-xl border border-border bg-card shadow-xl px-4 py-3 flex items-center gap-3 flex-wrap">
                      <span className="text-sm font-medium text-gold">{selectedIds.size} selected</span>
                      <div className="flex items-center gap-2 flex-wrap flex-1">
                        <Button size="sm" variant="outline" className="h-8 text-xs" disabled={batching} onClick={() => handleBatchAction("mark_sent")}>
                          {batching ? <Loader2 className="h-3 w-3 animate-spin mr-1" /> : null}Mark Sent
                        </Button>
                        <Button size="sm" variant="outline" className="h-8 text-xs text-green-500 border-green-500/30 hover:bg-green-500/10" disabled={batching} onClick={() => setShowBatchPayModal(true)}>
                          Mark Paid
                        </Button>
                        <Button size="sm" variant="outline" className="h-8 text-xs text-destructive border-destructive/30 hover:bg-destructive/10" disabled={batching}
                          onClick={() => { if (confirm(`Cancel ${selectedIds.size} invoice(s)?`)) handleBatchAction("cancel"); }}>
                          Cancel
                        </Button>
                        <Button size="sm" variant="outline" className="h-8 text-xs" disabled={batching} onClick={handleExportSelected}>
                          <Download className="h-3 w-3 mr-1" />Export
                        </Button>
                      </div>
                      <button onClick={() => setSelectedIds(new Set())} className="text-xs text-muted-foreground hover:text-foreground ml-auto">Clear</button>
                    </div>
                  </div>
                )}
              </div>
            )}
          </>
        )}

        {/* Quotations Tab — removed; use Indian Patents tab */}
        {false && (
          <>
            {quotLoading ? (
              <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
            ) : (
              <Card className="border-border">
                <CardHeader><CardTitle className="font-display flex items-center gap-2"><FileText className="h-4 w-4" />Quotations</CardTitle></CardHeader>
                <CardContent className="p-0">
                  <table className="w-full text-sm">
                    <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                      <tr>
                        <th className="px-4 py-3 text-left">Quote #</th>
                        <th className="px-4 py-3 text-left">Client</th>
                        <th className="px-4 py-3 text-left">Fee Structure</th>
                        <th className="px-4 py-3 text-left">Total</th>
                        <th className="px-4 py-3 text-left">Valid Until</th>
                        <th className="px-4 py-3 text-left">Status</th>
                        <th className="px-4 py-3 text-left">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {quotations.map((q) => (
                        <tr key={q.id} className="border-t border-border hover:bg-muted/30">
                          <td className="px-4 py-3 font-mono text-xs font-medium text-gold">{q.quote_code}</td>
                          <td className="px-4 py-3">{q.client?.company_name ?? "—"}</td>
                          <td className="px-4 py-3 text-muted-foreground">{q.fee_structure}</td>
                          <td className="px-4 py-3 font-medium">{q.currency} {parseFloat(String(q.total_amount)).toLocaleString()}</td>
                          <td className="px-4 py-3 text-muted-foreground text-xs">{fmtDate(q.valid_until)}</td>
                          <td className="px-4 py-3">
                            <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${QUOTE_STATUS_COLOR[q.status] ?? "bg-muted text-muted-foreground"}`}>{q.status}</span>
                          </td>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-2">
                              {["Sent", "Accepted"].includes(q.status) && (
                                <Button size="sm" variant="outline" className="h-7 text-xs text-green-500 border-green-500/30 hover:bg-green-500/10"
                                  disabled={converting === q.id} onClick={() => handleConvert(q.id)}>
                                  {converting === q.id ? <Loader2 className="h-3 w-3 animate-spin mr-1" /> : null}Convert to Invoice
                                </Button>
                              )}
                              {q.status === "Draft" && (
                                <Button size="sm" variant="outline" className="h-7 text-xs text-destructive border-destructive/30 hover:bg-destructive/10" onClick={() => handleDeleteQuotation(q.id)}>
                                  Cancel
                                </Button>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))}
                      {quotations.length === 0 && (
                        <tr><td colSpan={7} className="px-4 py-8 text-center text-muted-foreground text-sm">No quotations found. Create one using the button above.</td></tr>
                      )}
                    </tbody>
                  </table>
                </CardContent>
              </Card>
            )}
          </>
        )}
        {/* ── Indian Patents Tab ──────────────────────────────────────────────── */}
        {activeTab === "india" && (
          <>
            {/* Filters */}
            <div className="space-y-2">
              <div className="flex items-center gap-2 flex-wrap">
                <div className="relative">
                  <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <input className="h-9 pl-9 pr-3 w-64 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    placeholder="Search UIN, client, title…" value={indiaSearch}
                    onChange={e => setIndiaSearch(e.target.value)}
                    onKeyDown={e => e.key === "Enter" && loadIndia()} />
                </div>
                {(["all", "invoice", "quote"] as const).map(t => (
                  <button key={t} onClick={() => { setIndiaType(t); loadIndia({ type: t }); }}
                    className={`px-3 py-1 rounded text-xs font-medium border transition-colors ${indiaTypeFilter === t ? "bg-gold text-black border-gold" : "border-border text-muted-foreground hover:text-foreground"}`}>
                    {t === "all" ? "All" : t === "invoice" ? "Invoices" : "Quotes"}
                  </button>
                ))}
                <div className="h-4 w-px bg-border" />
                {["all", "Draft", "Sent", "Accepted", "Rejected", "Cancelled"].map(s => {
                  const colors: Record<string, string> = {
                    Draft: "border-muted-foreground text-muted-foreground bg-muted/40",
                    Sent: "border-sky-500 text-sky-400 bg-sky-500/10",
                    Accepted: "border-green-500 text-green-400 bg-green-500/10",
                    Rejected: "border-red-500 text-red-400 bg-red-500/10",
                    Cancelled: "border-destructive text-destructive bg-destructive/10",
                  };
                  return (
                    <button key={s} onClick={() => { setIndiaStatus(s); loadIndia({ status: s }); }}
                      className={`px-3 py-1 rounded text-xs font-medium border transition-colors ${indiaStatusFilter === s ? (s === "all" ? "bg-gold text-black border-gold" : colors[s]) : "border-border text-muted-foreground hover:text-foreground"}`}>
                      {s === "all" ? "All Status" : s}
                    </button>
                  );
                })}
                <div className="ml-auto flex gap-2">
                  {!isClientUser && <Button size="sm" variant="outline" className="h-8 text-xs" onClick={() => exportIndiaCSV(false)}><Download className="h-3 w-3 mr-1" />Export CSV</Button>}
                  <Button size="sm" variant="outline" className="h-8 text-xs" onClick={() => loadIndia()}>
                    {indiaLoading ? <Loader2 className="h-3 w-3 animate-spin" /> : "Refresh"}
                  </Button>
                </div>
              </div>
            </div>

            {indiaLoading ? (
              <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
            ) : (
              <Card className="border-border">
                <CardContent className="p-0">
                  <div className="overflow-x-auto">
                    <table className="text-xs whitespace-nowrap">
                      <thead className="bg-muted/40 text-[10px] uppercase tracking-wider text-muted-foreground">
                        <tr>
                          {!isClientUser && (
                            <th className="px-2 py-2.5 sticky left-0 bg-muted/40 z-20 w-6">
                              <input type="checkbox" className="h-3 w-3 cursor-pointer" checked={allIndiaSelected} onChange={toggleSelectAllIndia} />
                            </th>
                          )}
                          <th className="px-2 py-2.5 text-left sticky left-0 bg-muted/40 z-10 min-w-[130px]">Type / Status</th>
                          <th className="px-2 py-2.5 text-left sticky left-[130px] bg-muted/40 z-10 min-w-[160px]" title="Invoice UIN">UIN</th>
                          <th className="px-2 py-2.5 text-left min-w-[90px]" title="Invoice Date">Inv Date</th>
                          <th className="px-2 py-2.5 text-left min-w-[90px]" title="Tax Invoice Date">Tax Date ⁱ</th>
                          <th className="px-2 py-2.5 text-left min-w-[90px]" title="Tax Serial Number">Tax Serial ⁱ</th>
                          <th className="px-2 py-2.5 text-left min-w-[70px]" title="Client Reference">Cl Ref</th>
                          <th className="px-2 py-2.5 text-left min-w-[60px]" title="Client Code (first 4 chars)">Cl Code</th>
                          <th className="px-2 py-2.5 text-left min-w-[55px]" title="Invention # (chars 5-7)">Inv #</th>
                          <th className="px-2 py-2.5 text-left min-w-[55px]" title="Patent Office Code">Office</th>
                          <th className="px-2 py-2.5 text-left min-w-[120px]" title="First Inventor Name">Inventor</th>
                          <th className="px-2 py-2.5 text-left min-w-[150px]" title="Invention Title">Title</th>
                          <th className="px-2 py-2.5 text-left min-w-[90px]" title="Entity Status">Entity</th>
                          <th className="px-2 py-2.5 text-left min-w-[100px]" title="PO Application Number">App No.</th>
                          <th className="px-2 py-2.5 text-left min-w-[70px]" title="Service Code">Svc Code</th>
                          <th className="px-2 py-2.5 text-left min-w-[110px]" title="Additional Information">Addl Info</th>
                          <th className="px-2 py-2.5 text-left min-w-[80px]" title="State of Supply">State</th>
                          <th className="px-2 py-2.5 text-left min-w-[120px]" title="Client Name">Client</th>
                          <th className="px-2 py-2.5 text-left min-w-[100px]" title="PO Acknowledgement">Office Ack</th>
                          <th className="px-2 py-2.5 text-right min-w-[85px]" title="Patent Office Fees">PO Fees ₹</th>
                          <th className="px-2 py-2.5 text-right min-w-[80px]" title="Service Fees">Svc Fees ₹</th>
                          <th className="px-2 py-2.5 text-right min-w-[65px]" title="IGST 18%">IGST</th>
                          <th className="px-2 py-2.5 text-right min-w-[65px]" title="CGST 9%">CGST</th>
                          <th className="px-2 py-2.5 text-right min-w-[65px]" title="SGST 9%">SGST</th>
                          <th className="px-2 py-2.5 text-right min-w-[80px]" title="Other Expenses">Other Exp</th>
                          <th className="px-2 py-2.5 text-right min-w-[90px] font-bold" title="Invoice Amount">Total ₹</th>
                          {isInternal && <>
                            <th className="px-2 py-2.5 text-right min-w-[80px] text-amber-600" title="Attorney Fees (internal)">Atty ₹ *</th>
                            <th className="px-2 py-2.5 text-right min-w-[80px] text-amber-600" title="Consultant Fees (internal)">Consult ₹ *</th>
                            <th className="px-2 py-2.5 text-right min-w-[80px] text-amber-600" title="Referral Fees (internal)">Referral ₹ *</th>
                            <th className="px-2 py-2.5 text-right min-w-[85px] text-green-600" title="Net Revenue (internal)">Net Rev ₹ *</th>
                          </>}
                          <th className="px-2 py-2.5 text-left min-w-[100px]" title="Remarks">Remarks</th>
                          <th className="px-2 py-2.5 text-left min-w-[80px]" title="UIN Old">UIN Old</th>
                          <th className="px-2 py-2.5 text-left min-w-[80px]" title="UIN Old 2">UIN Old 2</th>
                          <th className="px-2 py-2.5 text-left min-w-[120px]">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {indiaRecords.map(r => {
                          const isInv = r.type === "invoice";
                          const f = (n: number | null | undefined) => (n ?? 0).toLocaleString("en-IN", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                          const statusCls: Record<string, string> = {
                            Draft:     "bg-muted/60 text-muted-foreground",
                            Sent:      "bg-sky-500/15 text-sky-400",
                            Accepted:  "bg-green-500/15 text-green-400",
                            Rejected:  "bg-red-500/15 text-red-400",
                            Cancelled: "bg-destructive/15 text-destructive",
                          };
                          const isSelected = indiaSelectedIds.has(r.id);
                          return (
                            <tr key={r.id} className={`border-t border-border hover:bg-muted/20 ${isSelected ? "bg-gold/5" : ""}`}>
                              {!isClientUser && (
                                <td className="px-2 py-1.5 sticky left-0 bg-card z-20">
                                  <input type="checkbox" className="h-3 w-3 cursor-pointer" checked={isSelected} onChange={() => toggleSelectIndia(r.id)} />
                                </td>
                              )}
                              <td className="px-2 py-1.5 sticky left-0 bg-card z-10">
                                <div className="flex flex-col gap-0.5">
                                  <span className={`px-1.5 py-0.5 rounded text-[10px] font-semibold w-fit ${isInv ? "bg-blue-500/15 text-blue-400" : "bg-violet-500/15 text-violet-400"}`}>
                                    {isInv ? "INV" : "QUO"}
                                  </span>
                                  <span className={`px-1.5 py-0.5 rounded text-[9px] font-medium w-fit ${statusCls[r.status] ?? "bg-muted text-muted-foreground"}`}>
                                    {r.status}
                                  </span>
                                </div>
                              </td>
                              <td className="px-2 py-1.5 sticky left-[130px] bg-card z-10 font-mono text-gold font-semibold">{r.invoice_uin || r.docket_number}</td>
                              <td className="px-2 py-1.5 font-mono text-muted-foreground">{fmtDate(r.invoice_date)}</td>
                              <td className="px-2 py-1.5 font-mono text-muted-foreground">{isInv ? fmtDate(r.tax_invoice_date) : "—"}</td>
                              <td className="px-2 py-1.5 text-muted-foreground">{isInv ? (r.tax_serial_number || "—") : "—"}</td>
                              <td className="px-2 py-1.5 font-mono text-muted-foreground">{r.client_reference || "—"}</td>
                              <td className="px-2 py-1.5 font-mono font-semibold">{r.client_code_prefix || "—"}</td>
                              <td className="px-2 py-1.5 font-mono text-muted-foreground">{r.invention_number || "—"}</td>
                              <td className="px-2 py-1.5 font-mono">{r.patent_office_code || "—"}</td>
                              <td className="px-2 py-1.5 max-w-[120px] truncate">{r.first_inventor_name || "—"}</td>
                              <td className="px-2 py-1.5 max-w-[150px] truncate">{r.invention_title || "—"}</td>
                              <td className="px-2 py-1.5 text-muted-foreground">{r.entity_status || "—"}</td>
                              <td className="px-2 py-1.5 font-mono text-muted-foreground">{r.patent_office_application_number || "—"}</td>
                              <td className="px-2 py-1.5 font-mono">{r.service_code || "—"}</td>
                              <td className="px-2 py-1.5 max-w-[110px] truncate text-muted-foreground">{r.additional_information || "—"}</td>
                              <td className="px-2 py-1.5 text-muted-foreground">{r.state_of_supply || "—"}</td>
                              <td className="px-2 py-1.5 max-w-[120px] truncate font-medium">{r.client_name || r.client?.legal_name || "—"}</td>
                              <td className="px-2 py-1.5 max-w-[100px] truncate text-muted-foreground">{r.patent_office_acknowledgement || "—"}</td>
                              <td className="px-2 py-1.5 text-right font-mono">{f(Number(r.patent_office_fees))}</td>
                              <td className="px-2 py-1.5 text-right font-mono">{f(Number(r.service_fees))}</td>
                              <td className="px-2 py-1.5 text-right font-mono text-muted-foreground">{Number(r.igst_amount) > 0 ? f(Number(r.igst_amount)) : "—"}</td>
                              <td className="px-2 py-1.5 text-right font-mono text-muted-foreground">{Number(r.cgst_amount) > 0 ? f(Number(r.cgst_amount)) : "—"}</td>
                              <td className="px-2 py-1.5 text-right font-mono text-muted-foreground">{Number(r.sgst_amount) > 0 ? f(Number(r.sgst_amount)) : "—"}</td>
                              <td className="px-2 py-1.5 text-right font-mono text-muted-foreground">{Number(r.other_expenses) > 0 ? f(Number(r.other_expenses)) : "—"}</td>
                              <td className="px-2 py-1.5 text-right font-mono font-bold text-gold">{f(Number(r.invoice_amount))}</td>
                              {isInternal && <>
                                <td className="px-2 py-1.5 text-right font-mono text-amber-500">{Number(r.attorney_fees) > 0 ? f(Number(r.attorney_fees)) : "—"}</td>
                                <td className="px-2 py-1.5 text-right font-mono text-amber-500">{Number(r.consultant_fees) > 0 ? f(Number(r.consultant_fees)) : "—"}</td>
                                <td className="px-2 py-1.5 text-right font-mono text-amber-500">{Number(r.referral_fees) > 0 ? f(Number(r.referral_fees)) : "—"}</td>
                                <td className="px-2 py-1.5 text-right font-mono font-semibold text-green-500">{f(Number(r.net_revenue))}</td>
                              </>}
                              <td className="px-2 py-1.5 max-w-[100px] truncate text-muted-foreground">{r.remarks || "—"}</td>
                              <td className="px-2 py-1.5 font-mono text-muted-foreground text-[10px]">{r.uin_old || "—"}</td>
                              <td className="px-2 py-1.5 font-mono text-muted-foreground text-[10px]">{r.uin_old_2 || "—"}</td>
                              <td className="px-2 py-1.5">
                                {!isClientUser && (
                                  <div className="flex gap-1 flex-wrap">
                                    <Button size="sm" variant="ghost" className="h-6 w-6 p-0" title="Edit" onClick={() => openEditIndia(r)}>
                                      <Pencil className="h-3 w-3" />
                                    </Button>
                                    {r.status === "Draft" && (
                                      <Button size="sm" variant="ghost" className="h-6 px-1.5 text-[10px] text-sky-400 hover:bg-sky-500/10" title="Mark Sent"
                                        onClick={() => changeIndiaStatus(r.id, "Sent")}>Send</Button>
                                    )}
                                    {r.status === "Sent" && isInv && (
                                      <Button size="sm" variant="ghost" className="h-6 px-1.5 text-[10px] text-green-400 hover:bg-green-500/10" title="Mark Accepted"
                                        onClick={() => changeIndiaStatus(r.id, "Accepted")}>Accept</Button>
                                    )}
                                    {!isInv && ["Draft","Sent"].includes(r.status) && (
                                      <Button size="sm" variant="ghost" className="h-6 px-1.5 text-[10px] text-violet-400 hover:bg-violet-500/10" title="Convert to Invoice"
                                        onClick={() => convertIndia(r.id)}>→INV</Button>
                                    )}
                                    {r.status !== "Cancelled" && (
                                      <Button size="sm" variant="ghost" className="h-6 w-6 p-0 text-destructive/60 hover:bg-destructive/10" title="Cancel"
                                        onClick={() => cancelIndiaRecord(r.id)}>
                                        <Trash2 className="h-3 w-3" />
                                      </Button>
                                    )}
                                  </div>
                                )}
                              </td>
                            </tr>
                          );
                        })}
                        {indiaRecords.length === 0 && (
                          <tr><td colSpan={isInternal ? 37 : 33} className="px-4 py-10 text-center text-muted-foreground">
                            No records found. Adjust filters or create your first record above.
                          </td></tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </CardContent>
              </Card>
            )}

            {/* Floating batch action bar */}
            {!isClientUser && indiaSelectedIds.size > 0 && (
              <div className="sticky bottom-4 mt-4 mx-auto max-w-3xl">
                <div className="rounded-xl border border-border bg-card shadow-2xl px-4 py-3 flex items-center gap-3 flex-wrap">
                  <span className="text-sm font-medium text-gold">{indiaSelectedIds.size} selected</span>
                  <div className="w-px h-5 bg-border" />
                  <div className="flex items-center gap-2 flex-wrap">
                    <Button size="sm" variant="outline" className="h-8 text-xs text-sky-400 border-sky-500/30 hover:bg-sky-500/10"
                      disabled={indiaBatching} onClick={() => handleBatchIndia("mark_sent")}>
                      {indiaBatching ? <Loader2 className="h-3 w-3 animate-spin mr-1" /> : null}Mark Sent
                    </Button>
                    <Button size="sm" variant="outline" className="h-8 text-xs text-green-400 border-green-500/30 hover:bg-green-500/10"
                      disabled={indiaBatching} onClick={() => handleBatchIndia("mark_accepted")}>
                      Accept
                    </Button>
                    <Button size="sm" variant="outline" className="h-8 text-xs text-red-400 border-red-500/30 hover:bg-red-500/10"
                      disabled={indiaBatching} onClick={() => handleBatchIndia("mark_rejected")}>
                      Reject
                    </Button>
                    <Button size="sm" variant="outline" className="h-8 text-xs text-destructive border-destructive/30 hover:bg-destructive/10"
                      disabled={indiaBatching}
                      onClick={() => { if (confirm(`Cancel ${indiaSelectedIds.size} record(s)?`)) handleBatchIndia("cancel"); }}>
                      Cancel
                    </Button>
                    <Button size="sm" variant="outline" className="h-8 text-xs" onClick={() => exportIndiaCSV(true)}>
                      <Download className="h-3 w-3 mr-1" />Export Selected
                    </Button>
                  </div>
                  <button onClick={() => setIndiaSelIds(new Set())} className="text-xs text-muted-foreground hover:text-foreground ml-auto">Clear</button>
                </div>
              </div>
            )}
          </>
        )}
      </div>

      {/* ── Indian Patent Invoice / Quote Modal ─────────────────────────────── */}
      {showIndiaModal && (() => {
        const gst = computeIndiaGst(indiaForm);
        const isInv = indiaForm.type === "invoice";
        const inp = "w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";
        const lbl = (t: string, req?: boolean) => (
          <div className="text-[10px] text-muted-foreground mb-0.5">{t}{req && <span className="text-destructive ml-0.5">*</span>}</div>
        );
        return (
          <div className="fixed inset-0 z-50 flex items-start justify-center bg-black/60 backdrop-blur-sm overflow-y-auto py-6 px-4">
            <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-5xl flex flex-col">
              {/* Header */}
              <div className="flex items-center justify-between px-6 py-4 border-b border-border sticky top-0 bg-background z-10 rounded-t-xl">
                <div>
                  <h2 className="font-display text-base font-semibold">
                    {editIndiaRec ? "Edit" : "New"} Indian Patent {isInv ? "Invoice" : "Quotation"}
                  </h2>
                  {editIndiaRec && (
                    <p className="text-xs text-muted-foreground mt-0.5 font-mono text-gold">{editIndiaRec.invoice_uin || editIndiaRec.docket_number}</p>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => sif("type", isInv ? "quote" : "invoice")}
                    className={`px-3 py-1 rounded text-xs font-medium border transition-colors ${isInv ? "bg-blue-500/15 text-blue-400 border-blue-500/30" : "bg-violet-500/15 text-violet-400 border-violet-500/30"}`}>
                    {isInv ? "Invoice" : "Quotation"} — click to toggle
                  </button>
                  <button onClick={() => setShowIndiaModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
                </div>
              </div>

              <div className="overflow-y-auto px-6 py-4 space-y-5">
                {indiaErr && (
                  <div className="flex items-center gap-2 p-3 rounded-md bg-destructive/10 border border-destructive/20 text-xs text-destructive">
                    <AlertCircle className="h-3.5 w-3.5 flex-shrink-0" />{indiaErr}
                  </div>
                )}

                {/* ── 1. Case (auto-filled from project) ── */}
                <section>
                  <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                    <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">1</span>
                    Case Details <span className="font-normal text-[9px]">(auto-filled on project select)</span>
                  </h3>
                  <div className="grid grid-cols-4 gap-2">
                    <div className="col-span-2">
                      {lbl("Project / Case *", true)}
                      <select value={indiaForm.project_id} onChange={e => fillFromProject(e.target.value)} className={inp}>
                        <option value="">— Select project —</option>
                        {projects.map(p => (
                          <option key={p.id} value={p.id}>{p.docket_number ?? p.project_code} — {p.project_name}</option>
                        ))}
                      </select>
                    </div>
                    <div>
                      {lbl("Docket Number - UIN *", true)}
                      <input value={indiaForm.docket_number} onChange={e => sif("docket_number", e.target.value)} className={inp} placeholder="e.g. A99M001INFER" />
                    </div>
                    <div>
                      {lbl("Invoice UIN (auto)")}
                      <input value={indiaForm.docket_number} readOnly className={inp + " bg-muted/30 cursor-not-allowed text-muted-foreground"} title="Computed on save as docket or docket/N" />
                    </div>
                    <div>
                      {lbl("Client Code (chars 1-4)")}
                      <input value={indiaForm.client_code_prefix} onChange={e => sif("client_code_prefix", e.target.value)} className={inp} />
                    </div>
                    <div>
                      {lbl("Invention # (chars 5-7)")}
                      <input value={indiaForm.invention_number} onChange={e => sif("invention_number", e.target.value)} className={inp} />
                    </div>
                    <div>
                      {lbl("Patent Office Code (chars 8-9)")}
                      <input value={indiaForm.patent_office_code} onChange={e => sif("patent_office_code", e.target.value)} className={inp} placeholder="IN / US / EP…" />
                    </div>
                    <div>
                      {lbl("Service Code")}
                      <input value={indiaForm.service_code} onChange={e => sif("service_code", e.target.value)} className={inp} />
                    </div>
                    <div className="col-span-2">
                      {lbl("First Inventor Name")}
                      <input value={indiaForm.first_inventor_name} onChange={e => sif("first_inventor_name", e.target.value)} className={inp} />
                    </div>
                    <div className="col-span-2">
                      {lbl("Invention Title")}
                      <input value={indiaForm.invention_title} onChange={e => sif("invention_title", e.target.value)} className={inp} />
                    </div>
                  </div>
                </section>

                {/* ── 2. Client ── */}
                <section>
                  <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                    <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">2</span>
                    Client Details
                  </h3>
                  <div className="grid grid-cols-3 gap-2">
                    <div className="col-span-2">
                      {lbl("Client Name")}
                      <input value={indiaForm.client_name} onChange={e => sif("client_name", e.target.value)} className={inp} />
                    </div>
                    <div>
                      {lbl("Client Reference (Referred by code)")}
                      <input value={indiaForm.client_reference} onChange={e => sif("client_reference", e.target.value)} className={inp} placeholder="Client code of referrer" />
                    </div>
                    <div>
                      {lbl("State of Supply")}
                      <input value={indiaForm.state_of_supply} onChange={e => sif("state_of_supply", e.target.value)} className={inp} placeholder="e.g. Karnataka, Maharashtra…" />
                    </div>
                  </div>
                </section>

                {/* ── 3. Invoice Details ── */}
                <section>
                  <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                    <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">3</span>
                    Invoice Details
                  </h3>
                  <div className="grid grid-cols-4 gap-2">
                    <div>
                      {lbl("Invoice Date *", true)}
                      <input type="date" value={indiaForm.invoice_date} onChange={e => sif("invoice_date", e.target.value)} className={inp} />
                    </div>
                    <div>
                      {lbl(`Tax Invoice Date${isInv ? " *" : " (n/a for quote)"}`)}
                      <input type="date" value={indiaForm.tax_invoice_date} onChange={e => sif("tax_invoice_date", e.target.value)} className={inp} disabled={!isInv} />
                    </div>
                    <div>
                      {lbl(`Tax Serial Number${isInv ? " *" : " (n/a for quote)"}`)}
                      <input value={indiaForm.tax_serial_number} onChange={e => sif("tax_serial_number", e.target.value)} className={inp} disabled={!isInv} />
                    </div>
                    <div>
                      {lbl("Entity Status")}
                      <input value={indiaForm.entity_status} onChange={e => sif("entity_status", e.target.value)} className={inp} placeholder="e.g. Small Entity, Natural Person…" />
                    </div>
                    <div className="col-span-2">
                      {lbl("Patent Office Application Number")}
                      <input value={indiaForm.patent_office_application_number} onChange={e => sif("patent_office_application_number", e.target.value)} className={inp} placeholder="e.g. 202341001234" />
                    </div>
                    <div className="col-span-2">
                      {lbl("Patent Office Acknowledgement")}
                      <input value={indiaForm.patent_office_acknowledgement} onChange={e => sif("patent_office_acknowledgement", e.target.value)} className={inp} />
                    </div>
                    <div className="col-span-4">
                      {lbl("Additional Information")}
                      <textarea value={indiaForm.additional_information} onChange={e => sif("additional_information", e.target.value)} rows={2}
                        className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                    </div>
                  </div>
                </section>

                {/* ── 4. Financials ── */}
                <section>
                  <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                    <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">4</span>
                    Financials (INR)
                  </h3>
                  <div className="grid grid-cols-4 gap-2">
                    <div>
                      {lbl("Patent Office Fees *")}
                      <input type="number" min="0" step="0.01" value={indiaForm.patent_office_fees} onChange={e => sif("patent_office_fees", e.target.value)} className={inp} />
                    </div>
                    <div>
                      {lbl("Service Fees *")}
                      <input type="number" min="0" step="0.01" value={indiaForm.service_fees} onChange={e => sif("service_fees", e.target.value)} className={inp} />
                    </div>
                    <div>
                      {lbl("Other Expenses *")}
                      <input type="number" min="0" step="0.01" value={indiaForm.other_expenses} onChange={e => sif("other_expenses", e.target.value)} className={inp} />
                    </div>
                    <div className="col-span-1" />
                    {/* GST preview */}
                    <div className="col-span-4 bg-muted/30 rounded-lg px-4 py-3 grid grid-cols-4 gap-3 text-xs">
                      <div>
                        <div className="text-muted-foreground text-[10px]">State of Supply</div>
                        <div className="font-medium">{indiaForm.state_of_supply || "—"}</div>
                        <div className="text-[10px] text-muted-foreground mt-0.5">
                          {(indiaForm.state_of_supply ?? "").toLowerCase() === "karnataka" ? "CGST + SGST (9% + 9%)" : indiaForm.state_of_supply ? "IGST (18%)" : "Enter state above"}
                        </div>
                      </div>
                      <div>
                        {(indiaForm.state_of_supply ?? "").toLowerCase() === "karnataka" ? (
                          <>
                            <div className="text-muted-foreground text-[10px]">CGST 9%</div>
                            <div className="font-mono font-medium">₹{gst.cgst.toLocaleString("en-IN", { minimumFractionDigits: 2 })}</div>
                            <div className="text-muted-foreground text-[10px] mt-0.5">SGST 9%: ₹{gst.sgst.toLocaleString("en-IN", { minimumFractionDigits: 2 })}</div>
                          </>
                        ) : (
                          <>
                            <div className="text-muted-foreground text-[10px]">IGST 18%</div>
                            <div className="font-mono font-medium">₹{gst.igst.toLocaleString("en-IN", { minimumFractionDigits: 2 })}</div>
                          </>
                        )}
                      </div>
                      <div>
                        <div className="text-muted-foreground text-[10px]">Invoice Amount</div>
                        <div className="font-mono font-bold text-gold text-sm">₹{gst.total.toLocaleString("en-IN", { minimumFractionDigits: 2 })}</div>
                        <div className="text-[10px] text-muted-foreground mt-0.5">PO + Svc + GST + Other</div>
                      </div>
                      <div />
                    </div>
                  </div>
                </section>

                {/* ── 5. Internal (restricted) ── */}
                {isInternal && (
                  <section>
                    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-amber-600 mb-2 flex items-center gap-1.5">
                      <span className="w-4 h-4 rounded-full bg-amber-600 text-white flex items-center justify-center text-[9px] font-bold">5</span>
                      Internal — Never Shown to Client
                    </h3>
                    <div className="grid grid-cols-4 gap-2">
                      <div>
                        {lbl("Attorney Fees")}
                        <input type="number" min="0" step="0.01" value={indiaForm.attorney_fees} onChange={e => sif("attorney_fees", e.target.value)} className={inp} />
                      </div>
                      <div>
                        {lbl("Consultant Fees")}
                        <input type="number" min="0" step="0.01" value={indiaForm.consultant_fees} onChange={e => sif("consultant_fees", e.target.value)} className={inp} />
                      </div>
                      <div>
                        {lbl("Referral Fees")}
                        <input type="number" min="0" step="0.01" value={indiaForm.referral_fees} onChange={e => sif("referral_fees", e.target.value)} className={inp} />
                      </div>
                      <div className="bg-muted/30 rounded-md px-3 py-2 flex flex-col justify-center">
                        <div className="text-[10px] text-muted-foreground">Net Revenue (auto)</div>
                        <div className={`font-mono font-bold text-sm ${gst.net >= 0 ? "text-green-500" : "text-destructive"}`}>
                          ₹{gst.net.toLocaleString("en-IN", { minimumFractionDigits: 2 })}
                        </div>
                      </div>
                    </div>
                  </section>
                )}

                {/* ── 6. Notes & Misc ── */}
                <section>
                  <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                    <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">{isInternal ? 6 : 5}</span>
                    Notes &amp; Misc
                  </h3>
                  <div className="grid grid-cols-3 gap-2">
                    <div className="col-span-3">
                      {lbl("Remarks")}
                      <textarea value={indiaForm.remarks} onChange={e => sif("remarks", e.target.value)} rows={2}
                        className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                    </div>
                    <div>
                      {lbl("UIN Old")}
                      <input value={indiaForm.uin_old} onChange={e => sif("uin_old", e.target.value)} className={inp} placeholder="Previous UIN if any" />
                    </div>
                    <div>
                      {lbl("UIN Old 2")}
                      <input value={indiaForm.uin_old_2} onChange={e => sif("uin_old_2", e.target.value)} className={inp} />
                    </div>
                  </div>
                </section>
              </div>

              {/* Footer */}
              <div className="flex items-center gap-2 px-6 py-4 border-t border-border sticky bottom-0 bg-background rounded-b-xl">
                <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleSaveIndia} disabled={indiaSaving}>
                  {indiaSaving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Saving…</> : editIndiaRec ? "Update" : `Create ${isInv ? "Invoice" : "Quotation"}`}
                </Button>
                <Button variant="outline" onClick={() => setShowIndiaModal(false)}>Cancel</Button>
              </div>
            </div>
          </div>
        );
      })()}
    </AppLayout>
  );
}
