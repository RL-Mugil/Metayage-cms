import type { ReactNode, KeyboardEvent } from "react";
import { useEffect, useRef, useState, useCallback } from "react";
import { usePage, Link, router } from "@inertiajs/react";
import { Search, Bell, HelpCircle, X, Users, Briefcase, ListChecks } from "lucide-react";
import { SidebarProvider, SidebarTrigger } from "@/components/ui/sidebar";
import { AppSidebar } from "@/components/app-sidebar";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { api } from "@/lib/api-client";
import type { SearchResult } from "@/lib/api-client";

interface Props {
  children: ReactNode;
}

const TYPE_ICON: Record<string, ReactNode> = {
  client:  <Users className="h-3.5 w-3.5" />,
  project: <Briefcase className="h-3.5 w-3.5" />,
  task:    <ListChecks className="h-3.5 w-3.5" />,
};
const TYPE_LABEL: Record<string, string> = {
  client: "Client", project: "Project", task: "Task",
};
const TYPE_COLOR: Record<string, string> = {
  client:  "bg-blue-500/10 text-blue-400",
  project: "bg-purple-500/10 text-purple-400",
  task:    "bg-emerald-500/10 text-emerald-400",
};

const SHORTCUTS = [
  { keys: ["Ctrl", "K"], label: "Focus global search" },
  { keys: ["/"],          label: "Focus global search" },
  { keys: ["Esc"],        label: "Close search / modal" },
  { keys: ["↑", "↓"],    label: "Navigate search results" },
  { keys: ["Enter"],      label: "Go to selected result" },
];

export default function AppLayout({ children }: Props) {
  const { props } = usePage() as any;
  const initialCount = props.auth?.user?.unread_notifications ?? 0;
  const [notifCount, setNotifCount] = useState<number>(initialCount);

  // ── Search state ──
  const [query, setQuery]       = useState("");
  const [results, setResults]   = useState<SearchResult[]>([]);
  const [searching, setSearching] = useState(false);
  const [open, setOpen]         = useState(false);
  const [activeIdx, setActiveIdx] = useState(-1);
  const searchRef               = useRef<HTMLInputElement>(null);
  const dropdownRef             = useRef<HTMLDivElement>(null);
  const debounceRef             = useRef<ReturnType<typeof setTimeout> | null>(null);

  // ── Help modal ──
  const [helpOpen, setHelpOpen] = useState(false);

  // Bell polling
  useEffect(() => {
    let active = true;
    const poll = () =>
      api.getUnreadNotificationCount()
        .then((c) => { if (active) setNotifCount(c); })
        .catch(() => {});
    poll();
    const t = setInterval(poll, 60000);
    return () => { active = false; clearInterval(t); };
  }, []);

  // Debounced search
  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    if (query.trim().length < 2) {
      setResults([]);
      setOpen(false);
      return;
    }
    debounceRef.current = setTimeout(async () => {
      setSearching(true);
      try {
        const { results: r } = await api.globalSearch(query.trim());
        setResults(r);
        setOpen(true);
        setActiveIdx(-1);
      } catch {
        setResults([]);
      } finally {
        setSearching(false);
      }
    }, 300);
    return () => { if (debounceRef.current) clearTimeout(debounceRef.current); };
  }, [query]);

  // Close on outside click
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (
        searchRef.current && !searchRef.current.contains(e.target as Node) &&
        dropdownRef.current && !dropdownRef.current.contains(e.target as Node)
      ) {
        setOpen(false);
      }
    };
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  // Global keyboard shortcut: "/" or Ctrl+K focuses search
  useEffect(() => {
    const handler = (e: globalThis.KeyboardEvent) => {
      const tag = (e.target as HTMLElement).tagName;
      if (tag === "INPUT" || tag === "TEXTAREA") return;
      if (e.key === "/" || (e.ctrlKey && e.key === "k")) {
        e.preventDefault();
        searchRef.current?.focus();
      }
    };
    document.addEventListener("keydown", handler);
    return () => document.removeEventListener("keydown", handler);
  }, []);

  const navigate = useCallback((url: string) => {
    setOpen(false);
    setQuery("");
    setResults([]);
    router.visit(url);
  }, []);

  const handleKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (!open) return;
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setActiveIdx((i) => Math.min(i + 1, results.length - 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setActiveIdx((i) => Math.max(i - 1, -1));
    } else if (e.key === "Enter" && activeIdx >= 0) {
      e.preventDefault();
      navigate(results[activeIdx].url);
    } else if (e.key === "Escape") {
      setOpen(false);
      searchRef.current?.blur();
    }
  };

  return (
    <SidebarProvider>
      <div className="flex min-h-screen w-full bg-background">
        <AppSidebar />
        <div className="flex flex-1 flex-col min-w-0">
          <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-border bg-background/85 px-4 backdrop-blur">
            <SidebarTrigger />

            {/* ── Global search ── */}
            <div className="relative max-w-md flex-1">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
              <Input
                ref={searchRef}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onFocus={() => { if (results.length) setOpen(true); }}
                onKeyDown={handleKeyDown}
                placeholder="Search matters, clients, tasks… (/ or Ctrl+K)"
                className="h-9 pl-9 pr-8 bg-secondary/60 border-transparent focus-visible:bg-background"
              />
              {query && (
                <button
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                  onClick={() => { setQuery(""); setResults([]); setOpen(false); searchRef.current?.focus(); }}
                  tabIndex={-1}
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              )}

              {/* Dropdown */}
              {open && (
                <div
                  ref={dropdownRef}
                  className="absolute top-full left-0 right-0 mt-1 z-50 rounded-md border border-border bg-popover shadow-xl overflow-hidden"
                >
                  {searching ? (
                    <div className="px-4 py-3 text-sm text-muted-foreground">Searching…</div>
                  ) : results.length === 0 ? (
                    <div className="px-4 py-3 text-sm text-muted-foreground">No results for "{query}"</div>
                  ) : (
                    <ul>
                      {results.map((r, i) => (
                        <li key={`${r.type}-${r.id}`}>
                          <button
                            className={`w-full flex items-center gap-3 px-4 py-2.5 text-left text-sm transition-colors hover:bg-accent ${i === activeIdx ? "bg-accent" : ""}`}
                            onClick={() => navigate(r.url)}
                            onMouseEnter={() => setActiveIdx(i)}
                          >
                            <span className={`flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium shrink-0 ${TYPE_COLOR[r.type]}`}>
                              {TYPE_ICON[r.type]}
                              {TYPE_LABEL[r.type]}
                            </span>
                            <span className="flex flex-col min-w-0">
                              <span className="font-medium truncate">{r.title}</span>
                              <span className="text-[11px] text-muted-foreground truncate">{r.subtitle}</span>
                            </span>
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              )}
            </div>

            <div className="ml-auto flex items-center gap-2">
              <Badge variant="outline" className="hidden md:inline-flex border-gold/40 text-gold">
                Production · v2.0
              </Badge>

              {/* Help button */}
              <Button variant="ghost" size="icon" onClick={() => setHelpOpen(true)} aria-label="Keyboard shortcuts">
                <HelpCircle className="h-4 w-4" />
              </Button>

              {/* Bell */}
              <Button variant="ghost" size="icon" className="relative" asChild>
                <Link href="/notifications" aria-label="Notifications">
                  <Bell className="h-4 w-4" />
                  {notifCount > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-gold px-1 text-[10px] font-semibold text-black">
                      {notifCount > 99 ? "99+" : notifCount}
                    </span>
                  )}
                </Link>
              </Button>
            </div>
          </header>

          <main className="flex-1 min-w-0">{children}</main>
        </div>
      </div>

      {/* ── Help / keyboard shortcuts modal ── */}
      <Dialog open={helpOpen} onOpenChange={setHelpOpen}>
        <DialogContent className="max-w-sm">
          <DialogHeader>
            <DialogTitle>Keyboard shortcuts</DialogTitle>
          </DialogHeader>
          <div className="space-y-2 pt-1">
            {SHORTCUTS.map(({ keys, label }) => (
              <div key={label} className="flex items-center justify-between gap-4 text-sm">
                <span className="text-muted-foreground">{label}</span>
                <span className="flex gap-1 shrink-0">
                  {keys.map((k) => (
                    <kbd key={k} className="rounded border border-border bg-muted px-2 py-0.5 font-mono text-[11px] font-medium">
                      {k}
                    </kbd>
                  ))}
                </span>
              </div>
            ))}
          </div>
          <p className="pt-2 text-xs text-muted-foreground">
            Search navigates to the list page for the matched entity. Use the page's own filters to narrow further.
          </p>
        </DialogContent>
      </Dialog>
    </SidebarProvider>
  );
}
