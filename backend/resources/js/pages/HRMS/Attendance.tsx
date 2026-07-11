import { Head, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import {
  Loader2, Clock, LogOut, LogIn, CheckCircle2, Users, Settings,
  FileBarChart2, Trash2, Pencil, Plus, Download, Printer, Search, RefreshCw,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";

// ── Types ─────────────────────────────────────────────────────────────────────
interface Session { in: string; out: string | null; duration_minutes: number | null }
interface AttendanceLog {
  id: number; attendance_date: string; status: string; duration_minutes: number;
  sessions: Session[]; session_count: number; has_open_session: boolean;
  can_clock_in: boolean; can_clock_out: boolean; is_today: boolean;
}
interface AdminRecord {
  id: number; employee_id: number; employee_name: string; employee_code: string;
  attendance_date: string; check_in: string | null; check_out: string | null;
  status: string; duration_minutes: number; sessions: Session[]; session_count: number;
  capture_method: string; regularized: boolean;
}
interface DailyRow {
  date: string; day: string; weekend: boolean; status: string;
  check_in: string | null; check_out: string | null; duration_minutes: number;
  sessions_count: number; late: boolean; overtime: boolean; no_lunch: boolean;
  out_of_hours: boolean;
}
interface EmpReport {
  employee_id: number; employee_code: string; name: string;
  working_days: number; present_days: number; absent_days: number;
  late_days: number; overtime_days: number; no_lunch_days: number;
  out_of_hours_days: number; total_hours: number; daily_rows: DailyRow[];
}
interface ReportData {
  month: number; year: number; month_label: string; working_days: number;
  settings: { work_start_time: string; work_end_time: string; lunch_start: string; lunch_end: string; standard_hours: number };
  employees: EmpReport[];
}
interface AttSettings {
  max_sessions_per_day: number; work_start_time: string; work_end_time: string;
  lunch_start: string; lunch_end: string; standard_hours_minutes: number;
}
interface Employee { id: number; employee_code: string; user?: { name: string } }

// ── Helpers ───────────────────────────────────────────────────────────────────
// Times from DB are already in IST (HH:MM:SS). Parse directly — no timezone conversion.
function fmtTimeIST(t: string | null | undefined): string {
  if (!t) return "—";
  const parts = t.split(":");
  if (parts.length < 2) return t;
  let h = parseInt(parts[0], 10);
  const m = parts[1].padStart(2, "0");
  if (isNaN(h)) return t;
  const ampm = h >= 12 ? "PM" : "AM";
  h = h % 12 || 12;
  return `${h}:${m} ${ampm}`;
}
function fmtDate(d: string): string {
  const [y, m, day] = d.split("-");
  return `${day}/${m}/${y}`;
}
function fmtDuration(minutes: number): string {
  if (!minutes) return "0h 0m";
  return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}
function toHM(hms: string): string { return hms ? hms.substring(0, 5) : "" }
const MONTHS = ["January","February","March","April","May","June","July","August","September","October","November","December"];
const STATUSES = ["Present","Absent","Half Day","On Leave","LOP","Weekend","Holiday"];
const HR_ADMIN_ROLES = ["super_admin","partner","hr","finance"];

// ── Main ──────────────────────────────────────────────────────────────────────
export default function HRMSAttendance() {
  const { props } = usePage() as any;
  const userRole: string = props.auth?.user?.role ?? "";
  const isHrAdmin = HR_ADMIN_ROLES.includes(userRole);

  const [tab, setTab] = useState<"mine" | "staff" | "settings" | "report">("mine");

  return (
    <AppLayout>
      <Head title="Attendance" />
      <PageHeader eyebrow="HRMS" title="Attendance" description="Track work hours and manage attendance." />
      <div className="px-8 py-4">
        {/* Tabs */}
        <div className="flex gap-1 border-b border-border mb-6">
          {([
            { key: "mine",     label: "My Attendance", icon: Clock },
            ...(isHrAdmin ? [
              { key: "staff",    label: "All Staff",     icon: Users },
              { key: "settings", label: "Settings",      icon: Settings },
              { key: "report",   label: "Report",        icon: FileBarChart2 },
            ] : []),
          ] as { key: typeof tab; label: string; icon: React.ElementType }[]).map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={`flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors ${
                tab === key
                  ? "border-gold text-gold"
                  : "border-transparent text-muted-foreground hover:text-foreground"
              }`}
            >
              <Icon className="h-3.5 w-3.5" />{label}
            </button>
          ))}
        </div>

        {tab === "mine"     && <MyAttendanceTab />}
        {tab === "staff"    && isHrAdmin && <StaffTab />}
        {tab === "settings" && isHrAdmin && <SettingsTab />}
        {tab === "report"   && isHrAdmin && <ReportTab />}
      </div>
    </AppLayout>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// My Attendance Tab
// ══════════════════════════════════════════════════════════════════════════════
function MyAttendanceTab() {
  const [logs, setLogs] = useState<AttendanceLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [clocking, setClocking] = useState(false);
  const [clockError, setClockError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");
  const [maxSessions, setMaxSessions] = useState(6);

  const load = () => api.getAttendance().then((data) => {
    setLogs(data as unknown as AttendanceLog[]);
  });

  useEffect(() => {
    Promise.all([
      load(),
      api.getAttendanceSettings().catch(() => null),
    ]).then(([, s]) => {
      if (s) setMaxSessions(s.max_sessions_per_day);
    }).finally(() => setLoading(false));
  }, []);

  const todayLog = logs.find((l) => l.is_today) ?? null;
  const canClockIn = todayLog ? todayLog.can_clock_in : true;
  const canClockOut = todayLog ? todayLog.can_clock_out : false;
  const sessionCount = todayLog?.session_count ?? 0;
  const maxReached = sessionCount >= maxSessions && !canClockOut;

  async function handleClock(type: "in" | "out") {
    setClocking(true); setClockError(""); setSuccessMsg("");
    try {
      if (type === "in") await api.clockIn(); else await api.clockOut();
      await load();
      setSuccessMsg(type === "in" ? "Clocked in!" : "Clocked out!");
      setTimeout(() => setSuccessMsg(""), 3000);
    } catch (e: any) {
      setClockError(e.message || "Failed.");
    } finally { setClocking(false); }
  }

  return (
    <div className="space-y-4">
      {/* Clock Controls */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          {todayLog && (
            <span className="text-xs text-muted-foreground">Today: {sessionCount}/{maxSessions} sessions</span>
          )}
          {canClockIn && !canClockOut && (
            <Button onClick={() => handleClock("in")} disabled={clocking} className="bg-green-600 hover:bg-green-700 text-white">
              {clocking ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <LogIn className="h-4 w-4 mr-2" />}Clock In
            </Button>
          )}
          {canClockOut && (
            <Button onClick={() => handleClock("out")} disabled={clocking} className="bg-red-600 hover:bg-red-700 text-white">
              {clocking ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <LogOut className="h-4 w-4 mr-2" />}Clock Out
            </Button>
          )}
          {maxReached && (
            <Badge variant="outline" className="text-xs px-3 py-1.5 border-gold text-gold">
              <CheckCircle2 className="h-3.5 w-3.5 mr-1.5" />Max sessions reached ({maxSessions}/{maxSessions})
            </Badge>
          )}
        </div>
        <div className="text-right">
          {successMsg && <p className="text-xs text-green-500">{successMsg}</p>}
          {clockError && <p className="text-xs text-red-500 max-w-72 text-right">{clockError}</p>}
        </div>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
      ) : (
        <Card className="border-border">
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left w-32">Date</th>
                  <th className="px-4 py-3 text-left">#</th>
                  <th className="px-4 py-3 text-left">Clock In</th>
                  <th className="px-4 py-3 text-left">Clock Out</th>
                  <th className="px-4 py-3 text-left">Session</th>
                  <th className="px-4 py-3 text-left">Total</th>
                  <th className="px-4 py-3 text-left">Status</th>
                </tr>
              </thead>
              <tbody>
                {logs.map((log) => {
                  const sessions = log.sessions ?? [];
                  if (sessions.length === 0) {
                    return (
                      <tr key={log.id} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3 font-mono text-xs">{fmtDate(log.attendance_date)}</td>
                        <td colSpan={4} className="px-4 py-3 text-muted-foreground text-xs">No sessions</td>
                        <td className="px-4 py-3 text-muted-foreground">—</td>
                        <td className="px-4 py-3"><Badge variant="secondary">{log.status}</Badge></td>
                      </tr>
                    );
                  }
                  return sessions.map((sess, idx) => {
                    const isFirst = idx === 0;
                    const isOpen = sess.out === null;
                    return (
                      <tr key={`${log.id}-${idx}`} className={[
                        "border-t border-border hover:bg-muted/30",
                        isOpen ? "bg-green-500/5" : "",
                        log.is_today && isFirst ? "ring-1 ring-inset ring-gold/30" : "",
                      ].join(" ")}>
                        <td className="px-4 py-2.5 font-mono text-xs">
                          {isFirst ? <span className="font-semibold text-foreground">{fmtDate(log.attendance_date)}</span> : <span className="text-muted-foreground/40">↓</span>}
                        </td>
                        <td className="px-4 py-2.5">
                          <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-muted text-[10px] font-semibold text-muted-foreground">{idx + 1}</span>
                        </td>
                        <td className="px-4 py-2.5 font-mono text-xs">{fmtTimeIST(sess.in)}</td>
                        <td className="px-4 py-2.5 font-mono text-xs">
                          {isOpen
                            ? <span className="flex items-center gap-1.5 text-green-600 font-medium"><span className="h-1.5 w-1.5 rounded-full bg-green-500 animate-pulse" />Active</span>
                            : <span>{fmtTimeIST(sess.out)}</span>}
                        </td>
                        <td className="px-4 py-2.5 text-xs text-muted-foreground">
                          {isOpen ? "—" : (sess.duration_minutes != null ? fmtDuration(sess.duration_minutes) : "—")}
                        </td>
                        <td className="px-4 py-2.5 text-xs font-medium">
                          {isFirst ? (log.duration_minutes > 0 ? <span className="text-gold">{fmtDuration(log.duration_minutes)}</span> : <span className="text-muted-foreground">—</span>) : ""}
                        </td>
                        <td className="px-4 py-2.5">{isFirst ? <Badge variant="secondary">{log.status}</Badge> : ""}</td>
                      </tr>
                    );
                  });
                })}
                {logs.length === 0 && (
                  <tr><td colSpan={7} className="px-4 py-12 text-center text-muted-foreground text-sm">No attendance records yet.</td></tr>
                )}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// All Staff Tab (Admin CRUD)
// ══════════════════════════════════════════════════════════════════════════════
function StaffTab() {
  const [records, setRecords] = useState<AdminRecord[]>([]);
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [loading, setLoading] = useState(true);
  const [filterEmp, setFilterEmp] = useState("");
  const [filterMonth, setFilterMonth] = useState(new Date().getMonth() + 1);
  const [filterYear, setFilterYear] = useState(new Date().getFullYear());
  const [filterStatus, setFilterStatus] = useState("");
  const [search, setSearch] = useState("");

  // Reset today
  const [resetting, setResetting] = useState<number | null>(null);
  async function handleResetToday(empId: number, empName: string) {
    if (!confirm(`Reset today's sessions for ${empName}? They'll be able to clock in again.`)) return;
    setResetting(empId);
    try {
      await api.resetEmployeeToday(empId);
      await load();
    } catch (e: any) { alert(e.message || "Failed."); }
    finally { setResetting(null); }
  }

  // Edit / Create modal
  const [modal, setModal] = useState<{ open: boolean; mode: "create" | "edit"; rec?: AdminRecord }>({ open: false, mode: "create" });
  const [form, setForm] = useState<{ employee_id: string; attendance_date: string; check_in: string; check_out: string; status: string; regularization_reason: string }>({
    employee_id: "", attendance_date: "", check_in: "", check_out: "", status: "Present", regularization_reason: "",
  });
  const [saving, setSaving] = useState(false);
  const [saveErr, setSaveErr] = useState("");

  const load = async () => {
    const params = new URLSearchParams();
    if (filterEmp) params.set("employee_id", filterEmp);
    params.set("month", String(filterMonth));
    params.set("year", String(filterYear));
    if (filterStatus) params.set("status", filterStatus);
    const res = await api.getAdminAttendance(params) as any;
    setRecords(Array.isArray(res) ? res : res.data ?? []);
  };

  useEffect(() => {
    Promise.all([
      load(),
      fetch("/api/hrms/employees", { credentials: "same-origin", headers: { Accept: "application/json" } }).then(r => r.json()),
    ]).then(([, empRes]) => {
      setEmployees((empRes as any)?.data ?? empRes ?? []);
    }).finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [filterEmp, filterMonth, filterYear, filterStatus]);

  const filtered = records.filter(r => {
    if (!search) return true;
    const q = search.toLowerCase();
    return r.employee_name.toLowerCase().includes(q) || r.employee_code.toLowerCase().includes(q);
  });

  function openCreate() {
    setForm({ employee_id: "", attendance_date: "", check_in: "", check_out: "", status: "Present", regularization_reason: "" });
    setSaveErr("");
    setModal({ open: true, mode: "create" });
  }
  function openEdit(r: AdminRecord) {
    setForm({
      employee_id: String(r.employee_id),
      attendance_date: r.attendance_date,
      check_in: r.check_in ? toHM(r.check_in) : "",
      check_out: r.check_out ? toHM(r.check_out) : "",
      status: r.status,
      regularization_reason: "",
    });
    setSaveErr("");
    setModal({ open: true, mode: "edit", rec: r });
  }
  async function handleSave() {
    setSaving(true); setSaveErr("");
    try {
      if (modal.mode === "create") {
        await api.createAdminAttendance(form);
      } else {
        await api.updateAdminAttendance(modal.rec!.id, form);
      }
      setModal({ open: false, mode: "create" });
      await load();
    } catch (e: any) { setSaveErr(e.message || "Failed."); }
    finally { setSaving(false); }
  }
  async function handleDelete(id: number) {
    if (!confirm("Delete this attendance record?")) return;
    await api.deleteAdminAttendance(id);
    await load();
  }

  const statusColor: Record<string, string> = {
    Present: "bg-green-500/10 text-green-600",
    Absent: "bg-red-500/10 text-red-600",
    "Half Day": "bg-yellow-500/10 text-yellow-600",
    "On Leave": "bg-blue-500/10 text-blue-600",
    LOP: "bg-orange-500/10 text-orange-600",
    Weekend: "bg-muted text-muted-foreground",
    Holiday: "bg-purple-500/10 text-purple-600",
  };

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3">
        <select value={filterEmp} onChange={e => setFilterEmp(e.target.value)}
          className="h-8 rounded border border-border bg-background px-2 text-sm text-foreground">
          <option value="">All Employees</option>
          {employees.map((e: any) => (
            <option key={e.id} value={e.id}>{e.user?.name ?? e.full_name} ({e.employee_code})</option>
          ))}
        </select>
        <select value={filterMonth} onChange={e => setFilterMonth(Number(e.target.value))}
          className="h-8 rounded border border-border bg-background px-2 text-sm text-foreground">
          {MONTHS.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
        </select>
        <select value={filterYear} onChange={e => setFilterYear(Number(e.target.value))}
          className="h-8 rounded border border-border bg-background px-2 text-sm text-foreground">
          {[2024, 2025, 2026, 2027].map(y => <option key={y} value={y}>{y}</option>)}
        </select>
        <select value={filterStatus} onChange={e => setFilterStatus(e.target.value)}
          className="h-8 rounded border border-border bg-background px-2 text-sm text-foreground">
          <option value="">All Status</option>
          {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <div className="relative ml-auto">
          <Search className="absolute left-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search employee…"
            className="h-8 pl-7 pr-3 rounded border border-border bg-background text-sm text-foreground placeholder:text-muted-foreground" />
        </div>
        <Button size="sm" onClick={openCreate} className="h-8">
          <Plus className="h-3.5 w-3.5 mr-1" />Add Record
        </Button>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
      ) : (
        <Card className="border-border">
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left">Employee</th>
                  <th className="px-4 py-3 text-left">Date</th>
                  <th className="px-4 py-3 text-left">Clock In</th>
                  <th className="px-4 py-3 text-left">Clock Out</th>
                  <th className="px-4 py-3 text-left">Duration</th>
                  <th className="px-4 py-3 text-left">Sessions</th>
                  <th className="px-4 py-3 text-left">Status</th>
                  <th className="px-4 py-3 text-left">Method</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map(r => (
                  <tr key={r.id} className="border-t border-border hover:bg-muted/30">
                    <td className="px-4 py-2.5">
                      <div className="font-medium text-foreground text-xs">{r.employee_name}</div>
                      <div className="text-[10px] text-muted-foreground font-mono">{r.employee_code}</div>
                    </td>
                    <td className="px-4 py-2.5 font-mono text-xs">{fmtDate(r.attendance_date)}</td>
                    <td className="px-4 py-2.5 font-mono text-xs">{fmtTimeIST(r.check_in)}</td>
                    <td className="px-4 py-2.5 font-mono text-xs">{fmtTimeIST(r.check_out)}</td>
                    <td className="px-4 py-2.5 text-xs text-muted-foreground">{r.duration_minutes ? fmtDuration(r.duration_minutes) : "—"}</td>
                    <td className="px-4 py-2.5 text-xs text-center">{r.session_count || "—"}</td>
                    <td className="px-4 py-2.5">
                      <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium ${statusColor[r.status] ?? "bg-muted text-muted-foreground"}`}>{r.status}</span>
                    </td>
                    <td className="px-4 py-2.5 text-[10px] text-muted-foreground">{r.regularized ? "✎ Admin" : r.capture_method}</td>
                    <td className="px-4 py-2.5 text-right">
                      <div className="flex items-center justify-end gap-1">
                        <button onClick={() => openEdit(r)} className="p-1 rounded hover:bg-muted text-muted-foreground hover:text-foreground" title="Edit"><Pencil className="h-3.5 w-3.5" /></button>
                        <button
                          onClick={() => handleResetToday(r.employee_id, r.employee_name)}
                          disabled={resetting === r.employee_id}
                          className="p-1 rounded hover:bg-blue-500/10 text-muted-foreground hover:text-blue-500 disabled:opacity-40"
                          title="Reset today's sessions for this employee"
                        >
                          {resetting === r.employee_id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                        </button>
                        <button onClick={() => handleDelete(r.id)} className="p-1 rounded hover:bg-red-500/10 text-muted-foreground hover:text-red-500" title="Delete"><Trash2 className="h-3.5 w-3.5" /></button>
                      </div>
                    </td>
                  </tr>
                ))}
                {filtered.length === 0 && (
                  <tr><td colSpan={9} className="px-4 py-12 text-center text-muted-foreground text-sm">No records found.</td></tr>
                )}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}

      {/* Modal */}
      {modal.open && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60">
          <div className="bg-card border border-border rounded-xl shadow-2xl w-full max-w-md p-6 space-y-4">
            <h2 className="text-base font-semibold">{modal.mode === "create" ? "Add Attendance Record" : "Edit Attendance Record"}</h2>
            <div className="grid grid-cols-2 gap-3">
              <div className="col-span-2">
                <label className="text-xs text-muted-foreground">Employee</label>
                <select value={form.employee_id} onChange={e => setForm(f => ({ ...f, employee_id: e.target.value }))} disabled={modal.mode === "edit"}
                  className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground">
                  <option value="">— Select —</option>
                  {employees.map((e: any) => <option key={e.id} value={e.id}>{e.user?.name ?? e.full_name} ({e.employee_code})</option>)}
                </select>
              </div>
              <div>
                <label className="text-xs text-muted-foreground">Date</label>
                <input type="date" value={form.attendance_date} onChange={e => setForm(f => ({ ...f, attendance_date: e.target.value }))}
                  className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
              </div>
              <div>
                <label className="text-xs text-muted-foreground">Status</label>
                <select value={form.status} onChange={e => setForm(f => ({ ...f, status: e.target.value }))}
                  className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground">
                  {STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
                </select>
              </div>
              <div>
                <label className="text-xs text-muted-foreground">Clock In</label>
                <input type="time" value={form.check_in} onChange={e => setForm(f => ({ ...f, check_in: e.target.value }))}
                  className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
              </div>
              <div>
                <label className="text-xs text-muted-foreground">Clock Out</label>
                <input type="time" value={form.check_out} onChange={e => setForm(f => ({ ...f, check_out: e.target.value }))}
                  className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
              </div>
              <div className="col-span-2">
                <label className="text-xs text-muted-foreground">Reason / Note</label>
                <input value={form.regularization_reason} onChange={e => setForm(f => ({ ...f, regularization_reason: e.target.value }))}
                  placeholder="Regularization reason…"
                  className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
              </div>
            </div>
            {saveErr && <p className="text-xs text-red-500">{saveErr}</p>}
            <div className="flex justify-end gap-2">
              <Button variant="outline" size="sm" onClick={() => setModal({ open: false, mode: "create" })}>Cancel</Button>
              <Button size="sm" onClick={handleSave} disabled={saving}>
                {saving && <Loader2 className="h-3.5 w-3.5 mr-1 animate-spin" />}Save
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Settings Tab
// ══════════════════════════════════════════════════════════════════════════════
function SettingsTab() {
  const [settings, setSettings] = useState<AttSettings | null>(null);
  const [form, setForm] = useState<{ max_sessions_per_day: string; work_start_time: string; work_end_time: string; lunch_start: string; lunch_end: string; standard_hours_minutes: string }>({
    max_sessions_per_day: "6", work_start_time: "09:30", work_end_time: "18:00",
    lunch_start: "13:00", lunch_end: "14:30", standard_hours_minutes: "480",
  });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState("");
  const [err, setErr] = useState("");

  useEffect(() => {
    api.getAttendanceSettings().then((s) => {
      setSettings(s);
      setForm({
        max_sessions_per_day: String(s.max_sessions_per_day),
        work_start_time: toHM(s.work_start_time),
        work_end_time: toHM(s.work_end_time),
        lunch_start: toHM(s.lunch_start),
        lunch_end: toHM(s.lunch_end),
        standard_hours_minutes: String(s.standard_hours_minutes),
      });
    }).finally(() => setLoading(false));
  }, []);

  async function handleSave() {
    setSaving(true); setMsg(""); setErr("");
    try {
      await api.updateAttendanceSettings({
        max_sessions_per_day: parseInt(form.max_sessions_per_day),
        work_start_time: form.work_start_time,
        work_end_time: form.work_end_time,
        lunch_start: form.lunch_start,
        lunch_end: form.lunch_end,
        standard_hours_minutes: parseInt(form.standard_hours_minutes),
      });
      setMsg("Settings saved.");
      setTimeout(() => setMsg(""), 3000);
    } catch (e: any) { setErr(e.message || "Failed."); }
    finally { setSaving(false); }
  }

  if (loading) return <div className="flex justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>;

  return (
    <div className="max-w-lg space-y-6">
      <Card className="border-border">
        <CardContent className="p-6 space-y-5">
          <h3 className="text-sm font-semibold text-foreground">Work Schedule</h3>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="text-xs text-muted-foreground">Work Start Time</label>
              <input type="time" value={form.work_start_time} onChange={e => setForm(f => ({ ...f, work_start_time: e.target.value }))}
                className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
            </div>
            <div>
              <label className="text-xs text-muted-foreground">Work End Time</label>
              <input type="time" value={form.work_end_time} onChange={e => setForm(f => ({ ...f, work_end_time: e.target.value }))}
                className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
            </div>
            <div>
              <label className="text-xs text-muted-foreground">Lunch Start</label>
              <input type="time" value={form.lunch_start} onChange={e => setForm(f => ({ ...f, lunch_start: e.target.value }))}
                className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
            </div>
            <div>
              <label className="text-xs text-muted-foreground">Lunch End</label>
              <input type="time" value={form.lunch_end} onChange={e => setForm(f => ({ ...f, lunch_end: e.target.value }))}
                className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
            </div>
            <div>
              <label className="text-xs text-muted-foreground">Standard Hours (minutes)</label>
              <input type="number" min="60" max="720" value={form.standard_hours_minutes}
                onChange={e => setForm(f => ({ ...f, standard_hours_minutes: e.target.value }))}
                className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
              <p className="text-[10px] text-muted-foreground mt-0.5">{Math.floor(parseInt(form.standard_hours_minutes || "0") / 60)}h {parseInt(form.standard_hours_minutes || "0") % 60}m per day</p>
            </div>
            <div>
              <label className="text-xs text-muted-foreground">Max Sessions Per Day</label>
              <input type="number" min="1" max="20" value={form.max_sessions_per_day}
                onChange={e => setForm(f => ({ ...f, max_sessions_per_day: e.target.value }))}
                className="mt-1 w-full h-9 rounded border border-border bg-background px-2 text-sm text-foreground" />
              <p className="text-[10px] text-muted-foreground mt-0.5">Max clock-in/out pairs per day</p>
            </div>
          </div>
          <div className="flex items-center justify-between pt-2">
            <div>
              {msg && <p className="text-xs text-green-500">{msg}</p>}
              {err && <p className="text-xs text-red-500">{err}</p>}
            </div>
            <Button size="sm" onClick={handleSave} disabled={saving}>
              {saving && <Loader2 className="h-3.5 w-3.5 mr-1 animate-spin" />}Save Settings
            </Button>
          </div>
        </CardContent>
      </Card>

      <div className="text-xs text-muted-foreground space-y-1 px-1">
        <p>• <strong>Late Check-in</strong>: First clock-in after Work Start Time.</p>
        <p>• <strong>Overtime</strong>: Last clock-out after Work End Time.</p>
        <p>• <strong>No Lunch Break</strong>: Employee worked through the lunch window without clocking out.</p>
        <p>• <strong>Out of Hours</strong>: Any work ending after Work End Time or starting before 6:00 AM.</p>
      </div>
    </div>
  );
}

// ══════════════════════════════════════════════════════════════════════════════
// Report Tab
// ══════════════════════════════════════════════════════════════════════════════
function ReportTab() {
  const [month, setMonth] = useState(new Date().getMonth() + 1);
  const [year, setYear] = useState(new Date().getFullYear());
  const [data, setData] = useState<ReportData | null>(null);
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState<number | null>(null);
  const printRef = useRef<HTMLDivElement>(null);

  async function loadReport() {
    setLoading(true);
    setExpanded(null);
    try {
      const res = await api.getAttendanceReport(month, year) as ReportData;
      setData(res);
    } catch (e: any) {
      console.error(e);
    } finally { setLoading(false); }
  }

  function exportCSV() {
    if (!data) return;

    const rows: string[][] = [];
    // Summary sheet rows
    rows.push([`Attendance Report — ${data.month_label}`]);
    rows.push([`Working Days (Mon–Fri): ${data.working_days}`]);
    rows.push([`Work Hours: ${data.settings.work_start_time} – ${data.settings.work_end_time} (Standard ${data.settings.standard_hours}h)`]);
    rows.push([`Lunch: ${data.settings.lunch_start} – ${data.settings.lunch_end}`]);
    rows.push([]);
    rows.push(["Employee", "Code", "Working Days", "Present", "Absent", "Late Check-in", "Overtime", "No Lunch Break", "Out of Hours", "Total Hours"]);
    data.employees.forEach(e => {
      rows.push([e.name, e.employee_code, String(e.working_days), String(e.present_days), String(e.absent_days), String(e.late_days), String(e.overtime_days), String(e.no_lunch_days), String(e.out_of_hours_days), String(e.total_hours)]);
    });

    // Daily detail rows
    data.employees.forEach(e => {
      rows.push([]);
      rows.push([`--- ${e.name} (${e.employee_code}) Daily Log ---`]);
      rows.push(["Date", "Day", "Status", "Clock In", "Clock Out", "Duration", "Sessions", "Late", "Overtime", "No Lunch", "Out of Hours"]);
      e.daily_rows.forEach(d => {
        rows.push([
          d.date, d.day, d.status,
          d.check_in ? toHM(d.check_in) : "—",
          d.check_out ? toHM(d.check_out) : "—",
          d.duration_minutes ? fmtDuration(d.duration_minutes) : "—",
          d.weekend ? "—" : String(d.sessions_count || 0),
          d.late ? "Yes" : "",
          d.overtime ? "Yes" : "",
          d.no_lunch ? "Yes" : "",
          d.out_of_hours ? "Yes" : "",
        ]);
      });
    });

    const csv = rows.map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(",")).join("\n");
    const blob = new Blob(["﻿" + csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url; a.download = `Attendance_${data.month_label.replace(" ", "_")}.csv`;
    a.click(); URL.revokeObjectURL(url);
  }

  function handlePrint() { window.print(); }

  const flagBadge = (val: boolean, label: string) =>
    val ? <span className="inline-flex items-center rounded-sm bg-red-500/10 text-red-500 text-[9px] px-1 py-0.5 font-medium">{label}</span> : null;

  return (
    <div className="space-y-4">
      {/* Controls */}
      <div className="flex flex-wrap items-center gap-3">
        <select value={month} onChange={e => setMonth(Number(e.target.value))}
          className="h-8 rounded border border-border bg-background px-2 text-sm text-foreground">
          {MONTHS.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
        </select>
        <select value={year} onChange={e => setYear(Number(e.target.value))}
          className="h-8 rounded border border-border bg-background px-2 text-sm text-foreground">
          {[2024, 2025, 2026, 2027].map(y => <option key={y} value={y}>{y}</option>)}
        </select>
        <Button size="sm" onClick={loadReport} disabled={loading} className="h-8">
          {loading && <Loader2 className="h-3.5 w-3.5 mr-1 animate-spin" />}Generate Report
        </Button>
        {data && (
          <>
            <Button size="sm" variant="outline" onClick={exportCSV} className="h-8 ml-auto">
              <Download className="h-3.5 w-3.5 mr-1" />Export CSV (Google Sheets)
            </Button>
            <Button size="sm" variant="outline" onClick={handlePrint} className="h-8">
              <Printer className="h-3.5 w-3.5 mr-1" />Print / PDF
            </Button>
          </>
        )}
      </div>

      {loading && (
        <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
      )}

      {data && (
        <div ref={printRef} id="attendance-print-area" className="space-y-4">
          {/* Report header */}
          <div className="flex items-start justify-between">
            <div>
              <h2 className="text-lg font-semibold">{data.month_label} — Attendance Report</h2>
              <p className="text-xs text-muted-foreground mt-0.5">
                Working days (Mon–Fri): <strong>{data.working_days}</strong> &nbsp;|&nbsp;
                Work hours: <strong>{toHM(data.settings.work_start_time)}–{toHM(data.settings.work_end_time)}</strong> &nbsp;|&nbsp;
                Standard: <strong>{data.settings.standard_hours}h</strong> &nbsp;|&nbsp;
                Lunch: <strong>{toHM(data.settings.lunch_start)}–{toHM(data.settings.lunch_end)}</strong>
              </p>
            </div>
          </div>

          {/* Summary table */}
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Employee</th>
                    <th className="px-4 py-3 text-center">Working Days</th>
                    <th className="px-4 py-3 text-center">Present</th>
                    <th className="px-4 py-3 text-center">Absent</th>
                    <th className="px-4 py-3 text-center">Late</th>
                    <th className="px-4 py-3 text-center">Overtime</th>
                    <th className="px-4 py-3 text-center">No Lunch</th>
                    <th className="px-4 py-3 text-center">Out of Hours</th>
                    <th className="px-4 py-3 text-center">Total Hrs</th>
                    <th className="px-4 py-3 text-right print:hidden"></th>
                  </tr>
                </thead>
                <tbody>
                  {data.employees.map(emp => (
                    <>
                      <tr key={emp.employee_id} className="border-t border-border hover:bg-muted/30 cursor-pointer" onClick={() => setExpanded(expanded === emp.employee_id ? null : emp.employee_id)}>
                        <td className="px-4 py-2.5">
                          <div className="font-medium text-foreground text-xs">{emp.name}</div>
                          <div className="text-[10px] text-muted-foreground font-mono">{emp.employee_code}</div>
                        </td>
                        <td className="px-4 py-2.5 text-center text-xs">{emp.working_days}</td>
                        <td className="px-4 py-2.5 text-center text-xs font-medium text-green-600">{emp.present_days}</td>
                        <td className="px-4 py-2.5 text-center text-xs font-medium text-red-500">{emp.absent_days}</td>
                        <td className="px-4 py-2.5 text-center text-xs">{emp.late_days > 0 ? <span className="text-orange-500 font-medium">{emp.late_days}</span> : <span className="text-muted-foreground">0</span>}</td>
                        <td className="px-4 py-2.5 text-center text-xs">{emp.overtime_days > 0 ? <span className="text-blue-500 font-medium">{emp.overtime_days}</span> : <span className="text-muted-foreground">0</span>}</td>
                        <td className="px-4 py-2.5 text-center text-xs">{emp.no_lunch_days > 0 ? <span className="text-yellow-600 font-medium">{emp.no_lunch_days}</span> : <span className="text-muted-foreground">0</span>}</td>
                        <td className="px-4 py-2.5 text-center text-xs">{emp.out_of_hours_days > 0 ? <span className="text-purple-500 font-medium">{emp.out_of_hours_days}</span> : <span className="text-muted-foreground">0</span>}</td>
                        <td className="px-4 py-2.5 text-center text-xs font-medium text-gold">{emp.total_hours}h</td>
                        <td className="px-4 py-2.5 text-right text-[10px] text-muted-foreground print:hidden">
                          {expanded === emp.employee_id ? "▲ collapse" : "▼ daily"}
                        </td>
                      </tr>

                      {/* Daily detail rows */}
                      {expanded === emp.employee_id && (
                        <tr key={`${emp.employee_id}-detail`} className="border-t border-border">
                          <td colSpan={10} className="p-0">
                            <div className="overflow-x-auto border-t border-dashed border-border/50">
                              <table className="w-full text-xs">
                                <thead className="bg-muted/20 text-[10px] uppercase tracking-wider text-muted-foreground">
                                  <tr>
                                    <th className="px-4 py-2 text-left">Date</th>
                                    <th className="px-4 py-2 text-left">Day</th>
                                    <th className="px-4 py-2 text-left">Status</th>
                                    <th className="px-4 py-2 text-left">In</th>
                                    <th className="px-4 py-2 text-left">Out</th>
                                    <th className="px-4 py-2 text-left">Duration</th>
                                    <th className="px-4 py-2 text-left">Sessions</th>
                                    <th className="px-4 py-2 text-left">Flags</th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {emp.daily_rows.map(d => (
                                    <tr key={d.date} className={`border-t border-border/30 ${d.weekend ? "opacity-40" : ""}`}>
                                      <td className="px-4 py-1.5 font-mono">{fmtDate(d.date)}</td>
                                      <td className="px-4 py-1.5 text-muted-foreground">{d.day}</td>
                                      <td className="px-4 py-1.5">
                                        {d.weekend
                                          ? <span className="text-muted-foreground">Weekend</span>
                                          : <span className={`font-medium ${d.status === "Present" ? "text-green-600" : d.status === "Absent" ? "text-red-500" : "text-muted-foreground"}`}>{d.status}</span>}
                                      </td>
                                      <td className="px-4 py-1.5 font-mono">{d.check_in ? toHM(d.check_in) : "—"}</td>
                                      <td className="px-4 py-1.5 font-mono">{d.check_out ? toHM(d.check_out) : "—"}</td>
                                      <td className="px-4 py-1.5">{d.duration_minutes ? fmtDuration(d.duration_minutes) : "—"}</td>
                                      <td className="px-4 py-1.5">{d.weekend ? "—" : (d.sessions_count || "—")}</td>
                                      <td className="px-4 py-1.5">
                                        <div className="flex flex-wrap gap-1">
                                          {flagBadge(d.late, "Late")}
                                          {flagBadge(d.overtime, "OT")}
                                          {flagBadge(d.no_lunch, "No Lunch")}
                                          {flagBadge(d.out_of_hours, "OOH")}
                                        </div>
                                      </td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          </td>
                        </tr>
                      )}
                    </>
                  ))}
                  {data.employees.length === 0 && (
                    <tr><td colSpan={10} className="px-4 py-12 text-center text-muted-foreground">No employees found.</td></tr>
                  )}
                </tbody>
              </table>
            </CardContent>
          </Card>

          {/* Print-only legend */}
          <div className="hidden print:block text-xs text-muted-foreground mt-4 pt-4 border-t border-border">
            <strong>Legend:</strong> Late = check-in after {toHM(data.settings.work_start_time)} &nbsp;|&nbsp; OT = Overtime (out after {toHM(data.settings.work_end_time)}) &nbsp;|&nbsp; No Lunch = worked through {toHM(data.settings.lunch_start)}–{toHM(data.settings.lunch_end)} without break &nbsp;|&nbsp; OOH = Out of Hours
          </div>
        </div>
      )}
    </div>
  );
}
