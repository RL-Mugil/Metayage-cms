import { createFileRoute, Link } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Briefcase, Users, Wallet, Clock, ArrowUpRight, TrendingUp, Loader2 } from "lucide-react";
import { statusColor } from "@/lib/utils";

export const Route = createFileRoute("/")({
  head: () => ({ meta: [{ title: "Dashboard — IPFlow" }] }),
  component: Dashboard,
});

import { useEffect, useState } from "react";
import { api, User } from "@/lib/api-client";

function Dashboard() {
  const [user, setUser] = useState<User | null>(null);
  const [metrics, setMetrics] = useState<any>(null);
  const [liveProjects, setLiveProjects] = useState<any[]>([]);
  const [liveTasks, setLiveTasks] = useState<any[]>([]);
  const [liveInvoices, setLiveInvoices] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setUser(api.getUser());

    Promise.all([
      api.getDashboardMetrics(),
      api.getProjects(),
      api.getTasks(),
      api.getInvoices(),
    ])
      .then(([metricsData, projs, tsks, invs]) => {
        setMetrics(metricsData.metrics);
        setLiveProjects(projs);
        setLiveTasks(tsks);
        setLiveInvoices(invs);
      })
      .catch((err) => console.error("Error loading dashboard data:", err))
      .finally(() => setLoading(false));
  }, []);

  const formatCurrency = (val: number) => {
    if (val >= 100000) {
      return `₹ ${(val / 100000).toFixed(1)}L`;
    }
    return `₹ ${val.toLocaleString()}`;
  };

  if (loading || !metrics) {
    return (
      <div className="flex h-screen items-center justify-center bg-background">
        <div className="flex flex-col items-center gap-2">
          <Loader2 className="h-8 w-8 animate-spin text-gold" />
          <p className="text-sm text-muted-foreground">Loading workspace metrics...</p>
        </div>
      </div>
    );
  }

  const welcomeName = user ? user.name.split(" ")[0] : "User";
  const wipDisplay = formatCurrency(metrics.wip_balance);
  const revenueDisplay = formatCurrency(metrics.received_payments);

  return (
    <div>
      <PageHeader
        eyebrow="Overview"
        title={`Good morning, ${welcomeName}`}
        description={`${metrics.active_matters} matters need attention today across ${metrics.clients} clients. WIP balance is ${wipDisplay}.`}
        actions={
          <>
            <Button variant="outline">Export PDF</Button>
            <Button>New Matter</Button>
          </>
        }
      />

      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <StatCard label="Active Matters" value={metrics.active_matters.toString()} delta="+2 this month" trend="up" icon={Briefcase} accent="primary" />
          <StatCard label="Active Clients" value={metrics.clients.toString()} delta="+1 this month" trend="up" icon={Users} accent="gold" />
          <StatCard label="WIP (unbilled)" value={wipDisplay} delta="-3.1% vs last week" trend="down" icon={Clock} accent="info" />
          <StatCard label="MTD Revenue" value={revenueDisplay} delta="+12.6% YoY" trend="up" icon={Wallet} accent="success" />
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="lg:col-span-2 border-border">
            <CardHeader className="flex flex-row items-center justify-between">
              <div>
                <CardTitle className="font-display">Matters needing attention</CardTitle>
                <p className="text-xs text-muted-foreground mt-1">Sorted by deadline proximity & priority</p>
              </div>
              <Button asChild variant="ghost" size="sm"><Link to="/projects">View all <ArrowUpRight className="ml-1 h-3 w-3" /></Link></Button>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-2 text-left">Matter</th>
                    <th className="px-4 py-2 text-left">Client</th>
                    <th className="px-4 py-2 text-left">Stage</th>
                    <th className="px-4 py-2 text-left">Priority</th>
                    <th className="px-4 py-2 text-left">Due</th>
                  </tr>
                </thead>
                <tbody>
                  {liveProjects.slice(0, 5).map((p) => {
                    const activeStage = p.stages?.find((s: any) => s.status === 'In Progress')?.stage_name || 'Intake';
                    return (
                      <tr key={p.id} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3">
                          <div className="font-medium text-foreground">{p.project_name}</div>
                          <div className="text-xs text-muted-foreground font-mono">{p.project_code}</div>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">{p.client?.company_name}</td>
                        <td className="px-4 py-3"><Badge variant="secondary">{activeStage}</Badge></td>
                        <td className="px-4 py-3"><Badge variant={p.urgency === "High" ? "destructive" : "outline"}>{p.urgency}</Badge></td>
                        <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{p.hard_deadline || "No deadline"}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </CardContent>
          </Card>

          <Card className="border-border">
            <CardHeader>
              <CardTitle className="font-display">My tasks</CardTitle>
              <p className="text-xs text-muted-foreground mt-1">{liveTasks.length} open tasks</p>
            </CardHeader>
            <CardContent className="space-y-3">
              {liveTasks.slice(0, 5).map((t) => (
                <div key={t.id} className="flex items-start gap-3 rounded-md border border-border p-3 hover:bg-muted/30">
                  <div className="mt-1 h-2 w-2 rounded-full bg-gold" />
                  <div className="flex-1 min-w-0">
                    <div className="text-sm font-medium truncate">{t.title}</div>
                    <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                      <span className="font-mono">{t.project?.project_code}</span>
                      <span>·</span>
                      <span>{t.due_date ? new Date(t.due_date).toLocaleDateString() : "No due date"}</span>
                    </div>
                  </div>
                  <Badge variant={statusColor(t.status)} className="text-[10px]">{t.status}</Badge>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="border-border">
            <CardHeader>
              <CardTitle className="font-display flex items-center gap-2">
                <TrendingUp className="h-4 w-4 text-gold" /> Realization rate
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="font-display text-4xl font-semibold">{metrics.realization_rate}%</div>
              <p className="text-xs text-muted-foreground mt-2">Billed vs. worked, 30-day rolling</p>
              <div className="mt-4 h-2 w-full rounded-full bg-muted overflow-hidden">
                <div className="h-full rounded-full bg-gradient-to-r from-primary to-gold" style={{ width: `${metrics.realization_rate}%` }} />
              </div>
            </CardContent>
          </Card>

          <Card className="lg:col-span-2 border-border">
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="font-display">Recent invoices</CardTitle>
              <Button asChild variant="ghost" size="sm"><Link to="/financial">View all <ArrowUpRight className="ml-1 h-3 w-3" /></Link></Button>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <tbody>
                  {liveInvoices.slice(0, 4).map((i) => (
                    <tr key={i.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3 font-mono text-xs">{i.invoice_code}</td>
                      <td className="px-4 py-3">{i.client?.company_name}</td>
                      <td className="px-4 py-3 font-medium">{formatCurrency(parseFloat(i.total_amount))}</td>
                      <td className="px-4 py-3"><Badge variant={statusColor(i.status)}>{i.status}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
