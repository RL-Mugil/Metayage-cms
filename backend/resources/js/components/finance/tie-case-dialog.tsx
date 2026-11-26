import { useEffect, useState } from "react"
import { toast } from "sonner"
import { Link2, Loader2, Sparkles } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { ProjectCombobox } from "@/components/project-combobox"
import { api } from "@/lib/api-client"

interface Suggestion { id: number; docket: string; title: string | null; client: string | null; why: string }
interface Sibling { id: number; kind: string; amount_bcy: number }
export interface TieCost { id: number; kind_label: string; vendor_name: string | null; vendor_reference: string | null; amount_bcy: number }

const inr = (v: number) => `₹${Number(v).toLocaleString("en-IN", { maximumFractionDigits: 2 })}`

/**
 * Tie a matter cost to a case: suggested cases first (docket on the bill, same invention, same
 * client), or search any case; then optionally the other unassigned lines of the same bill.
 */
export function TieCaseDialog({ cost, onClose, onDone }: { cost: TieCost; onClose: () => void; onDone: () => void }) {
  const [projects, setProjects] = useState<any[]>([])
  const [suggestions, setSuggestions] = useState<Suggestion[]>([])
  const [siblings, setSiblings] = useState<Sibling[]>([])
  const [loading, setLoading] = useState(true)
  const [projectId, setProjectId] = useState("")
  const [withSiblings, setWithSiblings] = useState(true)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    api.getProjects().then((p: any) => setProjects(Array.isArray(p) ? p : p?.data ?? [])).catch(() => setProjects([]))
    api.request<{ suggestions: Suggestion[]; siblings: Sibling[] }>(`/finance/costs/${cost.id}/case-suggestions`)
      .then((r) => { setSuggestions(r.suggestions ?? []); setSiblings(r.siblings ?? []); if (r.suggestions?.[0]?.why.startsWith("Docket")) setProjectId(String(r.suggestions[0].id)) })
      .catch(() => {}).finally(() => setLoading(false))
  }, [cost.id])

  const save = async () => {
    if (!projectId) { toast.error("Pick a case."); return }
    setSaving(true)
    try {
      const ids = [cost.id, ...(withSiblings ? siblings.map((s) => s.id) : [])]
      const r = await api.request<{ assigned: number }>("/finance/costs/assign", { method: "POST", body: JSON.stringify({ ids, project_id: Number(projectId) }) })
      toast.success(r.assigned > 1 ? `${r.assigned} costs tied to the case` : "Cost tied to the case")
      onDone()
    } catch (e) { toast.error((e as Error).message) } finally { setSaving(false) }
  }

  return (
    <Dialog open onOpenChange={(v) => !v && onClose()}>
      <DialogContent flip3d className="max-w-xl">
        <DialogHeader><DialogTitle className="flex items-center gap-2"><Link2 className="h-4 w-4 text-gold" />Tie to a case</DialogTitle></DialogHeader>
        <p className="text-xs text-muted-foreground">
          {cost.kind_label} · {[cost.vendor_name, cost.vendor_reference].filter(Boolean).join(" · ")} · <span className="font-semibold text-foreground">{inr(cost.amount_bcy)}</span>
        </p>
        <div>
          <p className="mb-1 flex items-center gap-1 text-xs font-medium"><Sparkles className="h-3.5 w-3.5 text-gold" />Suggested cases</p>
          {loading ? <Loader2 className="h-4 w-4 animate-spin text-gold" /> : suggestions.length === 0 ? (
            <p className="text-xs text-muted-foreground">No case found from the bill. Search below.</p>
          ) : (
            <div className="thin-scrollbar max-h-52 space-y-1 overflow-y-auto pr-1">
              {suggestions.map((s) => (
                <button key={s.id} onClick={() => setProjectId(String(s.id))}
                  className={`card-3d flex w-full items-start justify-between gap-2 rounded-lg border px-2.5 py-1.5 text-left text-xs ${projectId === String(s.id) ? "border-gold bg-gold/10" : "border-border hover:bg-muted"}`}>
                  <span className="min-w-0"><span className="font-mono font-semibold">{s.docket}</span> <span className="text-muted-foreground">{s.title}</span>
                    <span className="block truncate text-[10px] text-muted-foreground">{s.client}</span></span>
                  <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">{s.why}</span>
                </button>
              ))}
            </div>
          )}
        </div>
        <label className="text-xs">Or search any case<div className="mt-1"><ProjectCombobox value={projectId} projects={projects} onSelect={(id) => setProjectId(id)} /></div></label>
        {siblings.length > 0 && (
          <label className="flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/5 p-2 text-xs">
            <input type="checkbox" className="mt-0.5" checked={withSiblings} onChange={(e) => setWithSiblings(e.target.checked)} />
            <span>Also tie the other {siblings.length} unassigned line{siblings.length > 1 ? "s" : ""} of bill <span className="font-mono">{cost.vendor_reference}</span> ({inr(siblings.reduce((a, s) => a + s.amount_bcy, 0))}) to this case</span>
          </label>
        )}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button className="bg-gold text-black hover:bg-gold/90" disabled={saving || !projectId} onClick={save}>{saving && <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />}Tie to case</Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
