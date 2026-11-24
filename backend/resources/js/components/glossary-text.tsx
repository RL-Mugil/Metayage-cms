import { glossarySplit } from "@/lib/glossary"

/** Text with patent terms dotted-underlined; hovering one shows a one-line explanation. */
export function GlossaryText({ text, className }: { text: string | null | undefined; className?: string }) {
  if (!text) return null
  return (
    <span className={className}>
      {glossarySplit(text).map((part, i) => part.tip ? (
        <abbr key={i} title={part.tip} className="cursor-help no-underline decoration-dotted underline-offset-2 [text-decoration-line:underline]">{part.text}</abbr>
      ) : <span key={i}>{part.text}</span>)}
    </span>
  )
}

/** Coloured dot for a 0-100 case health score; the reasons show on hover. */
export function HealthDot({ health }: { health?: { score: number; level: string; reasons: string[] } | null }) {
  if (!health) return null
  const color = health.level === "good" ? "bg-emerald-500" : health.level === "watch" ? "bg-amber-500" : "bg-red-500"
  const tip = `Health ${health.score}/100${health.reasons.length ? ` — ${health.reasons.join("; ")}` : " — on track"}`
  return <span title={tip} aria-label={tip} className={`inline-block h-2 w-2 shrink-0 rounded-full ${color}`} />
}
