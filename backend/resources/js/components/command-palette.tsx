import { useEffect, useMemo, useRef, useState } from "react"
import { router } from "@inertiajs/react"
import { Briefcase, CalendarClock, FileText, GitBranch, Inbox, LayoutDashboard, Loader2, Receipt, Search, Users, Wallet } from "lucide-react"
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator } from "@/components/ui/command"
import { Dialog, DialogContent, DialogTitle } from "@/components/ui/dialog"
import { api } from "@/lib/api-client"
import type { SearchResult } from "@/types"

const PAGES: { label: string; url: string; icon: React.ComponentType<{ className?: string }>; staff?: boolean; client?: boolean }[] = [
  { label: "Dashboard", url: "/", icon: LayoutDashboard, staff: true, client: true },
  { label: "Patent Portfolio", url: "/patent-portfolio", icon: Briefcase, staff: true, client: true },
  { label: "Pending Payments", url: "/pending-payments", icon: Wallet, staff: true, client: true },
  { label: "Cases", url: "/projects", icon: FileText, staff: true },
  { label: "Clients", url: "/clients", icon: Users, staff: true },
  { label: "Financial Suite", url: "/financial", icon: Receipt, staff: true },
  { label: "Project Tracker", url: "/tracker", icon: GitBranch, staff: true },
  { label: "Discussions", url: "/discussions", icon: Inbox, staff: true, client: true },
  { label: "Notifications", url: "/notifications", icon: CalendarClock, staff: true, client: true },
]

/**
 * Ctrl+K palette: jump to any page, case, client, task or invoice, and run the common
 * case actions (open its deadlines, lifecycle, documents or payments) straight from a result.
 */
export function CommandPalette({ open, onOpenChange, isClient }: { open: boolean; onOpenChange: (open: boolean) => void; isClient: boolean }) {
  const [query, setQuery] = useState("")
  const [results, setResults] = useState<SearchResult[]>([])
  const [loading, setLoading] = useState(false)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => { if (!open) { setQuery(""); setResults([]) } }, [open])
  useEffect(() => {
    if (timer.current) clearTimeout(timer.current)
    if (query.trim().length < 2) { setResults([]); return }
    timer.current = setTimeout(async () => {
      setLoading(true)
      try { setResults((await api.globalSearch(query.trim())).results) } catch { setResults([]) } finally { setLoading(false) }
    }, 250)
    return () => { if (timer.current) clearTimeout(timer.current) }
  }, [query])

  const go = (url: string) => { onOpenChange(false); router.visit(url) }
  const pages = useMemo(() => PAGES.filter((p) => (isClient ? p.client : p.staff))
    .filter((p) => !query || p.label.toLowerCase().includes(query.toLowerCase())), [query, isClient])
  const projects = results.filter((r) => r.type === "project")
  const others = results.filter((r) => r.type !== "project")

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="overflow-hidden p-0 sm:max-w-xl">
        <DialogTitle className="sr-only">Go to or do anything</DialogTitle>
        <Command shouldFilter={false} className="[&_[cmdk-group-heading]]:px-2 [&_[cmdk-group-heading]]:text-xs [&_[cmdk-group-heading]]:text-muted-foreground [&_[cmdk-item]]:py-2">
          <CommandInput value={query} onValueChange={setQuery} placeholder="Type a docket, client, invoice number or page…" />
          <CommandList className="max-h-[60vh]">
            {loading && <div className="flex items-center gap-2 px-4 py-3 text-xs text-muted-foreground"><Loader2 className="h-3.5 w-3.5 animate-spin" /> Searching…</div>}
            {!loading && query.length >= 2 && results.length === 0 && pages.length === 0 && <CommandEmpty>No matches.</CommandEmpty>}

            {projects.length > 0 && (
              <CommandGroup heading="Cases">
                {projects.map((p) => (
                  <div key={`p${p.id}`}>
                    <CommandItem value={`p-${p.id}`} onSelect={() => go(`/projects/${p.id}`)}>
                      <FileText className="mr-2 h-4 w-4 text-gold" />
                      <div className="min-w-0"><p className="truncate font-mono text-sm">{p.title}</p><p className="truncate text-xs text-muted-foreground">{p.subtitle}</p></div>
                    </CommandItem>
                    <div className="flex flex-wrap gap-1 pb-1.5 pl-9">
                      {[["deadlines", "Deadlines"], ["lifecycle", "Lifecycle"], ["documents", "Documents"]].map(([tab, label]) => (
                        <CommandItem key={tab} value={`p-${p.id}-${tab}`} onSelect={() => go(`/projects/${p.id}?tab=${tab}`)}
                          className="h-6 rounded border border-border px-2 py-0 text-[11px]">{label}</CommandItem>
                      ))}
                      <CommandItem value={`p-${p.id}-pay`} onSelect={() => go(`/pending-payments?search=${encodeURIComponent(p.title)}`)}
                        className="h-6 rounded border border-border px-2 py-0 text-[11px]">Payments</CommandItem>
                    </div>
                  </div>
                ))}
              </CommandGroup>
            )}

            {others.length > 0 && (
              <CommandGroup heading="Clients, tasks, invoices">
                {others.map((r) => (
                  <CommandItem key={`${r.type}${r.id}`} value={`${r.type}-${r.id}`} onSelect={() => go(r.url)}>
                    {r.type === "invoice" ? <Receipt className="mr-2 h-4 w-4 text-emerald-500" /> : r.type === "client" ? <Users className="mr-2 h-4 w-4 text-blue-500" /> : <Search className="mr-2 h-4 w-4" />}
                    <div className="min-w-0"><p className="truncate text-sm">{r.title}</p><p className="truncate text-xs text-muted-foreground">{r.type} · {r.subtitle}</p></div>
                  </CommandItem>
                ))}
              </CommandGroup>
            )}

            {pages.length > 0 && <>
              {results.length > 0 && <CommandSeparator />}
              <CommandGroup heading="Go to">
                {pages.map((p) => (
                  <CommandItem key={p.url} value={`page-${p.url}`} onSelect={() => go(p.url)}>
                    <p.icon className="mr-2 h-4 w-4 text-muted-foreground" /> {p.label}
                  </CommandItem>
                ))}
              </CommandGroup>
            </>}
          </CommandList>
          <div className="border-t border-border px-3 py-1.5 text-[10px] text-muted-foreground">↑↓ to move · Enter to open · Esc to close · Ctrl+K anywhere</div>
        </Command>
      </DialogContent>
    </Dialog>
  )
}
