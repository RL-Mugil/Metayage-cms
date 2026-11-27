import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Search, ShieldCheck } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { FitPage } from "@/components/modern/fit-page";
import { TableCard } from "@/components/modern/table-card";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

interface Row { id: number; at: string | null; user: string; action: string; what: string | null; ip: string | null }
interface Data { data: Row[]; total: number; current_page: number; last_page: number; users: { id: number; name: string }[] }

/** A client's own audit log: what their users did in the portal, and changes to their logins. */
export default function PortalAudit() {
  const [data, setData] = useState<Data | null>(null);
  const [page, setPage] = useState(1);
  const [userId, setUserId] = useState("");
  const [search, setSearch] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const t = window.setTimeout(() => {
      setLoading(true);
      const p = new URLSearchParams({ page: String(page), per_page: "30" });
      if (userId) p.set("user_id", userId);
      if (search.trim()) p.set("search", search.trim());
      if (from) p.set("from", from);
      if (to) p.set("to", to);
      api.request<Data>(`/my-portal/audit?${p}`).then(setData).catch(() => setData(null)).finally(() => setLoading(false));
    }, 250);
    return () => window.clearTimeout(t);
  }, [page, userId, search, from, to]);

  return (
    <AppLayout>
      <Head title="Audit Log" />
      <FitPage eyebrow="Account" title="Audit Log" summary="What your users did in the portal, and changes to their logins and access">
        <div className="flex min-h-0 flex-1 flex-col gap-2.5">
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            <div className="relative min-w-[200px] flex-1">
              <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} placeholder="Search action (e.g. approve, signed in)…"
                className="h-8 w-full rounded-md border border-border bg-background/70 pl-8 pr-3 text-sm focus:outline-none focus:ring-2 focus:ring-gold/25" />
            </div>
            <select value={userId} onChange={(e) => { setUserId(e.target.value); setPage(1); }} className="h-8 rounded-md border border-border bg-background/70 px-2 text-xs">
              <option value="">All users</option>{data?.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
            </select>
            <input type="date" value={from} onChange={(e) => { setFrom(e.target.value); setPage(1); }} className="h-8 rounded-md border border-border bg-background/70 px-1.5 text-xs" />
            <span className="text-muted-foreground">–</span>
            <input type="date" value={to} onChange={(e) => { setTo(e.target.value); setPage(1); }} className="h-8 rounded-md border border-border bg-background/70 px-1.5 text-xs" />
          </div>
          <TableCard footer={data && data.total > 0 && (
            <div className="flex items-center justify-between px-4 py-1.5 text-xs text-muted-foreground">
              <span>{data.total.toLocaleString("en-IN")} entries</span>
              <div className="flex items-center gap-1">
                <Button size="sm" variant="outline" className="h-7" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Prev</Button>
                <span className="px-1">{data.current_page}/{data.last_page}</span>
                <Button size="sm" variant="outline" className="h-7" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
              </div>
            </div>
          )}>
            <table className="w-full text-xs">
              <thead className="table-sticky-head text-[10px] uppercase tracking-wider text-muted-foreground">
                <tr><th className="px-3 py-2 text-left">When</th><th className="px-3 py-2 text-left">Who</th><th className="px-3 py-2 text-left">What</th><th className="px-3 py-2 text-left">About</th><th className="px-3 py-2 text-left">IP</th></tr>
              </thead>
              <tbody className="table-rows-3d">
                {loading && !data && <tr><td colSpan={5} className="py-12 text-center"><Loader2 className="mx-auto h-5 w-5 animate-spin text-gold" /></td></tr>}
                {data && data.data.length === 0 && <tr><td colSpan={5} className="py-12 text-center text-muted-foreground"><ShieldCheck className="mx-auto mb-1 h-5 w-5" />Nothing recorded for this view.</td></tr>}
                {data?.data.map((r) => (
                  <tr key={r.id} className="border-t border-border/60">
                    <td className="whitespace-nowrap px-3 py-2 font-mono text-[11px]">{r.at ? new Date(r.at).toLocaleString("en-IN") : "—"}</td>
                    <td className="px-3 py-2">{r.user}</td>
                    <td className="px-3 py-2 font-medium">{r.action}</td>
                    <td className="px-3 py-2 text-muted-foreground">{r.what ?? ""}</td>
                    <td className="px-3 py-2 font-mono text-[11px] text-muted-foreground">{r.ip ?? ""}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </TableCard>
        </div>
      </FitPage>
    </AppLayout>
  );
}
