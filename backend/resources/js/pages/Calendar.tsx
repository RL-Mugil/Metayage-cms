import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { ChevronLeft, ChevronRight, CalendarDays, Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

type CaseEvent = {
  id: number;
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
};

const DAY_NAMES = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const MONTH_NAMES = [
  "January", "February", "March", "April", "May", "June",
  "July", "August", "September", "October", "November", "December",
];

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
  if (due < today && !isSameDay(due, today))
    return "bg-destructive/15 text-destructive border-destructive/30";
  const diff = (due.getTime() - today.getTime()) / (1000 * 60 * 60 * 24);
  if (diff <= 7) return "bg-amber-500/15 text-amber-700 border-amber-500/30";
  return "bg-gold/10 text-gold border-gold/30";
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

export default function Calendar() {
  const { props } = usePage() as any;
  const user = props.auth?.user;
  const isAdmin = ["super_admin", "admin"].includes(user?.role);

  const [events, setEvents] = useState<CaseEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [currentMonth, setCurrentMonth] = useState(
    new Date(new Date().getFullYear(), new Date().getMonth(), 1)
  );
  const [selectedDay, setSelectedDay] = useState<Date | null>(null);

  const today = new Date();
  const year = currentMonth.getFullYear();
  const month = currentMonth.getMonth();
  const cells = buildCalendarGrid(year, month);

  useEffect(() => {
    api.getCalendarEvents()
      .then((data) => { setEvents(data); setLoading(false); })
      .catch(() => setLoading(false));
  }, []);

  function prevMonth() { setCurrentMonth(new Date(year, month - 1, 1)); setSelectedDay(null); }
  function nextMonth() { setCurrentMonth(new Date(year, month + 1, 1)); setSelectedDay(null); }
  function goToToday() {
    setCurrentMonth(new Date(today.getFullYear(), today.getMonth(), 1));
    setSelectedDay(today);
  }

  function eventsForDay(date: Date) {
    return events.filter((e) => isSameDay(toDateOnly(e.delivery_due_date), date));
  }

  const thisMonthEvents = events.filter((e) => {
    const d = toDateOnly(e.delivery_due_date);
    return d.getFullYear() === year && d.getMonth() === month;
  });
  const overdueCount  = thisMonthEvents.filter(e => toDateOnly(e.delivery_due_date) < today && !isSameDay(toDateOnly(e.delivery_due_date), today)).length;
  const upcomingCount = thisMonthEvents.filter(e => toDateOnly(e.delivery_due_date) >= today || isSameDay(toDateOnly(e.delivery_due_date), today)).length;

  const selectedDayEvents = selectedDay ? eventsForDay(selectedDay) : [];

  const upcomingThisMonth = events
    .filter((e) => {
      const d = toDateOnly(e.delivery_due_date);
      return d.getFullYear() === year && d.getMonth() === month &&
        (d >= today || isSameDay(d, today));
    })
    .sort((a, b) => toDateOnly(a.delivery_due_date).getTime() - toDateOnly(b.delivery_due_date).getTime())
    .slice(0, 10);

  return (
    <AppLayout>
      <Head title="Calendar" />
      <PageHeader
        eyebrow="Practice"
        title="Calendar"
        description={isAdmin ? "All case delivery deadlines." : "Your assigned case delivery deadlines."}
        actions={
          <Button variant="outline" onClick={goToToday}>
            <CalendarDays className="h-4 w-4 mr-2" />Today
          </Button>
        }
      />

      <div className="px-8 py-6 space-y-4">
        {/* Stats row */}
        <div className="flex items-center gap-6">
          <div className="flex items-center gap-1.5">
            <CalendarDays className="h-4 w-4 text-muted-foreground" />
            <span className="text-sm text-muted-foreground">This month:</span>
            <span className="text-sm font-semibold">{thisMonthEvents.length} cases</span>
          </div>
          <div className="flex items-center gap-1.5">
            <span className="h-2 w-2 rounded-full bg-destructive" />
            <span className="text-sm text-muted-foreground">Overdue:</span>
            <span className="text-sm font-semibold text-destructive">{overdueCount}</span>
          </div>
          <div className="flex items-center gap-1.5">
            <span className="h-2 w-2 rounded-full bg-gold" />
            <span className="text-sm text-muted-foreground">Upcoming:</span>
            <span className="text-sm font-semibold">{upcomingCount}</span>
          </div>
        </div>

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
                  const visible = dayEvents.slice(0, 2);
                  const overflow = dayEvents.length - visible.length;

                  return (
                    <div
                      key={idx}
                      onClick={() => setSelectedDay(isSelected ? null : cell.date)}
                      className={[
                        "min-h-[90px] px-2 py-1.5 border-b border-r border-border cursor-pointer transition-colors",
                        !cell.isCurrentMonth ? "bg-muted/20" : "",
                        isSelected ? "bg-muted/50 ring-1 ring-inset ring-gold" : "hover:bg-muted/30",
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
                      </div>

                      <div className="space-y-0.5">
                        {visible.map((e) => (
                          <div
                            key={e.id}
                            className={`flex items-center gap-1 rounded px-1 py-0.5 text-[10px] border leading-tight ${eventChipClass(e, today)}`}
                            title={`${e.client_name ?? ""}${e.docket_number ? " · " + e.docket_number : ""}`}
                          >
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
                    {selectedDayEvents.length} case{selectedDayEvents.length !== 1 ? "s" : ""} due
                  </p>
                </div>
                <div className="divide-y divide-border">
                  {selectedDayEvents.length === 0 ? (
                    <div className="px-4 py-6 text-center text-sm text-muted-foreground">No cases due this day.</div>
                  ) : (
                    selectedDayEvents.map((e) => (
                      <div key={e.id} className="px-4 py-3 hover:bg-muted/20">
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
                          {e.my_role && (
                            <span className={`text-[10px] px-1.5 py-0.5 rounded border font-medium ${roleBadgeClass(e.my_role)}`}>
                              {e.my_role}
                            </span>
                          )}
                          {isAdmin && e.pcm_name && (
                            <span className="text-[10px] text-muted-foreground">PCM: {e.pcm_name}</span>
                          )}
                        </div>
                        {e.status && (
                          <div className="text-[10px] text-muted-foreground mt-1">{e.status}</div>
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

            {/* Upcoming this month */}
            {!selectedDay && upcomingThisMonth.length > 0 && (
              <div className="rounded-lg border border-border overflow-hidden">
                <div className="px-4 py-2.5 border-b border-border bg-muted/30">
                  <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Upcoming this month
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
                        {e.my_role && (
                          <span className={`text-[10px] px-1.5 py-0.5 rounded border font-medium flex-shrink-0 ${roleBadgeClass(e.my_role)}`}>
                            {e.my_role}
                          </span>
                        )}
                      </div>
                      <div className="flex items-center gap-2 mt-0.5">
                        <span className="font-mono text-[10px] text-muted-foreground">{fmtDate(e.delivery_due_date)}</span>
                        {e.record_type && (
                          <span className="text-[10px] text-muted-foreground">{e.record_type}</span>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
