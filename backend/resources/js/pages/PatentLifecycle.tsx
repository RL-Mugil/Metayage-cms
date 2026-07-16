import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, X, FileText, ExternalLink, MapPin, RefreshCw, Globe, Flag } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";
import { IndianPatentLifecycle } from "@/components/IndianPatentLifecycle";

type LifecycleTab = "india" | "abroad";

const TABS: { id: LifecycleTab; label: string; icon: React.ReactNode; description: string }[] = [
  {
    id: "india",
    label: "Indian Lifecycle",
    icon: <Flag className="h-3.5 w-3.5" />,
    description: "Indian Patent Office (IPO) prosecution flowchart",
  },
  {
    id: "abroad",
    label: "Abroad",
    icon: <Globe className="h-3.5 w-3.5" />,
    description: "International & foreign jurisdiction lifecycle",
  },
];

import React from "react";

export default function PatentLifecycle() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;
  const isAdmin = role === "super_admin";
  const isAnalyst = ['associate', 'galvanizer', 'partner', 'director'].includes(role);
  const [roleFilter, setRoleFilter] = useAnalystRoleFilter();

  const [activeTab, setActiveTab] = useState<LifecycleTab>("india");
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [loading, setLoading] = useState(true);

  const [openNode, setOpenNode] = useState<{ label: string; serviceCodes: string[] } | null>(null);
  const [projects, setProjects] = useState<any[]>([]);
  const [projLoading, setProjLoading] = useState(false);
  const [projTotal, setProjTotal] = useState(0);

  const load = (rf?: string) => {
    setLoading(true);
    const effectiveRf = isAnalyst ? (rf ?? roleFilter) : undefined;
    api.getLifecycleServiceStats(effectiveRf)
      .then(setCounts)
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(roleFilter); }, [roleFilter]);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, []);

  async function handleNodeClick(nodeId: string, label: string, serviceCodes: string[]) {
    if (!serviceCodes.length) return;
    setOpenNode({ label, serviceCodes });
    setProjLoading(true);
    setProjects([]);
    setProjTotal(0);
    try {
      const params = new URLSearchParams({
        service_code: serviceCodes.join(","),
        per_page: "200",
      });
      if (isAnalyst && roleFilter !== "all") params.set("role_filter", roleFilter);
      const res = await api.getProjectsPaged(params) as any;
      const data = Array.isArray(res) ? res : res?.data ?? [];
      setProjects(data);
      setProjTotal(Array.isArray(res) ? data.length : res?.total ?? data.length);
    } catch { /* empty */ }
    finally { setProjLoading(false); }
  }

  const totalActive = Object.values(counts).reduce((a, b) => a + b, 0);

  return (
    <AppLayout>
      <Head title="Patent Process Lifecycle" />
      <PageHeader
        eyebrow="Practice"
        title="Patent Process Lifecycle"
        description={`${totalActive} active cases across all jurisdictions. Click any node to drill in.`}
        actions={
          <div className="flex items-center gap-2">
            <AnalystRoleFilter value={roleFilter} onChange={(v) => { setRoleFilter(v); }} />
            <button
              onClick={() => load(roleFilter)}
              className="flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors"
            >
              <RefreshCw className="h-3.5 w-3.5" /> Refresh
            </button>
          </div>
        }
      />

      {/* Browser-style tab bar */}
      <div className="px-8 pt-2">
        <div className="flex items-end gap-0.5 border-b border-border">
          {TABS.map((tab) => {
            const isActive = activeTab === tab.id;
            return (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={`
                  group flex items-center gap-2 px-5 py-2.5 rounded-t-lg border border-b-0 text-sm font-medium
                  transition-all relative -mb-px
                  ${isActive
                    ? "bg-background border-border text-foreground shadow-sm z-10"
                    : "bg-muted/40 border-transparent text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                  }
                `}
              >
                {tab.icon}
                {tab.label}
                {isActive && (
                  <span className="absolute bottom-0 left-0 right-0 h-px bg-background" />
                )}
              </button>
            );
          })}
        </div>
      </div>

      {/* Tab content */}
      <div className="px-8 py-6">
        {/* Indian Lifecycle tab */}
        {activeTab === "india" && (
          loading ? (
            <div className="flex items-center justify-center py-24">
              <Loader2 className="h-8 w-8 animate-spin text-gold" />
            </div>
          ) : (
            <IndianPatentLifecycle
              isAdmin={isAdmin}
              counts={counts}
              onNodeClick={handleNodeClick}
            />
          )
        )}

        {/* Abroad tab */}
        {activeTab === "abroad" && (
          <div className="flex flex-col items-center justify-center py-32 gap-4 text-center">
            <Globe className="h-16 w-16 text-muted-foreground/30" />
            <div>
              <h3 className="text-lg font-semibold text-foreground">Abroad Lifecycle</h3>
              <p className="text-sm text-muted-foreground mt-1 max-w-sm">
                International jurisdiction lifecycle (EPO, USPTO, PCT National Phase abroad) will be added here.
              </p>
            </div>
            <Badge variant="outline" className="text-xs text-muted-foreground">Coming Soon</Badge>
          </div>
        )}
      </div>

      {/* Node drill-down modal */}
      {openNode && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm p-4 md:p-8">
          <div className="bg-background border border-border rounded-2xl shadow-2xl w-full h-full flex flex-col overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <div>
                <h2 className="font-display text-xl font-semibold">{openNode.label}</h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  Service code{openNode.serviceCodes.length > 1 ? "s" : ""}:{" "}
                  <span className="font-mono">{openNode.serviceCodes.join(", ")}</span>
                  {" · "}
                  {projLoading ? "Loading…" : `${projTotal} case${projTotal !== 1 ? "s" : ""}`}
                </p>
              </div>
              <button onClick={() => setOpenNode(null)} className="p-2 rounded-md hover:bg-muted transition-colors">
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>
            <div className="flex-1 overflow-auto">
              {projLoading ? (
                <div className="flex items-center justify-center h-full">
                  <Loader2 className="h-8 w-8 animate-spin text-gold" />
                </div>
              ) : projects.length === 0 ? (
                <div className="flex flex-col items-center justify-center h-full gap-2 text-muted-foreground">
                  <FileText className="h-10 w-10 opacity-30" />
                  <p className="text-sm">No cases for {openNode.serviceCodes.join(" / ")} right now.</p>
                </div>
              ) : (
                <table className="w-full text-sm">
                  <thead className="sticky top-0 bg-muted/70 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-5 py-3 text-left">Docket / UIN</th>
                      <th className="px-5 py-3 text-left">Case Name</th>
                      <th className="px-5 py-3 text-left">Client</th>
                      <th className="px-5 py-3 text-left">Service</th>
                      <th className="px-5 py-3 text-left">Status</th>
                      <th className="px-5 py-3 text-left">Hard Deadline</th>
                    </tr>
                  </thead>
                  <tbody>
                    {projects.map((p: any) => (
                      <tr key={p.id} className="border-t border-border hover:bg-muted/20 transition-colors group">
                        <td className="px-5 py-3">
                          <a href={`/projects/${p.id}`} className="flex items-center gap-1.5 font-mono font-medium text-foreground group-hover:text-gold transition-colors">
                            {p.docket_number ?? p.project_code}
                            <ExternalLink className="h-3 w-3 opacity-0 group-hover:opacity-100 transition-opacity" />
                          </a>
                        </td>
                        <td className="px-5 py-3 max-w-[240px] truncate" title={p.project_name}>{p.project_name}</td>
                        <td className="px-5 py-3 text-muted-foreground text-xs">
                          {p.client?.company_name ?? p.client?.legal_name ?? "—"}
                        </td>
                        <td className="px-5 py-3">
                          <span className="font-mono text-xs text-muted-foreground flex items-center gap-1">
                            <MapPin className="h-3 w-3" />
                            {p.patent_office_code ?? "—"} · {p.service_code ?? "—"}
                          </span>
                        </td>
                        <td className="px-5 py-3">
                          <Badge variant="outline" className="text-[10px]">{p.status}</Badge>
                        </td>
                        <td className="px-5 py-3 text-xs font-mono text-muted-foreground">
                          {p.hard_deadline
                            ? new Date(p.hard_deadline).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" })
                            : "—"}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
