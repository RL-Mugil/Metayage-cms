import { Head, usePage } from "@inertiajs/react";
import { useState, useEffect, useCallback } from "react";
import { Users, Clock, Calendar, TrendingUp, UserCheck, UserX, Loader2, X, Search, ChevronLeft, ChevronRight, RefreshCw, Clock as ClockIcon } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";

interface HrmsKpiDef { label: string; key: string; color: string; icon: React.ElementType; statusFilter: string | null }

const HRMS_KPI_DEFS: HrmsKpiDef[] = [
  { label: "Total Headcount",   key: "total",       color: "text-gold",       icon: Users,     statusFilter: null },
  { label: "Active Employees",  key: "active",      color: "text-green-500",  icon: UserCheck, statusFilter: "Active" },
  { label: "On Leave",          key: "on_leave",    color: "text-amber-500",  icon: Calendar,  statusFilter: "On Leave" },
  { label: "Departments",       key: "departments", color: "text-blue-500",   icon: TrendingUp,statusFilter: null },
];

function HrmsKpiModal({ kpi, onClose }: { kpi: HrmsKpiDef; onClose: () => void }) {
  const [result, setResult]   = useState<{ data: any[]; total: number }>({ data: [], total: 0 });
  const [search, setSearch]   = useState("");
  const [loading, setLoading] = useState(false);
  const [page, setPage]       = useState(1);
  const PER_PAGE = 15;

  const fetchPage = useCallback(async (pg: number, q: string) => {
    setLoading(true);
    try {
      const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(pg) });
      if (kpi.statusFilter) params.set("employment_status", kpi.statusFilter);
      if (q) params.set("search", q);
      const res: any = await api.getEmployeesPaged(params);
      setResult({ data: Array.isArray(res) ? res : (res?.data ?? []), total: res?.total ?? 0 });
    } finally { setLoading(false); }
  }, [kpi]);

  useEffect(() => { fetchPage(1, ""); }, [fetchPage]);
  function handleSearch(q: string) { setSearch(q); setPage(1); fetchPage(1, q); }
  function goPage(pg: number) { setPage(pg); fetchPage(pg, search); }
  const totalPages = Math.max(1, Math.ceil(result.total / PER_PAGE));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl max-h-[88vh] flex flex-col">
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <h2 className="font-display text-lg font-semibold">{kpi.label}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{result.total} employee{result.total !== 1 ? "s" : ""}</p>
          </div>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>
        <div className="px-6 py-3 border-b border-border flex-shrink-0">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold"
              placeholder="Search name, email…" value={search} onChange={(e) => handleSearch(e.target.value)} />
          </div>
        </div>
        <div className="flex-1 overflow-auto">
          {loading ? (
            <div className="flex items-center justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
          ) : (
            <table className="w-full text-sm">
              <thead className="sticky top-0 bg-muted/60 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left">Employee</th>
                  <th className="px-4 py-3 text-left">Designation</th>
                  <th className="px-4 py-3 text-left">Department</th>
                  <th className="px-4 py-3 text-left">Location</th>
                  <th className="px-4 py-3 text-left">Status</th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((e) => (
                  <tr key={e.id} className="border-t border-border hover:bg-muted/30">
                    <td className="px-4 py-2.5">
                      <div className="flex items-center gap-2">
                        <div className="h-7 w-7 rounded-full bg-gold/20 flex items-center justify-center text-gold font-semibold text-xs flex-shrink-0">
                          {e.full_name?.charAt(0) || "?"}
                        </div>
                        <div>
                          <div className="font-medium text-sm">{e.full_name}</div>
                          <div className="text-xs text-muted-foreground">{e.work_email}</div>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-2.5 text-xs text-muted-foreground">{e.designation?.title ?? "—"}</td>
                    <td className="px-4 py-2.5 text-xs">{e.department?.name ?? "—"}</td>
                    <td className="px-4 py-2.5 text-xs text-muted-foreground">{e.work_location ?? "—"}</td>
                    <td className="px-4 py-2.5">
                      <span className={`text-xs font-medium ${e.employment_status === "Active" ? "text-green-600" : "text-amber-600"}`}>
                        {e.employment_status ?? "Active"}
                      </span>
                    </td>
                  </tr>
                ))}
                {result.data.length === 0 && (
                  <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">No employees found.</td></tr>
                )}
              </tbody>
            </table>
          )}
        </div>
        <div className="flex items-center justify-between px-6 py-3 border-t border-border flex-shrink-0 text-xs text-muted-foreground">
          <span>Showing {result.data.length} of {result.total}</span>
          <div className="flex items-center gap-1">
            <button onClick={() => goPage(page - 1)} disabled={page === 1} className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40"><ChevronLeft className="h-4 w-4" /></button>
            <span className="px-2">{page} / {totalPages}</span>
            <button onClick={() => goPage(page + 1)} disabled={page >= totalPages} className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40"><ChevronRight className="h-4 w-4" /></button>
          </div>
        </div>
      </div>
    </div>
  );
}

const deptColors: Record<string, string> = {
  Patents: "bg-blue-500/10 text-blue-600 border-blue-200",
  Trademarks: "bg-purple-500/10 text-purple-600 border-purple-200",
  Litigation: "bg-red-500/10 text-red-600 border-red-200",
  Finance: "bg-green-500/10 text-green-600 border-green-200",
  "People Ops": "bg-amber-500/10 text-amber-600 border-amber-200",
};

const HR_ADMIN_ROLES = ["super_admin", "partner", "hr", "finance"];

// v2 — Team Availability board with real-time clock status
export default function HRMSIndex() {
  const { props } = usePage() as any;
  const userRole: string = props.auth?.user?.role ?? "";
  const isHrAdmin = HR_ADMIN_ROLES.includes(userRole);

  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [stats, setStats] = useState({ total: 0, active: 0, on_leave: 0, departments: 0 });
  const [kpiModal, setKpiModal] = useState<HrmsKpiDef | null>(null);
  const [resetting, setResetting] = useState<number | null>(null);

  const loadEmployees = useCallback(() => {
    return api.getEmployees().then((data) => {
      setEmployees(Array.isArray(data) ? data : []);
    });
  }, []);

  useEffect(() => {
    api.getHRMSStats().then(setStats).catch(() => {});
    loadEmployees().finally(() => setLoading(false));
  }, [loadEmployees]);

  async function handleResetToday(emp: any) {
    if (!confirm(`Reset today's clock-in sessions for ${emp.full_name}?`)) return;
    setResetting(emp.id);
    try {
      await api.resetEmployeeToday(emp.id);
      await loadEmployees();
    } catch (e: any) { alert(e.message || "Failed."); }
    finally { setResetting(null); }
  }

  const [search, setSearch] = useState("");
  const [filterStatus, setFilterStatus] = useState<"all" | "in" | "out" | "leave">("all");

  const departments = [...new Set(employees.map((e) => e.department?.name).filter(Boolean))];
  const deptBreakdown = departments.map((dept) => ({
    name: dept,
    count: employees.filter((e) => e.department?.name === dept).length,
  }));
  const locationBreakdown = [...new Set(employees.map((e) => e.work_location).filter(Boolean))]
    .map((loc) => ({ name: loc as string, count: employees.filter((e) => e.work_location === loc).length }))
    .sort((a, b) => b.count - a.count);

  const clockedIn  = employees.filter(e => e.today_status === "clocked_in").length;
  const onLeaveNow = employees.filter(e => e.employment_status === "On Leave").length;

  const visible = employees.filter(e => {
    const q = search.toLowerCase();
    if (q && !(e.full_name?.toLowerCase().includes(q) || e.work_email?.toLowerCase().includes(q) || e.designation?.title?.toLowerCase().includes(q) || e.department?.name?.toLowerCase().includes(q))) return false;
    if (filterStatus === "in")    return e.today_status === "clocked_in";
    if (filterStatus === "out")   return e.today_status === "clocked_out";
    if (filterStatus === "leave") return e.employment_status === "On Leave";
    return true;
  });

  return (
    <AppLayout>
      <Head title="HR Overview" />
      <PageHeader eyebrow="HRMS" title="HR Overview" description="See who's in, out, or on leave — refreshed on every page load." />
      {kpiModal && <HrmsKpiModal kpi={kpiModal} onClose={() => setKpiModal(null)} />}

      <div className="px-8 py-6 space-y-6">

        {/* Availability quick-stats — visible to everyone */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <button onClick={() => setFilterStatus("all")}
            className={`rounded-xl border bg-card p-4 text-left transition-all hover:shadow-md hover:border-gold/40 ${filterStatus === "all" ? "border-gold/60" : "border-border"}`}>
            <div className="flex items-center gap-2 mb-2"><Users className="h-5 w-5 text-gold" /><span className="text-xs text-muted-foreground">Total Staff</span></div>
            <div className="text-2xl font-bold text-gold">{loading ? "—" : employees.length}</div>
            <div className="mt-1 text-xs text-muted-foreground">Show all</div>
          </button>
          <button onClick={() => setFilterStatus("in")}
            className={`rounded-xl border bg-card p-4 text-left transition-all hover:shadow-md hover:border-green-500/40 ${filterStatus === "in" ? "border-green-500/60" : "border-border"}`}>
            <div className="flex items-center gap-2 mb-2"><UserCheck className="h-5 w-5 text-green-500" /><span className="text-xs text-muted-foreground">Clocked In</span></div>
            <div className="text-2xl font-bold text-green-500">{loading ? "—" : clockedIn}</div>
            <div className="mt-1 text-xs text-muted-foreground">At work right now</div>
          </button>
          <button onClick={() => setFilterStatus("leave")}
            className={`rounded-xl border bg-card p-4 text-left transition-all hover:shadow-md hover:border-amber-500/40 ${filterStatus === "leave" ? "border-amber-500/60" : "border-border"}`}>
            <div className="flex items-center gap-2 mb-2"><Calendar className="h-5 w-5 text-amber-500" /><span className="text-xs text-muted-foreground">On Leave</span></div>
            <div className="text-2xl font-bold text-amber-500">{loading ? "—" : onLeaveNow}</div>
            <div className="mt-1 text-xs text-muted-foreground">Do not disturb</div>
          </button>
          {isHrAdmin ? (
            <button onClick={() => setKpiModal(HRMS_KPI_DEFS[3])}
              className="rounded-xl border border-border bg-card p-4 text-left transition-all hover:shadow-md hover:border-blue-400/40">
              <div className="flex items-center gap-2 mb-2"><TrendingUp className="h-5 w-5 text-blue-500" /><span className="text-xs text-muted-foreground">Departments</span></div>
              <div className="text-2xl font-bold text-blue-500">{loading ? "—" : stats.departments}</div>
              <div className="mt-1 text-xs text-muted-foreground">Click to view</div>
            </button>
          ) : (
            <button onClick={() => setFilterStatus("out")}
              className={`rounded-xl border bg-card p-4 text-left transition-all hover:shadow-md hover:border-muted-foreground/40 ${filterStatus === "out" ? "border-muted-foreground/60" : "border-border"}`}>
              <div className="flex items-center gap-2 mb-2"><UserX className="h-5 w-5 text-muted-foreground" /><span className="text-xs text-muted-foreground">Done for Today</span></div>
              <div className="text-2xl font-bold text-muted-foreground">{loading ? "—" : employees.filter(e => e.today_status === "clocked_out").length}</div>
              <div className="mt-1 text-xs text-muted-foreground">Clocked out</div>
            </button>
          )}
        </div>

        {/* People availability table — the main view */}
        <Card className="border-border">
          <CardHeader className="flex flex-row items-center justify-between gap-4 pb-3">
            <CardTitle className="font-display text-base">Team Availability</CardTitle>
            <div className="flex items-center gap-2 ml-auto">
              <div className="relative">
                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                <input
                  value={search} onChange={e => setSearch(e.target.value)}
                  placeholder="Search name, role…"
                  className="h-8 pl-8 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold w-48"
                />
              </div>
              {filterStatus !== "all" && (
                <button onClick={() => setFilterStatus("all")} className="text-xs text-muted-foreground hover:text-foreground underline">Clear filter</button>
              )}
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {loading ? (
              <div className="flex items-center justify-center py-10"><Loader2 className="h-7 w-7 animate-spin text-gold" /></div>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Employee</th>
                    <th className="px-4 py-3 text-left">Designation</th>
                    <th className="px-4 py-3 text-left">Department</th>
                    <th className="px-4 py-3 text-left">Location</th>
                    <th className="px-4 py-3 text-left">Availability</th>
                    {isHrAdmin && <th className="px-4 py-3 text-left"></th>}
                  </tr>
                </thead>
                <tbody>
                  {visible.map((e) => {
                    const onLeave = e.employment_status === "On Leave";
                    const clockedIn = e.today_status === "clocked_in";
                    const clockedOut = e.today_status === "clocked_out";
                    return (
                      <tr key={e.id} className={`border-t border-border hover:bg-muted/30 ${onLeave ? "bg-amber-500/5" : ""}`}>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-3">
                            <div className="relative flex-shrink-0">
                              <div className="h-9 w-9 rounded-full bg-gold/20 flex items-center justify-center text-gold font-semibold text-sm">
                                {e.full_name?.charAt(0) || "?"}
                              </div>
                              {clockedIn && <span className="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-green-500 border-2 border-background" />}
                              {onLeave && <span className="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-amber-400 border-2 border-background" />}
                            </div>
                            <div>
                              <div className="font-medium text-foreground">{e.full_name}</div>
                              <div className="text-xs text-muted-foreground">{e.work_email}</div>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">{e.designation?.title || "—"}</td>
                        <td className="px-4 py-3">
                          <span className={`px-2 py-0.5 rounded border text-xs font-medium ${deptColors[e.department?.name] || "bg-muted text-muted-foreground border-border"}`}>
                            {e.department?.name || "—"}
                          </span>
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">{e.work_location || "—"}</td>
                        <td className="px-4 py-3">
                          {onLeave ? (
                            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-amber-600 bg-amber-500/10 px-2 py-1 rounded-full">
                              <span className="h-1.5 w-1.5 rounded-full bg-amber-400" />On Leave — Do Not Disturb
                            </span>
                          ) : clockedIn ? (
                            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-green-600 bg-green-500/10 px-2 py-1 rounded-full">
                              <span className="h-1.5 w-1.5 rounded-full bg-green-500 animate-pulse" />Clocked In
                            </span>
                          ) : clockedOut ? (
                            <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground bg-muted/50 px-2 py-1 rounded-full">
                              <span className="h-1.5 w-1.5 rounded-full bg-muted-foreground/40" />Done for Today
                            </span>
                          ) : (
                            <span className="text-xs text-muted-foreground/50 px-2 py-1">Not clocked in</span>
                          )}
                        </td>
                        {isHrAdmin && (
                          <td className="px-4 py-3">
                            <button onClick={() => handleResetToday(e)} disabled={resetting === e.id}
                              title="Reset today's clock-in sessions"
                              className="p-1 rounded hover:bg-muted text-muted-foreground hover:text-foreground disabled:opacity-40">
                              {resetting === e.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                            </button>
                          </td>
                        )}
                      </tr>
                    );
                  })}
                  {visible.length === 0 && (
                    <tr><td colSpan={isHrAdmin ? 6 : 5} className="px-4 py-10 text-center text-muted-foreground text-sm">
                      {search || filterStatus !== "all" ? "No employees match this filter." : "No employees found."}
                    </td></tr>
                  )}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>

        {/* Admin-only: dept breakdown + location split */}
        {isHrAdmin && (
          <div className="grid grid-cols-2 gap-6">
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display text-base">Department Breakdown</CardTitle></CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {deptBreakdown.map(({ name, count }) => (
                    <div key={name} className="flex items-center gap-3">
                      <span className={`px-2 py-0.5 rounded border text-xs font-medium ${deptColors[name] || "bg-muted text-muted-foreground border-border"}`}>{name}</span>
                      <div className="flex-1 h-2 bg-muted rounded-full">
                        <div className="h-full bg-gold rounded-full" style={{ width: `${employees.length ? (count / employees.length) * 100 : 0}%` }} />
                      </div>
                      <span className="text-sm font-semibold w-6 text-right">{count}</span>
                    </div>
                  ))}
                  {deptBreakdown.length === 0 && <p className="text-sm text-muted-foreground text-center py-4">No department data</p>}
                </div>
              </CardContent>
            </Card>
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display text-base">Work Location Split</CardTitle></CardHeader>
              <CardContent>
                <div className="space-y-3">
                  {locationBreakdown.map(({ name, count }) => (
                    <div key={name} className="flex items-center justify-between">
                      <span className="text-sm font-medium">{name}</span>
                      <div className="flex items-center gap-3">
                        <div className="w-32 h-2 bg-muted rounded-full">
                          <div className="h-full bg-blue-500 rounded-full" style={{ width: `${employees.length ? (count / employees.length) * 100 : 0}%` }} />
                        </div>
                        <span className="text-sm font-semibold w-6 text-right">{count}</span>
                      </div>
                    </div>
                  ))}
                  {locationBreakdown.length === 0 && <p className="text-sm text-muted-foreground text-center py-4">No location data</p>}
                </div>
              </CardContent>
            </Card>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
