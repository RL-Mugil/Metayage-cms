import { Head } from "@inertiajs/react";
import { useState, useEffect } from "react";
import { Users, Clock, Calendar, TrendingUp, UserCheck, UserX, Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";

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

  useEffect(() => {
    api.getEmployees().then((data) => {
      setEmployees(Array.isArray(data) ? data : []);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, []);

  const active = employees.filter((e) => e.employment_status === "Active").length;
  const onLeave = employees.filter((e) => e.employment_status === "On Leave").length;
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
      <div className="px-8 py-6 space-y-6">

        {/* Stats */}
        <div className="grid grid-cols-4 gap-4">
          {[
            { label: "Total Headcount", value: employees.length, icon: Users, color: "text-gold" },
            { label: "Active Employees", value: active, icon: UserCheck, color: "text-green-500" },
            { label: "On Leave", value: onLeave, icon: Calendar, color: "text-amber-500" },
            { label: "Departments", value: departments.length, icon: TrendingUp, color: "text-blue-500" },
          ].map(({ label, value, icon: Icon, color }) => (
            <Card key={label} className="border-border">
              <CardContent className="p-4 flex items-center gap-3">
                <Icon className={`h-8 w-8 ${color}`} />
                <div>
                  <div className="text-2xl font-bold">{loading ? "—" : value}</div>
                  <div className="text-xs text-muted-foreground">{label}</div>
                </div>
              </CardContent>
            </Card>
          ))}
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
