import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { fmtDate } from "@/lib/date-utils";

interface Props {
  projectId: number;
}

export default function ProjectShow({ projectId }: Props) {
  const [project, setProject] = useState<any>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.getProject(projectId).then((data) => {
      setProject(data);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, [projectId]);

  if (loading) {
    return (
      <AppLayout>
        <Head title="Case" />
        <div className="flex items-center justify-center h-screen">
          <Loader2 className="h-8 w-8 animate-spin text-gold" />
        </div>
      </AppLayout>
    );
  }

  const stages = ["Intake", "Drafting", "Filing", "Examination", "Opposition", "Registered", "Renewal"];

  return (
    <AppLayout>
      <Head title={project?.project_name || "Case"} />
      <PageHeader
        eyebrow="Practice"
        title={project?.project_name || "Case"}
        description={`${project?.project_code} · ${project?.project_type}`}
        actions={<Badge variant={statusColor(project?.status)}>{project?.status}</Badge>}
      />
      <div className="px-8 py-6 space-y-6">
        <Card className="border-border">
          <CardHeader><CardTitle className="font-display">Pipeline Stages</CardTitle></CardHeader>
          <CardContent>
            <div className="flex gap-1 overflow-x-auto">
              {(project?.stages || stages.map((s: string, i: number) => ({ stage_name: s, status: "Pending", sequence_order: i }))).map((s: any) => (
                <div
                  key={s.stage_name}
                  className={`flex-1 min-w-24 rounded-md p-3 text-center text-xs font-medium border ${
                    s.status === "Completed"
                      ? "bg-success/10 border-success/30 text-success"
                      : s.status === "In Progress"
                      ? "bg-gold/10 border-gold/30 text-gold"
                      : "bg-muted border-border text-muted-foreground"
                  }`}
                >
                  {s.stage_name}
                </div>
              ))}
            </div>
          </CardContent>
        </Card>

        <div className="grid gap-6 lg:grid-cols-2">
          <Card className="border-border">
            <CardHeader><CardTitle className="font-display">Case Details</CardTitle></CardHeader>
            <CardContent className="space-y-3 text-sm">
              <div className="flex justify-between"><span className="text-muted-foreground">Client</span><span>{project?.client?.company_name}</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Partner</span><span>{project?.partner?.name || "—"}</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Manager</span><span>{project?.manager?.name || "—"}</span></div>
              <div className="flex justify-between"><span className="text-muted-foreground">Deadline</span><span className="font-mono">{fmtDate(project?.hard_deadline)}</span></div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardHeader><CardTitle className="font-display">Tasks</CardTitle></CardHeader>
            <CardContent className="space-y-2">
              {project?.tasks?.map((t: any) => (
                <div key={t.id} className="flex items-center justify-between rounded border border-border p-2 text-sm">
                  <span>{t.title}</span>
                  <Badge variant={statusColor(t.status)}>{t.status}</Badge>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
