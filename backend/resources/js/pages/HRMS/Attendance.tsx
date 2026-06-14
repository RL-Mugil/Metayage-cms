import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Clock, LogOut, LogIn, CheckCircle2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";

interface Session {
  in: string;
  out: string | null;
  duration_minutes: number | null;
}

interface AttendanceLog {
  id: number;
  attendance_date: string;
  status: string;
  duration_minutes: number;
  sessions: Session[];
  session_count: number;
  has_open_session: boolean;
  can_clock_in: boolean;
  can_clock_out: boolean;
  is_today: boolean;
}

function fmtTimeIST(t: string | null | undefined): string {
  if (!t) return "—";
  // t is "HH:MM:SS" from DB
  const iso = `1970-01-01T${t}`;
  const date = new Date(iso);
  if (isNaN(date.getTime())) return t;
  return date.toLocaleTimeString("en-IN", {
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: true,
    timeZone: "Asia/Kolkata",
  }).toUpperCase() + " IST";
}

function fmtDate(d: string): string {
  const [y, m, day] = d.split("-");
  return `${day}-${m}-${y}`;
}

function fmtDuration(minutes: number): string {
  if (!minutes) return "0h 0m";
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

export default function HRMSAttendance() {
  const [logs, setLogs] = useState<AttendanceLog[]>([]);
  const [loading, setLoading] = useState(true);
  const [clocking, setClocking] = useState(false);
  const [clockError, setClockError] = useState("");
  const [successMsg, setSuccessMsg] = useState("");

  const load = () => {
    return api.getAttendance().then((data) => {
      setLogs(data as unknown as AttendanceLog[]);
    });
  };

  useEffect(() => {
    load().finally(() => setLoading(false));
  }, []);

  const todayLog = logs.find((l) => l.is_today) ?? null;

  const canClockIn  = todayLog ? todayLog.can_clock_in  : true;
  const canClockOut = todayLog ? todayLog.can_clock_out : false;
  const sessionCount = todayLog?.session_count ?? 0;
  const maxReached   = sessionCount >= 6 && !canClockOut;

  async function handleClockIn() {
    setClocking(true);
    setClockError("");
    setSuccessMsg("");
    try {
      await api.clockIn();
      await load();
      setSuccessMsg("Clocked in!");
      setTimeout(() => setSuccessMsg(""), 3000);
    } catch (e: any) {
      setClockError(e.message || "Failed to clock in.");
    } finally {
      setClocking(false);
    }
  }

  async function handleClockOut() {
    setClocking(true);
    setClockError("");
    setSuccessMsg("");
    try {
      await api.clockOut();
      await load();
      setSuccessMsg("Clocked out!");
      setTimeout(() => setSuccessMsg(""), 3000);
    } catch (e: any) {
      setClockError(e.message || "Failed to clock out.");
    } finally {
      setClocking(false);
    }
  }

  return (
    <AppLayout>
      <Head title="Attendance" />
      <PageHeader
        eyebrow="HRMS"
        title="Attendance"
        description="Flexible hours — up to 6 sessions per day. All times in IST."
        actions={
          <div className="flex flex-col items-end gap-1.5">
            <div className="flex items-center gap-2">
              {/* Session counter */}
              {todayLog && (
                <span className="text-xs text-muted-foreground">
                  Today: {sessionCount}/6 sessions
                </span>
              )}

              {/* Clock In */}
              {canClockIn && !canClockOut && (
                <Button
                  onClick={handleClockIn}
                  disabled={clocking}
                  className="bg-green-600 hover:bg-green-700 text-white"
                >
                  {clocking
                    ? <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    : <LogIn className="h-4 w-4 mr-2" />}
                  Clock In
                </Button>
              )}

              {/* Clock Out */}
              {canClockOut && (
                <Button
                  onClick={handleClockOut}
                  disabled={clocking}
                  className="bg-red-600 hover:bg-red-700 text-white"
                >
                  {clocking
                    ? <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    : <LogOut className="h-4 w-4 mr-2" />}
                  Clock Out
                </Button>
              )}

              {/* Max sessions reached */}
              {maxReached && (
                <Badge variant="outline" className="text-xs px-3 py-1.5 border-gold text-gold">
                  <CheckCircle2 className="h-3.5 w-3.5 mr-1.5" />
                  Max sessions reached (6/6)
                </Badge>
              )}

              {/* Clock In again after clocking out (not max) */}
              {!canClockIn && !canClockOut && !maxReached && todayLog && (
                <span className="text-xs text-muted-foreground">Session closed — clock in again</span>
              )}
            </div>

            {successMsg && <p className="text-xs text-green-500">{successMsg}</p>}
            {clockError && <p className="text-xs text-red-500 max-w-72 text-right">{clockError}</p>}
          </div>
        }
      />

      <div className="px-8 py-6">
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
                          <td className="px-4 py-3 text-muted-foreground">—</td>
                          <td className="px-4 py-3 text-muted-foreground">—</td>
                          <td className="px-4 py-3 text-muted-foreground">—</td>
                          <td className="px-4 py-3 text-muted-foreground">—</td>
                          <td className="px-4 py-3 text-muted-foreground">—</td>
                          <td className="px-4 py-3">
                            <Badge variant="secondary">{log.status}</Badge>
                          </td>
                        </tr>
                      );
                    }

                    return sessions.map((sess, idx) => {
                      const isFirst = idx === 0;
                      const isOpen  = sess.out === null;
                      const sessMin = sess.duration_minutes;

                      return (
                        <tr
                          key={`${log.id}-${idx}`}
                          className={[
                            "border-t border-border hover:bg-muted/30",
                            isOpen ? "bg-green-500/5" : "",
                            log.is_today && isFirst ? "ring-1 ring-inset ring-gold/30" : "",
                          ].join(" ")}
                        >
                          {/* Date — only in first row of a day, merged via rowSpan feeling */}
                          <td className="px-4 py-2.5 font-mono text-xs">
                            {isFirst ? (
                              <span className="font-semibold text-foreground">
                                {fmtDate(log.attendance_date)}
                              </span>
                            ) : (
                              <span className="text-muted-foreground/40">↓</span>
                            )}
                          </td>

                          {/* Session number */}
                          <td className="px-4 py-2.5">
                            <span className="inline-flex h-5 w-5 items-center justify-center rounded-full bg-muted text-[10px] font-semibold text-muted-foreground">
                              {idx + 1}
                            </span>
                          </td>

                          {/* Clock In */}
                          <td className="px-4 py-2.5 font-mono text-xs text-foreground">
                            {fmtTimeIST(sess.in)}
                          </td>

                          {/* Clock Out */}
                          <td className="px-4 py-2.5 font-mono text-xs">
                            {isOpen ? (
                              <span className="flex items-center gap-1.5 text-green-600 font-medium">
                                <span className="h-1.5 w-1.5 rounded-full bg-green-500 animate-pulse" />
                                Active
                              </span>
                            ) : (
                              <span className="text-foreground">{fmtTimeIST(sess.out ?? null)}</span>
                            )}
                          </td>

                          {/* Session duration */}
                          <td className="px-4 py-2.5 text-xs text-muted-foreground">
                            {isOpen ? "—" : (sessMin != null ? fmtDuration(sessMin) : "—")}
                          </td>

                          {/* Total duration — only in first row */}
                          <td className="px-4 py-2.5 text-xs font-medium">
                            {isFirst ? (
                              log.duration_minutes > 0
                                ? <span className="text-gold">{fmtDuration(log.duration_minutes)}</span>
                                : <span className="text-muted-foreground">—</span>
                            ) : ""}
                          </td>

                          {/* Status — only in first row */}
                          <td className="px-4 py-2.5">
                            {isFirst ? <Badge variant="secondary">{log.status}</Badge> : ""}
                          </td>
                        </tr>
                      );
                    });
                  })}

                  {logs.length === 0 && (
                    <tr>
                      <td colSpan={7} className="px-4 py-12 text-center text-muted-foreground text-sm">
                        No attendance records yet. Use Clock In to start tracking.
                      </td>
                    </tr>
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
