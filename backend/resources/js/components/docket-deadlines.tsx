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
  const [evtNotes, setEvtNotes] = useState("");
  const [editingEventId, setEditingEventId] = useState<number | null>(null);
  const [evtSaving, setEvtSaving] = useState(false);
  const [deadlineForm, setDeadlineForm] = useState({ title: "", due_date: "", extended_due_date: "", legal_basis: "", notes: "", risk_level: "High" as "Low" | "Medium" | "High" | "Critical" });
  const [editingDeadlineId, setEditingDeadlineId] = useState<number | null>(null);
  const [deadlineFormOpen, setDeadlineFormOpen] = useState(false);
  const [deadlineSaving, setDeadlineSaving] = useState(false);

  const load = () => {
    setLoading(true);
    api.getProjectDocket(projectId)
      .then((data) => {
        setDocket(data);
      })
      .catch(() => setDocket(null))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [projectId]);

  async function recordEvent() {
    if (!evtType || !evtDate) return;
    setEvtSaving(true);
    try {
      const payload = { event_type: evtType, event_date: evtDate, notes: evtNotes.trim() || undefined };
      if (editingEventId) await api.updateDocketEvent(editingEventId, payload);
      else await api.recordDocketEvent(projectId, payload);
      setEvtType(""); setEvtDate(""); setEvtNotes(""); setEditingEventId(null);
      load();
    } catch { /* ignore */ }
    finally { setEvtSaving(false); }
  }

  async function setDeadlineStatus(id: number, status: string) {
    try { await api.updateDocketDeadline(id, { status }); load(); } catch { /* ignore */ }
  }

  async function setRenewalStatus(id: number, status: string) {
    try { await api.updateRenewal(id, status); load(); } catch { /* ignore */ }
  }

  const [addYear, setAddYear] = useState("");
  const [addDue, setAddDue] = useState("");
  const [addSaving, setAddSaving] = useState(false);

  async function addRenewalYear() {
    if (!addYear || !addDue) return;
    setAddSaving(true);
    try {
      await api.addRenewal(projectId, { renewal_year: parseInt(addYear, 10), due_date: addDue });
      setAddYear(""); setAddDue("");
      load();
    } catch { /* ignore */ }
    finally { setAddSaving(false); }
  }

  function editEvent(event: any) {
    setEditingEventId(event.id); setEvtType(event.event_type);
    setEvtDate(event.event_date?.split("T")[0] ?? ""); setEvtNotes(event.notes ?? "");
  }

  async function deleteEvent(id: number) {
    if (!window.confirm("Delete this event and its generated deadlines?")) return;
    try { await api.deleteDocketEvent(id); load(); } catch { /* keep current data */ }
  }

  function resetDeadlineForm() {
    setEditingDeadlineId(null);
    setDeadlineFormOpen(false);
    setDeadlineForm({ title: "", due_date: "", extended_due_date: "", legal_basis: "", notes: "", risk_level: "High" });
  }

  function editDeadline(deadline: any) {
    setEditingDeadlineId(deadline.id);
    setDeadlineFormOpen(true);
    setDeadlineForm({ title: deadline.title, due_date: deadline.due_date?.split("T")[0] ?? "", extended_due_date: deadline.extended_due_date?.split("T")[0] ?? "", legal_basis: deadline.legal_basis ?? "", notes: deadline.notes ?? "", risk_level: deadline.risk_level ?? "High" });
  }

  async function saveDeadline() {
    if (!deadlineForm.title.trim() || !deadlineForm.due_date) return;
    setDeadlineSaving(true);
    const payload = { ...deadlineForm, title: deadlineForm.title.trim(), extended_due_date: deadlineForm.extended_due_date || null, legal_basis: deadlineForm.legal_basis.trim() || null, notes: deadlineForm.notes.trim() || null };
    try {
      if (editingDeadlineId) await api.updateDocketDeadline(editingDeadlineId, payload);
      else await api.createDocketDeadline(projectId, payload);
      resetDeadlineForm(); load();
    } catch { /* keep form values */ }
    finally { setDeadlineSaving(false); }
  }

  async function deleteDeadline(id: number) {
    if (!window.confirm("Delete this deadline?")) return;
    try { await api.deleteDocketDeadline(id); load(); } catch { /* keep current data */ }
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

  return (
    <div className="space-y-6">
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
        <div className="flex-1">
          <label className="text-[10px] text-muted-foreground block mb-1">Notes</label>
          <input value={evtNotes} onChange={(e) => setEvtNotes(e.target.value)} placeholder="Optional notes"
            className="w-full rounded-md border border-border bg-background px-2 py-1.5 text-xs" />
        </div>
        <button onClick={recordEvent} disabled={!evtType || !evtDate || evtSaving}
          className="rounded-md bg-primary text-primary-foreground px-3 py-1.5 text-xs font-medium disabled:opacity-50">
          {evtSaving ? "Saving…" : editingEventId ? "Update" : "Record"}
        </button>
        {editingEventId && <button onClick={() => { setEditingEventId(null); setEvtType(""); setEvtDate(""); setEvtNotes(""); }} className="text-xs text-muted-foreground hover:underline">Cancel</button>}
      </div>}

      {/* Deadlines table */}
      <div>
        <div className="mb-3 flex items-center justify-between"><h4 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Deadlines</h4>{canManage && <button onClick={() => { resetDeadlineForm(); setDeadlineFormOpen(true); }} className="text-xs text-blue-600 hover:underline">+ Add deadline</button>}</div>
        {canManage && deadlineFormOpen && <div className="mb-4 grid gap-2 rounded-md border border-border p-3 md:grid-cols-3">
          <input value={deadlineForm.title} onChange={(e) => setDeadlineForm({ ...deadlineForm, title: e.target.value })} placeholder="Deadline title" className="rounded-md border border-border bg-background px-2 py-1.5 text-xs md:col-span-2" />
          <select value={deadlineForm.risk_level} onChange={(e) => setDeadlineForm({ ...deadlineForm, risk_level: e.target.value as typeof deadlineForm.risk_level })} className="rounded-md border border-border bg-background px-2 py-1.5 text-xs"><option>Low</option><option>Medium</option><option>High</option><option>Critical</option></select>
          <label className="text-[10px] text-muted-foreground">Due date<input type="date" value={deadlineForm.due_date} onChange={(e) => setDeadlineForm({ ...deadlineForm, due_date: e.target.value })} className="mt-1 w-full rounded-md border border-border bg-background px-2 py-1.5 text-xs" /></label>
          <label className="text-[10px] text-muted-foreground">Outer limit<input type="date" value={deadlineForm.extended_due_date} onChange={(e) => setDeadlineForm({ ...deadlineForm, extended_due_date: e.target.value })} className="mt-1 w-full rounded-md border border-border bg-background px-2 py-1.5 text-xs" /></label>
          <input value={deadlineForm.legal_basis} onChange={(e) => setDeadlineForm({ ...deadlineForm, legal_basis: e.target.value })} placeholder="Legal basis (optional)" className="rounded-md border border-border bg-background px-2 py-1.5 text-xs" />
          <input value={deadlineForm.notes} onChange={(e) => setDeadlineForm({ ...deadlineForm, notes: e.target.value })} placeholder="Notes (optional)" className="rounded-md border border-border bg-background px-2 py-1.5 text-xs md:col-span-2" />
          <div className="flex items-end gap-2"><button onClick={saveDeadline} disabled={deadlineSaving || !deadlineForm.title.trim() || !deadlineForm.due_date} className="rounded-md bg-primary px-3 py-1.5 text-xs text-primary-foreground disabled:opacity-50">{deadlineSaving ? "Saving…" : editingDeadlineId ? "Update deadline" : "Create deadline"}</button><button onClick={resetDeadlineForm} className="text-xs text-muted-foreground hover:underline">Cancel</button></div>
        </div>}
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
                <th className="pb-2 font-medium">Risk</th>
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
                    <td className="py-2.5 pr-3"><div className="font-semibold text-red-700">{d.risk_level || "High"}</div></td>
                    <td className="py-2.5 pr-3">
                      <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                        d.status === "Completed" ? "bg-green-100 text-green-700" :
                        d.status === "Missed" ? "bg-red-100 text-red-700" :
                        d.status === "Waived" ? "bg-muted text-muted-foreground" :
                        overdue ? "bg-red-100 text-red-700" : "bg-blue-100 text-blue-700"
                      }`}>{overdue && d.status === "Open" ? "OVERDUE" : d.status}</span>
                    </td>
                    <td className="py-2.5 text-right whitespace-nowrap">
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
                      {canManage && <><button onClick={() => editDeadline(d)} className="ml-2 text-[10px] text-blue-600 hover:underline">Edit</button><button onClick={() => deleteDeadline(d.id)} className="ml-2 text-[10px] text-red-600 hover:underline">Delete</button></>}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>


      {/* Renewal schedule */}
      {((docket.renewals ?? []).length > 0 || (canManage && docket.application)) && (
        <div>
          <h4 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">
            Renewal Schedule
            {docket.application?.jurisdiction === "IN" && " (S.53 / Rule 80 — years 3–20 auto-generated on grant)"}
          </h4>
          {(docket.renewals ?? []).length > 0 && (
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
          )}
          {canManage && docket.application && (
            <div className="mt-2 flex items-end gap-2">
              <div>
                <label className="block text-[10px] text-muted-foreground">Year</label>
                <input type="number" min={1} max={30} value={addYear} onChange={(e) => setAddYear(e.target.value)}
                  className="w-16 rounded-md border border-border bg-background px-2 py-1 text-xs" placeholder="e.g. 3" />
              </div>
              <div>
                <label className="block text-[10px] text-muted-foreground">Due date</label>
                <input type="date" value={addDue} onChange={(e) => setAddDue(e.target.value)}
                  className="rounded-md border border-border bg-background px-2 py-1 text-xs" />
              </div>
              <button onClick={addRenewalYear} disabled={addSaving || !addYear || !addDue}
                className="rounded-md border border-border px-2.5 py-1 text-xs hover:bg-muted/40 disabled:opacity-50">
                {addSaving ? "Adding…" : "Add renewal year"}
              </button>
            </div>
          )}
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
                <th className="pb-2 font-medium text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              {(docket.events as any[]).map((ev) => (
                <tr key={ev.id} className="border-b border-border/50">
                  <td className="py-2 pr-3 font-mono text-muted-foreground whitespace-nowrap">{fmtDate(ev.event_date)}</td>
                  <td className="py-2 pr-3 font-medium">{docket.event_types?.[ev.event_type] ?? ev.event_type}</td>
                  <td className="py-2 pr-3 text-muted-foreground">{ev.creator?.name ?? "—"}</td>
                  <td className="py-2 text-muted-foreground italic max-w-[200px] truncate">{ev.notes ?? "—"}</td>
                  <td className="py-2 text-right whitespace-nowrap">{canManage ? <><button onClick={() => editEvent(ev)} className="text-blue-600 hover:underline">Edit</button><button onClick={() => deleteEvent(ev.id)} className="ml-2 text-red-600 hover:underline">Delete</button></> : <span className="text-muted-foreground">View only</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
