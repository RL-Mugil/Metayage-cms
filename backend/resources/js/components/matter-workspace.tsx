import { Link, router } from "@inertiajs/react"
import { useEffect, useRef, useState } from "react"
import {
  Activity, ArrowLeft, BriefcaseBusiness, CalendarClock, CheckCircle2,
  CircleDollarSign, Clock3, Eye, FileText, GitBranch, History, ListChecks,
  MessageSquare, Plus, Trash2, Download, Upload, Loader2, Pencil, FileSignature,
  Scale, ShieldCheck, Users,
} from "lucide-react"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { DocketDeadlines } from "@/components/docket-deadlines"
import { ProjectChat } from "@/components/project-chat"
import { api } from "@/lib/api-client"
import type {
  MatterWorkspace as WorkspaceData,
  WorkspaceDeadline,
  WorkspaceDocument,
  WorkspaceTimelineItem,
} from "@/types/matter-workspace"

type WorkspaceTab = "overview" | "lifecycle" | "deadlines" | "family" | "documents" | "discussion" | "tasks" | "costs" | "audit"

interface MatterWorkspaceProps {
  data: WorkspaceData
  projectId: number
  tab: WorkspaceTab
  onTabChange: (tab: WorkspaceTab) => void
}

const TAB_DEFS: Array<{ id: WorkspaceTab; label: string; icon: typeof Activity }> = [
  { id: "overview", label: "Overview", icon: Activity },
  { id: "lifecycle", label: "Lifecycle", icon: GitBranch },
  { id: "deadlines", label: "Deadlines", icon: CalendarClock },
  { id: "family", label: "Family", icon: Users },
  { id: "documents", label: "Documents", icon: FileText },
  { id: "discussion", label: "Discussion", icon: MessageSquare },
  { id: "tasks", label: "Tasks", icon: ListChecks },
  { id: "costs", label: "Costs", icon: CircleDollarSign },
  { id: "audit", label: "Audit", icon: ShieldCheck },
]

function formatDate(value?: string | null): string {
  if (!value) return "Not recorded"
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(date)
}

function formatMoney(value: number | string, currency = "INR"): string {
  return new Intl.NumberFormat("en-IN", { style: "currency", currency, maximumFractionDigits: 0 }).format(Number(value) || 0)
}

function deadlineTone(deadline: WorkspaceDeadline): string {
  if (deadline.status !== "Open") return "text-muted-foreground"
  return new Date(deadline.due_date).getTime() < Date.now() ? "text-destructive" : "text-foreground"
}

function timelineLabel(item: WorkspaceTimelineItem): string {
  if (item.type === "event") return item.title.replaceAll("_", " ")
  return item.title
}

export function MatterWorkspace({ data, projectId, tab, onTabChange }: MatterWorkspaceProps) {
  const { project, application, deadline_summary: summary } = data
  const visibleTabs = TAB_DEFS.filter(({ id }) => {
    if (id === "costs") return data.capabilities.can_view_financials
    if (id === "audit") return data.capabilities.can_view_audit
    return true
  })
  const completedStages = data.stages.filter((stage) => stage.status === "Completed").length
  const progress = data.stages.length ? Math.round((completedStages / data.stages.length) * 100) : 0

  return (
    <div className="min-h-full bg-background">
      <header className="border-b border-border bg-background">
        <div className="px-4 py-4 sm:px-6 lg:px-8">
          <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div className="flex min-w-0 gap-3">
              <Link href="/projects" title="Back to projects" className="mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border text-muted-foreground hover:bg-muted hover:text-foreground">
                <ArrowLeft className="h-4 w-4" />
              </Link>
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                  <span className="font-mono font-semibold text-gold">{project.docket_number || project.project_code}</span>
                  {project.patent_office_code && <span>{project.patent_office_code}</span>}
                  {project.service_code && <span>{project.service_code}</span>}
                  {application?.application_number && <span className="font-mono">App. {application.application_number}</span>}
                </div>
                <h1 className="mt-1 break-words font-display text-xl font-semibold sm:text-2xl">{project.project_name}</h1>
                <p className="mt-1 text-sm text-muted-foreground">
                  {project.client?.company_name || project.client?.legal_name || "No client"}
                </p>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-2 pl-11 xl:pl-0">
              <Badge variant="outline">{project.project_type || project.case_type || "IP Matter"}</Badge>
              <Badge variant="outline">{application?.legal_status || project.status}</Badge>
              <Badge variant={project.status === "Granted" || project.status === "Completed" ? "default" : "secondary"}>{project.status}</Badge>
              {project.urgency && project.urgency !== "Normal" && <Badge variant={project.urgency === "Critical" ? "destructive" : "outline"}>{project.urgency}</Badge>}
            </div>
          </div>
        </div>

        <div className="overflow-x-auto px-4 sm:px-6 lg:px-8">
          <nav className="flex min-w-max gap-1" aria-label="Matter workspace sections">
            {visibleTabs.map(({ id, label, icon: Icon }) => (
              <button key={id} onClick={() => onTabChange(id)} className={`flex h-11 items-center gap-2 border-b-2 px-3 text-xs font-medium transition-colors ${tab === id ? "border-gold text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"}`}>
                <Icon className="h-3.5 w-3.5" />{label}
                {id === "deadlines" && summary.overdue > 0 && <span className="rounded bg-destructive px-1.5 py-0.5 text-[10px] text-destructive-foreground">{summary.overdue}</span>}
              </button>
            ))}
          </nav>
        </div>
      </header>

      <main className="px-4 py-5 sm:px-6 lg:px-8">
        {tab === "overview" && (
          <div className="space-y-6">
            <section className="grid gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-2 xl:grid-cols-5">
              {[
                { label: "Current stage", value: data.stages.find((stage) => stage.status === "In Progress")?.stage_name || "Not started", icon: GitBranch },
                { label: "Nearest deadline", value: formatDate(summary.nearest_due_date), icon: CalendarClock },
                { label: "Overdue", value: String(summary.overdue), icon: Clock3, alert: summary.overdue > 0 },
                { label: "Unreviewed", value: String(summary.unreviewed), icon: ShieldCheck, alert: summary.unreviewed > 0 },
                { label: "Lifecycle progress", value: `${progress}%`, icon: CheckCircle2 },
              ].map(({ label, value, icon: Icon, alert }) => (
                <div key={label} className="bg-background p-4">
                  <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground"><span>{label}</span><Icon className="h-4 w-4" /></div>
                  <p className={`mt-3 text-lg font-semibold ${alert ? "text-destructive" : "text-foreground"}`}>{value}</p>
                </div>
              ))}
            </section>

            <section className="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(320px,0.7fr)]">
              <div>
                <h2 className="mb-3 text-sm font-semibold">Matter position</h2>
                <dl className="grid gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-2 lg:grid-cols-3">
                  {[
                    ["Application", application?.application_number],
                    ["Jurisdiction", application?.jurisdiction || project.patent_office_code],
                    ["Priority date", formatDate(application?.priority_date || project.priority_date)],
                    ["Filing date", formatDate(application?.filing_date || project.filing_date)],
                    ["Publication date", formatDate(application?.publication_date)],
                    ["Grant", application?.grant_number || formatDate(application?.grant_date)],
                    ["Partner", project.partner?.name],
                    ["Case manager", project.manager?.name],
                    ["Patent representative", project.patent_engineer?.name],
                  ].map(([label, value]) => (
                    <div key={label} className="min-h-20 bg-background p-3">
                      <dt className="text-[11px] text-muted-foreground">{label}</dt>
                      <dd className="mt-2 break-words text-sm font-medium">{value || "Not assigned"}</dd>
                    </div>
                  ))}
                </dl>
              </div>

              <div>
                <h2 className="mb-3 text-sm font-semibold">Recent matter activity</h2>
                <div className="max-h-[390px] overflow-y-auto border-l border-border pl-4">
                  {data.timeline.length === 0 ? <p className="py-8 text-sm text-muted-foreground">No activity has been recorded.</p> : data.timeline.slice(0, 12).map((item, index) => (
                    <div key={`${item.type}-${item.occurred_at}-${index}`} className="relative pb-4">
                      <span className="absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-background bg-muted-foreground" />
                      <div className="flex items-center gap-2 text-[10px] uppercase text-muted-foreground"><span>{item.type}</span><span>{formatDate(item.occurred_at)}</span></div>
                      <p className="mt-1 text-sm font-medium capitalize">{timelineLabel(item)}</p>
                      {item.status && <p className="text-xs text-muted-foreground">{item.status}</p>}
                    </div>
                  ))}
                </div>
              </div>
            </section>

            {project.notes && <section><h2 className="mb-2 text-sm font-semibold">Matter notes</h2><p className="whitespace-pre-wrap rounded-md border border-border bg-muted/20 p-4 text-sm leading-6 text-muted-foreground">{project.notes}</p></section>}
          </div>
        )}

        {tab === "lifecycle" && (
          <section>
            <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
              <div><h2 className="text-base font-semibold">Prosecution lifecycle</h2><p className="mt-1 text-sm text-muted-foreground">{completedStages} of {data.stages.length} stages completed</p>{data.lifecycle_template && <p className="mt-1 text-xs text-muted-foreground">{data.lifecycle_template.name} · version {data.lifecycle_template.version} · reviewer {data.docket_reviewer?.name || "Unassigned"}</p>}</div>
              <span className="font-mono text-sm font-semibold">{progress}%</span>
            </div>
            <div className="space-y-0">
              {data.stages.map((stage, index) => (
                <div key={stage.id} className="grid grid-cols-[32px_minmax(0,1fr)] gap-3">
                  <div className="flex flex-col items-center"><div className={`mt-1 flex h-7 w-7 items-center justify-center rounded-full border text-[10px] font-semibold ${stage.status === "Completed" ? "border-emerald-500 bg-emerald-500 text-white" : stage.status === "In Progress" ? "border-blue-500 bg-blue-500 text-white" : "border-border bg-background text-muted-foreground"}`}>{index + 1}</div>{index < data.stages.length - 1 && <div className="min-h-12 w-px flex-1 bg-border" />}</div>
                  <div className="pb-6">
                    <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="text-sm font-semibold">{stage.stage_name}</h3><Badge variant="outline">{stage.status}</Badge></div>
                    <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted-foreground"><span>Start: {formatDate(stage.actual_start_at)}</span><span>End: {formatDate(stage.actual_end_at)}</span><span>Due: {formatDate(stage.due_date)}</span>{stage.owner && <span>Owner: {stage.owner.name}</span>}{stage.working_days != null && <span>{stage.working_days} working days</span>}</div>
                  </div>
                </div>
              ))}
            </div>
          </section>
        )}

        {tab === "deadlines" && (
          <section className="space-y-5">
            <div className="grid gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-5">
              {[["Overdue", summary.overdue], ["Next 7 days", summary.next_7_days], ["Next 30 days", summary.next_30_days], ["Next 90 days", summary.next_90_days], ["Unreviewed", summary.unreviewed]].map(([label, value]) => <div key={label} className="bg-background p-3"><p className="text-[11px] text-muted-foreground">{label}</p><p className={`mt-1 text-xl font-semibold ${label === "Overdue" && Number(value) > 0 ? "text-destructive" : ""}`}>{value}</p></div>)}
            </div>
            <DocketDeadlines projectId={projectId} />
            {data.deadlines.length > 0 && <div><h2 className="mb-3 text-sm font-semibold">Deadline evidence</h2><div className="overflow-x-auto rounded-md border border-border"><table className="w-full min-w-[760px] text-xs"><thead className="bg-muted/40 text-left text-muted-foreground"><tr><th className="p-3">Deadline</th><th className="p-3">Due</th><th className="p-3">Source</th><th className="p-3">Rule</th><th className="p-3">Version</th><th className="p-3">Review</th></tr></thead><tbody>{data.deadlines.map((deadline) => <tr key={deadline.id} className="border-t border-border"><td className="p-3 font-medium">{deadline.title}</td><td className={`p-3 font-mono ${deadlineTone(deadline)}`}>{formatDate(deadline.due_date)}</td><td className="p-3">{deadline.source_type}</td><td className="p-3 font-mono text-[10px]">{deadline.rule_code || "Legacy"}</td><td className="p-3 font-mono text-[10px]">{deadline.rule_version || "Unversioned"}</td><td className="p-3"><Badge variant="outline">{deadline.review_status}</Badge></td></tr>)}</tbody></table></div></div>}
          </section>
        )}

        {tab === "family" && (
          <FamilyWorkspace data={data} projectId={projectId} />
        )}

        {tab === "documents" && (
          <DocumentsWorkspace initial={data.documents} projectId={projectId} canDelete={data.capabilities.can_update} />
        )}

        {tab === "discussion" && (
          <ProjectChat projectId={projectId} />
        )}

        {tab === "tasks" && (
          <section><div className="mb-4"><h2 className="text-base font-semibold">Matter tasks</h2><p className="mt-1 text-sm text-muted-foreground">Operational work assigned within this matter.</p></div>{data.tasks.length === 0 ? <EmptyState icon={ListChecks} title="No matter tasks" body="Tasks created for this matter will appear here." /> : <div className="overflow-x-auto rounded-md border border-border"><table className="w-full min-w-[700px] text-sm"><thead className="bg-muted/40 text-left text-xs text-muted-foreground"><tr><th className="p-3">Task</th><th className="p-3">Assignee</th><th className="p-3">Priority</th><th className="p-3">Due</th><th className="p-3">Hours</th><th className="p-3">Status</th></tr></thead><tbody>{data.tasks.map((task) => <tr key={task.id} className="border-t border-border"><td className="p-3 font-medium">{task.title}</td><td className="p-3">{task.assignee?.name || "Unassigned"}</td><td className="p-3">{task.priority}</td><td className="p-3">{formatDate(task.due_date)}</td><td className="p-3 font-mono">{task.actual_hours || 0} / {task.estimated_hours || 0}</td><td className="p-3"><Badge variant="outline">{task.status}</Badge></td></tr>)}</tbody></table></div>}</section>
        )}

        {tab === "costs" && data.financials && (
          <section className="space-y-6">
            <div className="grid gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-3">{[["Total invoiced", data.financials.summary.total_invoiced], ["Received", data.financials.summary.total_received], ["Pending", data.financials.summary.total_pending]].map(([label, value]) => <div key={label} className="bg-background p-4"><p className="text-xs text-muted-foreground">{label}</p><p className="mt-2 text-xl font-semibold">{formatMoney(value)}</p></div>)}</div>
            <PatentInvoicesWorkspace project={project} canManage={data.capabilities.can_view_financials} />
          </section>
        )}

        {tab === "audit" && (
          <section><div className="mb-4"><h2 className="text-base font-semibold">Matter audit history</h2><p className="mt-1 text-sm text-muted-foreground">Security-sensitive history is available only to authorized internal roles.</p></div>{data.audit.length === 0 ? <EmptyState icon={History} title="No audit entries" body="Recorded matter mutations will appear here." /> : <div className="divide-y divide-border rounded-md border border-border">{data.audit.map((entry) => <div key={entry.id} className="grid gap-2 p-3 sm:grid-cols-[160px_minmax(0,1fr)_180px] sm:items-center"><span className="text-xs text-muted-foreground">{formatDate(entry.created_at)}</span><span className="text-sm font-medium capitalize">{entry.action.replaceAll("_", " ")}</span><span className="text-xs text-muted-foreground">{entry.user?.name || "System"}</span></div>)}</div>}</section>
        )}
      </main>
    </div>
  )
}

function FamilyWorkspace({ data, projectId }: { data: WorkspaceData; projectId: number }) {
  const [open, setOpen] = useState(false)
  const currentOffice = data.project.patent_office_code?.toUpperCase() ?? "IN"
  const [office, setOffice] = useState(currentOffice)
  const [service, setService] = useState("")
  const [applicationNumber, setApplicationNumber] = useState("")
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const engagements = data.family_engagements.length ? data.family_engagements : data.related_matters
  const sameOffice = office === currentOffice
  const selectedTransition = data.allowed_transitions.find((transition) => transition.to_service_code === service)
  const branchAllowed = sameOffice
    ? Boolean(selectedTransition?.eligible)
    : /^[A-Z0-9]{3}$/.test(service)

  async function createBranch() {
    if (!data.family) return
    setSaving(true); setError(null)
    try {
      const response = await api.createFamilyEngagement(data.family.id, {
        source_project_id: projectId,
        patent_office_code: office.toUpperCase(),
        service_code: service.toUpperCase(),
        application_number: applicationNumber || undefined,
        complete_source: sameOffice,
      })
      window.location.href = `/projects/${response.project.id}`
    } catch (reason: unknown) {
      setError(reason instanceof Error ? reason.message : "The engagement could not be created.")
    } finally { setSaving(false) }
  }

  return <section className="space-y-5">
    <div className="flex flex-wrap items-end justify-between gap-3">
      <div><h2 className="text-base font-semibold">Global invention family</h2><p className="mt-1 text-sm text-muted-foreground">{data.family ? `${data.family.invention_number} - ${data.family.title}` : "This legacy matter has not been assigned to a family."}</p></div>
      {data.family && data.capabilities.can_update && <Button size="sm" onClick={() => setOpen((value) => !value)}><GitBranch className="mr-2 h-4 w-4" />New branch</Button>}
    </div>
    {open && <div className="grid gap-3 rounded-md border border-border bg-muted/20 p-4 sm:grid-cols-[140px_180px_minmax(180px,1fr)_auto] sm:items-end">
      <label className="text-xs text-muted-foreground">Office<select value={office} onChange={(event) => { setOffice(event.target.value); setService(""); setError(null) }} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 text-sm text-foreground"><option value="IN">IN - India</option><option value="WO">WO - PCT</option><option value="US">US - USPTO</option><option value="EP">EP - EPO</option></select></label>
      {sameOffice ? <label className="text-xs text-muted-foreground">Next service<select value={service} onChange={(event) => setService(event.target.value)} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 font-mono text-sm text-foreground"><option value="">Select permitted service</option>{data.allowed_transitions.map((transition) => <option key={transition.id} value={transition.to_service_code} disabled={!transition.eligible}>{transition.to_service_code} - {transition.eligible ? "Available" : "Blocked"}</option>)}</select></label> : <label className="text-xs text-muted-foreground">Service code<input value={service} maxLength={3} onChange={(event) => setService(event.target.value.toUpperCase())} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 font-mono text-sm text-foreground" /></label>}
      <label className="text-xs text-muted-foreground">Application number<input value={applicationNumber} onChange={(event) => setApplicationNumber(event.target.value)} className="mt-1 h-9 w-full rounded-md border border-border bg-background px-2 text-sm text-foreground" placeholder="Optional" /></label>
      <Button size="sm" disabled={saving || !branchAllowed} onClick={createBranch}>{saving ? "Creating..." : "Create"}</Button>
      {sameOffice && service && selectedTransition?.blocker && <p className="text-sm text-muted-foreground sm:col-span-4">{selectedTransition.blocker}</p>}
      {sameOffice && data.allowed_transitions.length === 0 && <p className="text-sm text-muted-foreground sm:col-span-4">No configured successor service is available for this engagement.</p>}
      {error && <p className="text-sm text-destructive sm:col-span-4">{error}</p>}
    </div>}
    {engagements.length === 0 ? <EmptyState icon={GitBranch} title="No related engagements" body="Create a jurisdiction or service branch to build this family." /> : <div className="overflow-x-auto rounded-md border border-border"><table className="w-full min-w-[720px] text-sm"><thead className="bg-muted/40 text-left text-xs text-muted-foreground"><tr><th className="p-3">UIN</th><th className="p-3">Matter</th><th className="p-3">Office</th><th className="p-3">Service</th><th className="p-3">Filed</th><th className="p-3">Status</th></tr></thead><tbody>{engagements.map((matter) => <tr key={matter.id} className="border-t border-border hover:bg-muted/20"><td className="p-3"><Link href={`/projects/${matter.id}`} className="font-mono text-xs font-semibold text-gold hover:underline">{matter.docket_number || matter.project_code}</Link></td><td className="p-3 font-medium">{matter.project_name}</td><td className="p-3">{matter.patent_office_code || "-"}</td><td className="p-3">{matter.service_code || "-"}</td><td className="p-3">{formatDate(matter.filing_date)}</td><td className="p-3"><Badge variant="outline">{matter.status}</Badge></td></tr>)}</tbody></table></div>}
  </section>
}

function EmptyState({ icon: Icon, title, body }: { icon: typeof BriefcaseBusiness; title: string; body: string }) {
  return <div className="flex min-h-56 flex-col items-center justify-center rounded-md border border-dashed border-border px-6 text-center"><Icon className="h-8 w-8 text-muted-foreground" /><h3 className="mt-3 text-sm font-semibold">{title}</h3><p className="mt-1 max-w-md text-sm text-muted-foreground">{body}</p></div>
}

/* ─────────────── Documents (upload / download / delete) ─────────────── */

const DOC_FOLDERS = ["General", "Patents", "Trademarks", "Contracts", "Correspondence", "Invoices"]

function DocumentsWorkspace({ initial, projectId, canDelete }: { initial: WorkspaceDocument[]; projectId: number; canDelete: boolean }) {
  const [docs, setDocs] = useState<WorkspaceDocument[]>(initial)
  const [folder, setFolder] = useState("Patents")
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const fileRef = useRef<HTMLInputElement | null>(null)

  async function upload(files: FileList | null) {
    if (!files || files.length === 0) return
    setBusy(true); setError(null)
    try {
      for (const file of Array.from(files)) {
        await api.uploadDocument(file, folder, null, projectId)
      }
      const list = await api.getMatterWorkspace(projectId)
      setDocs(list.documents)
    } catch (e: unknown) {
      setError(e instanceof Error ? e.message : "Upload failed")
    } finally { setBusy(false); if (fileRef.current) fileRef.current.value = "" }
  }

  async function remove(doc: WorkspaceDocument) {
    if (!doc.storage_path || !confirm(`Delete "${doc.file_name}"?`)) return
    setDocs((d) => d.filter((x) => x.id !== doc.id))
    try { await api.deleteDocument(doc.storage_path) } catch { /* refetch on failure */ setDocs(initial) }
  }

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div><h2 className="text-base font-semibold">Matter documents</h2><p className="mt-1 text-sm text-muted-foreground">Files linked to this matter. Clients and staff can upload.</p></div>
        <div className="flex items-end gap-2">
          <label className="text-xs text-muted-foreground">Folder
            <select value={folder} onChange={(e) => setFolder(e.target.value)} className="mt-1 block h-9 rounded-md border border-border bg-background px-2 text-sm">
              {DOC_FOLDERS.map((f) => <option key={f} value={f}>{f}</option>)}
            </select>
          </label>
          <input ref={fileRef} type="file" multiple hidden onChange={(e) => upload(e.target.files)} />
          <Button size="sm" disabled={busy} onClick={() => fileRef.current?.click()}>
            {busy ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Upload className="mr-2 h-4 w-4" />}Upload
          </Button>
        </div>
      </div>
      {error && <p className="text-sm text-destructive">{error}</p>}
      {docs.length === 0 ? (
        <EmptyState icon={FileText} title="No linked documents" body="Upload a document above to attach it to this matter." />
      ) : (
        <div className="divide-y divide-border rounded-md border border-border">
          {docs.map((document) => (
            <div key={document.id} className="grid gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_110px_90px_120px_auto] sm:items-center">
              <div className="min-w-0"><p className="truncate text-sm font-medium">{document.file_name}</p><p className="mt-1 text-xs text-muted-foreground">{document.uploader?.name || "Unknown"} · v{document.current_version}</p></div>
              <span className="text-xs text-muted-foreground">{document.category}</span>
              <Badge variant="outline" className="w-fit">{document.status}</Badge>
              <span className="text-xs text-muted-foreground">{formatDate(document.updated_at)}</span>
              <div className="flex items-center justify-end gap-1">
                {document.storage_path && <button title="View in new tab" onClick={() => window.open(`/api/documents/view?path=${encodeURIComponent(document.storage_path!)}`, "_blank", "noopener")} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"><Eye className="h-4 w-4" /></button>}
                {document.storage_path && <button title="Download" onClick={() => api.downloadDocument(document.storage_path!, document.file_name)} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"><Download className="h-4 w-4" /></button>}
                {canDelete && document.storage_path && <button title="Delete" onClick={() => remove(document)} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"><Trash2 className="h-4 w-4" /></button>}
              </div>
            </div>
          ))}
        </div>
      )}
    </section>
  )
}

/* ─────────────── Patent invoices (INR) — full CRUD ─────────────── */

interface PatentInvoiceRow {
  id: number; type: string; status: string; invoice_uin?: string | null; docket_number?: string | null;
  invoice_date?: string | null; invoice_amount?: number | string | null; currency?: string | null;
  patent_office_fees?: number | string | null; service_fees?: number | string | null; other_expenses?: number | string | null;
  invention_title?: string | null; state_of_supply?: string | null;
}

function PatentInvoicesWorkspace({ project, canManage }: { project: WorkspaceData["project"]; canManage: boolean }) {
  const [rows, setRows] = useState<PatentInvoiceRow[]>([])
  const [loading, setLoading] = useState(true)

  const load = () => {
    setLoading(true)
    const params = new URLSearchParams({ project_id: String(project.id), per_page: "100" })
    api.getPatentInvoicesIn(params)
      .then((res) => setRows((res.data as unknown as PatentInvoiceRow[]) ?? []))
      .catch(() => setRows([]))
      .finally(() => setLoading(false))
  }
  useEffect(load, [project.id])

  // Raising / editing use the full Financial Suite invoice system (identical
  // large form), deep-linked and prefilled with this matter.
  const raise = (kind: "invoice" | "quote") => router.visit(`/financial?india=${kind}&project_id=${project.id}`)
  const editFull = (id: number) => router.visit(`/financial?india_edit=${id}`)

  async function convert(id: number) { try { await api.convertPatentQuoteToInvoice(id); load() } catch { /* noop */ } }
  async function cancel(id: number) { if (!confirm("Cancel this record?")) return; try { await api.deletePatentInvoiceIn(id); load() } catch { /* noop */ } }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-base font-semibold">Patent invoices &amp; quotations (INR)</h2>
        {canManage && (
          <div className="flex gap-2">
            <Button size="sm" variant="outline" onClick={() => raise("quote")}><FileSignature className="mr-2 h-4 w-4" />Raise Quotation</Button>
            <Button size="sm" onClick={() => raise("invoice")}><Plus className="mr-2 h-4 w-4" />Raise Invoice</Button>
          </div>
        )}
      </div>

      {loading ? (
        <div className="flex h-24 items-center justify-center"><Loader2 className="h-5 w-5 animate-spin text-muted-foreground" /></div>
      ) : rows.length === 0 ? (
        <EmptyState icon={CircleDollarSign} title="No invoices yet" body="Raise an invoice or quotation — it opens the full Financial Suite form, prefilled for this matter." />
      ) : (
        <div className="overflow-x-auto rounded-md border border-border">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="bg-muted/40 text-left text-xs text-muted-foreground"><tr>
              <th className="p-3">UIN</th><th className="p-3">Type</th><th className="p-3">Date</th><th className="p-3">Status</th><th className="p-3 text-right">Amount</th><th className="p-3 text-right">Actions</th>
            </tr></thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.id} className="border-t border-border">
                  <td className="p-3 font-mono font-semibold text-gold">{r.invoice_uin || "—"}</td>
                  <td className="p-3 capitalize">{r.type}</td>
                  <td className="p-3">{formatDate(r.invoice_date)}</td>
                  <td className="p-3"><Badge variant="outline">{r.status}</Badge></td>
                  <td className="p-3 text-right font-mono">{formatMoney(r.invoice_amount ?? 0, r.currency || "INR")}</td>
                  <td className="p-3">
                    <div className="flex items-center justify-end gap-1">
                      {canManage && <button title="Open in full editor" onClick={() => editFull(r.id)} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"><Pencil className="h-4 w-4" /></button>}
                      {canManage && r.type === "quote" && r.status !== "Cancelled" && <button title="Convert to invoice" onClick={() => convert(r.id)} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-emerald-600"><CheckCircle2 className="h-4 w-4" /></button>}
                      {canManage && r.status !== "Cancelled" && <button title="Cancel" onClick={() => cancel(r.id)} className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive"><Trash2 className="h-4 w-4" /></button>}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

export type { WorkspaceTab }
