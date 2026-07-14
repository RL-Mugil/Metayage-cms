import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, X, FileText, ExternalLink, RefreshCw, MapPin } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";

// ── Phase definitions — mirrors stagesForServiceCode() in ProjectController ──
interface PhaseStage { name: string; label: string }
interface Phase {
  n: number;
  label: string;
  dotCls: string;          // Tailwind bg for the numbered circle
  cardBorder: string;      // border colour of the card
  stageBg: string;         // hover bg for each stage row
  badgeCls: string;        // count badge bg
  stages: PhaseStage[];
  abandonedBelow?: boolean;
}

const PHASES: Phase[] = [
  {
    n: 1,
    label: "Research",
    dotCls:    "bg-sky-500",
    cardBorder:"border-sky-200/70 dark:border-sky-500/25",
    stageBg:   "bg-sky-50 hover:bg-sky-100 dark:bg-sky-500/10 dark:hover:bg-sky-500/20 border-sky-200 dark:border-sky-500/30 text-sky-800 dark:text-sky-200",
    badgeCls:  "bg-sky-500",
    stages: [
      { name: "Awaiting IDF from Client", label: "Awaiting IDF from Client" },
      { name: "Invention Disclosure",     label: "Invention Disclosure"     },
      { name: "Patent Search",            label: "Patent Search"            },
      { name: "Prior Art Search",         label: "Prior Art Search"         },
      { name: "Search Report",            label: "Search Report"            },
      { name: "Search Report Ready",      label: "Search Report Ready"      },
      { name: "Search Report Shared",     label: "Search Report Shared"     },
    ],
    abandonedBelow: true,
  },
  {
    n: 2,
    label: "Drafting",
    dotCls:    "bg-amber-500",
    cardBorder:"border-amber-200/70 dark:border-amber-500/25",
    stageBg:   "bg-amber-50 hover:bg-amber-100 dark:bg-amber-500/10 dark:hover:bg-amber-500/20 border-amber-200 dark:border-amber-500/30 text-amber-800 dark:text-amber-200",
    badgeCls:  "bg-amber-500",
    stages: [
      { name: "Provisional or Complete Application", label: "Provisional / Complete App."  },
      { name: "IDF Received",                        label: "IDF Received"                 },
      { name: "Patent Drafting",                     label: "Patent Drafting"              },
      { name: "Drafting in Progress",                label: "Drafting in Progress (legacy)" },
      { name: "Drafting",                           label: "Drafting"                     },
      { name: "Claims Ready to Share",               label: "Claims Ready to Share"        },
      { name: "Claims Approved",                     label: "Claims Approved"              },
      { name: "Applicant/Inventor Review",           label: "Applicant / Inventor Review"  },
      { name: "Internal Review",                     label: "Internal Review"              },
      { name: "Draft Shared with Client",            label: "Draft Shared with Client"     },
      { name: "Awaiting Client Feedback",            label: "Awaiting Client Feedback"     },
      { name: "Client Comments Received",            label: "Client Comments Received"     },
      { name: "Revised Draft Shared",                label: "Revised Draft Shared"         },
      { name: "Draft Approved",                      label: "Draft Approved (legacy)"      },
      { name: "Drafted",                             label: "Drafted"                      },
    ],
  },
  {
    n: 3,
    label: "Filing",
    dotCls:    "bg-teal-500",
    cardBorder:"border-teal-200/70 dark:border-teal-500/25",
    stageBg:   "bg-teal-50 hover:bg-teal-100 dark:bg-teal-500/10 dark:hover:bg-teal-500/20 border-teal-200 dark:border-teal-500/30 text-teal-800 dark:text-teal-200",
    badgeCls:  "bg-teal-500",
    stages: [
      { name: "Provisional Filing",       label: "Provisional Filing"        },
      { name: "Awaiting Signed Forms",    label: "Awaiting Signed Forms"     },
      { name: "Filing with Patent Office",label: "Filing with Patent Office" },
      { name: "Filing",                   label: "Filing"                    },
      { name: "Filed",                    label: "Filed (legacy)"            },
      { name: "Filed — Waiting for FER or Grant", label: "Filed — Waiting for FER or Grant" },
    ],
  },
  {
    n: 4,
    label: "Examination",
    dotCls:    "bg-purple-500",
    cardBorder:"border-purple-200/70 dark:border-purple-500/25",
    stageBg:   "bg-purple-50 hover:bg-purple-100 dark:bg-purple-500/10 dark:hover:bg-purple-500/20 border-purple-200 dark:border-purple-500/30 text-purple-800 dark:text-purple-200",
    badgeCls:  "bg-purple-500",
    stages: [
      { name: "First Examination Report",      label: "First Examination Report"      },
      { name: "FER Received",                  label: "FER Received"                  },
      { name: "FER Response Preparation",      label: "FER Response Preparation"      },
      { name: "FER Response in Progress",      label: "FER Response in Progress"      },
      { name: "FER Response Filing",           label: "FER Response Filing"           },
      { name: "FER Response Filed",            label: "FER Response Filed"            },
      { name: "Hearing with Examiner",         label: "Hearing with Examiner"         },
      { name: "Hearing Scheduled",             label: "Hearing Scheduled"             },
      { name: "Hearing Response Preparation",  label: "Hearing Response Preparation"  },
      { name: "Hearing Response in Progress",  label: "Hearing Response in Progress"  },
      { name: "Hearing Response Filing",       label: "Hearing Response Filing"       },
      { name: "Hearing Response Filed",        label: "Hearing Response Filed"        },
    ],
    abandonedBelow: true,
  },
];

const TERMINAL_STAGES = [
  { name: "Granted", label: "Granted" },
  { name: "Renewal", label: "Renewal" },
];

// ── Components ────────────────────────────────────────────────────────────────

function StageRow({
  label, count, stageBg, badgeCls, onClick,
}: {
  label: string; count: number; stageBg: string; badgeCls: string; onClick: () => void;
}) {
  return (
    <button
      onClick={onClick}
      className={`w-full text-left rounded border px-2.5 py-1.5 text-[11px] font-medium transition-colors flex items-center justify-between gap-2 ${stageBg}`}
    >
      <span className="leading-snug">{label}</span>
      {count > 0 && (
        <span className={`flex-shrink-0 min-w-[18px] h-[18px] px-1 rounded-full text-white text-[9px] font-bold flex items-center justify-center ${badgeCls}`}>
          {count}
        </span>
      )}
    </button>
  );
}

function AbandonedCircle() {
  return (
    <div className="flex h-12 w-12 items-center justify-center rounded-full border-2 border-border bg-background text-[9px] font-medium text-muted-foreground text-center leading-tight">
      Abandoned
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function PatentLifecycle() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;
  const isAnalyst = ['associate', 'galvanizer', 'partner', 'director'].includes(role);
  const [roleFilter, setRoleFilter] = useAnalystRoleFilter();

  const [counts, setCounts] = useState<Record<string, number>>({});
  const [loading, setLoading] = useState(true);

  const [openStage, setOpenStage] = useState<{ name: string; label: string } | null>(null);
  const [projects, setProjects] = useState<any[]>([]);
  const [projLoading, setProjLoading] = useState(false);

  const load = (rf?: string) => {
    setLoading(true);
    const effectiveRf = isAnalyst ? (rf ?? roleFilter) : undefined;
    api.getLifecycleStats(effectiveRf).then(setCounts).catch(() => {}).finally(() => setLoading(false));
  };
  useEffect(() => { load(roleFilter); }, [roleFilter]);
  useEffect(() => { load(); }, []);

  async function openStagePopup(stage: { name: string; label: string }) {
    setOpenStage(stage);
    setProjLoading(true);
    setProjects([]);
    try {
      const params = new URLSearchParams({ lifecycle_stage: stage.name, per_page: "200" });
      if (isAnalyst && roleFilter !== "all") params.set("role_filter", roleFilter);
      const res = await api.getProjectsPaged(params) as any;
      setProjects(Array.isArray(res) ? res : res?.data ?? []);
    } catch { /* empty */ }
    finally { setProjLoading(false); }
  }

  const c = (name: string) => counts[name] ?? 0;
  const totalActive = Object.values(counts).reduce((a, b) => a + b, 0);

  return (
    <AppLayout>
      <Head title="Patent Process Lifecycle" />
      <PageHeader
        eyebrow="Practice"
        title="Patent Process Lifecycle"
        description={`End-to-end patent workflow — ${totalActive} active cases across all stages. Click any stage to drill in.`}
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

      {/* Stage drill-down modal */}
      {openStage && (
        <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm p-4 md:p-8">
          <div className="bg-background border border-border rounded-2xl shadow-2xl w-full h-full flex flex-col overflow-hidden">
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <div>
                <h2 className="font-display text-xl font-semibold">{openStage.label}</h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  {projLoading ? "Loading…" : `${projects.length} case${projects.length !== 1 ? "s" : ""} currently in this stage`}
                </p>
              </div>
              <button onClick={() => setOpenStage(null)} className="p-2 rounded-md hover:bg-muted transition-colors">
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
                  <p className="text-sm">No cases in "{openStage.label}" right now.</p>
                </div>
              ) : (
                <table className="w-full text-sm">
                  <thead className="sticky top-0 bg-muted/70 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-5 py-3 text-left">Docket / UIN</th>
                      <th className="px-5 py-3 text-left">Case Name</th>
                      <th className="px-5 py-3 text-left">Client</th>
                      <th className="px-5 py-3 text-left">Office</th>
                      <th className="px-5 py-3 text-left">Urgency</th>
                      <th className="px-5 py-3 text-left">Hard Deadline</th>
                      <th className="px-5 py-3 text-left">Status</th>
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
                        <td className="px-5 py-3 max-w-[260px] truncate" title={p.project_name}>{p.project_name}</td>
                        <td className="px-5 py-3 text-muted-foreground">{p.client?.company_name ?? p.client?.legal_name ?? "—"}</td>
                        <td className="px-5 py-3">
                          <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            <MapPin className="h-3 w-3" />{p.patent_office_code ?? "—"}
                          </span>
                        </td>
                        <td className="px-5 py-3">
                          <Badge variant={p.urgency === "Critical" || p.urgency === "High" ? "destructive" : "secondary"} className="text-[10px]">
                            {p.urgency ?? "—"}
                          </Badge>
                        </td>
                        <td className="px-5 py-3 text-xs font-mono text-muted-foreground">
                          {p.hard_deadline ? new Date(p.hard_deadline).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "—"}
                        </td>
                        <td className="px-5 py-3"><Badge variant="outline" className="text-[10px]">{p.status}</Badge></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6">
        {loading ? (
          <div className="flex items-center justify-center py-24">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
          </div>
        ) : (
          <div className="overflow-x-auto pb-4">
            <div className="min-w-[1100px]">

              {/* Phase number circles + connector line */}
              <div className="relative grid grid-cols-4 gap-5 mb-1">
                <div className="absolute top-3.5 left-[12.5%] right-[12.5%] h-px bg-border" />
                {PHASES.map((ph) => (
                  <div key={ph.n} className="flex flex-col items-center gap-1 relative z-10">
                    <div className={`flex h-7 w-7 items-center justify-center rounded-full text-white text-xs font-bold shadow ${ph.dotCls}`}>
                      {ph.n}
                    </div>
                    <span className="text-[11px] font-semibold text-foreground">{ph.label}</span>
                  </div>
                ))}
              </div>

              {/* Vertical drops */}
              <div className="grid grid-cols-4 gap-5 mb-1">
                {PHASES.map((ph) => (
                  <div key={ph.n} className="flex justify-center">
                    <div className="w-px h-4 bg-border" />
                  </div>
                ))}
              </div>

              {/* Phase columns */}
              <div className="grid grid-cols-4 gap-5 items-start">
                {PHASES.map((ph) => (
                  <div key={ph.n} className="flex flex-col gap-2">
                    {/* Stage card */}
                    <div className={`rounded-xl border ${ph.cardBorder} bg-card shadow-sm p-2 space-y-1`}>
                      {ph.stages.map((s) => (
                        <StageRow
                          key={s.name}
                          label={s.label}
                          count={c(s.name)}
                          stageBg={ph.stageBg}
                          badgeCls={ph.badgeCls}
                          onClick={() => openStagePopup(s)}
                        />
                      ))}
                    </div>

                    {/* Abandoned indicator */}
                    {ph.abandonedBelow && (
                      <div className="flex flex-col items-center">
                        <div className="w-px h-3 bg-border" />
                        <AbandonedCircle />
                      </div>
                    )}
                  </div>
                ))}
              </div>

              {/* Terminal row */}
              <div className="mt-8 flex items-center justify-end gap-3 pr-2">
                <span className="text-xs text-muted-foreground uppercase tracking-wider mr-1">Outcome</span>
                {TERMINAL_STAGES.map((s) => (
                  <button
                    key={s.name}
                    onClick={() => openStagePopup(s)}
                    className="flex items-center gap-2 rounded-lg border border-green-300 dark:border-green-500/40 bg-green-50 dark:bg-green-500/10 px-4 py-2 text-sm font-semibold text-green-700 dark:text-green-400 hover:bg-green-100 dark:hover:bg-green-500/20 transition-colors"
                  >
                    {s.label}
                    {c(s.name) > 0 && (
                      <span className="min-w-[20px] h-5 px-1 rounded-full bg-green-500 text-white text-[10px] font-bold flex items-center justify-center">
                        {c(s.name)}
                      </span>
                    )}
                  </button>
                ))}
              </div>

            </div>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
