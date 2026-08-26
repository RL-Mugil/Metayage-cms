import { Head, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { Loader2, Upload, CheckCircle2, FileText, MessageSquare, Download } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";

interface PendingPaymentRow {
  id: number;
  invoice_uin: string;
  docket_number: string;
  invoice_date: string;
  invoice_amount: string | number;
  currency: string;
  payment_status: string;
  status_note?: string | null;
  status_note_at?: string | null;
  project?: { id: number; docket_number: string; project_name: string } | null;
  client?: { id: number; company_name?: string; legal_name?: string } | null;
  proof_document?: { id: number; file_name: string; storage_path: string } | null;
}

function paymentStatusVariant(status: string): "default" | "secondary" | "outline" | "destructive" {
  if (status === "Confirmed") return "default";
  if (status === "Proof Submitted") return "secondary";
  return "outline";
}

function formatMoney(amount: string | number, currency: string) {
  return `${currency} ${Number(amount).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`;
}

export default function PendingPayments() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;
  const isClient = ["client", "client_admin", "client_finance"].includes(role);
  const isStaff = !isClient;

  const [rows, setRows] = useState<PendingPaymentRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [uploadingId, setUploadingId] = useState<number | null>(null);
  const [confirmingId, setConfirmingId] = useState<number | null>(null);
  const [noteDraft, setNoteDraft] = useState<Record<number, string>>({});
  const [savingNoteId, setSavingNoteId] = useState<number | null>(null);
  const fileInputRefs = useRef<Record<number, HTMLInputElement | null>>({});

  const load = () => {
    setLoading(true);
    api.getPendingPayments()
      .then((res: any) => setRows(res?.data ?? []))
      .catch((e: any) => setError(e?.message || "Failed to load pending payments."))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); }, []);

  async function handleProofUpload(row: PendingPaymentRow, file: File) {
    setUploadingId(row.id);
    try {
      const doc: any = await api.uploadDocument(file, "Payment Proof", row.client?.id ?? null, null);
      await api.submitRenewalProof(row.id, doc.id);
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to upload payment proof.");
    } finally {
      setUploadingId(null);
    }
  }

  async function handleConfirm(row: PendingPaymentRow) {
    setConfirmingId(row.id);
    try {
      await api.confirmRenewalReceipt(row.id);
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to confirm receipt.");
    } finally {
      setConfirmingId(null);
    }
  }

  async function handleSaveNote(row: PendingPaymentRow) {
    const note = (noteDraft[row.id] ?? "").trim();
    if (!note) return;
    setSavingNoteId(row.id);
    try {
      await api.postRenewalStatusNote(row.id, note);
      setNoteDraft((p) => ({ ...p, [row.id]: "" }));
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to save note.");
    } finally {
      setSavingNoteId(null);
    }
  }

  return (
    <AppLayout>
      <Head title="Pending Payments" />
      <PageHeader
        eyebrow="Billing"
        title="Pending Payments"
        description="Renewal invoices awaiting payment proof and confirmation."
      />

      <div className="px-8 py-6 space-y-4">
        {error && (
          <div className="rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">{error}</div>
        )}

        {loading ? (
          <div className="flex items-center justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
        ) : rows.length === 0 ? (
          <Card className="border-border">
            <CardContent className="py-16 text-center text-sm text-muted-foreground">No pending payments right now.</CardContent>
          </Card>
        ) : (
          <div className="space-y-3">
            {rows.map((row) => (
              <Card key={row.id} className="border-border">
                <CardContent className="py-4 space-y-3">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="font-mono text-xs font-semibold text-gold">{row.invoice_uin}</span>
                        <Badge variant={paymentStatusVariant(row.payment_status)}>{row.payment_status}</Badge>
                      </div>
                      <div className="mt-1 text-sm font-medium">{row.project?.project_name ?? row.docket_number}</div>
                      {isStaff && row.client && (
                        <div className="text-xs text-muted-foreground">{row.client.company_name ?? row.client.legal_name}</div>
                      )}
                      <div className="mt-1 text-xs text-muted-foreground font-mono">{row.docket_number} · {fmtDate(row.invoice_date)}</div>
                    </div>
                    <div className="text-right">
                      <div className="font-display text-xl font-semibold">{formatMoney(row.invoice_amount, row.currency)}</div>
                    </div>
                  </div>

                  {row.status_note && (
                    <div className="flex items-start gap-2 rounded-md bg-muted/40 px-3 py-2 text-xs">
                      <MessageSquare className="h-3.5 w-3.5 mt-0.5 flex-shrink-0 text-muted-foreground" />
                      <div>
                        <span className="text-foreground">{row.status_note}</span>
                        {row.status_note_at && <span className="ml-2 text-muted-foreground">· {fmtDate(row.status_note_at)}</span>}
                      </div>
                    </div>
                  )}

                  <div className="flex flex-wrap items-center gap-2 pt-1">
                    {row.proof_document && (
                      <Button size="sm" variant="outline" className="h-8 text-xs"
                        onClick={() => api.downloadDocument(row.proof_document!.storage_path, row.proof_document!.file_name)}>
                        <Download className="h-3 w-3 mr-1" />{row.proof_document.file_name}
                      </Button>
                    )}

                    {isClient && row.payment_status !== "Confirmed" && (
                      <>
                        <input
                          type="file"
                          className="hidden"
                          ref={(el) => { fileInputRefs.current[row.id] = el; }}
                          onChange={(e) => { const f = e.target.files?.[0]; if (f) handleProofUpload(row, f); e.target.value = ""; }}
                        />
                        <Button size="sm" variant="outline" className="h-8 text-xs"
                          disabled={uploadingId === row.id}
                          onClick={() => fileInputRefs.current[row.id]?.click()}>
                          {uploadingId === row.id ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : <Upload className="h-3 w-3 mr-1" />}
                          {row.payment_status === "Proof Submitted" ? "Re-upload Proof" : "Upload Payment Proof"}
                        </Button>
                      </>
                    )}

                    {isStaff && row.payment_status === "Proof Submitted" && (
                      <Button size="sm" className="h-8 text-xs bg-gold hover:bg-gold/90 text-black"
                        disabled={confirmingId === row.id}
                        onClick={() => handleConfirm(row)}>
                        {confirmingId === row.id ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : <CheckCircle2 className="h-3 w-3 mr-1" />}
                        Confirm Receipt
                      </Button>
                    )}

                    {row.payment_status !== "Confirmed" && (
                      <div className="flex items-center gap-1.5 flex-1 min-w-[220px]">
                        <input
                          value={noteDraft[row.id] ?? ""}
                          onChange={(e) => setNoteDraft((p) => ({ ...p, [row.id]: e.target.value }))}
                          placeholder="Status update (e.g. paying by 13 Aug)…"
                          className="h-8 flex-1 rounded-md border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
                        />
                        <Button size="sm" variant="ghost" className="h-8 text-xs"
                          disabled={savingNoteId === row.id || !(noteDraft[row.id] ?? "").trim()}
                          onClick={() => handleSaveNote(row)}>
                          {savingNoteId === row.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <FileText className="h-3 w-3" />}
                        </Button>
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </div>
    </AppLayout>
  );
}
