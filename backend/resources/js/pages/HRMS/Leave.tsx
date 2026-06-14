import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Calendar, CalendarDays, Check, X, Clock, Plus, Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";

const LEAVE_TYPES = ["Annual", "Sick", "Personal", "Emergency"];

const STATUS_STYLES: Record<string, string> = {
  Pending: "bg-amber-500/15 text-amber-400 border border-amber-500/30",
  Approved: "bg-emerald-500/15 text-emerald-400 border border-emerald-500/30",
  Rejected: "bg-red-500/15 text-red-400 border border-red-500/30",
};

// Maps display type → leave_balances column and yearly allocation
const BALANCE_CONFIG = [
  { type: "Annual", column: "earned_leave", total: 15, color: "bg-blue-500" },
  { type: "Sick", column: "sick_leave", total: 7, color: "bg-rose-500" },
  { type: "Personal", column: "casual_leave", total: 8, color: "bg-purple-500" },
];

function countWorkingDays(from: string, to: string): number {
  const a = new Date(from + "T00:00:00");
  const b = new Date(to + "T00:00:00");
  if (isNaN(a.getTime()) || isNaN(b.getTime()) || a > b) return 0;
  let count = 0;
  for (const d = new Date(a); d <= b; d.setDate(d.getDate() + 1)) {
    const dow = d.getDay();
    if (dow !== 0 && dow !== 6) count++;
  }
  return count;
}


export default function HRMSLeave() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role || "";
  const isApprover = ["super_admin", "hr", "manager", "partner"].includes(role);

  const [requests, setRequests] = useState<any[]>([]);
  const [balances, setBalances] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [activeTab, setActiveTab] = useState("All");
  const [showForm, setShowForm] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [form, setForm] = useState({ leave_type: "Annual", from_date: "", to_date: "", reason: "" });

  useEffect(() => {
    api.getLeaves()
      .then((data) => {
        setRequests(data.requests || []);
        setBalances(data.balances || null);
      })
      .catch(() => setError("Failed to load leave data."))
      .finally(() => setLoading(false));
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    setError("");
    try {
      const newLeave = await api.applyLeave(form);
      setRequests((prev) => [{ ...newLeave, is_mine: true, employee_name: props.auth?.user?.name }, ...prev]);
      setShowForm(false);
      setForm({ leave_type: "Annual", from_date: "", to_date: "", reason: "" });
    } catch (err: any) {
      setError(err.message || "Failed to submit leave request.");
    } finally {
      setSubmitting(false);
    }
  };

  const handleStatusChange = async (id: any, action: 'Approved' | 'Rejected') => {
    setError("");
    try {
      await api.resolveApproval('Leave', id, action);
      setRequests((prev) => prev.map((r) => (r.id === id ? { ...r, status: action } : r)));
    } catch (err: any) {
      setError(err.message || "Failed to update request.");
    }
  };

  const TABS = ["All", "Pending", "Approved", "Rejected", "My Leaves"];
  const filtered = requests.filter((r) => {
    if (activeTab === "All") return true;
    if (activeTab === "My Leaves") return r.is_mine;
    return r.status === activeTab;
  });

  const now = new Date();
  const daysInMonth = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
  const startDayOfWeek = new Date(now.getFullYear(), now.getMonth(), 1).getDay();
  const approvedDays = new Set<number>();
  requests.filter((r) => r.status === "Approved").forEach((r) => {
    const from = new Date(r.from_date);
    const to = new Date(r.to_date);
    for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
      if (d.getMonth() === now.getMonth() && d.getFullYear() === now.getFullYear()) {
        approvedDays.add(d.getDate());
      }
    }
  });

  return (
    <AppLayout>
      <Head title="Leave Management" />
      <PageHeader
        eyebrow="HRMS"
        title="Leave Management"
        description="Apply for leave, track balances, and manage approval requests"
        actions={
          <Button onClick={() => setShowForm((v) => !v)}>
            <Plus className="h-4 w-4 mr-2" />
            Apply Leave
          </Button>
        }
      />
      <div className="px-8 py-6 space-y-6">

        {error && (
          <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">
            {error}
          </div>
        )}

        {/* Leave Balance Cards */}
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
          {BALANCE_CONFIG.map((cfg) => {
            const remaining = balances ? Math.max(0, Number(balances[cfg.column] ?? 0)) : 0;
            const used = Math.max(0, cfg.total - remaining);
            const pct = Math.round((remaining / cfg.total) * 100);
            return (
              <Card key={cfg.type} className="border-border">
                <CardContent className="p-5">
                  <div className="flex items-center justify-between mb-3">
                    <span className="text-sm font-medium">{cfg.type} Leave</span>
                    <CalendarDays className="h-4 w-4 text-muted-foreground" />
                  </div>
                  <div className="text-3xl font-bold text-gold mb-0.5">{remaining}</div>
                  <div className="text-xs text-muted-foreground mb-3">of {cfg.total} days remaining</div>
                  <div className="h-1.5 bg-muted rounded-full overflow-hidden">
                    <div className={`h-full ${cfg.color} rounded-full`} style={{ width: `${pct}%` }} />
                  </div>
                  <div className="text-xs text-muted-foreground mt-1.5">{used} day{used !== 1 ? "s" : ""} used</div>
                </CardContent>
              </Card>
            );
          })}
        </div>

        {/* Apply Leave Form */}
        {showForm && (
          <Card className="border-border ring-1 ring-gold/20">
            <CardHeader className="pb-3">
              <CardTitle className="text-base flex items-center gap-2">
                <Calendar className="h-4 w-4 text-gold" />
                Apply for Leave
              </CardTitle>
            </CardHeader>
            <CardContent>
              <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <label className="text-xs text-muted-foreground font-semibold uppercase tracking-wide">Leave Type</label>
                  <select
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    value={form.leave_type}
                    onChange={(e) => setForm((f) => ({ ...f, leave_type: e.target.value }))}
                    required
                  >
                    {LEAVE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                </div>
                <div />
                <div className="space-y-1.5">
                  <label className="text-xs text-muted-foreground font-semibold uppercase tracking-wide">From Date</label>
                  <input
                    type="date"
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    value={form.from_date}
                    onChange={(e) => setForm((f) => ({ ...f, from_date: e.target.value }))}
                    required
                  />
                </div>
                <div className="space-y-1.5">
                  <label className="text-xs text-muted-foreground font-semibold uppercase tracking-wide">To Date</label>
                  <input
                    type="date"
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    value={form.to_date}
                    onChange={(e) => setForm((f) => ({ ...f, to_date: e.target.value }))}
                    required
                  />
                </div>
                <div className="md:col-span-2 space-y-1.5">
                  <label className="text-xs text-muted-foreground font-semibold uppercase tracking-wide">Reason</label>
                  <textarea
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none"
                    rows={3}
                    value={form.reason}
                    onChange={(e) => setForm((f) => ({ ...f, reason: e.target.value }))}
                    placeholder="Brief reason for your leave request..."
                    required
                  />
                </div>
                {form.from_date && form.to_date && (
                  <div className="md:col-span-2 text-xs text-muted-foreground">
                    Duration: <span className="text-gold font-semibold">{countWorkingDays(form.from_date, form.to_date)} working day(s)</span>
                    <span className="ml-1 text-muted-foreground/60">(weekends excluded)</span>
                  </div>
                )}
                <div className="md:col-span-2 flex gap-2 justify-end pt-1">
                  <Button type="button" variant="outline" onClick={() => setShowForm(false)}>Cancel</Button>
                  <Button type="submit" disabled={submitting}>
                    {submitting && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
                    Submit Request
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        )}

        {/* Calendar Mini-View */}
        <Card className="border-border">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-medium flex items-center gap-2">
              <Calendar className="h-4 w-4 text-gold" />
              {now.toLocaleString("default", { month: "long", year: "numeric" })} — Leave Calendar
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-7 gap-1 text-center text-xs mb-1">
              {["Su","Mo","Tu","We","Th","Fr","Sa"].map((d, idx) => (
                <div key={d} className={`font-semibold py-1 ${idx === 0 || idx === 6 ? "text-muted-foreground/40" : "text-muted-foreground"}`}>{d}</div>
              ))}
            </div>
            <div className="grid grid-cols-7 gap-1 text-center text-xs">
              {Array.from({ length: startDayOfWeek }).map((_, i) => <div key={`pad-${i}`} />)}
              {Array.from({ length: daysInMonth }).map((_, i) => {
                const day = i + 1;
                const colIdx = (startDayOfWeek + i) % 7;
                const isWeekend = colIdx === 0 || colIdx === 6;
                const isLeave = approvedDays.has(day);
                const isToday = day === now.getDate();
                return (
                  <div
                    key={day}
                    className={`rounded py-1.5 font-mono cursor-default select-none ${
                      isLeave
                        ? "bg-amber-500/20 text-amber-400 font-semibold"
                        : isToday
                        ? "bg-gold/20 text-gold font-semibold ring-1 ring-gold/40"
                        : isWeekend
                        ? "text-muted-foreground/30 bg-muted/20"
                        : "text-muted-foreground hover:bg-muted/40"
                    }`}
                  >
                    {day}
                  </div>
                );
              })}
            </div>
            <div className="flex gap-5 mt-3 text-xs text-muted-foreground">
              <span className="flex items-center gap-1.5">
                <span className="inline-block w-3 h-3 rounded bg-amber-500/20 border border-amber-500/40" />
                Approved Leave
              </span>
              <span className="flex items-center gap-1.5">
                <span className="inline-block w-3 h-3 rounded bg-gold/20 border border-gold/40" />
                Today
              </span>
              <span className="flex items-center gap-1.5">
                <span className="inline-block w-3 h-3 rounded bg-muted/20 border border-border" />
                Weekend (not counted)
              </span>
            </div>
          </CardContent>
        </Card>

        {/* Filter Tabs */}
        <div className="flex gap-0 border-b border-border">
          {TABS.map((tab) => {
            const count = tab !== "All" && tab !== "My Leaves" ? requests.filter((r) => r.status === tab).length : null;
            return (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={`px-4 py-2.5 text-sm font-medium transition-colors border-b-2 -mb-px ${
                  activeTab === tab
                    ? "border-gold text-gold"
                    : "border-transparent text-muted-foreground hover:text-foreground"
                }`}
              >
                {tab}
                {count !== null && (
                  <span className="ml-1.5 text-xs bg-muted text-muted-foreground px-1.5 py-0.5 rounded-full">{count}</span>
                )}
              </button>
            );
          })}
        </div>

        {/* Requests Table */}
        {loading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
          </div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Employee</th>
                    <th className="px-4 py-3 text-left">Type</th>
                    <th className="px-4 py-3 text-left">From</th>
                    <th className="px-4 py-3 text-left">To</th>
                    <th className="px-4 py-3 text-center">Days</th>
                    <th className="px-4 py-3 text-left">Reason</th>
                    <th className="px-4 py-3 text-left">Status</th>
                    <th className="px-4 py-3 text-left">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-14 text-center text-muted-foreground">
                        No leave requests found for this filter.
                      </td>
                    </tr>
                  ) : (
                    filtered.map((r) => (
                      <tr key={r.id} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <div className="h-7 w-7 rounded-full bg-muted flex items-center justify-center text-xs font-bold text-gold shrink-0">
                              {(r.employee_name || "U").charAt(0).toUpperCase()}
                            </div>
                            <span className="font-medium whitespace-nowrap">{r.employee_name || "—"}</span>
                          </div>
                        </td>
                        <td className="px-4 py-3">
                          <span className="text-xs px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-400 border border-blue-500/20 whitespace-nowrap">
                            {r.leave_type}
                          </span>
                        </td>
                        <td className="px-4 py-3 font-mono text-xs whitespace-nowrap">{fmtDate(r.from_date)}</td>
                        <td className="px-4 py-3 font-mono text-xs whitespace-nowrap">{fmtDate(r.to_date)}</td>
                        <td className="px-4 py-3 text-center font-semibold">
                          {r.total_days ?? "—"}
                        </td>
                        <td className="px-4 py-3 text-muted-foreground max-w-[160px] truncate" title={r.reason}>
                          {r.reason || "—"}
                        </td>
                        <td className="px-4 py-3">
                          <span className={`inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium ${STATUS_STYLES[r.status] || "bg-muted text-muted-foreground"}`}>
                            {r.status === "Pending" && <Clock className="h-3 w-3" />}
                            {r.status === "Approved" && <Check className="h-3 w-3" />}
                            {r.status === "Rejected" && <X className="h-3 w-3" />}
                            {r.status}
                          </span>
                        </td>
                        <td className="px-4 py-3">
                          {r.status === "Pending" && isApprover && !r.is_mine ? (
                            <div className="flex gap-1">
                              <Button
                                size="sm"
                                variant="ghost"
                                className="h-7 px-2 text-emerald-400 hover:bg-emerald-500/10 hover:text-emerald-400"
                                onClick={() => handleStatusChange(r.id, "Approved")}
                                title="Approve"
                              >
                                <Check className="h-3.5 w-3.5" />
                              </Button>
                              <Button
                                size="sm"
                                variant="ghost"
                                className="h-7 px-2 text-red-400 hover:bg-red-500/10 hover:text-red-400"
                                onClick={() => handleStatusChange(r.id, "Rejected")}
                                title="Reject"
                              >
                                <X className="h-3.5 w-3.5" />
                              </Button>
                            </div>
                          ) : (
                            <span className="text-xs text-muted-foreground">—</span>
                          )}
                        </td>
                      </tr>
                    ))
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
