import { Head } from "@inertiajs/react"
import { useEffect, useState } from "react"
import { Loader2 } from "lucide-react"
import AppLayout from "@/layouts/AppLayout"
import { MatterWorkspace, type WorkspaceTab } from "@/components/matter-workspace"
import { api } from "@/lib/api-client"
import type { MatterWorkspace as MatterWorkspaceData } from "@/types/matter-workspace"

interface Props {
  projectId: number
}

export default function ProjectShow({ projectId }: Props) {
  const [data, setData] = useState<MatterWorkspaceData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  // ?tab=deadlines (etc.) opens the case on that tab, e.g. from Patent Portfolio → Action Required.
  const TABS: WorkspaceTab[] = ["overview", "lifecycle", "deadlines", "family", "documents", "forms", "discussion", "tasks", "costs", "audit"]
  const [tab, setTabState] = useState<WorkspaceTab>(() => {
    const wanted = typeof window === "undefined" ? null : new URLSearchParams(window.location.search).get("tab")
    return wanted && (TABS as string[]).includes(wanted) ? (wanted as WorkspaceTab) : "overview"
  })
  const setTab = (next: WorkspaceTab) => {
    setTabState(next)
    const url = new URL(window.location.href)
    if (next === "overview") url.searchParams.delete("tab"); else url.searchParams.set("tab", next)
    window.history.replaceState(window.history.state, "", url.toString())
  }

  useEffect(() => {
    let active = true
    setLoading(true)
    setError(null)
    api.getMatterWorkspace(projectId)
      .then((workspace) => { if (active) setData(workspace) })
      .catch((reason: unknown) => {
        if (!active) return
        setError(reason instanceof Error ? reason.message : "The matter workspace could not be loaded.")
      })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [projectId])

  // Quiet refresh (no spinner) after an edit inside a tab.
  const reload = () => { api.getMatterWorkspace(projectId).then(setData).catch(() => { /* keep current data */ }) }

  return (
    <AppLayout>
      <Head title={data?.project.project_name || "Matter Workspace"} />
      {loading ? (
        <div className="flex min-h-[70vh] items-center justify-center"><Loader2 className="h-7 w-7 animate-spin text-gold" /></div>
      ) : error || !data ? (
        <div className="flex min-h-[70vh] flex-col items-center justify-center px-6 text-center"><p className="text-base font-semibold">Matter workspace unavailable</p><p className="mt-2 max-w-lg text-sm text-muted-foreground">{error || "This matter was not found or is outside your access scope."}</p></div>
      ) : (
        <MatterWorkspace data={data} projectId={projectId} tab={tab} onTabChange={setTab} onReload={reload} />
      )}
    </AppLayout>
  )
}
