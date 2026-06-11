import { Head } from "@inertiajs/react";
import { useState } from "react";
import { Briefcase, Users, CheckCircle, Clock, Plus, ChevronRight, Building2, X, Eye } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

const jobs: { id: number; title: string; dept: string; posted: string; applicants: number; status: string }[] = [];

const pipeline: { stage: string; color: string; candidates: { id: number; name: string; role: string; date: string }[] }[] = [];

export default function HRMSRecruitment() {
  const [showNewJob, setShowNewJob] = useState(false);
  const [newJob, setNewJob] = useState({ title: "", dept: "", description: "", type: "Full-time" });
  const [jobsList, setJobsList] = useState(jobs);
  const [viewApplicants, setViewApplicants] = useState<typeof jobs[0] | null>(null);

  const totalApplicants = jobsList.reduce((s, j) => s + j.applicants, 0);
  const active = jobsList.filter((j) => j.status === "Active").length;
  const hired = pipeline.find((p) => p.stage === "Hired")?.candidates.length || 0;

  return (
    <AppLayout>
      <Head title="Recruitment" />
      <PageHeader eyebrow="HRMS" title="Recruitment"
        description="Job postings, applicant tracking, and hiring pipeline"
        actions={<Button className="bg-gold hover:bg-gold/90 text-black" onClick={() => setShowNewJob(true)}><Plus className="h-4 w-4 mr-2" />Post New Job</Button>}
      />
      <div className="px-8 py-6 space-y-6">
        {/* Stats */}
        <div className="grid grid-cols-3 gap-4">
          {[
            { label: "Open Roles", value: active, icon: Briefcase, color: "text-gold" },
            { label: "Total Applicants", value: totalApplicants, icon: Users, color: "text-blue-500" },
            { label: "Hired This Quarter", value: hired, icon: CheckCircle, color: "text-green-500" },
          ].map(({ label, value, icon: Icon, color }) => (
            <Card key={label} className="border-border">
              <CardContent className="p-4 flex items-center gap-3">
                <Icon className={`h-8 w-8 ${color}`} />
                <div><div className="text-2xl font-bold">{value}</div><div className="text-xs text-muted-foreground">{label}</div></div>
              </CardContent>
            </Card>
          ))}
        </div>

        {/* New job form */}
        {showNewJob && (
          <Card className="border-gold/30 bg-gold/5">
            <CardHeader className="pb-3"><CardTitle className="font-display text-base">Post New Job</CardTitle></CardHeader>
            <CardContent className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Job Title</label>
                  <input value={newJob.title} onChange={(e) => setNewJob((p) => ({ ...p, title: e.target.value }))}
                    placeholder="e.g. Senior Patent Attorney"
                    className="w-full h-8 rounded border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Department</label>
                  <input value={newJob.dept} onChange={(e) => setNewJob((p) => ({ ...p, dept: e.target.value }))}
                    placeholder="e.g. Legal"
                    className="w-full h-8 rounded border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Job Description</label>
                <textarea value={newJob.description} onChange={(e) => setNewJob((p) => ({ ...p, description: e.target.value }))}
                  rows={3} placeholder="Describe the role and requirements..."
                  className="w-full rounded border border-border bg-background px-3 py-2 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-gold" />
              </div>
              <div className="flex items-center gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Type</label>
                  <select value={newJob.type} onChange={(e) => setNewJob((p) => ({ ...p, type: e.target.value }))}
                    className="h-8 rounded border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                    {["Full-time", "Part-time", "Contract", "Internship"].map((t) => <option key={t}>{t}</option>)}
                  </select>
                </div>
                <div className="flex gap-2 mt-4">
                  <Button size="sm" className="bg-gold hover:bg-gold/90 text-black" onClick={() => {
                    if (!newJob.title.trim()) return;
                    const newId = Math.max(...jobsList.map(j => j.id)) + 1;
                    setJobsList(prev => [...prev, { id: newId, title: newJob.title, dept: newJob.dept || "General", posted: new Date().toISOString().slice(0,10), applicants: 0, status: "Active" }]);
                    setNewJob({ title: "", dept: "", description: "", type: "Full-time" });
                    setShowNewJob(false);
                  }}>Publish Job</Button>
                  <Button size="sm" variant="outline" onClick={() => setShowNewJob(false)}>Cancel</Button>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {viewApplicants && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
            <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-lg p-6 m-4">
              <div className="flex items-center justify-between mb-4">
                <h2 className="font-display text-lg font-semibold">Applicants — {viewApplicants.title}</h2>
                <button onClick={() => setViewApplicants(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
              </div>
              <div className="space-y-2 max-h-96 overflow-y-auto">
                {pipeline.flatMap(p => p.candidates).filter((_, i) => i < viewApplicants.applicants || viewApplicants.applicants === 0).slice(0, Math.max(viewApplicants.applicants, 3)).map((c, i) => (
                  <div key={i} className="flex items-center justify-between p-3 rounded-lg border border-border hover:bg-muted/30">
                    <div>
                      <div className="font-medium text-sm">{c.name}</div>
                      <div className="text-xs text-muted-foreground">{c.role} · Applied {c.date}</div>
                    </div>
                    <div className="flex gap-1">
                      <Button size="sm" variant="outline" className="h-7 text-xs">Review</Button>
                    </div>
                  </div>
                ))}
                {viewApplicants.applicants === 0 && <p className="text-sm text-muted-foreground text-center py-4">No applicants yet.</p>}
              </div>
              <Button className="mt-4 w-full" variant="outline" onClick={() => setViewApplicants(null)}>Close</Button>
            </div>
          </div>
        )}

        {/* Job postings table */}
        <Card className="border-border">
          <CardHeader><CardTitle className="font-display text-base">Job Postings</CardTitle></CardHeader>
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left">Role</th>
                  <th className="px-4 py-3 text-left">Department</th>
                  <th className="px-4 py-3 text-left">Posted</th>
                  <th className="px-4 py-3 text-left">Applicants</th>
                  <th className="px-4 py-3 text-left">Status</th>
                  <th className="px-4 py-3 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                {jobsList.map((job) => (
                  <tr key={job.id} className="border-t border-border hover:bg-muted/30">
                    <td className="px-4 py-3"><div className="font-medium">{job.title}</div></td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1 text-muted-foreground text-xs"><Building2 className="h-3 w-3" />{job.dept}</div>
                    </td>
                    <td className="px-4 py-3 text-xs text-muted-foreground">{job.posted}</td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1.5"><Users className="h-3.5 w-3.5 text-blue-500" /><span className="font-medium">{job.applicants}</span></div>
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant="outline" className={job.status === "Active" ? "text-green-600 border-green-200 bg-green-50" : "text-muted-foreground"}>{job.status}</Badge>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex gap-2">
                        <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => setViewApplicants(job)}>
                          <Eye className="h-3 w-3 mr-1" />View Applicants
                        </Button>
                        {job.status === "Active" && (
                          <Button size="sm" variant="outline" className="h-7 text-xs border-red-200 text-red-600"
                            onClick={() => setJobsList(prev => prev.map(j => j.id === job.id ? { ...j, status: "Closed" } : j))}>
                            Close
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>

        {/* Pipeline Kanban */}
        <div>
          <h3 className="text-sm font-semibold mb-3">Candidate Pipeline</h3>
          <div className="flex gap-3 overflow-x-auto pb-2">
            {pipeline.map(({ stage, color, candidates }) => (
              <div key={stage} className="min-w-[200px] w-[200px]">
                <div className="flex items-center justify-between mb-2">
                  <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{stage}</span>
                  <span className="text-xs text-muted-foreground bg-muted rounded-full px-1.5">{candidates.length}</span>
                </div>
                <div className="space-y-2">
                  {candidates.map((c) => (
                    <div key={c.id} className={`p-3 rounded-lg border text-xs ${color}`}>
                      <div className="font-medium">{c.name}</div>
                      <div className="text-muted-foreground mt-0.5">{c.role}</div>
                      <div className="flex items-center justify-between mt-1.5">
                        <span className="text-muted-foreground flex items-center gap-1"><Clock className="h-2.5 w-2.5" />{c.date}</span>
                        <button className="text-muted-foreground hover:text-foreground"><ChevronRight className="h-3 w-3" /></button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
