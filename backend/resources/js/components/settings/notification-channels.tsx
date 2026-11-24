import { useEffect, useState } from "react"
import { toast } from "sonner"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { api, type NotificationChannelPrefs } from "@/lib/api-client"

const OPTIONS: { value: "in_app" | "email" | "both" | "off"; label: string }[] = [
  { value: "in_app", label: "In-app" },
  { value: "email", label: "Email" },
  { value: "both", label: "Both" },
  { value: "off", label: "Quiet" },
]

/** How each kind of notification reaches this person. Saved on every change. */
export function NotificationChannels() {
  const [prefs, setPrefs] = useState<NotificationChannelPrefs | null>(null)

  useEffect(() => { api.getNotificationChannels().then(setPrefs).catch(() => setPrefs(null)) }, [])
  if (!prefs) return null

  const choose = async (category: string, value: string) => {
    const before = prefs
    setPrefs({ ...prefs, channels: { ...prefs.channels, [category]: value as NotificationChannelPrefs["channels"][string] } })
    try { setPrefs(await api.updateNotificationChannels({ [category]: value })) }
    catch (e) { setPrefs(before); toast.error((e as Error).message) }
  }

  return (
    <Card className="mt-6 border-border">
      <CardHeader>
        <CardTitle className="font-display">How each kind reaches you</CardTitle>
        <p className="text-xs text-muted-foreground">Email sends a copy to your inbox. Quiet keeps it in your notification history without the unread badge.</p>
      </CardHeader>
      <CardContent className="divide-y divide-border">
        {Object.entries(prefs.categories).map(([key, label]) => (
          <div key={key} className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
            <span className="text-sm">{label}</span>
            <div className="flex rounded-md border border-border p-0.5 text-xs">
              {OPTIONS.map((o) => (
                <button key={o.value} onClick={() => choose(key, o.value)}
                  className={`rounded px-2.5 py-1 ${prefs.channels[key] === o.value ? "bg-gold font-medium text-black" : "text-muted-foreground hover:text-foreground"}`}>{o.label}</button>
              ))}
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
