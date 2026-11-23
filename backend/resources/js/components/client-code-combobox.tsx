import { useEffect, useId, useRef, useState } from "react"
import { useQuery } from "@tanstack/react-query"
import { Loader2, Search, X } from "lucide-react"
import { api } from "@/lib/api-client"

interface ClientOption {
  id: number
  client_code: string
  name: string
}

async function searchClients(term: string): Promise<ClientOption[]> {
  const params = new URLSearchParams({ search: term, per_page: "10", page: "1" })
  const result = await api.getClients(params)
  return (result.data ?? [])
    .filter((c: any) => c.client_code)
    .map((c: any) => ({ id: c.id, client_code: c.client_code, name: c.legal_name ?? c.company_name ?? "" }))
}

/**
 * Pick a client by code or name, searching all clients on the server (not just the page
 * on screen). The value is only ever a code picked from the list; typing alone saves nothing.
 */
export function ClientCodeCombobox({ value, onChange, excludeCode, className = "" }: {
  value: string
  onChange: (code: string) => void
  /** e.g. the client being edited, which cannot refer itself */
  excludeCode?: string | null
  className?: string
}) {
  const listId = useId()
  const wrapper = useRef<HTMLDivElement>(null)
  const [text, setText] = useState("")
  const [term, setTerm] = useState("")
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(0)

  useEffect(() => {
    const timer = setTimeout(() => setTerm(text.trim()), 250)
    return () => clearTimeout(timer)
  }, [text])

  useEffect(() => {
    const close = (event: MouseEvent) => { if (!wrapper.current?.contains(event.target as Node)) setOpen(false) }
    document.addEventListener("mousedown", close)
    return () => document.removeEventListener("mousedown", close)
  }, [])

  const { data: options = [], isFetching } = useQuery({
    queryKey: ["client-code-search", term],
    queryFn: () => searchClients(term),
    enabled: open && term.length > 0,
    staleTime: 30_000,
  })
  const shown = options.filter((o) => o.client_code !== excludeCode)

  // Name of the saved referrer, whichever page it is on.
  const { data: selected } = useQuery({
    queryKey: ["client-code-lookup", value],
    queryFn: async () => (await searchClients(value)).find((o) => o.client_code.toUpperCase() === value.toUpperCase()) ?? null,
    enabled: !!value,
    staleTime: 300_000,
  })

  function pick(option: ClientOption) {
    onChange(option.client_code)
    setText("")
    setOpen(false)
  }

  if (value && !open) {
    return (
      <div className={`flex h-8 items-center gap-2 rounded border border-border bg-background px-2 text-xs ${className}`}>
        <span className="font-mono font-semibold text-gold">{value}</span>
        <span className="truncate text-muted-foreground">{selected?.name ?? (selected === null ? "Not found in clients" : "")}</span>
        <button type="button" className="ml-auto text-muted-foreground hover:text-foreground" title="Change" onClick={() => setOpen(true)}>
          <Search className="h-3 w-3" />
        </button>
        <button type="button" className="text-muted-foreground hover:text-foreground" title="Clear" onClick={() => onChange("")}>
          <X className="h-3 w-3" />
        </button>
      </div>
    )
  }

  return (
    <div ref={wrapper} className={`relative ${className}`}>
      <input
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        autoFocus={!!value}
        value={text}
        placeholder="Type a client code or name…"
        onFocus={() => setOpen(true)}
        onChange={(event) => { setText(event.target.value); setOpen(true); setActive(0) }}
        onKeyDown={(event) => {
          if (event.key === "ArrowDown") { event.preventDefault(); setActive((i) => Math.min(i + 1, shown.length - 1)) }
          else if (event.key === "ArrowUp") { event.preventDefault(); setActive((i) => Math.max(i - 1, 0)) }
          else if (event.key === "Enter" && shown[active]) { event.preventDefault(); pick(shown[active]) }
          else if (event.key === "Escape") { setOpen(false); setText("") }
        }}
        className="h-8 w-full rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
      />
      {open && term.length > 0 && (
        <div id={listId} role="listbox" className="absolute z-20 mt-1 max-h-48 w-full overflow-y-auto rounded-md border border-border bg-background shadow-lg">
          {isFetching && shown.length === 0 ? (
            <div className="flex items-center gap-2 px-3 py-2 text-xs text-muted-foreground"><Loader2 className="h-3 w-3 animate-spin" />Searching…</div>
          ) : shown.length === 0 ? (
            <p className="px-3 py-2 text-xs text-muted-foreground">No client matches “{term}”.</p>
          ) : shown.map((option, index) => (
            <button key={option.id} type="button" role="option" aria-selected={index === active}
              onMouseEnter={() => setActive(index)} onClick={() => pick(option)}
              className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-xs ${index === active ? "bg-muted" : ""}`}>
              <span className="font-mono font-semibold text-gold">{option.client_code}</span>
              <span className="truncate text-muted-foreground">{option.name}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
