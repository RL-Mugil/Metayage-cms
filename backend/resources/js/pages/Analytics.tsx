import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { TrendingUp, BarChart3, Target, Loader2, AlertTriangle, CheckCircle2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  Legend,
} from "recharts";

const MONTHLY_REVENUE = [
  { month: "Jan", revenue: 45000, target: 50000 },
  { month: "Feb", revenue: 52000, target: 50000 },
  { month: "Mar", revenue: 48000, target: 55000 },
  { month: "Apr", revenue: 61000, target: 55000 },
  { month: "May", revenue: 57000, target: 60000 },
  { month: "Jun", revenue: 63000, target: 60000 },
  { month: "Jul", revenue: 59000, target: 65000 },
  { month: "Aug", revenue: 71000, target: 65000 },
  { month: "Sep", revenue: 68000, target: 70000 },
  { month: "Oct", revenue: 75000, target: 70000 },
  { month: "Nov", revenue: 82000, target: 75000 },
  { month: "Dec", revenue: 78000, target: 80000 },
];

const MATTER_DISTRIBUTION = [
  { name: "Patents", value: 45 },
  { name: "Trademarks", value: 30 },
  { name: "Copyrights", value: 15 },
  { name: "Other", value: 10 },
];

const PIE_COLORS = ["#C8971D", "#3b82f6", "#22c55e", "#a855f7"];

const TASK_VELOCITY = [
  { week: "W1",  completed: 8,  created: 12 },
  { week: "W2",  completed: 11, created: 9  },
  { week: "W3",  completed: 7,  created: 14 },
  { week: "W4",  completed: 13, created: 11 },
  { week: "W5",  completed: 9,  created: 10 },
  { week: "W6",  completed: 15, created: 13 },
  { week: "W7",  completed: 12, created: 8  },
  { week: "W8",  completed: 10, created: 15 },
  { week: "W9",  completed: 14, created: 12 },
  { week: "W10", completed: 16, created: 11 },
  { week: "W11", completed: 11, created: 13 },
  { week: "W12", completed: 18, created: 14 },
];

function formatCurrency(val: number) {
  if (val >= 100000) return `₹${(val / 100000).toFixed(1)}L`;
  return `₹${val.toLocaleString()}`;
}

function KPICard({
  label,
  value,
  delta,
  icon: Icon,
}: {
  label: string;
  value: string;
  delta: string;
  icon: React.ElementType;
}) {
  return (
    <Card className="border-border">
      <CardContent className="p-5">
        <div className="flex items-start justify-between">
          <div>
            <p className="text-xs text-muted-foreground uppercase tracking-wider">{label}</p>
            <p className="mt-1 text-2xl font-semibold font-display">{value}</p>
            <p className="mt-1 text-xs text-muted-foreground">{delta}</p>
          </div>
          <div className="h-9 w-9 rounded-lg bg-gold/10 flex items-center justify-center">
            <Icon className="h-5 w-5 text-gold" />
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

const CustomTooltip = ({ active, payload, label }: any) => {
  if (active && payload && payload.length) {
    return (
      <div className="rounded-lg border border-border bg-background px-3 py-2 text-xs shadow-lg">
        <p className="font-medium mb-1">{label}</p>
        {payload.map((entry: any) => (
          <p key={entry.name} style={{ color: entry.color }}>
            {entry.name}: {typeof entry.value === "number" && entry.name.toLowerCase().includes("revenue")
              ? formatCurrency(entry.value)
              : entry.value}
          </p>
        ))}
      </div>
    );
  }
  return null;
};

const PAYMENT_COLORS: Record<string, string> = {
  Paid: "#22c55e", Partial: "#f59e0b", Pending: "#ef4444", "Not Set": "#6b7280",
};

export default function Analytics() {
  const [projects, setProjects]   = useState<any[]>([]);
  const [tasks, setTasks]         = useState<any[]>([]);
  const [invoices, setInvoices]   = useState<any[]>([]);
  const [clients, setClients]     = useState<any[]>([]);
  const [tracker, setTracker]     = useState<any>(null);
  const [loading, setLoading]     = useState(true);

  useEffect(() => {
    Promise.all([
      api.getProjects(),
      api.getTasks(),
      api.getInvoices(),
      api.getClients(),
      api.getTrackerAnalytics(),
    ])
      .then(([p, t, i, c, tr]) => {
        setProjects(Array.isArray(p) ? p : (p as any).data || []);
        setTasks(Array.isArray(t) ? t : (t as any).data || []);
        setInvoices(Array.isArray(i) ? i : (i as any).data || []);
        setClients(Array.isArray(c) ? c : (c as any).data || []);
        setTracker(tr);
      })
      .catch((err) => console.error("Analytics load error:", err))
      .finally(() => setLoading(false));
  }, []);

  const activeMatters = projects.filter((p) => ["Open", "In Progress", "Active"].includes(p.status)).length;
  const completedTasks = tasks.filter((t) => t.status === "Completed" || t.status === "Done").length;

  const now = new Date();
  const revenueMTD = invoices
    .filter((inv) => {
      if (!inv.created_at && !inv.invoice_date) return false;
      const d = new Date(inv.invoice_date || inv.created_at);
      return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth();
    })
    .reduce((sum, inv) => sum + parseFloat(inv.total_amount || "0"), 0);

  if (loading) {
    return (
      <AppLayout>
        <Head title="Analytics" />
        <div className="flex h-screen items-center justify-center">
          <div className="flex flex-col items-center gap-2">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
            <p className="text-sm text-muted-foreground">Loading analytics…</p>
          </div>
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <Head title="Analytics" />
      <PageHeader
        eyebrow="Insight"
        title="Analytics"
        description="Revenue trends, case velocity, and KPIs across your IP practice"
      />

      <div className="px-8 py-6 space-y-6">
        {/* Row 1 — KPI cards */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <KPICard
            label="Active Cases"
            value={(tracker?.total_cases ?? activeMatters).toString()}
            delta={`${projects.length} in projects system`}
            icon={BarChart3}
          />
          <KPICard
            label="Overdue Cases"
            value={(tracker?.overdue ?? 0).toString()}
            delta={`${tracker?.on_time_rate ?? 100}% on-time rate`}
            icon={AlertTriangle}
          />
          <KPICard
            label="Revenue MTD"
            value={revenueMTD > 0 ? formatCurrency(revenueMTD) : "₹0"}
            delta="Current month invoices"
            icon={TrendingUp}
          />
          <KPICard
            label="Tasks Completed"
            value={completedTasks.toString() || "—"}
            delta={`${tasks.length} tasks total`}
            icon={CheckCircle2}
          />
        </div>

        {/* Row 2 — Revenue bar + case pie */}
        <div className="flex gap-6">
          {/* Monthly Revenue bar chart */}
          <Card className="border-border" style={{ flex: "0 0 60%" }}>
            <CardHeader>
              <CardTitle className="font-display text-base">Monthly Revenue</CardTitle>
              <p className="text-xs text-muted-foreground">Actual vs. target — current fiscal year</p>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart data={MONTHLY_REVENUE} margin={{ top: 4, right: 8, left: 8, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
                  <XAxis dataKey="month" tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} />
                  <YAxis
                    tickFormatter={(v) => `₹${v / 1000}k`}
                    tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }}
                  />
                  <Tooltip content={<CustomTooltip />} />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Bar dataKey="revenue" name="Revenue" fill="#C8971D" radius={[3, 3, 0, 0]} />
                  <Bar dataKey="target" name="Target" fill="hsl(var(--muted-foreground))" radius={[3, 3, 0, 0]} opacity={0.4} />
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          {/* Matter distribution pie chart */}
          <Card className="border-border flex-1">
            <CardHeader>
              <CardTitle className="font-display text-base">Case Distribution</CardTitle>
              <p className="text-xs text-muted-foreground">By IP type</p>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={300}>
                <PieChart>
                  <Pie
                    data={MATTER_DISTRIBUTION}
                    cx="50%"
                    cy="50%"
                    innerRadius={60}
                    outerRadius={100}
                    paddingAngle={3}
                    dataKey="value"
                    label={({ name, value }) => `${name}: ${value}%`}
                    labelLine={false}
                  >
                    {MATTER_DISTRIBUTION.map((_, index) => (
                      <Cell key={`cell-${index}`} fill={PIE_COLORS[index % PIE_COLORS.length]} />
                    ))}
                  </Pie>
                  <Tooltip formatter={(val: any) => [`${val}%`, "Share"]} />
                </PieChart>
              </ResponsiveContainer>
              <div className="flex flex-wrap justify-center gap-3 mt-2">
                {MATTER_DISTRIBUTION.map((item, i) => (
                  <div key={item.name} className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <span className="h-2.5 w-2.5 rounded-full flex-shrink-0" style={{ background: PIE_COLORS[i] }} />
                    {item.name}
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Row 3 — Task velocity line chart */}
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display text-base">Task Velocity</CardTitle>
            <p className="text-xs text-muted-foreground">Tasks created vs. completed over the last 12 weeks</p>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={260}>
              <LineChart data={TASK_VELOCITY} margin={{ top: 4, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
                <XAxis dataKey="week" tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} />
                <YAxis tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} />
                <Tooltip content={<CustomTooltip />} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <Line
                  type="monotone"
                  dataKey="completed"
                  name="Completed"
                  stroke="#C8971D"
                  strokeWidth={2}
                  dot={{ r: 3 }}
                  activeDot={{ r: 5 }}
                />
                <Line
                  type="monotone"
                  dataKey="created"
                  name="Created"
                  stroke="#3b82f6"
                  strokeWidth={2}
                  dot={{ r: 3 }}
                  activeDot={{ r: 5 }}
                  strokeDasharray="4 2"
                />
              </LineChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        {/* Row 3b — Tracker: Workload + Payment status */}
        {tracker && (
          <div className="flex gap-6">
            {/* Team workload */}
            <Card className="border-border flex-1">
              <CardHeader>
                <CardTitle className="font-display text-base">Team Workload (Tracker)</CardTitle>
                <p className="text-xs text-muted-foreground">Cases assigned per team member across PCM / SCM / PR roles</p>
              </CardHeader>
              <CardContent>
                <ResponsiveContainer width="100%" height={260}>
                  <BarChart data={(tracker.workload ?? []).slice(0, 10)} layout="vertical" margin={{ top: 4, right: 20, left: 60, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" horizontal={false} />
                    <XAxis type="number" tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} />
                    <YAxis type="category" dataKey="name" tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} width={56} />
                    <Tooltip content={<CustomTooltip />} />
                    <Legend wrapperStyle={{ fontSize: 11 }} />
                    <Bar dataKey="PCM" name="PCM" stackId="a" fill="#C8971D" radius={[0, 0, 0, 0]} />
                    <Bar dataKey="SCM" name="SCM" stackId="a" fill="#3b82f6" />
                    <Bar dataKey="PR"  name="PR"  stackId="a" fill="#22c55e" radius={[0, 3, 3, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>

            {/* Payment status pie */}
            <Card className="border-border" style={{ flex: "0 0 280px" }}>
              <CardHeader>
                <CardTitle className="font-display text-base">Payment Status</CardTitle>
                <p className="text-xs text-muted-foreground">Across all tracker cases</p>
              </CardHeader>
              <CardContent>
                <ResponsiveContainer width="100%" height={200}>
                  <PieChart>
                    <Pie data={(tracker.payment ?? []).filter((p: any) => p.value > 0)} cx="50%" cy="50%"
                      innerRadius={50} outerRadius={80} paddingAngle={3} dataKey="value" nameKey="label"
                      label={({ label, value }) => `${label}: ${value}`} labelLine={false}>
                      {(tracker.payment ?? []).map((p: any) => (
                        <Cell key={p.label} fill={PAYMENT_COLORS[p.label] ?? "#6b7280"} />
                      ))}
                    </Pie>
                    <Tooltip formatter={(v: any, name: any) => [v, name]} />
                  </PieChart>
                </ResponsiveContainer>
                <div className="flex flex-wrap justify-center gap-3 mt-1">
                  {(tracker.payment ?? []).filter((p: any) => p.value > 0).map((p: any) => (
                    <div key={p.label} className="flex items-center gap-1.5 text-xs text-muted-foreground">
                      <span className="h-2.5 w-2.5 rounded-full" style={{ background: PAYMENT_COLORS[p.label] ?? "#6b7280" }} />
                      {p.label} ({p.value})
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>
        )}

        {/* Row 3c — Status breakdown horizontal bar */}
        {tracker && (tracker.by_status ?? []).length > 0 && (
          <Card className="border-border">
            <CardHeader>
              <CardTitle className="font-display text-base">Case Status Breakdown</CardTitle>
              <p className="text-xs text-muted-foreground">Current workflow stages across all tracker cases</p>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={Math.max(200, (tracker.by_status ?? []).length * 32)}>
                <BarChart data={tracker.by_status ?? []} layout="vertical" margin={{ top: 4, right: 40, left: 160, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" horizontal={false} />
                  <XAxis type="number" allowDecimals={false} tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} />
                  <YAxis type="category" dataKey="status" tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} width={156} />
                  <Tooltip />
                  <Bar dataKey="count" name="Cases" fill="#C8971D" radius={[0, 3, 3, 0]}>
                    {tracker.by_status.map((_: any, i: number) => (
                      <Cell key={i} fill={i % 2 === 0 ? "#C8971D" : "#b8860b"} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        )}

        {/* Row 4 — Top clients table */}
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display text-base">Top Clients by Case Count</CardTitle>
            <p className="text-xs text-muted-foreground">Active relationships sorted by portfolio size</p>
          </CardHeader>
          <CardContent className="p-0">
            {clients.length === 0 ? (
              <div className="flex items-center justify-center py-10 text-sm text-muted-foreground">
                No client data available
              </div>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Client</th>
                    <th className="px-4 py-3 text-left">Code</th>
                    <th className="px-4 py-3 text-left">Industry</th>
                    <th className="px-4 py-3 text-left">Status</th>
                    <th className="px-4 py-3 text-left">Cases</th>
                    <th className="px-4 py-3 text-left">GST Type</th>
                  </tr>
                </thead>
                <tbody>
                  {clients.slice(0, 10).map((c) => {
                    const matterCount = projects.filter((p) => p.client_id === c.id).length;
                    return (
                      <tr key={c.id} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3 font-medium">{c.company_name}</td>
                        <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{c.client_code}</td>
                        <td className="px-4 py-3 text-muted-foreground">{c.industry || "—"}</td>
                        <td className="px-4 py-3">
                          <Badge variant={statusColor(c.status)}>{c.status}</Badge>
                        </td>
                        <td className="px-4 py-3">
                          <span className="inline-flex items-center justify-center h-6 min-w-6 rounded-full bg-gold/10 text-gold text-xs font-semibold px-2">
                            {matterCount}
                          </span>
                        </td>
                        <td className="px-4 py-3">
                          <Badge variant="outline">{c.gst_type || "—"}</Badge>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
