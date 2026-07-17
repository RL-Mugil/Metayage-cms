import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { api } from "@/lib/api-client";

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}-${m}-${y}`;
}

/**
 * Statutory docketing panel — application legal status, event recording,
 * auto-generated deadlines, renewal schedule (S.53/Rule 80), event log.
 * Self-contained: fetches /projects/{id}/docket on mount.
 */
export function DocketDeadlines({ projectId }: { projectId: number | string }) {
  const [docket, setDocket] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [evtType, setEvtType] = useState("");
  const [evtDate, setEvtDate] = useState("");
  const [evtSaving, setEvtSaving] = useState(false);
  const [rules, setRules] = useState<any[]>([]);

  const load = () => {
    setLoading(true);
    api.getProjectDocket(projectId)
      .then(async (data) => {
        setDocket(data);
        setRules(data.capabilities?.can_approve_rules ? await api.getDeadlineRules() : []);
      })
      .catch(() => setDocket(null))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [projectId]);

  async function recordEvent() {
    if (!evtType || !evtDate) return;
    setEvtSaving(true);
    try {
      await api.recordDocketEvent(projectId, { event_type: evtType, event_date: evtDate });
      setEvtType(""); setEvtDate("");
      load();
    } catch { /* ignore */ }
    finally { setEvtSaving(false); }
  }

  async function setDeadlineStatus(id: number, status: string) {
    try { await api.updateDocketDeadline(id, status); load(); } catch { /* ignore */ }
  }

  async function setRenewalStatus(id: number, status: string) {
    try { await api.updateRenewal(id, status); load(); } catch { /* ignore */ }
  }

  async function reviewDeadline(id: number, status: 'Approved' | 'Rejected') {
    try { await api.reviewDocketDeadline(id, status); load(); } catch { /* ignore */ }
  }

  async function setRuleStatus(id: number, status: 'Approved' | 'Retired') {
    try { await api.updateDeadlineRule(id, status); load(); } catch { /* ignore */ }
  }

  if (loading) {
    return (
      <div className="flex items-center justify-center h-40">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (!docket) {
    return (
      <p className="text-muted-foreground text-sm text-center py-10">
        No docket data — this matter is not linked to a patent application.
      </p>
    );
  }

  const canManage = docket.capabilities?.can_manage === true;
  const canReview = docket.capabilities?.can_review_deadlines === true;

  return (
    <div className="space-y-6">
      <div className="rounded-md border border-border bg-muted/20 p-3 text-xs">
        <div className="flex flex-wrap items-center justify-between gap-2"><span className="font-semibold">Deadline rule engine</span><span className="font-mono">{docket.rule_engine?.jurisdiction || "—"}</span></div>
        <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-muted-foreground"><span>{docket.rule_engine?.approved_rules || 0} approved rules</span><span>{docket.rule_engine?.draft_rules || 0} awaiting approval</span><span>Reviewer: {docket.rule_engine?.reviewer?.name || "Unassigned"}</span></div>
        {(docket.rule_engine?.approved_rules || 0) === 0 && <p className="mt-2 font-medium text-amber-700">Events are recorded, but no statutory deadline is generated until a rule is approved.</p>}
      </div>
      {/* Application legal status card */}
      {docket.application && (
        <div className="rounded-lg border border-border bg-muted/30 p-4">
          <div className="flex items-center justify-between mb-2">
            <h4 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Patent Application (legal status)
            </h4>
            <span className={`text-[10px] px-2 py-0.5 rounded-full font-semibold ${
              docket.application.legal_status === "Granted" ? "bg-green-100 text-green-700" :
              ["Refused", "Abandoned", "Lapsed", "Withdrawn"].includes(docket.application.legal_status) ? "bg-red-100 text-red-700" :
              docket.application.legal_status === "Under Examination" ? "bg-blue-100 text-blue-700" :
              "bg-amber-100 text-amber-700"
            }`}>{docket.application.legal_status}</span>
          </div>
          <div className="grid grid-cols-3 gap-x-4 gap-y-1 text-xs">
            {[
              ["App No.", docket.application.application_number],
              ["Priority", fmtDate(docket.application.priority_date)],
              ["Filed", fmtDate(docket.application.filing_date)],
              ["Published", fmtDate(docket.application.publication_date)],
              ["Grant No.", docket.application.grant_number],
              ["Granted", fmtDate(docket.application.grant_date)],
            ].map(([label, val]) => (
              <div key={label as string}>
                <span className="text-muted-foreground">{label}: </span>
                <span className="font-mono">{val ?? "—"}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Record event */}
      {canManage && <div className="flex items-end gap-2">
        <div className="flex-1">
          <label className="text-[10px] text-muted-foreground block mb-1">Record docket event (deadlines auto-generate)</label>
          <select value={evtType} onChange={(e) => setEvtType(e.target.value)}
            className="w-full rounded-md border border-border bg-background px-2 py-1.5 text-xs">
            <option value="">Select event…</option>
            {Object.entries(docket.event_types ?? {}).map(([k, v]) => (
              <option key={k} value={k}>{v as string}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="text-[10px] text-muted-foreground block mb-1">Event date</label>
          <input type="date" value={evtDate} onChange={(e) => setEvtDate(e.target.value)}
            className="rounded-md border border-border bg-background px-2 py-1.5 text-xs" />
        </div>
        <button onClick={recordEvent} disabled={!evtType || !evtDate || evtSaving}
          className="rounded-md bg-primary text-primary-foreground px-3 py-1.5 text-xs font-medium disabled:opacity-50">
          {evtSaving ? "Saving…" : "Record"}
        </button>
      </div>}

      {/* Deadlines table */}
      <div>
        <h4 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">Statutory Deadlines</h4>
        {(docket.deadlines ?? []).length === 0 ? (
          <p className="text-muted-foreground text-xs py-4 text-center">
            No deadlines yet — record an event above (e.g. "FER Received") and the statutory deadlines generate automatically.
          </p>
        ) : (
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left border-b border-border text-muted-foreground">
                <th className="pb-2 font-medium">Deadline</th>
                <th className="pb-2 font-medium">Legal Basis</th>
                <th className="pb-2 font-medium">Due</th>
                <th className="pb-2 font-medium">Outer Limit</th>
                <th className="pb-2 font-medium">Risk / Review</th>
                <th className="pb-2 font-medium">Status</th>
                <th className="pb-2 font-medium text-right">Action</th>
              </tr>
            </thead>
            <tbody>
              {(docket.deadlines as any[]).map((d) => {
                const overdue = d.status === "Open" && new Date(d.due_date) < new Date();
                return (
                  <tr key={d.id} className={`border-b border-border/50 ${overdue ? "bg-red-500/5" : ""}`}>
                    <td className="py-2.5 pr-3 font-medium max-w-[200px]">{d.title}</td>
                    <td className="py-2.5 pr-3 text-muted-foreground text-[10px] max-w-[160px]">{d.legal_basis ?? "—"}</td>
                    <td className={`py-2.5 pr-3 font-mono whitespace-nowrap ${overdue ? "text-destructive font-bold" : ""}`}>{fmtDate(d.due_date)}</td>
                    <td className="py-2.5 pr-3 font-mono text-muted-foreground whitespace-nowrap">{fmtDate(d.extended_due_date)}</td>
                    <td className="py-2.5 pr-3"><div className="font-semibold text-red-700">{d.risk_level || "High"}</div><div className="text-[10px] text-muted-foreground">{d.review_status || "Unreviewed"}</div></td>
                    <td className="py-2.5 pr-3">
                      <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                        d.status === "Completed" ? "bg-green-100 text-green-700" :
                        d.status === "Missed" ? "bg-red-100 text-red-700" :
                        d.status === "Waived" ? "bg-muted text-muted-foreground" :
                        overdue ? "bg-red-100 text-red-700" : "bg-blue-100 text-blue-700"
                      }`}>{overdue && d.status === "Open" ? "OVERDUE" : d.status}</span>
                    </td>
                    <td className="py-2.5 text-right whitespace-nowrap">
                      {canReview && d.review_status === "Unreviewed" ? <><button onClick={() => reviewDeadline(d.id, "Approved")} className="mr-2 text-[10px] text-green-600 hover:underline">Approve</button><button onClick={() => reviewDeadline(d.id, "Rejected")} className="mr-2 text-[10px] text-red-600 hover:underline">Reject</button></> : null}
                      {!canManage ? (
                        <span className="text-[10px] text-muted-foreground">View only</span>
                      ) : d.status === "Open" ? (
                        <>
                          <button onClick={() => setDeadlineStatus(d.id, "Completed")}
                            className="text-[10px] text-green-600 hover:underline mr-2">Done</button>
                          <button onClick={() => setDeadlineStatus(d.id, "Waived")}
                            className="text-[10px] text-muted-foreground hover:underline">Waive</button>
                        </>
                      ) : (
                        <button onClick={() => setDeadlineStatus(d.id, "Open")}
                          className="text-[10px] text-muted-foreground hover:underline">Reopen</button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>

      {docket.capabilities?.can_approve_rules && rules.length > 0 && <div><h4 className="mb-3 text-xs font-semibold uppercase text-muted-foreground">Rule registry</h4><div className="overflow-x-auto rounded-md border border-border"><table className="w-full min-w-[760px] text-xs"><thead><tr className="border-b text-left text-muted-foreground"><th className="p-2">Rule</th><th className="p-2">Event</th><th className="p-2">Legal basis</th><th className="p-2">Version</th><th className="p-2">Status</th><th className="p-2 text-right">Action</th></tr></thead><tbody>{rules.map((rule) => <tr key={rule.id} className="border-b border-border/50"><td className="p-2 font-mono text-[10px]">{rule.rule_code}</td><td className="p-2">{rule.event_type}</td><td className="p-2">{rule.legal_basis}</td><td className="p-2 font-mono">{rule.version}</td><td className="p-2">{rule.status}</td><td className="p-2 text-right">{rule.status === "Draft" ? <button onClick={() => setRuleStatus(rule.id, "Approved")} className="text-green-600 hover:underline">Approve</button> : rule.status === "Approved" ? <button onClick={() => setRuleStatus(rule.id, "Retired")} className="text-red-600 hover:underline">Retire</button> : null}</td></tr>)}</tbody></table></div></div>}

      {/* Renewal schedule */}
      {(docket.renewals ?? []).length > 0 && (
        <div>
          <h4 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">
            Renewal Schedule (S.53 / Rule 80 — years 3–20)
          </h4>
          <div className="grid grid-cols-6 gap-1.5">
            {(docket.renewals as any[]).map((r) => {
              const overdue = r.status === "Unpaid" && new Date(r.due_date) < new Date();
              return (
                <button key={r.id}
                  title={`Year ${r.renewal_year} — due ${fmtDate(r.due_date)} (${r.status}). Click to mark ${r.status === "Paid" ? "Unpaid" : "Paid"}.`}
                  onClick={() => canManage && setRenewalStatus(r.id, r.status === "Paid" ? "Unpaid" : "Paid")}
                  disabled={!canManage}
                  className={`rounded-md border px-1.5 py-1.5 text-center transition-colors ${
                    r.status === "Paid" ? "border-green-500/40 bg-green-500/10 text-green-600" :
                    r.status === "Waived" ? "border-border bg-muted/40 text-muted-foreground" :
                    overdue ? "border-red-500/40 bg-red-500/10 text-red-600" :
                    "border-border bg-muted/20 text-foreground hover:border-blue-400"
                  }`}>
                  <div className="text-[10px] font-bold">Y{r.renewal_year}</div>
                  <div className="text-[8px] font-mono">{fmtDate(r.due_date).slice(0, 5)}<br/>{fmtDate(r.due_date).slice(6)}</div>
                </button>
              );
            })}
          </div>
        </div>
      )}

      {/* Event log */}
      {(docket.events ?? []).length > 0 && (
        <div>
          <h4 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">Event Log</h4>
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left border-b border-border text-muted-foreground">
                <th className="pb-2 font-medium">Date</th>
                <th className="pb-2 font-medium">Event</th>
                <th className="pb-2 font-medium">By</th>
                <th className="pb-2 font-medium">Notes</th>
              </tr>
            </thead>
            <tbody>
              {(docket.events as any[]).map((ev) => (
                <tr key={ev.id} className="border-b border-border/50">
                  <td className="py-2 pr-3 font-mono text-muted-foreground whitespace-nowrap">{fmtDate(ev.event_date)}</td>
                  <td className="py-2 pr-3 font-medium">{docket.event_types?.[ev.event_type] ?? ev.event_type}</td>
                  <td className="py-2 pr-3 text-muted-foreground">{ev.creator?.name ?? "—"}</td>
                  <td className="py-2 text-muted-foreground italic max-w-[200px] truncate">{ev.notes ?? "—"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
