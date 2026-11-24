import { useEffect, useState } from "react"
import { toast } from "sonner"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { api } from "@/lib/api-client"

const SWITCHES: { key: string; label: string; desc: string }[] = [
  { key: "weekly_digest_enabled", label: "Weekly digest email", desc: "Every Monday 9:00 IST to partners, directors, managers and super admins: overdue deadlines, overdue payments, renewals in 30 days, last week's collections. Each person can still opt out above." },
  { key: "leaderboard_enabled", label: "Team leaderboard", desc: "Shows stages completed and deadlines met per person this month on the dashboard's Team tab." },
]

/** Firm-wide switches (super admin only); both are off until turned on here. */
export function FirmSwitches() {
  const [values, setValues] = useState<Record<string, boolean> | null>(null)

  useEffect(() => { api.getSwitches().then(setValues).catch(() => setValues({})) }, [])

  const flip = async (key: string, value: boolean) => {
    const before = values
    setValues((v) => ({ ...(v ?? {}), [key]: value }))
    try {
      setValues(await api.updateSwitches({ [key]: value }))
      toast.success(value ? "Turned on" : "Turned off")
    } catch (e) {
      setValues(before)
      toast.error((e as Error).message)
    }
  }

  return (
    <Card className="mt-6 border-border">
      <CardHeader><CardTitle className="font-display">Firm-wide (super admin)</CardTitle></CardHeader>
      <CardContent className="divide-y divide-border">
        {SWITCHES.map((s) => (
          <label key={s.key} className="flex cursor-pointer items-center justify-between gap-4 py-4 first:pt-0 last:pb-0">
            <div>
              <div className="text-sm font-medium">{s.label}</div>
              <div className="mt-0.5 text-xs text-muted-foreground">{s.desc}</div>
            </div>
            <input type="checkbox" className="h-4 w-4 accent-[hsl(45_93%_47%)]" disabled={!values}
              checked={!!values?.[s.key]} onChange={(e) => flip(s.key, e.target.checked)} />
          </label>
        ))}
      </CardContent>
    </Card>
  )
}
