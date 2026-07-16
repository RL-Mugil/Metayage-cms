import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { X, Loader2, ArrowRight, Clock, Lock } from "lucide-react";
import { api } from "@/lib/api-client";
import { DocketDeadlines } from "@/components/docket-deadlines";

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}-${m}-${y}`;
}

interface Props {
  projectId: number;
  onClose: () => void;
}

type Tab = "stages" | "deadlines" | "tasks" | "invoices" | "ledger" | "history";

export function ProjectDetailPanel({ projectId, onClose }: Props) {
  const [data, setData] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<Tab>("stages");
  const [advancingStage, setAdvancingStage] = useState<string | null>(null);
  const [lockedMsg, setLockedMsg] = useState<string | null>(null);

  const TERMINAL_STATUSES = ['Granted', 'Refused', 'Abandoned'];

  const fetchDetail = (cancelled: { v: boolean }) => {
    setLoading(true);
    setData(null);
    api.request(`/projects/${projectId}/detail`).then((res: any) => {
      if (!cancelled.v) { setData(res); setLoading(false); }
    }).catch(() => {
      if (!cancelled.v) setLoading(false);
    });
  };

  useEffect(() => {
    const ref = { v: false };
    fetchDetail(ref);
    return () => { ref.v = true; };
  }, [projectId]);

  async function advanceToStage(stageName: string) {
    const projectStatus = data?.project?.status;
    if (TERMINAL_STATUSES.includes(projectStatus)) {
      setLockedMsg(`Case is ${projectStatus} — no workflow stage is applicable. Change the project status first to resume the pipeline.`);
      return;
    }
    setLockedMsg(null);
    setAdvancingStage(stageName);
    try {
      await api.updateProjectStage(projectId, stageName);
      const ref = { v: false };
      fetchDetail(ref);
    } catch { /* ignore */ }
    finally { setAdvancingStage(null); }
  }

  return createPortal(
    <>
      <div className="fixed inset-0 bg-black/50 z-40" onClick={onClose} />
      <div className="fixed right-0 top-0 h-full w-full max-w-3xl bg-background border-l border-border z-50 flex flex-col shadow-2xl">

        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <p className="text-xs text-muted-foreground font-mono">
              {data?.project?.docket_number ?? "Loading…"}
              {(() => {
                const notes: string = data?.project?.notes ?? "";
                const m = notes.match(/ref:\s*([^\n\)]+)/i);
                const ref = m?.[1]?.trim();
                if (!ref || ref === data?.project?.docket_number) return null;
                return <span className="ml-2 text-[10px] text-muted-foreground/50">(DocketTrak: {ref})</span>;
              })()}
            </p>
            <h2 className="text-base font-semibold truncate max-w-[500px]">
              {data?.project?.project_name ?? ""}
            </h2>
            {data?.project && (
              <p className="text-xs text-muted-foreground mt-0.5">
                {data.project.client?.company_name ?? data.project.client?.legal_name ?? ""}
                {data.project.patent_office_code ? ` · ${data.project.patent_office_code}` : ""}
                {data.project.service_code ? ` · ${data.project.service_code}` : ""}
                {data.project.status === "Completed" && data.project.patent_granted
                  ? <span className="ml-1 text-[10px] text-green-500 font-medium">· Granted</span>
                  : data.project.status === "Closed"
                  ? <span className="ml-1 text-[10px] text-destructive font-medium">· Closed</span>
                  : null}
              </p>
            )}
          </div>
          <button onClick={onClose} className="p-1.5 rounded hover:bg-muted/50">
            <X className="h-4 w-4" />
          </button>
        </div>

        {/* Tabs */}
        <div className="flex border-b border-border flex-shrink-0 px-6">
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
              {/* Pipeline — horizontal flowchart */}
              {tab === "stages" && (
                <div className="p-6">
                  {/* Terminal status banner */}
                  {(() => {
                    const projectStatus = data?.project?.status;
                    const isTerminal = TERMINAL_STATUSES.includes(projectStatus);
                    if (!isTerminal) return null;
                    return (
                      <div className="mb-5 flex items-start gap-3 rounded-lg border border-amber-500/30 bg-amber-500/10 px-4 py-3">
                        <Lock className="h-4 w-4 text-amber-500 flex-shrink-0 mt-0.5" />
                        <div className="text-xs text-amber-700 dark:text-amber-400 leading-relaxed">
                          <span className="font-semibold">Case is {projectStatus}.</span> No workflow stage is applicable.
                          Change the project status to resume the pipeline.
                        </div>
                      </div>
                    );
                  })()}

                  {/* Inline locked message (when a circle is clicked) */}
                  {lockedMsg && (
                    <div className="mb-4 flex items-start gap-3 rounded-lg border border-destructive/30 bg-destructive/10 px-4 py-3">
                      <Lock className="h-4 w-4 text-destructive flex-shrink-0 mt-0.5" />
                      <p className="text-xs text-destructive leading-relaxed flex-1">{lockedMsg}</p>
                      <button onClick={() => setLockedMsg(null)} className="text-destructive/60 hover:text-destructive">
                        <X className="h-3.5 w-3.5" />
                      </button>
                    </div>
                  )}

                  {/* Progress bar */}
                  {(() => {
                    const stages = data.stages as any[];
                    const done = stages.filter((s: any) => s.status === "Completed").length;
                    const pct = stages.length ? Math.round((done / stages.length) * 100) : 0;
                    return (
                      <div className="flex items-center gap-4 mb-6">
                        <div className="flex items-center gap-2 flex-1">
                          <span className="text-xs text-muted-foreground whitespace-nowrap">
                            {done} / {stages.length} stages
                          </span>
                          <div className="flex-1 h-1.5 rounded-full bg-muted overflow-hidden max-w-[180px]">
                            <div
                              className="h-full rounded-full bg-green-500 transition-all"
                              style={{ width: `${pct}%` }}
                            />
                          </div>
                          <span className="text-xs font-semibold text-green-600">{pct}%</span>
                        </div>
                        {data.project?.filing_date && (
                          <p className="text-xs text-muted-foreground flex-shrink-0">
                            Filed: <span className="font-mono font-semibold text-foreground">{fmtDate(data.project.filing_date)}</span>
                          </p>
                        )}
                      </div>
                    );
                  })()}

                  {/* Flowchart */}
                  <div className="overflow-x-auto pb-3">
                    <div className="flex items-start min-w-max gap-0 py-2">
                      {(data.stages as any[]).map((s: any, i: number) => {
                        const isDone   = s.status === "Completed";
                        const isActive = s.status === "In Progress";
                        const stages   = data.stages as any[];
                        const nextDone = stages[i + 1]?.status === "Completed";
                        const isTerminal = TERMINAL_STATUSES.includes(data.project?.status);

                        return (
                          <div key={s.id} className="flex items-start">
                            {/* Stage node */}
                            <div className="flex flex-col items-center w-[88px] text-center">
                              {/* Circle — clickable to advance to this stage (locked when terminal) */}
                              <button
                                title={isTerminal ? `Case is ${data.project?.status} — pipeline locked` : `Set "${s.stage_name}" as active stage`}
                                disabled={advancingStage !== null}
                                onClick={() => advanceToStage(s.stage_name)}
                                className={`w-9 h-9 rounded-full flex items-center justify-center border-2 flex-shrink-0 transition-all ${
                                  isTerminal
                                    ? "border-muted-foreground/30 bg-muted/20 cursor-not-allowed opacity-60"
                                    : advancingStage === s.stage_name
                                    ? "border-gold bg-gold/20 animate-pulse cursor-wait"
                                    : isDone
                                    ? "border-green-500 bg-green-500 shadow shadow-green-500/30 hover:bg-green-400 hover:scale-110 hover:shadow-lg cursor-pointer"
                                    : isActive
                                    ? "border-blue-500 bg-blue-500 shadow shadow-blue-500/30 hover:bg-blue-400 hover:scale-110 hover:shadow-lg cursor-pointer"
                                    : "border-border bg-muted/40 hover:border-blue-400 hover:bg-blue-500/10 hover:scale-110 hover:shadow-lg cursor-pointer"
                                }`}>
                                {isTerminal ? (
                                  <Lock className="w-3 h-3 text-muted-foreground/50" />
                                ) : advancingStage === s.stage_name ? (
                                  <Loader2 className="w-3.5 h-3.5 text-gold animate-spin" />
                                ) : isDone ? (
                                  <svg className="w-4 h-4 text-white" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                  </svg>
                                ) : isActive ? (
                                  <div className="w-2.5 h-2.5 rounded-full bg-white animate-pulse" />
                                ) : (
                                  <div className="w-2 h-2 rounded-full bg-muted-foreground/25" />
                                )}
                              </button>
                              {/* Step number */}
                              <span className="text-[9px] text-muted-foreground/50 font-mono mt-0.5">{i + 1}</span>
                              {/* Stage name */}
                              <div className={`mt-1 text-[10px] leading-snug font-medium px-0.5 ${
                                isDone   ? "text-green-600 dark:text-green-400" :
                                isActive ? "text-blue-600 dark:text-blue-400"   :
                                           "text-muted-foreground"
                              }`}>
                                {s.stage_name}
                              </div>
                              {/* Duration */}
                              {s.working_days != null && (
                                <div className="mt-0.5 text-[9px] font-mono text-muted-foreground/40">
                                  {s.working_days}d
                                </div>
                              )}
                              {/* Dates (only for completed/active) */}
                              {(isDone || isActive) && s.actual_start_at && (
                                <div className="mt-0.5 text-[9px] font-mono text-muted-foreground/50 leading-tight">
                                  {fmtDate(s.actual_start_at)}
                                  {s.actual_end_at && <><br />{fmtDate(s.actual_end_at)}</>}
                                </div>
                              )}
                            </div>

                            {/* Arrow connector */}
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

                  {/* Legend */}
                  <div className="flex items-center gap-5 mt-4 pt-4 border-t border-border">
                    {[
                      { cls: "bg-green-500", label: "Completed" },
                      { cls: "bg-blue-500 animate-pulse",  label: "In Progress" },
                      { cls: "bg-muted-foreground/20 border border-border", label: "Pending" },
                    ].map((l) => (
                      <div key={l.label} className="flex items-center gap-1.5">
                        <div className={`w-2.5 h-2.5 rounded-full ${l.cls}`} />
                        <span className="text-[10px] text-muted-foreground">{l.label}</span>
                      </div>
                    ))}
                    {!TERMINAL_STATUSES.includes(data.project?.status) && (
                      <span className="text-[10px] text-muted-foreground/50 italic">Click a stage circle to advance</span>
                    )}
                    {(data.total_stage_days ?? 0) > 0 && (
                      <span className="ml-auto text-[10px] text-muted-foreground">
                        Total: <span className="font-semibold text-foreground">{data.total_stage_days}</span> working days
                      </span>
                    )}
                  </div>
                </div>
              )}

              {/* Deadlines — statutory docketing engine (shared component) */}
              {tab === "deadlines" && (
                <div className="p-6">
                  <DocketDeadlines projectId={projectId} />
                </div>
              )}

              {/* Tasks */}
              {tab === "tasks" && (
                <div className="p-6">
                  {data.tasks.length === 0 ? (
                    <p className="text-muted-foreground text-sm text-center py-10">No tasks for this case.</p>
                  ) : (
                    <table className="w-full text-xs">
                      <thead>
                        <tr className="text-left border-b border-border text-muted-foreground">
                          <th className="pb-2 font-medium">Task</th>
                          <th className="pb-2 font-medium">Assignee</th>
                          <th className="pb-2 font-medium">Status</th>
                          <th className="pb-2 font-medium">Start</th>
                          <th className="pb-2 font-medium">Completed</th>
                          <th className="pb-2 font-medium text-right">W.Days</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.tasks as any[]).map((t: any) => (
                          <tr key={t.id} className="border-b border-border/50 hover:bg-muted/20">
                            <td className="py-2.5 pr-3 font-medium max-w-[180px] truncate">{t.title}</td>
                            <td className="py-2.5 pr-3 text-muted-foreground">{t.assignee?.name ?? "—"}</td>
                            <td className="py-2.5 pr-3">
                              <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                t.status === "Completed" ? "bg-green-100 text-green-700" :
                                t.status === "In Progress" ? "bg-blue-100 text-blue-700" :
                                t.status === "Blocked" ? "bg-red-100 text-red-700" :
                                "bg-muted text-muted-foreground"
                              }`}>{t.status}</span>
                            </td>
                            <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(t.created_at)}</td>
                            <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(t.completed_at)}</td>
                            <td className="py-2.5 text-right font-semibold">{t.working_days != null ? t.working_days : "—"}</td>
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
                    <p className="text-muted-foreground text-sm text-center py-8">No invoices for this case.</p>
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

                  {/* Chain invoices from predecessor cases */}
                  {data.chain_invoices && data.chain_invoices.length > 0 && (
                    <div className="mt-6">
                      <h4 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">
                        Invoices from predecessor cases
                      </h4>
                      <table className="w-full text-xs">
                        <thead>
                          <tr className="text-left border-b border-border text-muted-foreground">
                            <th className="pb-2 font-medium">Invoice #</th>
                            <th className="pb-2 font-medium">From Case</th>
                            <th className="pb-2 font-medium">Status</th>
                            <th className="pb-2 font-medium">Date</th>
                            <th className="pb-2 font-medium text-right">Total</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(data.chain_invoices as any[]).map((inv: any) => (
                            <tr key={inv.id} className="border-b border-border/50 hover:bg-muted/20">
                              <td className="py-2.5 pr-3 font-mono text-gold font-semibold">{inv.invoice_code}</td>
                              <td className="py-2.5 pr-3 font-mono text-[10px] text-muted-foreground">{inv.source_docket ?? "—"}</td>
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
                              <td className="py-2.5 text-right font-mono">
                                {inv.currency === "INR" ? "₹" : `${inv.currency} `}
                                {Number(inv.total_amount).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              )}

              {/* Ledger */}
              {tab === "ledger" && (
                <div className="p-6">
                  {data.ledger.length === 0 ? (
                    <p className="text-muted-foreground text-sm text-center py-8">No ledger entries for this case.</p>
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
              {/* History — elevation chain timeline */}
              {tab === "history" && (
                <div className="p-6">
                  {(!data.elevations || data.elevations.length === 0) ? (
                    <div className="flex flex-col items-center justify-center py-16 text-center">
                      <Clock className="h-10 w-10 text-muted-foreground opacity-30 mb-3" />
                      <p className="text-sm text-muted-foreground">No service history — the service code on this case has not been changed yet.</p>
                      <p className="text-xs text-muted-foreground mt-1">Use "Change Service" or "Link Predecessor" on the case to build a chain.</p>
                    </div>
                  ) : (
                    <div>
                      <h4 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-6">
                        Service Chain
                      </h4>

                      {/* Build chain nodes from elevations */}
                      {(() => {
                        const elevations = data.elevations as any[];
                        // Reconstruct nodes: first node is the initial docket, then each elevation adds a node
                        type ChainNode = { docket: string; serviceCode: string; label: string; elevatedAt?: string; elevatedBy?: string; note?: string; isRetroactive?: boolean };
                        const nodes: ChainNode[] = [];

                        if (elevations.length > 0) {
                          const first = elevations[0];
                          nodes.push({
                            docket: first.from_docket,
                            serviceCode: first.from_service_code,
                            label: "Origin",
                          });
                          for (const ev of elevations) {
                            nodes.push({
                              docket: ev.to_docket,
                              serviceCode: ev.to_service_code,
                              label: ev.is_retroactive_link ? "Linked" : "Changed",
                              elevatedAt: ev.elevated_at,
                              elevatedBy: ev.elevated_by?.name,
                              note: ev.note,
                              isRetroactive: ev.is_retroactive_link,
                            });
                          }
                        }

                        // Calendar-day duration between nodes
                        const daysBetween = (a?: string, b?: string): number | null => {
                          if (!a || !b) return null;
                          const diff = new Date(b).getTime() - new Date(a).getTime();
                          return Math.max(0, Math.round(diff / (1000 * 60 * 60 * 24)));
                        };

                        // Total from first elevation to now
                        const totalDays = daysBetween(
                          elevations[0]?.elevated_at,
                          new Date().toISOString()
                        );

                        return (
                          <div>
                            {/* Chain diagram — scrollable horizontally */}
                            <div className="overflow-x-auto pb-4">
                              <div className="flex items-center min-w-max gap-0">
                                {nodes.map((node, i) => (
                                  <div key={i} className="flex items-center">
                                    {/* Node */}
                                    <div className="flex flex-col items-center w-28 text-center gap-1">
                                      <div className={`text-[10px] font-semibold px-2 py-0.5 rounded-full border ${
                                        i === nodes.length - 1
                                          ? "border-gold text-gold bg-gold/10"
                                          : "border-border text-muted-foreground bg-muted/40"
                                      }`}>
                                        {node.serviceCode}
                                      </div>
                                      <span className="text-[10px] font-mono text-foreground font-semibold leading-tight break-all">
                                        {node.docket}
                                      </span>
                                      {node.elevatedAt && (
                                        <span className="text-[9px] text-muted-foreground font-mono">
                                          {fmtDate(node.elevatedAt)}
                                        </span>
                                      )}
                                      {node.label !== "Origin" && (
                                        <span className={`text-[9px] px-1.5 py-0.5 rounded font-medium ${
                                          node.isRetroactive
                                            ? "bg-teal-500/10 text-teal-500"
                                            : "bg-amber-500/10 text-amber-500"
                                        }`}>
                                          {node.label}
                                        </span>
                                      )}
                                      {node.note && (
                                        <span className="text-[9px] text-muted-foreground italic truncate max-w-[100px]" title={node.note}>
                                          "{node.note}"
                                        </span>
                                      )}
                                    </div>

                                    {/* Arrow with duration */}
                                    {i < nodes.length - 1 && (() => {
                                      const duration = daysBetween(
                                        elevations[i]?.elevated_at,
                                        elevations[i + 1]?.elevated_at ?? new Date().toISOString()
                                      );
                                      return (
                                        <div className="flex flex-col items-center w-20 flex-shrink-0">
                                          <span className="text-[9px] text-muted-foreground mb-0.5">
                                            {duration !== null ? `${duration}d` : ""}
                                          </span>
                                          <div className="flex items-center w-full">
                                            <div className="flex-1 h-px bg-border" />
                                            <ArrowRight className="h-3 w-3 text-muted-foreground flex-shrink-0" />
                                          </div>
                                        </div>
                                      );
                                    })()}
                                  </div>
                                ))}

                                {/* "Current" node for last elevation to now */}
                                {elevations.length > 0 && (() => {
                                  const last = elevations[elevations.length - 1];
                                  const sinceElevation = daysBetween(last.elevated_at, new Date().toISOString());
                                  return (
                                    <div className="flex items-center">
                                      <div className="flex flex-col items-center w-20 flex-shrink-0">
                                        <span className="text-[9px] text-muted-foreground mb-0.5">
                                          {sinceElevation !== null ? `${sinceElevation}d` : ""}
                                        </span>
                                        <div className="flex items-center w-full">
                                          <div className="flex-1 h-px bg-border border-dashed" />
                                          <ArrowRight className="h-3 w-3 text-muted-foreground flex-shrink-0" />
                                        </div>
                                      </div>
                                      <div className="flex flex-col items-center w-20 text-center gap-1">
                                        <div className="text-[10px] font-semibold px-2 py-0.5 rounded-full border border-primary text-primary bg-primary/10">
                                          {data.project?.service_code ?? "—"}
                                        </div>
                                        <span className="text-[10px] text-primary font-medium">Active</span>
                                      </div>
                                    </div>
                                  );
                                })()}
                              </div>
                            </div>

                            {/* Total summary */}
                            {totalDays !== null && (
                              <div className="mt-4 rounded-lg border border-border bg-muted/30 px-4 py-3 text-xs text-muted-foreground">
                                Total time across {elevations.length} service change{elevations.length !== 1 ? "s" : ""}:{" "}
                                <span className="font-semibold text-foreground">{totalDays} calendar days</span>
                                {data.project?.original_docket && (
                                  <span className="ml-4">Original docket: <span className="font-mono text-gold">{data.project.original_docket}</span></span>
                                )}
                              </div>
                            )}

                            {/* Elevation log table */}
                            <div className="mt-6">
                              <h4 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">Event Log</h4>
                              <table className="w-full text-xs">
                                <thead>
                                  <tr className="text-left border-b border-border text-muted-foreground">
                                    <th className="pb-2 font-medium">Date</th>
                                    <th className="pb-2 font-medium">From</th>
                                    <th className="pb-2 font-medium">To</th>
                                    <th className="pb-2 font-medium">By</th>
                                    <th className="pb-2 font-medium">Type</th>
                                    <th className="pb-2 font-medium">Note</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {elevations.map((ev: any) => (
                                    <tr key={ev.id} className="border-b border-border/50 hover:bg-muted/20">
                                      <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(ev.elevated_at)}</td>
                                      <td className="py-2.5 pr-3 font-mono text-[10px]">{ev.from_docket}</td>
                                      <td className="py-2.5 pr-3 font-mono text-[10px] text-gold">{ev.to_docket}</td>
                                      <td className="py-2.5 pr-3">{ev.elevated_by?.name ?? "—"}</td>
                                      <td className="py-2.5 pr-3">
                                        <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                          ev.is_retroactive_link
                                            ? "bg-teal-500/10 text-teal-500"
                                            : "bg-amber-500/10 text-amber-500"
                                        }`}>
                                          {ev.is_retroactive_link ? "Link" : "Changed"}
                                        </span>
                                      </td>
                                      <td className="py-2.5 text-muted-foreground italic">{ev.note ?? "—"}</td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          </div>
                        );
                      })()}
                    </div>
                  )}
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </>,
    document.body
  );
}
