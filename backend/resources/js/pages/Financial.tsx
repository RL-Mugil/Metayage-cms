import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Plus, X, Trash2, Download, DollarSign, Clock, CheckCircle, AlertCircle } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api, downloadCSV } from "@/lib/api-client";
import { statusColor } from "@/lib/mock-data";
import { fmtDate } from "@/lib/date-utils";

function fmt(n: number) {
  if (n >= 100000) return `₹${(n / 100000).toFixed(1)}L`;
  return `₹${n.toLocaleString("en-IN")}`;
}

const blankItem = { description: "", amount: "" };

export default function Financial() {
  const [invoices, setInvoices] = useState<any[]>([]);
  const [clients, setClients] = useState<any[]>([]);
  const [projects, setProjects] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterStatus, setFilterStatus] = useState("All");

  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState({ client_id: "", project_id: "", due_date: "", payment_terms: "Net 30", notes: "" });
  const [items, setItems] = useState([{ ...blankItem }]);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");

  const [showPayment, setShowPayment] = useState<any | null>(null);
  const [payForm, setPayForm] = useState({ amount: "", payment_method: "Bank Transfer", transaction_reference: "" });
  const [paying, setPaying] = useState(false);

  useEffect(() => {
    Promise.all([api.getInvoices(), api.getClients(), api.getProjects()])
      .then(([i, c, p]) => {
        setInvoices(Array.isArray(i) ? i : (i as any).data || []);
        setClients(Array.isArray(c) ? c : (c as any).data || []);
        setProjects(Array.isArray(p) ? p : (p as any).data || []);
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  const filtered = invoices.filter(i => filterStatus === "All" || i.status === filterStatus);

  const totalBilled = invoices.reduce((s, i) => s + parseFloat(i.total_amount || 0), 0);
  const totalReceived = invoices.filter(i => i.status === "Paid").reduce((s, i) => s + parseFloat(i.total_amount || 0), 0);
  const totalOutstanding = invoices.filter(i => ["Sent", "Overdue", "Partially Paid"].includes(i.status)).reduce((s, i) => s + parseFloat(i.balance_due || 0), 0);
  const overdueCount = invoices.filter(i => i.status === "Overdue").length;

  function addItem() { setItems(prev => [...prev, { ...blankItem }]); }
  function removeItem(idx: number) { setItems(prev => prev.filter((_, i) => i !== idx)); }
  function updateItem(idx: number, field: string, value: string) {
    setItems(prev => prev.map((item, i) => i === idx ? { ...item, [field]: value } : item));
  }

  const subtotal = items.reduce((s, item) => s + (parseFloat(item.amount) || 0), 0);
  const tax = subtotal * 0.18;
  const total = subtotal + tax;

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
        items: validItems.map(i => ({ description: i.description, amount: parseFloat(i.amount) })),
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

  const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";
  const statuses = ["All", "Draft", "Sent", "Paid", "Overdue", "Partially Paid", "Cancelled"];

  return (
    <AppLayout>
      <Head title="Financial Suite" />
      <PageHeader
        eyebrow="Finance"
        title="Financial Suite"
        description="Invoices, payments, and revenue analytics"
        actions={
          <>
            <Button variant="outline" onClick={handleExport}><Download className="h-4 w-4 mr-2" />Export CSV</Button>
            <Button onClick={() => { setShowModal(true); setSaveError(""); }}><Plus className="h-4 w-4 mr-2" />New Invoice</Button>
          </>
        }
      />

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
                      <input value={item.description} onChange={e => updateItem(idx, "description", e.target.value)}
                        placeholder="Description of service" className="flex-1 h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      <input type="number" value={item.amount} onChange={e => updateItem(idx, "amount", e.target.value)}
                        placeholder="Amount (₹)" className="w-32 h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      {items.length > 1 && (
                        <button onClick={() => removeItem(idx)} className="p-1.5 rounded hover:bg-destructive/10 text-muted-foreground hover:text-destructive">
                          <Trash2 className="h-4 w-4" />
                        </button>
                      )}
                    </div>
                  ))}
                </div>
                <div className="mt-3 text-right text-sm space-y-0.5">
                  <div className="text-muted-foreground">Subtotal: <span className="font-mono">₹{subtotal.toLocaleString("en-IN")}</span></div>
                  <div className="text-muted-foreground">GST 18%: <span className="font-mono">₹{tax.toFixed(2)}</span></div>
                  <div className="font-semibold">Total: <span className="font-mono text-gold">₹{total.toFixed(2)}</span></div>
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
                <input type="number" value={payForm.amount} onChange={e => setPayForm(p => ({ ...p, amount: e.target.value }))}
                  placeholder={showPayment.balance_due} className={inputCls} />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Payment Method</label>
                <select value={payForm.payment_method} onChange={e => setPayForm(p => ({ ...p, payment_method: e.target.value }))} className={inputCls}>
                  {["Bank Transfer", "NEFT", "RTGS", "UPI", "Cheque", "Cash"].map(m => <option key={m}>{m}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Transaction Reference</label>
                <input value={payForm.transaction_reference} onChange={e => setPayForm(p => ({ ...p, transaction_reference: e.target.value }))}
                  placeholder="UTR / reference number" className={inputCls} />
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

      <div className="px-8 py-6 space-y-6">
        <div className="grid grid-cols-4 gap-4">
          {[
            { label: "Total Billed", value: fmt(totalBilled), icon: DollarSign, color: "text-gold" },
            { label: "Received", value: fmt(totalReceived), icon: CheckCircle, color: "text-green-500" },
            { label: "Outstanding", value: fmt(totalOutstanding), icon: Clock, color: "text-blue-500" },
            { label: "Overdue", value: overdueCount.toString(), icon: AlertCircle, color: "text-red-500", suffix: " invoices" },
          ].map(({ label, value, icon: Icon, color, suffix }) => (
            <Card key={label} className="border-border">
              <CardContent className="p-4">
                <div className="flex items-center gap-2 mb-2"><Icon className={`h-4 w-4 ${color}`} /><span className="text-xs text-muted-foreground">{label}</span></div>
                <div className="text-xl font-bold">{value}{suffix}</div>
              </CardContent>
            </Card>
          ))}
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          {statuses.map(s => (
            <button key={s} onClick={() => setFilterStatus(s)}
              className={`px-3 py-1 rounded text-xs font-medium border transition-colors ${filterStatus === s ? "bg-gold text-black border-gold" : "border-border text-muted-foreground hover:text-foreground"}`}>
              {s}
            </button>
          ))}
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : (
          <Card className="border-border">
            <CardHeader><CardTitle className="font-display">Invoices</CardTitle></CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
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
                    <tr key={i.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3 font-mono text-xs font-medium">{i.invoice_code}</td>
                      <td className="px-4 py-3">{i.client?.company_name}</td>
                      <td className="px-4 py-3 font-medium">{fmt(parseFloat(i.total_amount))}</td>
                      <td className="px-4 py-3 text-muted-foreground">{fmt(parseFloat(i.balance_due || 0))}</td>
                      <td className="px-4 py-3"><Badge variant={statusColor(i.status)}>{i.status}</Badge></td>
                      <td className="px-4 py-3 text-muted-foreground text-xs">{fmtDate(i.due_date)}</td>
                      <td className="px-4 py-3">
                        {["Sent", "Overdue", "Partially Paid"].includes(i.status) && (
                          <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => { setShowPayment(i); setPayForm({ amount: i.balance_due, payment_method: "Bank Transfer", transaction_reference: "" }); }}>
                            Record Payment
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                  {filtered.length === 0 && (
                    <tr><td colSpan={7} className="px-4 py-8 text-center text-muted-foreground text-sm">No invoices found</td></tr>
                  )}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
