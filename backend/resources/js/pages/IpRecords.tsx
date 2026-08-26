import { Head, Link } from "@inertiajs/react"
import { useState } from "react"
import { useQuery } from "@tanstack/react-query"
import { Award, Loader2, Search, ShieldCheck } from "lucide-react"
import AppLayout from "@/layouts/AppLayout"
import { PageHeader } from "@/components/page-header"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
import { api } from "@/lib/api-client"

export default function IpRecords() {
  const [search, setSearch] = useState("")
  const [recordType, setRecordType] = useState("")
  const params = new URLSearchParams({ per_page: "100" })
  if (search.trim()) params.set("search", search.trim())
  if (recordType) params.set("record_type", recordType)
  const query = useQuery({ queryKey: ["ip-records", search, recordType], queryFn: () => api.getIpRecords(params) })
  const records = query.data?.data ?? []

  return <AppLayout>
    <Head title="IP Portfolio" />
    <PageHeader eyebrow="Projects & Docketing" title="IP Portfolio" description="Durable legal assets linked to MYPL service engagements" />
    <div className="space-y-5 px-8 py-6">
      <div className="grid gap-3 rounded-md border border-border bg-card p-4 md:grid-cols-[1fr_220px]">
        <label className="relative"><Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Find by UIN, title, mark, or client reference" className="w-full rounded-md border border-border bg-background py-2 pl-9 pr-3 text-sm" /></label>
        <select value={recordType} onChange={(event) => setRecordType(event.target.value)} className="rounded-md border border-border bg-background px-3 py-2 text-sm"><option value="">Patents and trademarks</option><option>Patent</option><option>Trademark</option></select>
      </div>
      {query.isLoading ? <div className="flex justify-center py-16"><Loader2 className="h-7 w-7 animate-spin text-gold" /></div> : query.isError ? <Card><CardContent className="py-10 text-center text-sm text-destructive">Unable to load the IP portfolio.</CardContent></Card> : records.length === 0 ? <Card><CardContent className="flex flex-col items-center gap-2 py-12 text-muted-foreground"><Award className="h-8 w-8" /><p>No matching IP records.</p></CardContent></Card> : (
        <div className="overflow-x-auto rounded-md border border-border"><table className="w-full min-w-[900px] text-sm"><thead className="bg-muted/40 text-left text-xs text-muted-foreground"><tr><th className="p-3">UIN</th><th className="p-3">Type</th><th className="p-3">Title / Mark</th><th className="p-3">Client</th><th className="p-3">Jurisdiction</th><th className="p-3">Legal status</th><th className="p-3">Responsible</th><th className="p-3">Quality</th></tr></thead><tbody>{records.map((record) => <tr key={record.id} className="border-t border-border hover:bg-muted/20"><td className="p-3 font-mono text-xs font-semibold text-gold">{record.uins.length > 0 ? record.uins.map((uin) => <div key={uin}>{uin}</div>) : <span className="font-sans font-normal text-muted-foreground">Not linked</span>}</td><td className="p-3"><Badge variant="outline">{record.record_type}</Badge></td><td className="p-3 font-medium">{record.title}</td><td className="p-3">{record.client?.name ?? "—"}<div className="font-mono text-[10px] text-muted-foreground">{record.client?.client_code}</div></td><td className="p-3">{record.jurisdiction}</td><td className="p-3">{record.legal_status}</td><td className="p-3">{record.responsible_user?.name ?? "Unassigned"}</td><td className="p-3"><span className={record.data_quality_status === "Verified" ? "text-emerald-600" : "text-amber-600"}><ShieldCheck className="mr-1 inline h-3.5 w-3.5" />{record.data_quality_status}</span></td></tr>)}</tbody></table></div>
      )}
      <p className="text-xs text-muted-foreground">Open an engagement from <Link href="/projects" className="text-gold hover:underline">Projects</Link> to work with its lifecycle, deadlines, documents, and billing.</p>
    </div>
  </AppLayout>
}
