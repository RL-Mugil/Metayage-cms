import { Head, Link } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, ArrowLeft, ExternalLink } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { DocketDeadlines } from "@/components/docket-deadlines";

interface Props {
  projectId: number;
}

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}-${m}-${y}`;
}

type Tab = "stages" | "deadlines" | "tasks" | "invoices" | "ledger" | "history";

export default function ProjectShow({ projectId }: Props) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<Tab>("stages");

  useEffect(() => {
    api.request(`/projects/${projectId}/detail`).then((res: any) => {
      setData(res);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, [projectId]);

  if (loading) {
    return (
      <AppLayout>
        <Head title="Case" />
        <div className="flex items-center justify-center h-screen">
          <Loader2 className="h-8 w-8 animate-spin text-gold" />
        </div>
      </AppLayout>
    );
  }

  if (!data) {
    return (
      <AppLayout>
        <Head title="Case Not Found" />
        <div className="flex flex-col items-center justify-center h-screen gap-3 text-muted-foreground">
          <p className="text-lg font-medium">Case not found.</p>
          <Link href="/projects" className="text-sm underline hover:text-foreground">← Back to Projects</Link>
        </div>
      </AppLayout>
    );
  }

  const p = data.project;
  const stages: any[] = data.stages ?? [];
  const done = stages.filter((s: any) => s.status === "Completed").length;
  const pct = stages.length ? Math.round((done / stages.length) * 100) : 0;

  return (
    <AppLayout>
      <Head title={p?.project_name || "Case"} />

      {/* Header */}
      <div className="flex items-center justify-between px-8 py-4 border-b border-border">
        <div className="flex items-start gap-4">
          <Link href="/projects" className="mt-1 text-muted-foreground hover:text-foreground transition-colors">
            <ArrowLeft className="h-4 w-4" />
          </Link>
          <div>
            <p className="text-xs text-muted-foreground font-mono">
              {p?.docket_number ?? p?.project_code ?? "—"}
            </p>
            <h1 className="text-xl font-semibold font-display mt-0.5">{p?.project_name}</h1>
            <p className="text-xs text-muted-foreground mt-1">
              {p?.client?.company_name ?? p?.client?.legal_name ?? ""}
              {p?.patent_office_code ? ` · ${p.patent_office_code}` : ""}
              {p?.service_code ? ` · ${p.service_code}` : ""}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Badge variant={statusColor(p?.status)}>{p?.status}</Badge>
          {p?.urgency && p.urgency !== "Normal" && (
            <Badge variant={p.urgency === "Critical" || p.urgency === "High" ? "destructive" : "secondary"} className="text-[10px]">
              {p.urgency}
            </Badge>
          )}
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-border px-8">
        {(["stages", "deadlines", "tasks", "invoices", "ledger", "history"] as Tab[]).map((t) => (
          <button key={t} onClick={() => setTab(t)}
            className={`px-4 py-2.5 text-xs font-medium capitalize border-b-2 transition-colors ${
              tab === t
                ? "border-primary text-primary"
                : "border-transparent text-muted-foreground hover:text-foreground"
            }`}>
            {t === "stages" ? "Pipeline" : t === "deadlines" ? "Deadlines" : t === "invoices" ? "Invoices" : t === "ledger" ? "Ledger" : t === "history" ? "History" : "Tasks"}
          </button>
        ))}
      </div>

      {/* Body */}
      <div className="px-8 py-6">

        {/* Pipeline */}
        {tab === "stages" && (
          <div>
            <div className="flex items-center gap-4 mb-6">
              <div className="flex items-center gap-2 flex-1">
                <span className="text-xs text-muted-foreground whitespace-nowrap">{done} / {stages.length} stages</span>
                <div className="flex-1 h-1.5 rounded-full bg-muted overflow-hidden max-w-[180px]">
                  <div className="h-full rounded-full bg-green-500 transition-all" style={{ width: `${pct}%` }} />
                </div>
                <span className="text-xs font-semibold text-green-600">{pct}%</span>
              </div>
              {p?.filing_date && (
                <p className="text-xs text-muted-foreground">
                  Filed: <span className="font-mono font-semibold text-foreground">{fmtDate(p.filing_date)}</span>
                </p>
              )}
            </div>

            <div className="overflow-x-auto pb-3">
              <div className="flex items-start min-w-max gap-0 py-2">
                {stages.map((s: any, i: number) => {
                  const isDone   = s.status === "Completed";
                  const isActive = s.status === "In Progress";
                  const nextDone = stages[i + 1]?.status === "Completed";
                  return (
                    <div key={s.id} className="flex items-start">
                      <div className="flex flex-col items-center w-[96px] text-center">
                        <div className={`w-9 h-9 rounded-full flex items-center justify-center border-2 flex-shrink-0 transition-all ${
                          isDone
                            ? "border-green-500 bg-green-500 shadow shadow-green-500/30"
                            : isActive
                            ? "border-blue-500 bg-blue-500 shadow shadow-blue-500/30"
                            : "border-border bg-muted/40"
                        }`}>
                          {isDone ? (
                            <svg className="w-4 h-4 text-white" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                          ) : isActive ? (
                            <div className="w-2.5 h-2.5 rounded-full bg-white animate-pulse" />
                          ) : (
                            <div className="w-2 h-2 rounded-full bg-muted-foreground/25" />
                          )}
                        </div>
                        <span className="text-[9px] text-muted-foreground/50 font-mono mt-0.5">{i + 1}</span>
                        <div className={`mt-1 text-[10px] leading-snug font-medium px-0.5 ${
                          isDone ? "text-green-600 dark:text-green-400" :
                          isActive ? "text-blue-600 dark:text-blue-400" :
                          "text-muted-foreground"
                        }`}>{s.stage_name}</div>
                        {(isDone || isActive) && s.actual_start_at && (
                          <div className="mt-0.5 text-[9px] font-mono text-muted-foreground/50 leading-tight">
                            {fmtDate(s.actual_start_at)}
                            {s.actual_end_at && <><br />{fmtDate(s.actual_end_at)}</>}
                          </div>
                        )}
                      </div>
                      {i < stages.length - 1 && (
                        <div className="flex items-center mt-[17px] flex-shrink-0 w-5">
                          <div className={`h-px flex-1 ${isDone && nextDone ? "bg-green-400/70" : isDone ? "bg-blue-400/70" : "bg-border"}`} />
                          <svg width="6" height="8" viewBox="0 0 6 8" className="flex-shrink-0">
                            <path d="M0 0 L6 4 L0 8 Z" fill={isDone && nextDone ? "rgb(74 222 128 / 0.7)" : isDone ? "rgb(96 165 250 / 0.7)" : "rgb(148 163 184 / 0.4)"} />
                          </svg>
                        </div>
                      )}
                    </div>
                  );
                })}
              </div>
            </div>

            <div className="flex items-center gap-5 mt-4 pt-4 border-t border-border">
              {[
                { cls: "bg-green-500", label: "Completed" },
                { cls: "bg-blue-500 animate-pulse", label: "In Progress" },
                { cls: "bg-muted-foreground/20 border border-border", label: "Pending" },
              ].map((l) => (
                <div key={l.label} className="flex items-center gap-1.5">
                  <div className={`w-2.5 h-2.5 rounded-full ${l.cls}`} />
                  <span className="text-[10px] text-muted-foreground">{l.label}</span>
                </div>
              ))}
            </div>

            {/* Key details */}
            <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {[
                { label: "Client",       value: p?.client?.company_name ?? p?.client?.legal_name ?? "—" },
                { label: "Hard Deadline", value: fmtDate(p?.hard_deadline), mono: true },
                { label: "Partner",      value: p?.partner?.name ?? "—" },
                { label: "Manager",      value: p?.manager?.name ?? "—" },
              ].map((f) => (
                <div key={f.label} className="rounded-lg border border-border bg-muted/20 p-3">
                  <p className="text-[10px] text-muted-foreground uppercase tracking-wide">{f.label}</p>
                  <p className={`mt-1 text-sm font-medium ${f.mono ? "font-mono" : ""}`}>{f.value}</p>
                </div>
              ))}
            </div>
          </div>
        )}

        {/* Deadlines — statutory docketing engine */}
        {tab === "deadlines" && <DocketDeadlines projectId={projectId} />}

        {/* Tasks */}
        {tab === "tasks" && (
          <div>
            {(data.tasks?.length ?? 0) === 0 ? (
              <p className="text-muted-foreground text-sm text-center py-16">No tasks for this case.</p>
            ) : (
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-left border-b border-border text-muted-foreground">
                    <th className="pb-2 font-medium">Task</th>
                    <th className="pb-2 font-medium">Assignee</th>
                    <th className="pb-2 font-medium">Status</th>
                    <th className="pb-2 font-medium">Due</th>
                    <th className="pb-2 font-medium text-right">Hours</th>
                  </tr>
                </thead>
                <tbody>
                  {(data.tasks as any[]).map((t: any) => (
                    <tr key={t.id} className="border-b border-border/50 hover:bg-muted/20">
                      <td className="py-2.5 pr-3 font-medium max-w-[280px] truncate">{t.title}</td>
                      <td className="py-2.5 pr-3 text-muted-foreground">{t.assignee?.name ?? "—"}</td>
                      <td className="py-2.5 pr-3">
                        <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                          t.status === "Completed" ? "bg-green-100 text-green-700" :
                          t.status === "In Progress" ? "bg-blue-100 text-blue-700" :
                          t.status === "Blocked" ? "bg-red-100 text-red-700" :
                          "bg-muted text-muted-foreground"
                        }`}>{t.status}</span>
                      </td>
                      <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(t.due_date)}</td>
                      <td className="py-2.5 text-right font-semibold">{t.estimated_hours ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* Invoices */}
        {tab === "invoices" && (
          <div className="space-y-4">
            {data.invoice_summary && (
              <div className="grid grid-cols-3 gap-3 mb-4">
                {[
                  { label: "Total Invoiced", value: data.invoice_summary.total_invoiced },
                  { label: "Received",       value: data.invoice_summary.total_received },
                  { label: "Pending",        value: data.invoice_summary.total_pending  },
                ].map((c) => (
                  <div key={c.label} className="rounded-lg border border-border bg-muted/30 p-3">
                    <p className="text-[10px] text-muted-foreground">{c.label}</p>
                    <p className="text-sm font-semibold font-mono mt-1">
                      ₹{Number(c.value).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                    </p>
                  </div>
                ))}
              </div>
            )}
            {(data.invoices?.length ?? 0) === 0 ? (
              <p className="text-muted-foreground text-sm text-center py-10">No invoices for this case.</p>
            ) : (
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-left border-b border-border text-muted-foreground">
                    <th className="pb-2 font-medium">Invoice</th>
                    <th className="pb-2 font-medium">Date</th>
                    <th className="pb-2 font-medium">Status</th>
                    <th className="pb-2 font-medium text-right">Amount</th>
                    <th className="pb-2 font-medium text-right">Balance Due</th>
                  </tr>
                </thead>
                <tbody>
                  {(data.invoices as any[]).map((inv: any) => (
                    <tr key={inv.id} className="border-b border-border/50 hover:bg-muted/20">
                      <td className="py-2.5 pr-3 font-mono font-medium">{inv.invoice_code}</td>
                      <td className="py-2.5 pr-3 text-muted-foreground font-mono">{fmtDate(inv.created_at)}</td>
                      <td className="py-2.5 pr-3">
                        <Badge variant="outline" className="text-[10px]">{inv.status}</Badge>
                      </td>
                      <td className="py-2.5 pr-3 text-right font-mono">₹{Number(inv.total_amount).toLocaleString("en-IN", { maximumFractionDigits: 0 })}</td>
                      <td className="py-2.5 text-right font-mono font-semibold text-destructive">₹{Number(inv.balance_due).toLocaleString("en-IN", { maximumFractionDigits: 0 })}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* Ledger */}
        {tab === "ledger" && (
          <div>
            {(data.ledger?.length ?? 0) === 0 ? (
              <p className="text-muted-foreground text-sm text-center py-10">No ledger entries.</p>
            ) : (
              <table className="w-full text-xs">
                <thead>
                  <tr className="text-left border-b border-border text-muted-foreground">
                    <th className="pb-2 font-medium">Date</th>
                    <th className="pb-2 font-medium">Reference</th>
                    <th className="pb-2 font-medium">Type</th>
                    <th className="pb-2 font-medium text-right">Debit</th>
                    <th className="pb-2 font-medium text-right">Credit</th>
                    <th className="pb-2 font-medium text-right">Balance</th>
                  </tr>
                </thead>
                <tbody>
                  {(data.ledger as any[]).map((row: any, i: number) => (
                    <tr key={i} className="border-b border-border/50 hover:bg-muted/20">
                      <td className="py-2 pr-3 font-mono text-muted-foreground">{fmtDate(row.created_at)}</td>
                      <td className="py-2 pr-3 font-mono">{row.document_reference}</td>
                      <td className="py-2 pr-3 text-muted-foreground">{row.document_type}</td>
                      <td className="py-2 pr-3 text-right font-mono">{row.debit ? `₹${Number(row.debit).toLocaleString("en-IN")}` : "—"}</td>
                      <td className="py-2 pr-3 text-right font-mono text-green-600">{row.credit ? `₹${Number(row.credit).toLocaleString("en-IN")}` : "—"}</td>
                      <td className="py-2 text-right font-mono font-semibold">{`₹${Number(row.balance).toLocaleString("en-IN")}`}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        )}

        {/* History */}
        {tab === "history" && (
          <div className="space-y-3">
            {(data.elevations?.length ?? 0) === 0 ? (
              <p className="text-muted-foreground text-sm text-center py-10">
                No service history — the service code on this case has not been changed yet.
              </p>
            ) : (
              (data.elevations as any[]).map((ev: any) => (
                <div key={ev.id} className="flex items-start gap-3 rounded-lg border border-border bg-muted/20 p-3">
                  <div className="mt-0.5 text-[10px] px-1.5 py-0.5 rounded bg-muted font-mono font-medium">Changed</div>
                  <div className="flex-1 min-w-0">
                    <p className="text-xs font-medium">
                      {ev.from_service_code} → {ev.to_service_code}
                    </p>
                    {ev.predecessor_project && (
                      <p className="text-[10px] text-muted-foreground mt-0.5">
                        Predecessor: {ev.predecessor_project.docket_number ?? ev.predecessor_project.project_code}
                      </p>
                    )}
                    <p className="text-[10px] text-muted-foreground mt-0.5 font-mono">{fmtDate(ev.created_at)}</p>
                  </div>
                </div>
              ))
            )}
          </div>
        )}

      </div>
    </AppLayout>
  );
}
