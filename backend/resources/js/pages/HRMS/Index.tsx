import { Head } from "@inertiajs/react";
import { useState, useEffect, useCallback } from "react";
import { Users, Clock, Calendar, TrendingUp, UserCheck, UserX, Loader2, X, Search, ChevronLeft, ChevronRight } from "lucide-react";
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

export default function HRMSIndex() {
  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [stats, setStats] = useState({ total: 0, active: 0, on_leave: 0, departments: 0 });
  const [kpiModal, setKpiModal] = useState<HrmsKpiDef | null>(null);

  useEffect(() => {
    api.getHRMSStats().then(setStats).catch(() => {});
    api.getEmployees().then((data) => {
      setEmployees(Array.isArray(data) ? data : []);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, []);

  const departments = [...new Set(employees.map((e) => e.department?.name).filter(Boolean))];

  const deptBreakdown = departments.map((dept) => ({
    name: dept,
    count: employees.filter((e) => e.department?.name === dept).length,
  }));

  const locationBreakdown = [...new Set(employees.map((e) => e.work_location).filter(Boolean))]
    .map((loc) => ({
      name: loc as string,
      count: employees.filter((e) => e.work_location === loc).length,
    }))
    .sort((a, b) => b.count - a.count);

  return (
    <AppLayout>
      <Head title="HR Overview" />
      <PageHeader eyebrow="HRMS" title="HR Overview" description="Headcount, department summary, and workforce snapshot" />
      {kpiModal && <HrmsKpiModal kpi={kpiModal} onClose={() => setKpiModal(null)} />}

      <div className="px-8 py-6 space-y-6">

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          {HRMS_KPI_DEFS.map((kpi) => {
            const Icon = kpi.icon;
            const val  = stats[kpi.key as keyof typeof stats] ?? 0;
            const canDrill = kpi.statusFilter !== null || kpi.key === "total";
            return (
              <button key={kpi.key}
                onClick={canDrill ? () => setKpiModal(kpi) : undefined}
                className={`rounded-xl border border-border bg-card p-4 text-left transition-all ${canDrill ? "hover:shadow-md hover:border-gold/40 cursor-pointer" : "cursor-default"}`}
              >
                <div className="flex items-center gap-2 mb-2">
                  <Icon className={`h-5 w-5 ${kpi.color}`} />
                  <span className="text-xs text-muted-foreground">{kpi.label}</span>
                </div>
                <div className={`text-2xl font-bold ${kpi.color}`}>{loading ? "—" : val}</div>
                {canDrill && <div className="mt-1 text-xs text-muted-foreground">Click to view</div>}
              </button>
            );
          })}
        </div>

        <div className="grid grid-cols-2 gap-6">
          {/* Department breakdown */}
          <Card className="border-border">
            <CardHeader><CardTitle className="font-display text-base">Department Breakdown</CardTitle></CardHeader>
            <CardContent>
              {loading ? (
                <div className="flex items-center justify-center py-8"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
              ) : (
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
              )}
            </CardContent>
          </Card>

          {/* Location split */}
          <Card className="border-border">
            <CardHeader><CardTitle className="font-display text-base">Work Location Split</CardTitle></CardHeader>
            <CardContent>
              {loading ? (
                <div className="flex items-center justify-center py-8"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
              ) : (
                <div className="space-y-3">
                  {locationBreakdown.map(({ name, count }) => (
                    <div key={name} className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">{name}</span>
                      </div>
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
              )}
            </CardContent>
          </Card>
        </div>

        {/* Recent employees */}
        <Card className="border-border">
          <CardHeader><CardTitle className="font-display text-base">All Employees</CardTitle></CardHeader>
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
                    <th className="px-4 py-3 text-left">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {employees.map((e) => (
                    <tr key={e.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-3">
                          <div className="h-8 w-8 rounded-full bg-gold/20 flex items-center justify-center text-gold font-semibold text-xs flex-shrink-0">
                            {e.full_name?.charAt(0) || "?"}
                          </div>
                          <div>
                            <div className="font-medium">{e.full_name}</div>
                            <div className="text-xs text-muted-foreground">{e.work_email}</div>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">{e.designation?.title || "—"}</td>
                      <td className="px-4 py-3">
                        <span className={`px-2 py-0.5 rounded border text-xs font-medium ${deptColors[e.department?.name] || "bg-muted text-muted-foreground border-border"}`}>
                          {e.department?.name || "—"}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">{e.work_location || "—"}</td>
                      <td className="px-4 py-3">
                        <Badge variant="outline" className={e.employment_status === "Active" ? "text-green-600 border-green-200 bg-green-50" : "text-amber-600 border-amber-200 bg-amber-50"}>
                          {e.employment_status || "Active"}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                  {employees.length === 0 && (
                    <tr>
                      <td colSpan={5} className="px-4 py-10 text-center text-muted-foreground text-sm">No employees found</td>
                    </tr>
                  )}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
