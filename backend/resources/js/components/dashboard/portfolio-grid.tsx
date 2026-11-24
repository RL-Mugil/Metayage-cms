import { Link, router } from "@inertiajs/react"
import { motion } from "framer-motion"
import { ArrowUpRight, GitBranch, MapPin, PieChart } from "lucide-react"
import { GlassCard, staggerParent } from "@/components/modern/glass-card"
import { Ring3D, type RingDatum } from "@/components/modern/ring-3d"
import { HBar3D } from "@/components/modern/bar-3d"
import { GlossaryText } from "@/components/glossary-text"

/** Dashboard → IP Portfolio: case mix ring, offices (granted vs pending), pipeline by stage. */
export function PortfolioGrid({ statusMix, offices, stages }: {
  statusMix: { data: RingDatum[]; total: number }
  offices: { rows: { code: string; name: string; granted: number; pending: number; total: number }[]; max: number }
  stages: { rows: { name: string; count: number }[]; max: number }
}) {
  if (statusMix.total === 0) return <p className="py-12 text-center text-sm text-muted-foreground">No patent portfolio data for your scope yet.</p>
  const open = () => router.visit("/patent-portfolio")

  return (
    <motion.div variants={staggerParent} initial="hidden" animate="show" className="grid gap-3 lg:grid-cols-3 fit:h-full">
      <GlassCard title="Case status mix" icon={<PieChart className="h-4 w-4 text-gold" />} subtitle={`${statusMix.total} unique patent cases`}
        actions={<Link href="/patent-portfolio" className="flex items-center gap-1 text-[11px] font-medium text-gold hover:underline">Full portfolio <ArrowUpRight className="h-3 w-3" /></Link>}>
        <div className="flex h-full items-center justify-center px-4 pb-4">
          <Ring3D data={statusMix.data} total={statusMix.total} label="cases" size={200} thickness={26} onSelect={open} />
        </div>
      </GlassCard>

      <GlassCard title="Where the patents are" icon={<MapPin className="h-4 w-4 text-gold" />} subtitle="Granted and pending by office" scroll bodyClassName="px-3 pb-3">
        <div className="space-y-2">
          {offices.rows.map((r) => (
            <button key={r.code} onClick={open} className="block w-full rounded-lg px-2 py-1 text-left hover:bg-muted/50">
              <div className="flex items-center justify-between text-[11px]">
                <span className="font-medium">{r.name}</span>
                <span className="font-mono text-muted-foreground">{r.granted} granted · {r.pending} pending</span>
              </div>
              <div className="mt-1 flex h-2.5 overflow-hidden rounded-full bg-muted/70" style={{ width: `${Math.max(8, (r.total / (offices.max || 1)) * 100)}%`, boxShadow: "inset 0 1px 2px rgb(15 23 42 / 0.12)" }}>
                <div style={{ flex: r.granted, background: "linear-gradient(180deg,#4ade80,#16a34a)" }} />
                <div style={{ flex: r.pending, background: "linear-gradient(180deg,#fcd34d,#d97706)" }} />
              </div>
            </button>
          ))}
          <div className="flex gap-3 pt-1 text-[10px] text-muted-foreground">
            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-green-500" />Granted</span>
            <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-full bg-amber-500" />Pending</span>
          </div>
        </div>
      </GlassCard>

      <GlassCard title="Prosecution pipeline" icon={<GitBranch className="h-4 w-4 text-gold" />} subtitle="Pending patents by current stage" scroll bodyClassName="px-2 pb-3">
        {stages.rows.length === 0 ? <p className="py-8 text-center text-xs text-muted-foreground">No pending patents.</p> : (
          <div className="space-y-0.5">
            {stages.rows.map((s) => <HBar3D key={s.name} label={s.name} value={s.count} max={stages.max} onClick={open} right={<GlossaryText text={String(s.count)} />} />)}
          </div>
        )}
      </GlassCard>
    </motion.div>
  )
}
