import { useEffect, useRef } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { Loader2, Trash2, Upload } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { api } from "@/lib/api-client"

const MAX_BYTES = 2 * 1024 * 1024
const TYPES = ["image/png", "image/jpeg"]

/**
 * Signature image of a Registered Patent Agent (PNG/JPG up to 2 MB). Saved straight away
 * on the server (private) and placed in the signature block of the Indian Patent Forms.
 */
export function AgentSignatureField({ employeeId, savedAsAgent }: { employeeId: number; savedAsAgent: boolean }) {
  const queryClient = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const key = ["agent-signature", employeeId]
  const { data: url, isLoading } = useQuery({
    queryKey: key,
    queryFn: () => api.agentSignatureObjectUrl(employeeId),
    enabled: savedAsAgent,
    retry: false,
    gcTime: 0,
  })

  useEffect(() => () => { if (url) URL.revokeObjectURL(url) }, [url])

  const refresh = () => queryClient.invalidateQueries({ queryKey: key })
  const upload = useMutation({
    mutationFn: (file: File) => api.uploadAgentSignature(employeeId, file),
    onSuccess: () => { refresh(); toast.success("Signature saved.") },
    onError: (failure: Error) => toast.error(failure.message),
  })
  const remove = useMutation({
    mutationFn: () => api.deleteAgentSignature(employeeId),
    onSuccess: () => { refresh(); toast.success("Signature removed.") },
    onError: (failure: Error) => toast.error(failure.message),
  })

  function pick(file: File | undefined) {
    if (inputRef.current) inputRef.current.value = ""
    if (!file) return
    if (!TYPES.includes(file.type)) { toast.error("The signature must be a PNG or JPG image."); return }
    if (file.size > MAX_BYTES) { toast.error("The signature image must be 2 MB or smaller."); return }
    upload.mutate(file)
  }

  if (!savedAsAgent) {
    return <p className="mt-2 text-xs text-muted-foreground">Save the employee as a Registered Patent Agent first, then add the signature image.</p>
  }

  const busy = upload.isPending || remove.isPending
  return (
    <div className="mt-3">
      <div className="mb-1 text-xs font-medium text-muted-foreground">Signature image</div>
      <div className="flex flex-wrap items-center gap-3">
        <div className="flex h-16 w-40 items-center justify-center rounded border border-dashed border-border bg-white">
          {isLoading ? <Loader2 className="h-4 w-4 animate-spin text-gold" />
            : url ? <img src={url} alt="Patent agent signature" className="max-h-14 max-w-[9.5rem] object-contain" />
            : <span className="text-[11px] text-muted-foreground">No signature</span>}
        </div>
        <input ref={inputRef} type="file" accept="image/png,image/jpeg" className="hidden" onChange={e => pick(e.target.files?.[0])} />
        <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => inputRef.current?.click()}>
          {upload.isPending ? <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" /> : <Upload className="mr-1.5 h-3.5 w-3.5" />}
          {url ? "Replace" : "Upload"}
        </Button>
        {url && (
          <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={() => remove.mutate()}>
            <Trash2 className="mr-1.5 h-3.5 w-3.5" />Remove
          </Button>
        )}
      </div>
      <p className="mt-1 text-xs text-muted-foreground">PNG or JPG, 2 MB or smaller. Printed above the agent&apos;s name on Form 1 and Form 2.</p>
    </div>
  )
}
