import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Plus, X, Briefcase, ChevronDown, ChevronRight, Edit2, Receipt } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

const BLANK_FORM = { name: "", email: "", phone: "", pan_tax_id: "", gstin: "", bank_name: "", bank_account_number: "", bank_ifsc_code: "", payment_terms: "", tds_section: "194J" };
const BLANK_PAYMENT = { invoice_number: "", invoice_date: new Date().toISOString().slice(0, 10), amount: "", tds_deducted: "", status: "Pending" as "Pending" | "Paid" };

export default function PayrollPortalContractors() {
  const [contractors, setContractors] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState<number | null>(null);

  const [showModal, setShowModal] = useState(false);
  const [editing, setEditing] = useState<any | null>(null);
  const [form, setForm] = useState({ ...BLANK_FORM });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const [payModalFor, setPayModalFor] = useState<number | null>(null);
  const [paymentForm, setPaymentForm] = useState({ ...BLANK_PAYMENT });
  const [paySaving, setPaySaving] = useState(false);
  const [payError, setPayError] = useState("");

  function load() {
    setLoading(true);
    api.getContractors().then(setContractors).catch(() => setContractors([])).finally(() => setLoading(false));
  }

  useEffect(() => { load(); }, []);

  function openNew() {
    setEditing(null);
    setForm({ ...BLANK_FORM });
    setError("");
    setShowModal(true);
  }

  function openEdit(c: any) {
    setEditing(c);
    setForm({
      name: c.name || "", email: c.email || "", phone: c.phone || "",
      pan_tax_id: c.pan_tax_id || "", gstin: c.gstin || "",
      bank_name: c.bank_name || "", bank_account_number: c.bank_account_number || "", bank_ifsc_code: c.bank_ifsc_code || "",
      payment_terms: c.payment_terms || "", tds_section: c.tds_section || "194J",
    });
    setError("");
    setShowModal(true);
  }

  async function handleSave() {
    if (!form.name.trim()) { setError("Name is required."); return; }
    setSaving(true);
    setError("");
    try {
      const blankToNull = (v: string) => (v.trim() ? v.trim() : null);
      const payload = { ...form, pan_tax_id: blankToNull(form.pan_tax_id), gstin: blankToNull(form.gstin), bank_account_number: blankToNull(form.bank_account_number), bank_ifsc_code: blankToNull(form.bank_ifsc_code) };
      if (editing) await api.updateContractor(editing.id, payload);
      else await api.createContractor(payload);
      setShowModal(false);
      load();
    } catch (e: any) {
      setError(e.message || "Failed to save contractor.");
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm("Deactivate this contractor? If they have payment history, they'll be marked Inactive instead of deleted.")) return;
    await api.deleteContractor(id);
    load();
  }

  async function handleRecordPayment() {
    if (!payModalFor) return;
    if (!paymentForm.invoice_number.trim() || !paymentForm.amount) { setPayError("Invoice number and amount are required."); return; }
    setPaySaving(true);
    setPayError("");
    try {
      await api.recordContractorPayment(payModalFor, {
        invoice_number: paymentForm.invoice_number,
        invoice_date: paymentForm.invoice_date,
        amount: parseFloat(paymentForm.amount),
        tds_deducted: paymentForm.tds_deducted ? parseFloat(paymentForm.tds_deducted) : 0,
        status: paymentForm.status,
      });
      setPayModalFor(null);
      setPaymentForm({ ...BLANK_PAYMENT });
      load();
    } catch (e: any) {
      setPayError(e.message || "Failed to record payment.");
    } finally {
      setPaySaving(false);
    }
  }

  const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

  // Compact, dense field style for the wide Add/Edit Contractor modal — same
  // pattern as the Indian Patent Invoice/Quotation form in Financial.tsx.
  const fInp = "w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";
  const FLbl = ({ children }: { children: React.ReactNode }) => (
    <div className="text-[10px] text-muted-foreground mb-0.5">{children}</div>
  );
  const FSectionHeading = ({ n, children }: { n: number; children: React.ReactNode }) => (
    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">{n}</span>
      {children}
    </h3>
  );

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Contractors" />
      <PageHeader eyebrow="Payroll Portal" title="Contractors" description="Invoice-driven contractor payments (194J/194C) — separate from employee payroll"
        actions={<Button className="bg-gold hover:bg-gold/90 text-black" onClick={openNew}><Plus className="h-4 w-4 mr-2" />Add Contractor</Button>}
      />

      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm overflow-y-auto py-8">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-4xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">{editing ? "Edit Contractor" : "Add Contractor"}</h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {error && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{error}</div>}
            <div className="space-y-5">
              <section>
                <FSectionHeading n={1}>Contact Details</FSectionHeading>
                <div className="grid grid-cols-4 gap-2">
                  <div className="col-span-2"><FLbl>Name *</FLbl><input value={form.name} onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>Email</FLbl><input type="email" value={form.email} onChange={(e) => setForm((p) => ({ ...p, email: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>Phone</FLbl><input value={form.phone} onChange={(e) => setForm((p) => ({ ...p, phone: e.target.value }))} className={fInp} /></div>
                </div>
              </section>
              <section>
                <FSectionHeading n={2}>Statutory Details</FSectionHeading>
                <div className="grid grid-cols-4 gap-2">
                  <div><FLbl>TDS Section</FLbl>
                    <select value={form.tds_section} onChange={(e) => setForm((p) => ({ ...p, tds_section: e.target.value }))} className={fInp}>
                      <option value="194J">194J — Professional</option>
                      <option value="194C">194C — Contract</option>
                    </select>
                  </div>
                  <div><FLbl>PAN</FLbl><input value={form.pan_tax_id} onChange={(e) => setForm((p) => ({ ...p, pan_tax_id: e.target.value.toUpperCase() }))} placeholder="ABCDE1234F" className={fInp} /></div>
                  <div><FLbl>GSTIN</FLbl><input value={form.gstin} onChange={(e) => setForm((p) => ({ ...p, gstin: e.target.value.toUpperCase() }))} className={fInp} /></div>
                  <div><FLbl>Payment Terms</FLbl><input value={form.payment_terms} onChange={(e) => setForm((p) => ({ ...p, payment_terms: e.target.value }))} placeholder="e.g. Net 30" className={fInp} /></div>
                </div>
              </section>
              <section>
                <FSectionHeading n={3}>Banking Details</FSectionHeading>
                <div className="grid grid-cols-4 gap-2">
                  <div className="col-span-2"><FLbl>Bank Name</FLbl><input value={form.bank_name} onChange={(e) => setForm((p) => ({ ...p, bank_name: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>Bank Account Number</FLbl><input value={form.bank_account_number} onChange={(e) => setForm((p) => ({ ...p, bank_account_number: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>Bank IFSC Code</FLbl><input value={form.bank_ifsc_code} onChange={(e) => setForm((p) => ({ ...p, bank_ifsc_code: e.target.value.toUpperCase() }))} className={fInp} /></div>
                </div>
              </section>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleSave} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}{editing ? "Save Changes" : "Add Contractor"}
              </Button>
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {payModalFor !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Record Payment</h2>
              <button onClick={() => setPayModalFor(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {payError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{payError}</div>}
            <div className="space-y-3">
              <div><label className="block text-xs text-muted-foreground mb-1">Invoice Number *</label><input value={paymentForm.invoice_number} onChange={(e) => setPaymentForm((p) => ({ ...p, invoice_number: e.target.value }))} className={inputCls} /></div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="block text-xs text-muted-foreground mb-1">Invoice Date</label><input type="date" value={paymentForm.invoice_date} onChange={(e) => setPaymentForm((p) => ({ ...p, invoice_date: e.target.value }))} className={inputCls} /></div>
                <div><label className="block text-xs text-muted-foreground mb-1">Amount (₹) *</label><input type="number" value={paymentForm.amount} onChange={(e) => setPaymentForm((p) => ({ ...p, amount: e.target.value }))} className={inputCls} /></div>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div><label className="block text-xs text-muted-foreground mb-1">TDS Deducted (₹)</label><input type="number" value={paymentForm.tds_deducted} onChange={(e) => setPaymentForm((p) => ({ ...p, tds_deducted: e.target.value }))} className={inputCls} /></div>
                <div><label className="block text-xs text-muted-foreground mb-1">Status</label>
                  <select value={paymentForm.status} onChange={(e) => setPaymentForm((p) => ({ ...p, status: e.target.value as "Pending" | "Paid" }))} className={inputCls}>
                    <option value="Pending">Pending</option>
                    <option value="Paid">Paid</option>
                  </select>
                </div>
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleRecordPayment} disabled={paySaving}>
                {paySaving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Record Payment
              </Button>
              <Button variant="outline" onClick={() => setPayModalFor(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6">
        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : contractors.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 gap-3 text-muted-foreground">
            <Briefcase className="h-10 w-10 opacity-20" />
            <p className="text-sm">No contractors yet.</p>
          </div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-xs">
                <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2.5 text-left">Contractor</th>
                    <th className="px-3 py-2.5 text-left">TDS Section</th>
                    <th className="px-3 py-2.5 text-left">Status</th>
                    <th className="px-3 py-2.5 text-center">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {contractors.map((c) => (
                    <>
                      <tr key={c.id} className="border-t border-border hover:bg-muted/20">
                        <td className="px-3 py-2.5">
                          <button className="flex items-center gap-1 text-left hover:text-gold transition-colors" onClick={() => setExpanded((cur) => (cur === c.id ? null : c.id))}>
                            {expanded === c.id ? <ChevronDown className="h-3 w-3 shrink-0 text-muted-foreground" /> : <ChevronRight className="h-3 w-3 shrink-0 text-muted-foreground" />}
                            <span>
                              <div className="font-medium">{c.name}</div>
                              <div className="text-muted-foreground text-[10px]">{c.contractor_code} · {c.email}</div>
                            </span>
                          </button>
                        </td>
                        <td className="px-3 py-2.5">{c.tds_section}</td>
                        <td className="px-3 py-2.5">
                          <Badge variant="outline" className={`text-[10px] ${c.status === "Active" ? "bg-green-100 text-green-700 border-green-200" : "bg-muted text-muted-foreground"}`}>{c.status}</Badge>
                        </td>
                        <td className="px-3 py-2.5 text-center whitespace-nowrap">
                          <Button size="sm" variant="ghost" className="h-6 px-2 text-[10px]" onClick={() => setPayModalFor(c.id)}><Receipt className="h-3 w-3 mr-1" />Record Payment</Button>
                          <button className="p-1 rounded hover:bg-muted" title="Edit" onClick={() => openEdit(c)}><Edit2 className="h-3 w-3 text-muted-foreground" /></button>
                          <button className="p-1 rounded hover:bg-destructive/10 hover:text-destructive" title="Deactivate" onClick={() => handleDelete(c.id)}><X className="h-3 w-3 text-muted-foreground" /></button>
                        </td>
                      </tr>
                      {expanded === c.id && (
                        <tr className="border-t border-border bg-muted/10">
                          <td colSpan={4} className="px-3 py-2.5">
                            {c.payments?.length ? (
                              <table className="w-full text-[11px] pl-5">
                                <thead className="text-muted-foreground">
                                  <tr><th className="text-left py-1">Invoice</th><th className="text-left py-1">Date</th><th className="text-right py-1">Amount</th><th className="text-right py-1">TDS</th><th className="text-right py-1">Net Paid</th><th className="text-left py-1">Status</th></tr>
                                </thead>
                                <tbody>
                                  {c.payments.map((p: any) => (
                                    <tr key={p.id} className="border-t border-border/50">
                                      <td className="py-1">{p.invoice_number}</td>
                                      <td className="py-1">{new Date(p.invoice_date).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" })}</td>
                                      <td className="py-1 text-right">{fmt(p.amount)}</td>
                                      <td className="py-1 text-right text-red-500">-{fmt(p.tds_deducted)}</td>
                                      <td className="py-1 text-right font-medium">{fmt(p.net_paid)}</td>
                                      <td className="py-1">{p.status}</td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            ) : (
                              <p className="pl-5 text-[11px] text-muted-foreground">No payments recorded yet.</p>
                            )}
                          </td>
                        </tr>
                      )}
                    </>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
    </PayrollPortalLayout>
  );
}
