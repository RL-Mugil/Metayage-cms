import { useEffect, useMemo, useRef, useState } from "react"
import { Canvas, useFrame, useThree, type ThreeEvent } from "@react-three/fiber"
import * as THREE from "three"
import { Minus, Plus, RotateCcw } from "lucide-react"
import type { Bars3DProps } from "./chart-3d"

/** A canvas-drawn text label as a sprite (no font loader needed). */
function useLabelTexture(text: string, color: string) {
  return useMemo(() => {
    const cv = document.createElement("canvas")
    cv.width = 256
    cv.height = 64
    const ctx = cv.getContext("2d")!
    ctx.font = "600 26px Inter, system-ui, sans-serif"
    ctx.fillStyle = color
    ctx.textAlign = "center"
    ctx.textBaseline = "middle"
    ctx.fillText(text.length > 14 ? `${text.slice(0, 13)}…` : text, 128, 32)
    const tex = new THREE.CanvasTexture(cv)
    tex.colorSpace = THREE.SRGBColorSpace
    tex.anisotropy = 4
    return tex
  }, [text, color])
}

function Label({ text, position, color }: { text: string; position: [number, number, number]; color: string }) {
  const tex = useLabelTexture(text, color)
  useEffect(() => () => tex.dispose(), [tex])
  return (
    <sprite position={position} scale={[1.6, 0.4, 1]}>
      <spriteMaterial map={tex} transparent depthWrite={false} />
    </sprite>
  )
}

/** One column: rises on load, lifts and glows on hover, clicks through. */
function Column({ x, z, w, h, color, delay, onHover, onClick, active }: {
  x: number; z: number; w: number; h: number; color: string; delay: number; active: boolean
  onHover: (e: ThreeEvent<PointerEvent> | null) => void; onClick: () => void
}) {
  const mesh = useRef<THREE.Mesh>(null)
  const grow = useRef(0)
  const started = useRef(performance.now())
  const [hover, setHover] = useState(false)
  useFrame(() => {
    if (!mesh.current) return
    const t = (performance.now() - started.current) / 1000 - delay
    grow.current = t <= 0 ? 0 : Math.min(1, grow.current + (1 - grow.current) * 0.12 + 0.002)
    const height = Math.max(0.001, h * grow.current)
    mesh.current.scale.y = height
    const lift = hover || active ? 0.12 : 0
    mesh.current.position.y = THREE.MathUtils.lerp(mesh.current.position.y, height / 2 + lift, 0.25)
  })
  return (
    <mesh ref={mesh} position={[x, 0, z]} castShadow
      onPointerOver={(e) => { e.stopPropagation(); setHover(true); onHover(e); document.body.style.cursor = "pointer" }}
      onPointerMove={(e) => { e.stopPropagation(); onHover(e) }}
      onPointerOut={() => { setHover(false); onHover(null); document.body.style.cursor = "" }}
      onClick={(e) => { e.stopPropagation(); onClick() }}>
      <boxGeometry args={[w, 1, w]} />
      <meshStandardMaterial color={color} roughness={0.35} metalness={0.15} emissive={color} emissiveIntensity={hover || active ? 0.35 : 0.04} />
    </mesh>
  )
}

/** Eases the camera to the zoom distance. */
function Rig({ zoom }: { zoom: number }) {
  const { camera } = useThree()
  useFrame(() => {
    camera.position.z = THREE.MathUtils.lerp(camera.position.z, zoom, 0.15)
    camera.lookAt(0, 1.2, 0)
  })
  return null
}

/**
 * Grouped 3D columns (WebGL): drag to turn, +/- to zoom, hover for the value, click to drill in.
 * Categories run left to right; each category's series stand side by side.
 */
export default function Bars3DGL({ data, series, format = (n) => n.toLocaleString("en-IN"), onSelect, height = 300, activeLabel }: Bars3DProps) {
  const wrap = useRef<HTMLDivElement>(null)
  const rot = useRef({ x: 0, y: -0.35 })
  const drag = useRef<{ x: number; y: number } | null>(null)
  const [zoom, setZoom] = useState(11)
  const [tip, setTip] = useState<{ x: number; y: number; label: string; series: string; value: number } | null>(null)
  const dark = typeof document !== "undefined" && document.documentElement.classList.contains("dark")

  const max = Math.max(1, ...data.flatMap((d) => series.map((s) => Math.abs(d.values[s.key] ?? 0))))
  const w = Math.min(0.6, 1.6 / Math.max(1, series.length))
  const gap = w * series.length + 0.5
  const width = data.length * gap
  const scale = 3.2 / max

  const hover = (label: string, s: { key: string; label: string }, value: number) => (e: ThreeEvent<PointerEvent> | null) => {
    if (!e) { setTip(null); return }
    const box = wrap.current?.getBoundingClientRect()
    setTip({ x: e.nativeEvent.clientX - (box?.left ?? 0), y: e.nativeEvent.clientY - (box?.top ?? 0), label, series: s.label, value })
  }

  return (
    <div className="relative w-full">
      <div ref={wrap} className="relative w-full touch-none select-none overflow-hidden rounded-xl border border-border/60 bg-[radial-gradient(circle_at_50%_30%,color-mix(in_oklab,var(--gold)_10%,transparent),transparent_70%)] shadow-3d"
        style={{ height }}
        onPointerDown={(e) => { drag.current = { x: e.clientX, y: e.clientY } }}
        onPointerMove={(e) => {
          if (!drag.current) return
          rot.current.y = Math.max(-1.2, Math.min(1.2, rot.current.y + (e.clientX - drag.current.x) * 0.008))
          rot.current.x = Math.max(-0.35, Math.min(0.5, rot.current.x + (e.clientY - drag.current.y) * 0.004))
          drag.current = { x: e.clientX, y: e.clientY }
        }}
        onPointerUp={() => { drag.current = null }} onPointerLeave={() => { drag.current = null }}>
        <Canvas shadows camera={{ position: [0, 4.2, 11], fov: 38 }} dpr={[1, 1.5]} gl={{ antialias: true, alpha: true }}>
          <ambientLight intensity={0.65} />
          <directionalLight position={[4, 8, 6]} intensity={1.3} castShadow />
          <directionalLight position={[-5, 3, -4]} intensity={0.35} color="#bfdbfe" />
          <Rig zoom={zoom} />
          <Turnable rot={rot}>
            <group position={[-width / 2 + gap / 2, 0, 0]}>
              {data.map((d, i) => (
                <group key={d.label} position={[i * gap, 0, 0]}>
                  {series.map((s, j) => {
                    const v = d.values[s.key] ?? 0
                    return (
                      <Column key={s.key} x={(j - (series.length - 1) / 2) * w} z={0} w={w * 0.86} h={Math.max(0.02, Math.abs(v) * scale)} color={s.color}
                        delay={i * 0.04 + j * 0.02} active={activeLabel === d.label}
                        onHover={hover(d.label, s, v)} onClick={() => onSelect?.(d.label, s.key)} />
                    )
                  })}
                  <Label text={d.label} position={[0, -0.28, w * 1.2]} color={dark ? "#cbd5e1" : "#334155"} />
                </group>
              ))}
            </group>
            <mesh rotation={[-Math.PI / 2, 0, 0]} position={[0, -0.01, 0]} receiveShadow>
              <planeGeometry args={[width + 2, 4]} />
              <meshStandardMaterial color={dark ? "#0f172a" : "#f1f5f9"} transparent opacity={0.85} />
            </mesh>
            <gridHelper args={[Math.max(width + 2, 4), Math.max(4, data.length * 2), dark ? "#334155" : "#cbd5e1", dark ? "#1e293b" : "#e2e8f0"]} position={[0, 0, 0]} />
          </Turnable>
        </Canvas>
        <div className="absolute right-2 top-2 flex gap-1">
          <button className="rounded-md border border-border bg-background/80 p-1 hover:bg-muted" title="Zoom in" onClick={() => setZoom((z) => Math.max(5, z - 1.5))}><Plus className="h-3.5 w-3.5" /></button>
          <button className="rounded-md border border-border bg-background/80 p-1 hover:bg-muted" title="Zoom out" onClick={() => setZoom((z) => Math.min(20, z + 1.5))}><Minus className="h-3.5 w-3.5" /></button>
          <button className="rounded-md border border-border bg-background/80 p-1 hover:bg-muted" title="Reset view" onClick={() => { rot.current = { x: 0, y: -0.35 }; setZoom(11) }}><RotateCcw className="h-3.5 w-3.5" /></button>
        </div>
        <p className="pointer-events-none absolute bottom-1.5 left-2 text-[10px] text-muted-foreground">Drag to turn · click a column to open it</p>
        {tip && (
          <div className="pointer-events-none absolute z-10 rounded-lg border border-border bg-background/95 px-2.5 py-1.5 text-[11px] shadow-3d"
            style={{ left: Math.min(tip.x + 12, (wrap.current?.clientWidth ?? 400) - 180), top: Math.max(tip.y - 48, 4) }}>
            <p className="font-semibold">{tip.label}</p>
            <p className="text-muted-foreground">{tip.series}: <span className="font-mono text-foreground">{format(tip.value)}</span></p>
          </div>
        )}
      </div>
      <div className="mt-2 flex flex-wrap gap-3 text-[11px] text-muted-foreground">
        {series.map((s) => <span key={s.key} className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: s.color, boxShadow: `0 0 6px ${s.color}` }} />{s.label}</span>)}
      </div>
    </div>
  )
}

/** The chart's group, turned by the drag state. */
function Turnable({ rot, children }: { rot: React.MutableRefObject<{ x: number; y: number }>; children: React.ReactNode }) {
  const g = useRef<THREE.Group>(null)
  useFrame(() => {
    if (!g.current) return
    g.current.rotation.y = THREE.MathUtils.lerp(g.current.rotation.y, rot.current.y, 0.15)
    g.current.rotation.x = THREE.MathUtils.lerp(g.current.rotation.x, rot.current.x, 0.15)
  })
  return <group ref={g}>{children}</group>
}
