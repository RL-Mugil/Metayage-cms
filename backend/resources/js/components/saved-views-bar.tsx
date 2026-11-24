import { useEffect, useState } from "react"
import { toast } from "sonner"
import { Bookmark, Plus, X } from "lucide-react"
import { api, type SavedViewRow } from "@/lib/api-client"

/**
 * A person's saved filter sets for a page, as chips: click one to apply it, × to delete,
 * "Save view" stores the current filters under a name (same name replaces it).
 */
export function SavedViewsBar({ page, current, onApply, hasFilters }: {
  page: string
  current: Record<string, unknown>
  onApply: (filters: Record<string, unknown>) => void
  hasFilters: boolean
}) {
  const [views, setViews] = useState<SavedViewRow[]>([])
  const [naming, setNaming] = useState(false)
  const [name, setName] = useState("")

  useEffect(() => { api.getSavedViews(page).then(setViews).catch(() => setViews([])) }, [page])

  const save = async () => {
    if (!name.trim()) return
    try {
      const view = await api.saveView(page, name.trim(), current)
      setViews((v) => [...v.filter((x) => x.name !== view.name), view].sort((a, b) => a.name.localeCompare(b.name)))
      setNaming(false); setName("")
      toast.success("View saved")
    } catch (e) { toast.error((e as Error).message) }
  }
  const remove = async (id: number) => {
    try { await api.deleteSavedView(id); setViews((v) => v.filter((x) => x.id !== id)) } catch (e) { toast.error((e as Error).message) }
  }

  if (views.length === 0 && !hasFilters && !naming) return null
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <Bookmark className="h-3.5 w-3.5 text-gold" />
      {views.map((v) => (
        <span key={v.id} className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/40 pl-2.5 pr-1 py-0.5 text-xs">
          <button onClick={() => onApply(v.filters)} className="hover:text-gold">{v.name}</button>
          <button onClick={() => remove(v.id)} title="Delete view" className="rounded-full p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"><X className="h-3 w-3" /></button>
        </span>
      ))}
      {naming ? (
        <span className="inline-flex items-center gap-1">
          <input autoFocus value={name} maxLength={60} onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") save(); if (e.key === "Escape") setNaming(false) }}
            placeholder="View name" className="h-6 w-36 rounded border border-border bg-background px-2 text-xs" />
          <button onClick={save} className="text-xs font-medium text-gold">Save</button>
          <button onClick={() => setNaming(false)} className="text-xs text-muted-foreground">Cancel</button>
        </span>
      ) : hasFilters && (
        <button onClick={() => setNaming(true)} className="inline-flex items-center gap-1 rounded-full border border-dashed border-gold/50 px-2.5 py-0.5 text-xs text-gold hover:bg-gold/10">
          <Plus className="h-3 w-3" /> Save view
        </button>
      )}
    </div>
  )
}
