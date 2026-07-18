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
  const [tab, setTab] = useState<WorkspaceTab>("overview")

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

  return (
    <AppLayout>
      <Head title={data?.project.project_name || "Matter Workspace"} />
      {loading ? (
        <div className="flex min-h-[70vh] items-center justify-center"><Loader2 className="h-7 w-7 animate-spin text-gold" /></div>
      ) : error || !data ? (
        <div className="flex min-h-[70vh] flex-col items-center justify-center px-6 text-center"><p className="text-base font-semibold">Matter workspace unavailable</p><p className="mt-2 max-w-lg text-sm text-muted-foreground">{error || "This matter was not found or is outside your access scope."}</p></div>
      ) : (
        <MatterWorkspace data={data} projectId={projectId} tab={tab} onTabChange={setTab} />
      )}
    </AppLayout>
  )
}
