import { Head } from "@inertiajs/react";
import { Fragment, useEffect, useState } from "react";
import { Star, Target, TrendingUp, CheckCircle, Clock, Award, Plus, Loader2, X, Search } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

type ReviewTab = "reviews" | "goals" | "360";
type PerfKpiKey = "completed" | "pending" | "avg";

function PerformanceKpiModal({ kpiKey, reviews, onClose }: { kpiKey: PerfKpiKey; reviews: any[]; onClose: () => void }) {
  const [search, setSearch] = useState("");

  const titles: Record<PerfKpiKey, string> = {
    completed: "Completed Reviews",
    pending: "Pending Reviews",
    avg: "All Reviews by Rating",
  };

  const rows = (() => {
    if (kpiKey === "completed") return reviews.filter((r) => r.status === "Completed");
    if (kpiKey === "pending") return reviews.filter((r) => r.status !== "Completed");
    return [...reviews].sort((a, b) => b.rating - a.rating);
  })();

  const filtered = rows.filter((r: any) => {
    const q = search.toLowerCase();
    return !q || r.employee?.toLowerCase().includes(q) || r.reviewer?.toLowerCase().includes(q);
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl max-h-[88vh] flex flex-col">
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <h2 className="font-display text-lg font-semibold">{titles[kpiKey]}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{filtered.length} review{filtered.length !== 1 ? "s" : ""}</p>
          </div>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>
        <div className="px-6 py-3 border-b border-border flex-shrink-0">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold"
              placeholder="Search employee or reviewer…" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </div>
        <div className="flex-1 overflow-auto">
          <table className="w-full text-sm">
            <thead className="sticky top-0 bg-muted/60 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
              <tr>
                <th className="px-4 py-3 text-left">Employee</th>
                <th className="px-4 py-3 text-left">Reviewer</th>
                <th className="px-4 py-3 text-left">Period</th>
                <th className="px-4 py-3 text-left">Rating</th>
                <th className="px-4 py-3 text-left">Status</th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 && <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">No reviews found.</td></tr>}
              {filtered.map((r: any) => (
                <tr key={r.id} className="border-t border-border hover:bg-muted/30">
                  <td className="px-4 py-2.5 font-medium">{r.employee}</td>
                  <td className="px-4 py-2.5 text-xs text-muted-foreground">{r.reviewer}</td>
                  <td className="px-4 py-2.5 text-xs">{r.period}</td>
                  <td className="px-4 py-2.5 text-xs">
                    {r.rating > 0 ? (
                      <div className="flex items-center gap-1">
                        <Star className="h-3 w-3 fill-gold text-gold" />
                        <span className="font-medium">{r.rating.toFixed(1)}</span>
                      </div>
                    ) : <span className="text-muted-foreground">—</span>}
                  </td>
                  <td className="px-4 py-2.5 text-xs">
                    <span className={r.status === "Completed" ? "text-green-600 font-medium" : "text-amber-600"}>{r.status}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

function Stars({ rating }: { rating: number }) {
  return (
    <div className="flex items-center gap-0.5">
      {[1, 2, 3, 4, 5].map((s) => (
        <Star key={s} className={`h-3.5 w-3.5 ${s <= Math.round(rating) ? "fill-gold text-gold" : "text-muted-foreground"}`} />
      ))}
      <span className="ml-1 text-xs font-medium">{rating > 0 ? rating.toFixed(1) : "—"}</span>
    </div>
  );
}

export default function HRMSPerformance() {
  const [tab, setTab] = useState<ReviewTab>("reviews");
  const [activeReview, setActiveReview] = useState<number | null>(null);
  const [scores, setScores] = useState<Record<string, number>>({ technical: 4, communication: 4, teamwork: 4, leadership: 3, initiative: 4 });
  const [comments, setComments] = useState("");
  const [reviews, setReviews] = useState<any[]>([]);
  const [goals, setGoals] = useState<any[]>([]);
  const [feedback360, setFeedback360] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [kpiModal, setKpiModal] = useState<PerfKpiKey | null>(null);

  const load = () => api.getPerformance()
    .then((d) => { setReviews(d.reviews); setGoals(d.goals); setFeedback360(d.feedback360); })
    .catch(() => {})
    .finally(() => setLoading(false));

  useEffect(() => { load(); }, []);

  async function submitReview(id: number) {
    setSaving(true);
    try {
      await api.submitPerformanceReview(id, { scores, comments: comments || undefined });
      setActiveReview(null);
      setComments("");
      load();
    } catch { /* leave the form open */ }
    finally { setSaving(false); }
  }

  const completed = reviews.filter((r) => r.status === "Completed").length;
  const avgRating = reviews.filter((r) => r.rating > 0).reduce((s, r, _, a) => s + r.rating / a.length, 0);

  if (loading) return (
    <AppLayout>
      <Head title="Performance Reviews" />
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-gold" />
      </div>
    </AppLayout>
  );

  return (
    <AppLayout>
      <Head title="Performance Reviews" />
      <PageHeader eyebrow="HRMS" title="Performance Reviews"
        description="Q2 2026 review cycle — closes June 30, 2026"
        actions={<Button className="bg-gold hover:bg-gold/90 text-black"><Plus className="h-4 w-4 mr-2" />Start Review</Button>}
      />
      <div className="px-8 py-6 space-y-6">
        {kpiModal && <PerformanceKpiModal kpiKey={kpiModal} reviews={reviews} onClose={() => setKpiModal(null)} />}
        {/* Cycle banner */}
        <Card className="border-gold/30 bg-gold/5">
          <CardContent className="p-4 flex items-center gap-4">
            <Award className="h-8 w-8 text-gold flex-shrink-0" />
            <div className="flex-1">
              <div className="font-semibold text-sm">Q2 2026 Review Cycle — Active</div>
              <div className="flex items-center gap-2 mt-2">
                <div className="flex-1 h-2 bg-muted rounded-full"><div className="h-full bg-gold rounded-full" style={{ width: `${reviews.length ? (completed / reviews.length) * 100 : 0}%` }} /></div>
                <span className="text-xs text-muted-foreground">{completed}/{reviews.length} complete</span>
              </div>
            </div>
            <div className="text-right">
              <div className="text-2xl font-bold text-gold">{avgRating.toFixed(1)}</div>
              <div className="text-xs text-muted-foreground">Avg rating</div>
            </div>
          </CardContent>
        </Card>

        {/* Stats */}
        <div className="grid grid-cols-3 gap-4">
          {([
            { label: "Reviews Completed", value: completed, icon: CheckCircle, color: "text-green-500", kpiKey: "completed" as PerfKpiKey },
            { label: "Pending Reviews", value: reviews.length - completed, icon: Clock, color: "text-amber-500", kpiKey: "pending" as PerfKpiKey },
            { label: "Avg Rating (Q2)", value: `${avgRating.toFixed(1)} / 5.0`, icon: Star, color: "text-gold", kpiKey: "avg" as PerfKpiKey },
          ]).map(({ label, value, icon: Icon, color, kpiKey }) => (
            <button key={label} onClick={() => setKpiModal(kpiKey)}
              className="rounded-xl border border-border bg-card p-4 text-left transition-all hover:shadow-md hover:border-gold/40 cursor-pointer">
              <div className="flex items-center gap-3">
                <Icon className={`h-7 w-7 ${color}`} />
                <div><div className="text-xl font-bold">{value}</div><div className="text-xs text-muted-foreground">{label}</div></div>
              </div>
              <div className="mt-1 text-xs text-muted-foreground">Click to view</div>
            </button>
          ))}
        </div>

        {/* Tabs */}
        <div className="flex gap-1 border border-border rounded-lg p-1 bg-muted/30 w-fit">
          {(["reviews", "goals", "360"] as ReviewTab[]).map((t) => (
            <button key={t} onClick={() => setTab(t)}
              className={`px-4 py-1.5 rounded text-sm font-medium transition-colors capitalize ${tab === t ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"}`}>
              {t === "360" ? "360° Feedback" : t.charAt(0).toUpperCase() + t.slice(1)}
            </button>
          ))}
        </div>

        {tab === "reviews" && (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Employee</th>
                    <th className="px-4 py-3 text-left">Reviewer</th>
                    <th className="px-4 py-3 text-left">Period</th>
                    <th className="px-4 py-3 text-left">Rating</th>
                    <th className="px-4 py-3 text-left">Status</th>
                    <th className="px-4 py-3 text-left">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {reviews.map((r) => (
                    <Fragment key={r.id}>
                      <tr className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3 font-medium">{r.employee}</td>
                        <td className="px-4 py-3 text-muted-foreground">{r.reviewer}</td>
                        <td className="px-4 py-3"><Badge variant="outline">{r.period}</Badge></td>
                        <td className="px-4 py-3"><Stars rating={r.rating} /></td>
                        <td className="px-4 py-3">
                          <Badge variant="outline" className={r.status === "Completed" ? "text-green-600 border-green-200 bg-green-50" : r.status === "In Progress" ? "text-amber-600 border-amber-200 bg-amber-50" : ""}>
                            {r.status}
                          </Badge>
                        </td>
                        <td className="px-4 py-3">
                          {r.status !== "Completed" && (
                            <Button size="sm" variant="outline" className="h-7 text-xs"
                              onClick={() => setActiveReview(activeReview === r.id ? null : r.id)}>
                              {r.status === "In Progress" ? "Continue" : "Start Review"}
                            </Button>
                          )}
                        </td>
                      </tr>
                      {activeReview === r.id && (
                        <tr className="border-t border-dashed border-gold/30 bg-gold/5">
                          <td colSpan={6} className="px-6 py-5">
                            <div className="text-sm font-semibold mb-3">Review: {r.employee}</div>
                            <div className="grid grid-cols-2 gap-4">
                              {(["technical", "communication", "teamwork", "leadership", "initiative"] as const).map((comp) => (
                                <div key={comp}>
                                  <label className="text-xs text-muted-foreground capitalize">{comp} Skills</label>
                                  <div className="flex items-center gap-2 mt-1">
                                    {[1, 2, 3, 4, 5].map((s) => (
                                      <button key={s} onClick={() => setScores((p) => ({ ...p, [comp]: s }))}>
                                        <Star className={`h-5 w-5 ${s <= scores[comp] ? "fill-gold text-gold" : "text-muted-foreground"} hover:text-gold`} />
                                      </button>
                                    ))}
                                    <span className="text-xs text-muted-foreground">{scores[comp]}/5</span>
                                  </div>
                                </div>
                              ))}
                            </div>
                            <textarea placeholder="Overall comments..." value={comments} onChange={(e) => setComments(e.target.value)}
                              className="mt-4 w-full h-20 rounded-md border border-border bg-background px-3 py-2 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-gold" />
                            <div className="flex gap-2 mt-3">
                              <Button size="sm" className="bg-gold hover:bg-gold/90 text-black" disabled={saving} onClick={() => submitReview(r.id)}>
                                {saving ? <><Loader2 className="h-3 w-3 mr-1 animate-spin" />Submitting…</> : "Submit Review"}
                              </Button>
                              <Button size="sm" variant="outline" onClick={() => setActiveReview(null)}>Cancel</Button>
                            </div>
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}

        {tab === "goals" && (
          <div className="space-y-3">
            {goals.map((g) => (
              <Card key={g.id} className="border-border">
                <CardContent className="p-4">
                  <div className="flex items-start justify-between gap-4">
                    <div className="flex items-start gap-3">
                      <Target className="h-5 w-5 text-gold flex-shrink-0 mt-0.5" />
                      <div>
                        <div className="font-medium text-sm">{g.title}</div>
                        <div className="text-xs text-muted-foreground mt-0.5">{g.employee} · Due {g.due}</div>
                      </div>
                    </div>
                    <Badge variant="outline" className={g.status === "On Track" ? "text-green-600 border-green-200 bg-green-50" : "text-amber-600 border-amber-200 bg-amber-50"}>
                      {g.status}
                    </Badge>
                  </div>
                  <div className="flex items-center gap-3 mt-3">
                    <div className="flex-1 h-2 bg-muted rounded-full">
                      <div className="h-full bg-gold rounded-full transition-all" style={{ width: `${g.progress}%` }} />
                    </div>
                    <span className="text-xs font-medium text-gold w-10 text-right">{g.progress}%</span>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}

        {tab === "360" && (
          <Card className="border-border">
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle className="font-display text-base">360° Feedback Requests</CardTitle>
              <Button size="sm" variant="outline" className="h-7 text-xs"><Plus className="h-3 w-3 mr-1" />Send Request</Button>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Feedback From</th>
                    <th className="px-4 py-3 text-left">About</th>
                    <th className="px-4 py-3 text-left">Sent</th>
                    <th className="px-4 py-3 text-left">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {feedback360.map((f) => (
                    <tr key={f.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">{f.from}</td>
                      <td className="px-4 py-3 font-medium">{f.to}</td>
                      <td className="px-4 py-3 text-muted-foreground text-xs">{f.sent}</td>
                      <td className="px-4 py-3">
                        <Badge variant="outline" className={f.status === "Submitted" ? "text-green-600 border-green-200 bg-green-50" : "text-amber-600 border-amber-200 bg-amber-50"}>
                          {f.status}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
