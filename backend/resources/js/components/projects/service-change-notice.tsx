import { useEffect, useState } from "react"
import { AlertTriangle, Loader2 } from "lucide-react"
import { api, type ServiceChangePreview } from "@/lib/api-client"

/**
 * Edit form: when the service code (or office) differs from the saved one, shows what the
 * save will do — new docket, the workflow it switches to and the stage the case lands on.
 * The old stages are kept as a snapshot.
 */
export function ServiceChangeNotice({ projectId, fromService, fromOffice, service, office }: {
  projectId: number
  fromService: string
  fromOffice: string
  service: string
  office: string
}) {
  const changed = (service || "").toUpperCase() !== (fromService || "").toUpperCase() || (office || "").toUpperCase() !== (fromOffice || "").toUpperCase()
  const valid = /^[A-Z0-9]{2}$/i.test(office || "") && /^[A-Z0-9]{3,5}$/i.test(service || "")
  const [preview, setPreview] = useState<ServiceChangePreview | null>(null)
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (!changed || !valid) { setPreview(null); return }
    setLoading(true)
    const t = window.setTimeout(() => {
      api.getServiceChangePreview(projectId, office, service).then(setPreview).catch(() => setPreview(null)).finally(() => setLoading(false))
    }, 250)
    return () => window.clearTimeout(t)
  }, [projectId, service, office, changed, valid])

  if (!changed || !valid) return null

  return (
    <div className="mt-3 rounded-xl border border-amber-300/70 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
      <div className="flex items-center gap-1.5 font-semibold">
        <AlertTriangle className="h-3.5 w-3.5" />
        Service {fromService.toUpperCase()} → {service.toUpperCase()}: saving changes this case's workflow
        {loading && <Loader2 className="h-3 w-3 animate-spin" />}
      </div>
      {preview && (
        <ul className="mt-1.5 list-disc space-y-0.5 pl-5">
          {preview.docket_error
            ? <li className="font-semibold text-red-700">{preview.docket_error} — pick another service or office.</li>
            : <li>Docket becomes <span className="font-mono font-semibold">{preview.docket}</span></li>}
          <li>Workflow: {preview.workflow} ({preview.stages.length} stages)</li>
          <li>Lands on: <span className="font-semibold">{preview.lands_on ?? "all stages complete"}</span></li>
          <li>The current stages are kept as a snapshot.</li>
        </ul>
      )}
    </div>
  )
}
