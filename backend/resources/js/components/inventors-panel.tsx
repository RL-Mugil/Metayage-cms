import { useState } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { CheckCircle2, KeyRound, Loader2, TriangleAlert, Users } from "lucide-react"
import { toast } from "sonner"
import { api } from "@/lib/api-client"
import type { InventorRecord } from "@/types/ipo-forms"

/**
 * The inventors named on a case (edited in the case form or the Forms tab). An inventor
 * needs no login; staff can optionally give one portal access, which shows them only
 * the cases they are named on. The password is shared directly (no email is sent).
 */
export function InventorsPanel({ projectId, canManage }: { projectId: number | string; canManage: boolean }) {
  const key = ["project-inventors", projectId]
  const { data: inventors = [], isLoading } = useQuery<InventorRecord[]>({ queryKey: key, queryFn: () => api.getProjectInventors(projectId) })
  const [granting, setGranting] = useState<number | null>(null)

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="flex items-center gap-1.5 text-sm font-semibold"><Users className="h-3.5 w-3.5" /> Inventors</h2>
        <span className="text-xs text-muted-foreground">Edit them in the case form or the Forms tab</span>
      </div>
      {isLoading ? (
        <div className="flex justify-center py-4"><Loader2 className="h-4 w-4 animate-spin text-gold" /></div>
      ) : inventors.length === 0 ? (
        <p className="py-2 text-xs text-muted-foreground">No inventors added to this case yet.</p>
      ) : (
        <div className="space-y-2">
          {inventors.map((inventor, index) => (
            <div key={inventor.id} className="rounded-md bg-muted px-2.5 py-1.5 text-xs">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground">{index + 1}.</span>
                {inventor.complete === true && <CheckCircle2 className="h-3 w-3 text-emerald-600" />}
                {inventor.complete === false && <TriangleAlert className="h-3 w-3 text-amber-500" />}
                <span className="font-medium">{inventor.full_name}</span>
                <span className="text-muted-foreground">{[inventor.city, inventor.country].filter(Boolean).join(", ")}</span>
                {inventor.has_portal_access
                  ? <span className="ml-auto rounded bg-background px-1.5 py-0.5 text-[10px]">Portal access</span>
                  : canManage && (
                    <button onClick={() => setGranting(granting === inventor.id ? null : inventor.id)} className="ml-auto flex items-center gap-1 font-medium text-gold hover:underline">
                      <KeyRound className="h-3 w-3" /> Give portal access
                    </button>
                  )}
              </div>
              {granting === inventor.id && (
                <PortalAccessForm inventor={inventor} onDone={() => setGranting(null)} invalidateKey={key} />
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

function PortalAccessForm({ inventor, onDone, invalidateKey }: { inventor: InventorRecord; onDone: () => void; invalidateKey: unknown[] }) {
  const queryClient = useQueryClient()
  const [email, setEmail] = useState(inventor.email ?? "")
  const [password, setPassword] = useState("")
  const grant = useMutation({
    mutationFn: () => api.grantInventorPortal(inventor.id, { email: email.trim(), password }),
    onSuccess: () => {
      toast.success(`Portal login created for ${inventor.full_name}. Share the password with them directly.`)
      void queryClient.invalidateQueries({ queryKey: invalidateKey })
      onDone()
    },
    onError: (failure: Error) => toast.error(failure.message),
  })

  return (
    <div className="mt-2 flex flex-wrap gap-2">
      <input value={email} onChange={(event) => setEmail(event.target.value)} placeholder="Email" type="email"
        className="h-7 min-w-[12rem] flex-1 rounded border border-border bg-background px-2 text-xs" />
      <input value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Password (min. 8)"
        className="h-7 w-40 rounded border border-border bg-background px-2 font-mono text-xs" />
      <button onClick={() => grant.mutate()} disabled={grant.isPending || !email.trim() || password.length < 8}
        className="h-7 rounded bg-gold px-3 text-xs font-medium text-black hover:bg-gold/90 disabled:opacity-50">
        {grant.isPending ? <Loader2 className="h-3 w-3 animate-spin" /> : "Create login"}
      </button>
      <button onClick={onDone} className="h-7 rounded border border-border px-3 text-xs">Cancel</button>
    </div>
  )
}
