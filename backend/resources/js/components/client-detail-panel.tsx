import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { X, Loader2 } from "lucide-react";
import { api } from "@/lib/api-client";
import { ProjectDetailPanel } from "@/components/project-detail-panel";

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}-${m}-${y}`;
}

interface Props {
  clientId: number;
  onClose: () => void;
}

type Tab = "cases" | "invoices" | "ledger";

export function ClientDetailPanel({ clientId, onClose }: Props) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<Tab>("cases");
  const [detailProjectId, setDetailProjectId] = useState<number | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setData(null);
    api.request(`/clients/${clientId}/detail`).then((res: any) => {
      if (!cancelled) { setData(res); setLoading(false); }
    }).catch(() => {
      if (!cancelled) setLoading(false);
    });
    return () => { cancelled = true; };
  }, [clientId]);

  const client = data?.client;

  return createPortal(
    <>
      <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />
      <div className="fixed right-0 top-0 h-full w-full max-w-3xl bg-background border-l border-border z-50 flex flex-col shadow-2xl">

        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <p className="text-xs text-muted-foreground font-mono">
              {client?.client_code ?? "Loading…"}
            </p>
            <h2 className="text-base font-semibold truncate max-w-[500px]">
              {client?.legal_name ?? client?.company_name ?? ""}
            </h2>
            {client && (
              <p className="text-xs text-muted-foreground mt-0.5">
                {client.nationality}
                {client.gst_type ? ` · ${client.gst_type}` : ""}
                {client.gstin ? ` · ${client.gstin}` : ""}
              </p>
            )}
          </div>
          <button onClick={onClose} className="p-1.5 rounded hover:bg-muted/50">
            <X className="h-4 w-4" />
          </button>
        </div>

        {/* Tabs */}
        <div className="flex border-b border-border flex-shrink-0 px-6">
          {(["cases", "invoices", "ledger"] as Tab[]).map((t) => (
            <button key={t} onClick={() => setTab(t)}
              className={`px-4 py-2.5 text-xs font-medium capitalize border-b-2 transition-colors ${
                tab === t
                  ? "border-primary text-primary"
                  : "border-transparent text-muted-foreground hover:text-foreground"
              }`}>
              {t === "cases" ? "Cases" : t === "invoices" ? "Invoices" : "Ledger"}
            </button>
          ))}
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto">
          {loading ? (
            <div className="flex items-center justify-center h-40">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : !data ? (
            <div className="flex items-center justify-center h-40 text-muted-foreground text-sm">
              Failed to load details.
            </div>
          ) : (
            <>
              {/* Cases */}
              {tab === "cases" && (
                <div className="p-6">
                  {data.projects.length === 0 ? (
                    <p className="text-muted-foreground text-sm text-center py-10">No cases for this client.</p>
                  ) : (
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="text-left border-b border-border text-muted-foreground">
                          <th className="pb-2 font-medium">Docket</th>
                          <th className="pb-2 font-medium">Title</th>
                          <th className="pb-2 font-medium">Status</th>
                          <th className="pb-2 font-medium">Current Stage</th>
                          <th className="pb-2 font-medium">Filed</th>
                          <th className="pb-2 font-medium">Deadline</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.projects as any[]).map((p: any) => (
                          <tr key={p.id} className="border-b border-border/50 hover:bg-muted/20">
                            <td className="py-2.5 pr-3">
                              <button
                                onClick={() => setDetailProjectId(p.id)}
                                className="font-mono font-semibold text-gold hover:underline text-left"
                              >
                                {p.docket_number}
                              </button>
                            </td>
                            <td className="py-2.5 pr-3 max-w-[180px] truncate font-medium" title={p.project_name}>
                              {p.project_name}
                            </td>
                            <td className="py-2.5 pr-3">
                              <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                p.status === "Completed" ? "bg-green-100 text-green-700" :
                                p.status === "In Progress" ? "bg-blue-100 text-blue-700" :
                                p.status === "On Hold" ? "bg-amber-100 text-amber-700" :
                                "bg-muted text-muted-foreground"
                              }`}>{p.status}</span>
                            </td>
                            <td className="py-2.5 pr-3 text-muted-foreground">{p.current_stage ?? "—"}</td>
                            <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(p.filing_date)}</td>
                            <td className="py-2.5 font-mono text-muted-foreground">{fmtDate(p.hard_deadline)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}

              {/* Invoices */}
              {tab === "invoices" && (
                <div className="p-6 space-y-4">
                  <div className="grid grid-cols-3 gap-3">
                    {[
                      { label: "Total Invoiced", value: data.invoice_summary.total_invoiced },
                      { label: "Received", value: data.invoice_summary.total_received },
                      { label: "Pending", value: data.invoice_summary.total_pending },
                    ].map((c) => (
                      <div key={c.label} className="rounded-lg border border-border bg-muted/30 p-3">
                        <p className="text-[10px] text-muted-foreground">{c.label}</p>
                        <p className="text-sm font-semibold font-mono mt-1">
                          ₹{Number(c.value).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                        </p>
                      </div>
                    ))}
                  </div>
                  {data.invoices.length === 0 ? (
                    <p className="text-muted-foreground text-sm text-center py-8">No invoices for this client.</p>
                  ) : (
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="text-left border-b border-border text-muted-foreground">
                          <th className="pb-2 font-medium">Invoice #</th>
                          <th className="pb-2 font-medium">Status</th>
                          <th className="pb-2 font-medium">Date</th>
                          <th className="pb-2 font-medium text-right">Total</th>
                          <th className="pb-2 font-medium text-right">Balance</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.invoices as any[]).map((inv: any) => (
                          <tr key={inv.id} className="border-b border-border/50 hover:bg-muted/20">
                            <td className="py-2.5 pr-3 font-mono text-gold font-semibold">{inv.invoice_code}</td>
                            <td className="py-2.5 pr-3">
                              <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                inv.status === "Paid" ? "bg-green-100 text-green-700" :
                                inv.status === "Overdue" ? "bg-red-100 text-red-700" :
                                inv.status === "Sent" || inv.status === "Viewed" ? "bg-blue-100 text-blue-700" :
                                inv.status === "Partially Paid" ? "bg-amber-100 text-amber-700" :
                                "bg-muted text-muted-foreground"
                              }`}>{inv.status}</span>
                            </td>
                            <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(inv.created_at)}</td>
                            <td className="py-2.5 pr-3 text-right font-mono">
                              {inv.currency === "INR" ? "₹" : `${inv.currency} `}
                              {Number(inv.total_amount).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                            </td>
                            <td className="py-2.5 text-right font-mono">
                              {Number(inv.balance_due) > 0
                                ? <span className="text-muted-foreground">₹{Number(inv.balance_due).toLocaleString("en-IN", { maximumFractionDigits: 0 })}</span>
                                : <span className="text-green-600">Cleared</span>}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}

              {/* Ledger */}
              {tab === "ledger" && (
                <div className="p-6">
                  {data.ledger.length === 0 ? (
                    <p className="text-muted-foreground text-sm text-center py-8">No ledger entries for this client.</p>
                  ) : (
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="text-left border-b border-border text-muted-foreground">
                          <th className="pb-2 font-medium">Date</th>
                          <th className="pb-2 font-medium">Type</th>
                          <th className="pb-2 font-medium">Reference</th>
                          <th className="pb-2 font-medium text-right">Debit</th>
                          <th className="pb-2 font-medium text-right">Credit</th>
                          <th className="pb-2 font-medium text-right">Balance</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.ledger as any[]).map((l: any) => (
                          <tr key={l.id} className="border-b border-border/50 hover:bg-muted/20">
                            <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(l.created_at)}</td>
                            <td className="py-2.5 pr-3 capitalize text-muted-foreground">{l.document_type}</td>
                            <td className="py-2.5 pr-3 font-mono text-gold text-[10px]">{l.document_reference}</td>
                            <td className="py-2.5 pr-3 text-right font-mono">
                              {Number(l.debit) > 0 ? `₹${Number(l.debit).toLocaleString("en-IN", { maximumFractionDigits: 0 })}` : "—"}
                            </td>
                            <td className="py-2.5 pr-3 text-right font-mono text-green-600">
                              {Number(l.credit) > 0 ? `₹${Number(l.credit).toLocaleString("en-IN", { maximumFractionDigits: 0 })}` : "—"}
                            </td>
                            <td className="py-2.5 text-right font-mono font-semibold">
                              ₹{Number(l.balance).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )}
                </div>
              )}
            </>
          )}
        </div>
      </div>

      {/* Project detail nested panel */}
      {detailProjectId !== null && (
        <ProjectDetailPanel projectId={detailProjectId} onClose={() => setDetailProjectId(null)} />
      )}
    </>,
    document.body
  );
}
