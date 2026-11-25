import { useEffect, useState } from "react"
import { toast } from "sonner"
import { Loader2, Plug, Unplug } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { api } from "@/lib/api-client"
import type { ClaudeConnection } from "@/types"

const when = (v: string | null) => (v ? new Date(v).toLocaleString() : "—")

/** Settings → Profile → Connected apps: the user's Claude connector sign-ins, with Disconnect. */
export function ConnectedAppsCard() {
  const [rows, setRows] = useState<ClaudeConnection[] | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const load = () => api.getClaudeConnections().then(setRows).catch(() => setRows([]))
  useEffect(() => { load() }, [])

  const disconnect = async (row: ClaudeConnection) => {
    setBusy(row.client_id)
    try {
      await api.disconnectClaude(row.client_id)
      toast.success(`${row.name} disconnected`)
      await load()
    } catch (e) {
      toast.error((e as Error).message)
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card className="border-border">
      <CardHeader>
        <CardTitle className="font-display flex items-center gap-2"><Plug className="h-4 w-4 text-gold" />Connected apps</CardTitle>
        <CardDescription>
          Claude apps you have allowed to read the portal for you (read-only, limited to your own access). Add one from AI Assistant → Connect Claude.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {rows === null ? (
          <Loader2 className="h-4 w-4 animate-spin text-gold" />
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted-foreground">No apps connected.</p>
        ) : (
          <div className="divide-y divide-border rounded-lg border border-border">
            {rows.map((r) => (
              <div key={r.client_id} className="flex items-center justify-between gap-3 px-3 py-2.5">
                <div className="min-w-0">
                  <div className="truncate text-sm font-medium">{r.name}</div>
                  <div className="text-xs text-muted-foreground">Connected {when(r.connected_at)} · last used {when(r.last_used_at)}</div>
                </div>
                <Button variant="outline" size="sm" disabled={busy === r.client_id} onClick={() => disconnect(r)}>
                  {busy === r.client_id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <><Unplug className="mr-1 h-3.5 w-3.5" />Disconnect</>}
                </Button>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
