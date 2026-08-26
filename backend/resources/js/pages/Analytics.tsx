import { Head } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import { TrendingUp, BarChart3, Loader2, AlertTriangle, CheckCircle2 } from "lucide-react";
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

const PIE_COLORS = ["#C8971D", "#3b82f6", "#22c55e", "#a855f7"];

function formatCurrency(value: number) {
  if (value >= 100000) return `Rs ${ (value / 100000).toFixed(1) }L`;
  return `Rs ${ value.toLocaleString() }`;
}

function monthKey(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

function monthLabel(date: Date) {
  return date.toLocaleString("en-IN", { month: "short" });
}

function startOfWeek(date: Date) {
  const value = new Date(date);
  const day = value.getDay();
  const diff = day === 0 ? -6 : 1 - day;
  value.setDate(value.getDate() + diff);
  value.setHours(0, 0, 0, 0);
  return value;
}

function weekKey(date: Date) {
  return startOfWeek(date).toISOString().slice(0, 10);
}

function weekLabel(date: Date) {
  return `Wk ${date.toLocaleString("en-IN", { day: "2-digit", month: "short" })}`;
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
  if (!active || !payload || !payload.length) return null;

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
};

const PAYMENT_COLORS: Record<string, string> = {
  Paid: "#22c55e",
  Partial: "#f59e0b",
  Pending: "#ef4444",
  "Not Set": "#6b7280",
};

export default function Analytics() {
  const [projects, setProjects] = useState<any[]>([]);
  const [tasks, setTasks] = useState<any[]>([]);
  const [invoices, setInvoices] = useState<any[]>([]);
  const [clients, setClients] = useState<any[]>([]);
  const [tracker, setTracker] = useState<any>(null);
  const [zohoMonthly, setZohoMonthly] = useState<{ month: string; total: number }[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Each source is independent — roles without financial/tracker access
    // (e.g. Patent Analyst) get 403s that must NOT blank the whole page.
    Promise.all([
      api.getProjects().catch(() => []),
      api.getTasks().catch(() => []),
      api.getInvoices().catch(() => []),
      api.getClients().catch(() => []),
      api.getTrackerAnalytics().catch(() => null),
      api.getZohoMonthlyAnalytics().catch(() => []),
    ])
      .then(([p, t, i, c, tr, zm]) => {
        setZohoMonthly(Array.isArray(zm) ? zm : []);
        setProjects(Array.isArray(p) ? p : (p as any).data || []);
        setTasks(Array.isArray(t) ? t : (t as any).data || []);
        setInvoices(Array.isArray(i) ? i : (i as any).data || []);
        setClients(Array.isArray(c) ? c : (c as any).data || []);
        setTracker(tr);
      })
      .finally(() => setLoading(false));
  }, []);

  const now = new Date();
  const activeMatters = projects.filter((p) => ["Open", "In Progress", "Active"].includes(p.status)).length;
  const completedTasks = tasks.filter((t) => t.status === "Completed" || t.status === "Done").length;

  const revenueMTD = invoices
    .filter((invoice) => {
      const rawDate = invoice.issue_date || invoice.invoice_date || invoice.created_at;
      if (!rawDate) return false;
      const date = new Date(rawDate);
      return date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth();
    })
    .reduce((sum, invoice) => sum + Number(invoice.total_amount || 0), 0);

  const monthlyRevenue = useMemo(() => {
    const months: { key: string; month: string; revenue: number; zoho_collected: number }[] = [];
    for (let i = 11; i >= 0; i -= 1) {
      const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
      months.push({ key: monthKey(date), month: monthLabel(date), revenue: 0, zoho_collected: 0 });
    }

    const byMonth = new Map(months.map((entry) => [entry.key, entry]));
    invoices.forEach((invoice) => {
      const rawDate = invoice.issue_date || invoice.invoice_date || invoice.created_at;
      if (!rawDate) return;
      const date = new Date(rawDate);
      if (Number.isNaN(date.getTime())) return;
      const bucket = byMonth.get(monthKey(date));
      if (bucket) bucket.revenue += Number(invoice.total_amount || 0);
    });
    zohoMonthly.forEach((entry) => {
      const bucket = byMonth.get(entry.month);
      if (bucket) bucket.zoho_collected += Number(entry.total || 0);
    });

    return months;
  }, [invoices, zohoMonthly, now]);

  const matterDistribution = useMemo(() => {
    const counts = { Patents: 0, Trademarks: 0, Copyrights: 0, Other: 0 };
    projects.forEach((project) => {
      const value = String(project.project_type || "").toLowerCase();
      if (value.includes("patent")) counts.Patents += 1;
      else if (value.includes("trademark")) counts.Trademarks += 1;
      else if (value.includes("copyright")) counts.Copyrights += 1;
      else counts.Other += 1;
    });

    return Object.entries(counts)
      .map(([name, value]) => ({ name, value }))
      .filter((entry) => entry.value > 0);
  }, [projects]);

  const taskVelocity = useMemo(() => {
    const weeks: { key: string; week: string; created: number; completed: number }[] = [];
    for (let i = 11; i >= 0; i -= 1) {
      const date = startOfWeek(new Date(now.getFullYear(), now.getMonth(), now.getDate() - i * 7));
      weeks.push({ key: weekKey(date), week: weekLabel(date), created: 0, completed: 0 });
    }

    const byWeek = new Map(weeks.map((entry) => [entry.key, entry]));
    tasks.forEach((task) => {
      const createdAt = task.created_at ? new Date(task.created_at) : null;
      if (createdAt && !Number.isNaN(createdAt.getTime())) {
        const createdBucket = byWeek.get(weekKey(createdAt));
        if (createdBucket) createdBucket.created += 1;
      }

      if (task.status === "Completed" || task.status === "Done") {
        const completedAt = task.updated_at ? new Date(task.updated_at) : null;
        if (completedAt && !Number.isNaN(completedAt.getTime())) {
          const completedBucket = byWeek.get(weekKey(completedAt));
          if (completedBucket) completedBucket.completed += 1;
        }
      }
    });

    return weeks;
  }, [tasks, now]);

  const topClients = useMemo(() => {
    const matterCountByClient = new Map<number, number>();
    projects.forEach((project) => {
      if (!project.client_id) return;
      matterCountByClient.set(project.client_id, (matterCountByClient.get(project.client_id) || 0) + 1);
    });

    return [...clients]
      .map((client) => ({ ...client, matterCount: matterCountByClient.get(client.id) || 0 }))
      .sort((a, b) => b.matterCount - a.matterCount)
      .slice(0, 10);
  }, [clients, projects]);

  if (loading) {
    return (
      <AppLayout>
        <Head title="Analytics" />
        <div className="flex h-screen items-center justify-center">
          <div className="flex flex-col items-center gap-2">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
            <p className="text-sm text-muted-foreground">Loading analytics...</p>
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
            value={revenueMTD > 0 ? formatCurrency(revenueMTD) : "Rs 0"}
            delta="Current month invoice totals"
            icon={TrendingUp}
          />
          <KPICard
            label="Tasks Completed"
            value={completedTasks.toString()}
            delta={`${tasks.length} tasks total`}
            icon={CheckCircle2}
          />
        </div>

        <div className="flex gap-6">
          <Card className="border-border" style={{ flex: "0 0 60%" }}>
            <CardHeader>
              <CardTitle className="font-display text-base">Monthly Billing</CardTitle>
              <p className="text-xs text-muted-foreground">Live invoice totals for the last 12 months</p>
            </CardHeader>
            <CardContent>
              <ResponsiveContainer width="100%" height={300}>
                <BarChart data={monthlyRevenue} margin={{ top: 4, right: 8, left: 8, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
                  <XAxis dataKey="month" tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} />
                  <YAxis tickFormatter={(value) => `Rs ${Math.round(value / 1000)}k`} tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} />
                  <Tooltip content={<CustomTooltip />} />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Bar dataKey="revenue" name="Revenue" fill="#C8971D" radius={[3, 3, 0, 0]} />
                  <Bar dataKey="zoho_collected" name="Zoho Collected" fill="#3b82f6" radius={[3, 3, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>

          <Card className="border-border flex-1">
            <CardHeader>
              <CardTitle className="font-display text-base">Case Distribution</CardTitle>
              <p className="text-xs text-muted-foreground">By live project type mix</p>
            </CardHeader>
            <CardContent>
              {matterDistribution.length === 0 ? (
                <div className="flex h-[300px] items-center justify-center text-sm text-muted-foreground">
                  No project data available
                </div>
              ) : (
                <>
                  <ResponsiveContainer width="100%" height={300}>
                    <PieChart>
                      <Pie
                        data={matterDistribution}
                        cx="50%"
                        cy="50%"
                        innerRadius={60}
                        outerRadius={100}
                        paddingAngle={3}
                        dataKey="value"
                        label={({ name, value }) => `${name}: ${value}`}
                        labelLine={false}
                      >
                        {matterDistribution.map((_, index) => (
                          <Cell key={`cell-${index}`} fill={PIE_COLORS[index % PIE_COLORS.length]} />
                        ))}
                      </Pie>
                      <Tooltip formatter={(value: any) => [value, "Count"]} />
                    </PieChart>
                  </ResponsiveContainer>
                  <div className="flex flex-wrap justify-center gap-3 mt-2">
                    {matterDistribution.map((item, index) => (
                      <div key={item.name} className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <span className="h-2.5 w-2.5 rounded-full flex-shrink-0" style={{ background: PIE_COLORS[index % PIE_COLORS.length] }} />
                        {item.name}
                      </div>
                    ))}
                  </div>
                </>
              )}
            </CardContent>
          </Card>
        </div>

        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display text-base">Task Velocity</CardTitle>
            <p className="text-xs text-muted-foreground">Tasks created versus tasks marked completed over the last 12 weeks</p>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={260}>
              <LineChart data={taskVelocity} margin={{ top: 4, right: 8, left: 8, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="hsl(var(--border))" />
                <XAxis dataKey="week" tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} />
                <YAxis tick={{ fontSize: 11, fill: "hsl(var(--muted-foreground))" }} />
                <Tooltip content={<CustomTooltip />} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <Line type="monotone" dataKey="completed" name="Completed" stroke="#C8971D" strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} />
                <Line type="monotone" dataKey="created" name="Created" stroke="#3b82f6" strokeWidth={2} dot={{ r: 3 }} activeDot={{ r: 5 }} strokeDasharray="4 2" />
              </LineChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        {tracker && (
          <div className="flex gap-6">
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
                    <Bar dataKey="PCM" name="PCM" stackId="a" fill="#C8971D" />
                    <Bar dataKey="SCM" name="SCM" stackId="a" fill="#3b82f6" />
                    <Bar dataKey="PR" name="PR" stackId="a" fill="#22c55e" radius={[0, 3, 3, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </CardContent>
            </Card>

            <Card className="border-border" style={{ flex: "0 0 280px" }}>
              <CardHeader>
                <CardTitle className="font-display text-base">Payment Status</CardTitle>
                <p className="text-xs text-muted-foreground">Across all tracker cases</p>
              </CardHeader>
              <CardContent>
                <ResponsiveContainer width="100%" height={200}>
                  <PieChart>
                    <Pie
                      data={(tracker.payment ?? []).filter((item: any) => item.value > 0)}
                      cx="50%"
                      cy="50%"
                      innerRadius={50}
                      outerRadius={80}
                      paddingAngle={3}
                      dataKey="value"
                      nameKey="label"
                      label={({ label, value }) => `${label}: ${value}`}
                      labelLine={false}
                    >
                      {(tracker.payment ?? []).map((item: any) => (
                        <Cell key={item.label} fill={PAYMENT_COLORS[item.label] ?? "#6b7280"} />
                      ))}
                    </Pie>
                    <Tooltip formatter={(value: any, name: any) => [value, name]} />
                  </PieChart>
                </ResponsiveContainer>
                <div className="flex flex-wrap justify-center gap-3 mt-1">
                  {(tracker.payment ?? []).filter((item: any) => item.value > 0).map((item: any) => (
                    <div key={item.label} className="flex items-center gap-1.5 text-xs text-muted-foreground">
                      <span className="h-2.5 w-2.5 rounded-full" style={{ background: PAYMENT_COLORS[item.label] ?? "#6b7280" }} />
                      {item.label} ({item.value})
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          </div>
        )}

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
                    {(tracker.by_status ?? []).map((_: any, index: number) => (
                      <Cell key={index} fill={index % 2 === 0 ? "#C8971D" : "#b8860b"} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            </CardContent>
          </Card>
        )}

        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display text-base">Top Clients by Case Count</CardTitle>
            <p className="text-xs text-muted-foreground">Active relationships sorted by portfolio size</p>
          </CardHeader>
          <CardContent className="p-0">
            {topClients.length === 0 ? (
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
                  {topClients.map((client) => (
                    <tr key={client.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3 font-medium">{client.company_name || client.legal_name || "Untitled Client"}</td>
                      <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{client.client_code}</td>
                      <td className="px-4 py-3 text-muted-foreground">{client.industry || "—"}</td>
                      <td className="px-4 py-3">
                        <Badge variant={statusColor(client.status)}>{client.status}</Badge>
                      </td>
                      <td className="px-4 py-3">
                        <span className="inline-flex items-center justify-center h-6 min-w-6 rounded-full bg-gold/10 text-gold text-xs font-semibold px-2">
                          {client.matterCount}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <Badge variant="outline">{client.gst_type || "—"}</Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
