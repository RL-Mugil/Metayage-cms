import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState, useCallback } from "react";
import { ChevronLeft, ChevronRight, CalendarDays, Loader2, RefreshCw, AlertTriangle, Clock } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

type EventType = "hard_deadline" | "target_filing" | "delivery_due";

type CaseEvent = {
  id: string | number;
  project_id: number | null;
  docket_number: string | null;
  client_name: string | null;
  record_type: string | null;
  delivery_due_date: string;
  status: string | null;
  pcm_id: number | null;
  pcm_name: string | null;
  scm_id: number | null;
  scm_name: string | null;
  pr_id: number | null;
  pr_name: string | null;
  percentage_of_completion: number;
  my_role: "PCM" | "SCM" | "PR" | null;
  event_type: EventType;
  event_label: string;
};

const DAY_NAMES = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const MONTH_NAMES = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December",
];
const MONTH_SHORT = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

function toDateOnly(d: string) {
  return new Date(d + "T00:00:00");
}

function isSameDay(a: Date, b: Date) {
  return (
    a.getFullYear() === b.getFullYear() &&
    a.getMonth() === b.getMonth() &&
    a.getDate() === b.getDate()
  );
}

function buildCalendarGrid(year: number, month: number) {
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const daysInPrevMonth = new Date(year, month, 0).getDate();
  const cells: { date: Date; isCurrentMonth: boolean }[] = [];

  for (let i = firstDay - 1; i >= 0; i--)
    cells.push({ date: new Date(year, month - 1, daysInPrevMonth - i), isCurrentMonth: false });
  for (let d = 1; d <= daysInMonth; d++)
    cells.push({ date: new Date(year, month, d), isCurrentMonth: true });
  let next = 1;
  while (cells.length % 7 !== 0)
    cells.push({ date: new Date(year, month + 1, next++), isCurrentMonth: false });

  return cells;
}

function eventChipClass(event: CaseEvent, today: Date): string {
  const due = toDateOnly(event.delivery_due_date);
  const isOverdue = due < today && !isSameDay(due, today);

  if (isOverdue) return "bg-destructive/15 text-destructive border-destructive/30";

  const diff = (due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24);
  const isUrgent = diff <= 7;

  if (event.event_type === "hard_deadline") {
    return isUrgent
      ? "bg-orange-500/20 text-orange-700 border-orange-500/40"
      : "bg-blue-500/10 text-blue-700 border-blue-500/25";
  }
  if (event.event_type === "target_filing") {
    return isUrgent
      ? "bg-amber-500/20 text-amber-700 border-amber-500/40"
      : "bg-teal-500/10 text-teal-700 border-teal-500/25";
  }
  return isUrgent
    ? "bg-amber-500/15 text-amber-700 border-amber-500/30"
    : "bg-gold/10 text-gold border-gold/30";
}

function roleBadgeClass(role: string | null) {
  if (role === "PCM") return "bg-blue-500/10 text-blue-600 border-blue-500/30";
  if (role === "SCM") return "bg-purple-500/10 text-purple-600 border-purple-500/30";
  if (role === "PR")  return "bg-green-500/10 text-green-600 border-green-500/30";
  return "bg-muted text-muted-foreground border-border";
}

function fmtDate(d: string) {
  return toDateOnly(d).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" });
}

function eventTypeDot(type: EventType) {
  if (type === "hard_deadline") return "bg-blue-500";
  if (type === "target_filing") return "bg-teal-500";
  return "bg-gold";
}

// ── Yearly mini-month component ───────────────────────────────────────────────
function YearMiniMonth({
  year,
  monthIdx,
  events,
  today,
  onDayClick,
}: {
  year: number;
  monthIdx: number;
  events: CaseEvent[];
  today: Date;
  onDayClick: (date: Date) => void;
}) {
  const cells = buildCalendarGrid(year, monthIdx);
  const weeks: typeof cells[] = [];
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));

  function eventsOnDay(d: Date) {
    return events.filter((e) => isSameDay(toDateOnly(e.delivery_due_date), d));
  }

  return (
    <div className="rounded-lg border border-border bg-card overflow-hidden">
      <div className="px-3 py-2 text-center text-xs font-semibold uppercase tracking-wider text-muted-foreground border-b border-border bg-muted/20">
        {MONTH_SHORT[monthIdx]}
      </div>
      <div className="grid grid-cols-7 px-1 pt-1">
        {["S","M","T","W","T","F","S"].map((d, i) => (
          <div key={i} className="text-center text-[9px] text-muted-foreground/70 pb-0.5">{d}</div>
        ))}
      </div>
      <div className="px-1 pb-1.5 space-y-px">
        {weeks.map((week, wi) => (
          <div key={wi} className="grid grid-cols-7">
            {week.map((cell, di) => {
              const evts = eventsOnDay(cell.date);
              const isToday = isSameDay(cell.date, today);
              const hasOverdue = evts.some((e) => {
                const d = toDateOnly(e.delivery_due_date);
                return d < today && !isSameDay(d, today);
              });
              const hasHard = evts.some((e) => e.event_type === "hard_deadline");
              const hasFiling = evts.some((e) => e.event_type === "target_filing");
              const hasDelivery = evts.some((e) => e.event_type === "delivery_due");

              return (
                <div
                  key={di}
                  onClick={() => cell.isCurrentMonth && evts.length > 0 && onDayClick(cell.date)}
                  className={[
                    "relative flex flex-col items-center py-0.5 rounded text-[10px]",
                    cell.isCurrentMonth ? "" : "opacity-20",
                    evts.length > 0 && cell.isCurrentMonth ? "cursor-pointer hover:bg-muted/40" : "",
                    isToday ? "ring-1 ring-gold ring-inset rounded" : "",
                  ].join(" ")}
                >
                  <span className={[
                    "h-4 w-4 flex items-center justify-center rounded-full leading-none font-medium",
                    isToday ? "bg-gold text-white text-[9px]" : "text-foreground",
                  ].join(" ")}>
                    {cell.date.getDate()}
                  </span>
                  {evts.length > 0 && cell.isCurrentMonth && (
                    <div className="flex gap-px mt-px">
                      {hasOverdue && <span className="h-1 w-1 rounded-full bg-destructive" />}
                      {!hasOverdue && hasHard && <span className="h-1 w-1 rounded-full bg-blue-500" />}
                      {!hasOverdue && hasFiling && <span className="h-1 w-1 rounded-full bg-teal-500" />}
                      {!hasOverdue && hasDelivery && <span className="h-1 w-1 rounded-full bg-amber-400" />}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        ))}
      </div>
    </div>
  );
}

export default function Calendar() {
  const { props } = usePage() as any;
  const user = props.auth?.user;
  const isAdmin = ["super_admin", "partner", "manager", "galvanizer"].includes(user?.role);

  const [events, setEvents] = useState<CaseEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [viewMode, setViewMode] = useState<"monthly" | "yearly">("monthly");
  const [currentMonth, setCurrentMonth] = useState(
    new Date(new Date().getFullYear(), new Date().getMonth(), 1)
  );
  const [currentYear, setCurrentYear] = useState(new Date().getFullYear());
  const [selectedDay, setSelectedDay] = useState<Date | null>(null);

  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const year = currentMonth.getFullYear();
  const month = currentMonth.getMonth();
  const cells = buildCalendarGrid(year, month);

  const fetchEvents = useCallback((isRefresh = false) => {
    if (isRefresh) setRefreshing(true);
    else setLoading(true);
    api.getCalendarEvents()
      .then((data) => setEvents(data as CaseEvent[]))
      .catch(() => {})
      .finally(() => { setLoading(false); setRefreshing(false); });
  }, []);

  useEffect(() => { fetchEvents(); }, [fetchEvents]);

  function prevMonth() { setCurrentMonth(new Date(year, month - 1, 1)); setSelectedDay(null); }
  function nextMonth() { setCurrentMonth(new Date(year, month + 1, 1)); setSelectedDay(null); }
  function goToToday() {
    setCurrentMonth(new Date(today.getFullYear(), today.getMonth(), 1));
    setCurrentYear(today.getFullYear());
    setSelectedDay(today);
    setViewMode("monthly");
  }

  function eventsForDay(date: Date) {
    return events.filter((e) => isSameDay(toDateOnly(e.delivery_due_date), date));
  }

  function handleYearDayClick(date: Date) {
    setCurrentMonth(new Date(date.getFullYear(), date.getMonth(), 1));
    setCurrentYear(date.getFullYear());
    setSelectedDay(date);
    setViewMode("monthly");
  }

  const allOverdue = events.filter((e) => {
    const d = toDateOnly(e.delivery_due_date);
    return d < today && !isSameDay(d, today) && e.status !== "Completed";
  });
  const next30Days = events.filter((e) => {
    const d = toDateOnly(e.delivery_due_date);
    const diff = (d.getTime() - today.getTime()) / 86400000;
    return diff >= 0 && diff <= 30;
  });

  const thisMonthEvents = events.filter((e) => {
    const d = toDateOnly(e.delivery_due_date);
    return d.getFullYear() === year && d.getMonth() === month;
  });

  const thisYearEvents = events.filter((e) => {
    const d = toDateOnly(e.delivery_due_date);
    return d.getFullYear() === currentYear;
  });

  const selectedDayEvents = selectedDay ? eventsForDay(selectedDay) : [];

  const upcomingThisMonth = events
    .filter((e) => {
      const d = toDateOnly(e.delivery_due_date);
      return d.getFullYear() === year && d.getMonth() === month && d >= today;
    })
    .sort((a, b) => toDateOnly(a.delivery_due_date).getTime() - toDateOnly(b.delivery_due_date).getTime())
    .slice(0, 10);

  return (
    <AppLayout>
      <Head title="Calendar" />
      <PageHeader
        eyebrow="Practice"
        title="Calendar"
        description={isAdmin ? "All case deadlines across hard deadline, filing date, and delivery due." : "Your assigned case deadlines."}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="outline" size="sm" onClick={() => fetchEvents(true)} disabled={refreshing}>
              <RefreshCw className={`h-3.5 w-3.5 mr-1.5 ${refreshing ? "animate-spin" : ""}`} />
              Refresh
            </Button>
            <div className="flex items-center rounded-md border border-border overflow-hidden">
              <button
                onClick={() => setViewMode("monthly")}
                className={`px-3 py-1.5 text-xs font-medium transition-colors ${viewMode === "monthly" ? "bg-gold text-background" : "bg-background text-muted-foreground hover:bg-muted/50"}`}
              >
                Monthly
              </button>
              <button
                onClick={() => setViewMode("yearly")}
                className={`px-3 py-1.5 text-xs font-medium transition-colors ${viewMode === "yearly" ? "bg-gold text-background" : "bg-background text-muted-foreground hover:bg-muted/50"}`}
              >
                Yearly
              </button>
            </div>
            <Button variant="outline" onClick={goToToday}>
              <CalendarDays className="h-4 w-4 mr-2" />Today
            </Button>
          </div>
        }
      />

      <div className="px-8 py-6 space-y-4">
        {/* Global stats row */}
        <div className="grid grid-cols-4 gap-3">
          <div className="flex items-center gap-3 rounded-lg border border-border bg-background px-4 py-3">
            <CalendarDays className="h-5 w-5 text-muted-foreground flex-shrink-0" />
            <div>
              <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Total Events</p>
              <p className="text-2xl font-bold">{events.length}</p>
            </div>
          </div>
          <div className="flex items-center gap-3 rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3">
            <AlertTriangle className="h-5 w-5 text-destructive flex-shrink-0" />
            <div>
              <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Overdue (all time)</p>
              <p className="text-2xl font-bold text-destructive">{allOverdue.length}</p>
            </div>
          </div>
          <div className="flex items-center gap-3 rounded-lg border border-amber-300/50 bg-amber-500/5 px-4 py-3">
            <Clock className="h-5 w-5 text-amber-600 flex-shrink-0" />
            <div>
              <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Next 30 Days</p>
              <p className="text-2xl font-bold text-amber-600">{next30Days.length}</p>
            </div>
          </div>
          <div className="flex items-center gap-3 rounded-lg border border-border bg-background px-4 py-3">
            <div className="flex flex-col gap-0.5">
              <p className="text-[10px] text-muted-foreground uppercase tracking-wide">
                {viewMode === "yearly" ? "This Year" : "This Month"}
              </p>
              <p className="text-2xl font-bold">
                {viewMode === "yearly" ? thisYearEvents.length : thisMonthEvents.length}
              </p>
            </div>
          </div>
        </div>

        {/* Legend */}
        <div className="flex items-center gap-4 text-[11px] text-muted-foreground">
          <span className="font-medium text-foreground">Legend:</span>
          <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-blue-500" /> Hard Deadline</span>
          <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-teal-500" /> Target Filing Date</span>
          <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-amber-400" /> Delivery Due (Tracker)</span>
          <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-destructive" /> Overdue</span>
        </div>

        {/* ── YEARLY VIEW ─────────────────────────────────────────────────── */}
        {viewMode === "yearly" && (
          <div className="space-y-4">
            <div className="flex items-center justify-between">
              <Button variant="ghost" size="sm" onClick={() => setCurrentYear((y) => y - 1)}>
                <ChevronLeft className="h-4 w-4 mr-1" /> {currentYear - 1}
              </Button>
              <h2 className="font-display font-semibold text-lg">{currentYear}</h2>
              <Button variant="ghost" size="sm" onClick={() => setCurrentYear((y) => y + 1)}>
                {currentYear + 1} <ChevronRight className="h-4 w-4 ml-1" />
              </Button>
            </div>

            {loading ? (
              <div className="flex items-center justify-center py-20">
                <Loader2 className="h-8 w-8 animate-spin text-gold" />
              </div>
            ) : (
              <div className="grid grid-cols-3 gap-3 md:grid-cols-4">
                {MONTH_NAMES.map((_, mi) => (
                  <YearMiniMonth
                    key={mi}
                    year={currentYear}
                    monthIdx={mi}
                    events={events}
                    today={today}
                    onDayClick={handleYearDayClick}
                  />
                ))}
              </div>
            )}

            <p className="text-[11px] text-muted-foreground text-center">
              Click any highlighted day to jump to that month's detail view.
            </p>
          </div>
        )}

        {/* ── MONTHLY VIEW ────────────────────────────────────────────────── */}
        {viewMode === "monthly" && (
          <div className="flex gap-6 items-start">
            {/* Calendar grid */}
            <div className="flex-1 min-w-0 rounded-lg border border-border overflow-hidden">
              <div className="flex items-center justify-between px-4 py-3 border-b border-border bg-muted/30">
                <Button variant="ghost" size="icon" onClick={prevMonth}>
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                <h2 className="font-display font-semibold text-base">
                  {MONTH_NAMES[month]} {year}
                </h2>
                <Button variant="ghost" size="icon" onClick={nextMonth}>
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>

              <div className="grid grid-cols-7 border-b border-border">
                {DAY_NAMES.map((d) => (
                  <div key={d} className="px-2 py-2 text-center text-xs font-medium uppercase tracking-wider text-muted-foreground">
                    {d}
                  </div>
                ))}
              </div>

              {loading ? (
                <div className="flex items-center justify-center py-20">
                  <Loader2 className="h-8 w-8 animate-spin text-gold" />
                </div>
              ) : (
                <div className="grid grid-cols-7">
                  {cells.map((cell, idx) => {
                    const dayEvents = eventsForDay(cell.date);
                    const isToday = isSameDay(cell.date, today);
                    const isSelected = selectedDay ? isSameDay(cell.date, selectedDay) : false;
                    const visible = dayEvents.slice(0, 3);
                    const overflow = dayEvents.length - visible.length;
                    const hasOverdue = dayEvents.some((e) => {
                      const d = toDateOnly(e.delivery_due_date);
                      return d < today && !isSameDay(d, today);
                    });

                    return (
                      <div
                        key={idx}
                        onClick={() => setSelectedDay(isSelected ? null : cell.date)}
                        className={[
                          "min-h-[90px] px-1.5 py-1.5 border-b border-r border-border cursor-pointer transition-colors",
                          !cell.isCurrentMonth ? "bg-muted/20" : "",
                          isSelected ? "bg-muted/50 ring-1 ring-inset ring-gold" : "hover:bg-muted/30",
                          hasOverdue && !isSelected ? "bg-destructive/5" : "",
                        ].join(" ")}
                      >
                        <div className="flex items-center justify-between mb-1">
                          <span className={[
                            "text-sm font-medium inline-flex h-6 w-6 items-center justify-center rounded-full",
                            isToday ? "bg-gold text-white font-bold"
                              : !cell.isCurrentMonth ? "text-muted-foreground/50"
                              : "text-foreground",
                          ].join(" ")}>
                            {cell.date.getDate()}
                          </span>
                          {dayEvents.length > 0 && (
                            <span className="text-[9px] text-muted-foreground">{dayEvents.length}</span>
                          )}
                        </div>

                        <div className="space-y-0.5">
                          {visible.map((e) => (
                            <div
                              key={e.id}
                              className={`flex items-center gap-1 rounded px-1 py-0.5 text-[10px] border leading-tight ${eventChipClass(e, today)}`}
                              title={`[${e.event_label}] ${e.client_name ?? ""}${e.docket_number ? " · " + e.docket_number : ""}`}
                            >
                              <span className={`h-1.5 w-1.5 rounded-full flex-shrink-0 ${eventTypeDot(e.event_type)}`} />
                              <span className="truncate font-medium">
                                {e.docket_number ?? e.client_name ?? "—"}
                              </span>
                            </div>
                          ))}
                          {overflow > 0 && (
                            <div className="text-[10px] text-muted-foreground pl-1">+{overflow} more</div>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Side panel */}
            <div className="w-[300px] flex-shrink-0 space-y-4">
              {selectedDay ? (
                <div className="rounded-lg border border-border overflow-hidden">
                  <div className="px-4 py-3 border-b border-border bg-muted/30">
                    <h3 className="font-display font-semibold text-sm">
                      {selectedDay.toLocaleDateString("en-IN", { weekday: "long", day: "2-digit", month: "short", year: "numeric" })}
                    </h3>
                    <p className="text-xs text-muted-foreground mt-0.5">
                      {selectedDayEvents.length} event{selectedDayEvents.length !== 1 ? "s" : ""} due
                    </p>
                  </div>
                  <div className="divide-y divide-border max-h-[420px] overflow-y-auto">
                    {selectedDayEvents.length === 0 ? (
                      <div className="px-4 py-6 text-center text-sm text-muted-foreground">No cases due this day.</div>
                    ) : (
                      selectedDayEvents.map((e) => (
                        <div key={e.id} className="px-4 py-3 hover:bg-muted/20">
                          <div className="flex items-center gap-1.5 mb-1">
                            <span className={`h-2 w-2 rounded-full flex-shrink-0 ${eventTypeDot(e.event_type)}`} />
                            <span className="text-[10px] font-medium text-muted-foreground uppercase tracking-wide">{e.event_label}</span>
                          </div>
                          <div className="text-sm font-semibold mb-0.5 leading-snug truncate" title={e.client_name ?? ""}>
                            {e.client_name ?? "—"}
                          </div>
                          {e.docket_number && (
                            <div className="font-mono text-[11px] text-muted-foreground mb-1">{e.docket_number}</div>
                          )}
                          <div className="flex items-center gap-1.5 flex-wrap">
                            {e.record_type && (
                              <Badge variant="outline" className="text-[10px] px-1.5">{e.record_type}</Badge>
                            )}
                            {e.status && (
                              <Badge variant="outline" className="text-[10px] px-1.5">{e.status}</Badge>
                            )}
                            {e.my_role && (
                              <span className={`text-[10px] px-1.5 py-0.5 rounded border font-medium ${roleBadgeClass(e.my_role)}`}>
                                {e.my_role}
                              </span>
                            )}
                          </div>
                          {isAdmin && (e.pcm_name || e.pr_name) && (
                            <div className="text-[10px] text-muted-foreground mt-1.5 space-y-0.5">
                              {e.pcm_name && <div>PCM: {e.pcm_name}</div>}
                              {e.scm_name && <div>SCM: {e.scm_name}</div>}
                              {e.pr_name  && <div>PR: {e.pr_name}</div>}
                            </div>
                          )}
                        </div>
                      ))
                    )}
                  </div>
                </div>
              ) : (
                <div className="rounded-lg border border-dashed border-border px-4 py-8 text-center">
                  <CalendarDays className="h-8 w-8 mx-auto text-muted-foreground mb-2" />
                  <p className="text-sm text-muted-foreground">Click a day to see cases due.</p>
                </div>
              )}

              {/* Overdue cases */}
              {!selectedDay && allOverdue.length > 0 && (
                <div className="rounded-lg border border-destructive/30 overflow-hidden">
                  <div className="px-4 py-2.5 border-b border-destructive/20 bg-destructive/5">
                    <h4 className="text-xs font-semibold uppercase tracking-wider text-destructive flex items-center gap-1.5">
                      <AlertTriangle className="h-3 w-3" />
                      Overdue ({allOverdue.length})
                    </h4>
                  </div>
                  <div className="divide-y divide-border max-h-[240px] overflow-y-auto">
                    {allOverdue
                      .sort((a, b) => toDateOnly(a.delivery_due_date).getTime() - toDateOnly(b.delivery_due_date).getTime())
                      .slice(0, 8)
                      .map((e) => (
                        <div
                          key={e.id}
                          className="px-4 py-2.5 hover:bg-muted/20 cursor-pointer"
                          onClick={() => { setCurrentMonth(new Date(toDateOnly(e.delivery_due_date).getFullYear(), toDateOnly(e.delivery_due_date).getMonth(), 1)); setSelectedDay(toDateOnly(e.delivery_due_date)); }}
                        >
                          <div className="text-xs font-medium truncate">{e.docket_number ?? e.client_name ?? "—"}</div>
                          <div className="flex items-center gap-2 mt-0.5">
                            <span className="font-mono text-[10px] text-destructive">{fmtDate(e.delivery_due_date)}</span>
                            <span className="text-[10px] text-muted-foreground">{e.event_label}</span>
                          </div>
                        </div>
                      ))}
                    {allOverdue.length > 8 && (
                      <div className="px-4 py-2 text-[11px] text-muted-foreground text-center">+{allOverdue.length - 8} more overdue</div>
                    )}
                  </div>
                </div>
              )}

              {/* Upcoming this month */}
              {!selectedDay && upcomingThisMonth.length > 0 && (
                <div className="rounded-lg border border-border overflow-hidden">
                  <div className="px-4 py-2.5 border-b border-border bg-muted/30">
                    <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                      Upcoming this month ({upcomingThisMonth.length})
                    </h4>
                  </div>
                  <div className="divide-y divide-border max-h-[360px] overflow-y-auto">
                    {upcomingThisMonth.map((e) => (
                      <div
                        key={e.id}
                        className="px-4 py-2.5 hover:bg-muted/20 cursor-pointer"
                        onClick={() => setSelectedDay(toDateOnly(e.delivery_due_date))}
                      >
                        <div className="flex items-center justify-between gap-2">
                          <span className="text-xs font-medium truncate flex-1" title={e.client_name ?? ""}>
                            {e.docket_number ?? e.client_name ?? "—"}
                          </span>
                          <div className="flex items-center gap-1 flex-shrink-0">
                            <span className={`h-1.5 w-1.5 rounded-full ${eventTypeDot(e.event_type)}`} />
                            {e.my_role && (
                              <span className={`text-[10px] px-1.5 py-0.5 rounded border font-medium ${roleBadgeClass(e.my_role)}`}>
                                {e.my_role}
                              </span>
                            )}
                          </div>
                        </div>
                        <div className="flex items-center gap-2 mt-0.5">
                          <span className="font-mono text-[10px] text-muted-foreground">{fmtDate(e.delivery_due_date)}</span>
                          <span className="text-[10px] text-muted-foreground">{e.event_label}</span>
                        </div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
