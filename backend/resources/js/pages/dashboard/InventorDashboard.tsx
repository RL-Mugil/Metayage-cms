import { Head, Link } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, ArrowUpRight, Clock } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";

interface ActionItem {
  id: number;
  docket_number: string;
  project_name?: string | null;
  pending_action: string;
  current_stage?: string | null;
  hard_deadline?: string | null;
}

/**
 * Inventor login's dashboard — every case they're inventor-of-record on
 * (project_inventors pivot, across drafting/provisional/complete filing/FER
 * response/office action — not just the disclosure-only scope IDPD covers
 * today), spanning potentially multiple different clients. Sourced from
 * DashboardController::inventorOnlyMetrics() (ActionItemService::inventorActionFeed()).
 */
export function InventorDashboard() {
  const [metrics, setMetrics] = useState<Record<string, any> | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.getDashboardMetrics()
      .then((m: any) => setMetrics(m?.metrics ?? {}))
      .catch(() => setMetrics({}))
      .finally(() => setLoading(false));
  }, []);

  if (loading || !metrics) {
    return (
      <AppLayout>
        <Head title="Dashboard" />
        <div className="flex h-screen items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-gold" />
        </div>
      </AppLayout>
    );
  }

  const items: ActionItem[] = metrics.action_items ?? [];

  return (
    <AppLayout>
      <Head title="Dashboard" />
      <PageHeader
        eyebrow="Overview"
        title="Your Inventions"
        description={`${items.length} case${items.length === 1 ? "" : "s"} you're listed as inventor on`}
      />

      <div className="px-8 py-6">
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display">Cases</CardTitle>
            <p className="text-xs text-muted-foreground mt-1">Drafting, provisional, complete filing, FER response, and office action — everything you're inventor on.</p>
          </CardHeader>
          <CardContent className="space-y-2">
            {items.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-10">No cases are linked to you as inventor yet.</p>
            ) : (
              items.map((item) => {
                const overdue = item.hard_deadline && new Date(item.hard_deadline) < new Date();
                return (
                  <Link key={item.id} href={`/projects/${item.id}`}
                    className="flex items-center justify-between gap-4 rounded-md border border-border px-4 py-3 hover:bg-muted/30 transition-colors">
                    <div className="min-w-0">
                      <span className="font-mono text-xs font-semibold text-gold">{item.docket_number}</span>
                      {item.project_name && <div className="mt-0.5 text-sm font-medium truncate">{item.project_name}</div>}
                      {item.current_stage && <Badge variant="secondary" className="mt-1 text-[10px]">{item.current_stage}</Badge>}
                      <div className="mt-1 text-xs text-muted-foreground">{item.pending_action}</div>
                    </div>
                    <div className="flex flex-shrink-0 items-center gap-3">
                      {item.hard_deadline && (
                        <span className={`flex items-center gap-1 text-xs font-mono ${overdue ? "text-destructive font-semibold" : "text-muted-foreground"}`}>
                          <Clock className="h-3 w-3" />{fmtDate(item.hard_deadline)}
                        </span>
                      )}
                      <ArrowUpRight className="h-4 w-4 text-muted-foreground" />
                    </div>
                  </Link>
                );
              })
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
