import { useEffect, useMemo, useRef, useState } from "react"
import { Canvas, useFrame, type ThreeEvent } from "@react-three/fiber"
import * as THREE from "three"
import { Minus, Plus, RotateCcw } from "lucide-react"
import LAND from "./land-points.json"

/** Patent offices at their seat (lat, lon). Regional offices sit at their headquarters. */
const OFFICES: Record<string, [number, number]> = {
  IN: [28.6, 77.2], US: [38.9, -77.0], EP: [48.1, 11.6], WO: [46.2, 6.1], CN: [39.9, 116.4], JP: [35.7, 139.7],
  KR: [36.4, 127.4], GB: [51.5, -0.1], DE: [48.1, 11.6], FR: [48.9, 2.35], AU: [-35.3, 149.1], CA: [45.4, -75.7],
  BR: [-22.9, -43.2], SG: [1.35, 103.8], AE: [24.5, 54.4], ZA: [-25.7, 28.2], RU: [55.8, 37.6], EA: [55.8, 37.6],
  MY: [3.1, 101.7], ID: [-6.2, 106.8], TH: [13.8, 100.5], VN: [21.0, 105.8], PH: [14.6, 121.0], NZ: [-41.3, 174.8],
  MX: [19.4, -99.1], IL: [31.8, 35.2], SA: [24.7, 46.7], TW: [25.0, 121.5], CH: [46.9, 7.4], IT: [41.9, 12.5],
  ES: [40.4, -3.7], NL: [52.1, 4.3], SE: [59.3, 18.1], EG: [30.0, 31.2], NG: [9.1, 7.5], KE: [-1.3, 36.8], AR: [-34.6, -58.4],
  CL: [-33.4, -70.6], TR: [39.9, 32.9], PK: [33.7, 73.1], BD: [23.8, 90.4], LK: [6.9, 79.9], NP: [27.7, 85.3], HK: [22.3, 114.2],
}

const R = 1
const toVec = (lat: number, lon: number, r = R) => {
  const phi = ((90 - lat) * Math.PI) / 180
  const theta = ((lon + 180) * Math.PI) / 180
  return new THREE.Vector3(-r * Math.sin(phi) * Math.cos(theta), r * Math.cos(phi), r * Math.sin(phi) * Math.sin(theta))
}

type Counts = Record<string, number>
export interface GlobeProps {
  active: Counts; granted: Counts; other: Counts
  label: (code: string) => string
  selected: string | null
  onSelect: (code: string) => void
}

function LandDots() {
  const ref = useRef<THREE.InstancedMesh>(null)
  const points = LAND as [number, number][]
  useEffect(() => {
    const m = new THREE.Matrix4()
    points.forEach(([lat, lon], i) => { m.setPosition(toVec(lat, lon, R * 1.002)); ref.current?.setMatrixAt(i, m) })
    if (ref.current) ref.current.instanceMatrix.needsUpdate = true
  }, [points])
  return (
    <instancedMesh ref={ref} args={[undefined, undefined, points.length]}>
      <sphereGeometry args={[0.0085, 6, 6]} />
      <meshStandardMaterial color="#1d4ed8" emissive="#1e3a8a" emissiveIntensity={0.25} roughness={0.5} />
    </instancedMesh>
  )
}

/** One office: a stacked column (granted green, active amber, other grey) standing on the surface. */
function OfficeBar({ code, counts, max, selected, onHover, onSelect }: {
  code: string; counts: { granted: number; active: number; other: number }; max: number; selected: boolean
  onHover: (code: string | null, e?: ThreeEvent<PointerEvent>) => void; onSelect: (code: string) => void
}) {
  const [lat, lon] = OFFICES[code]
  const total = counts.granted + counts.active + counts.other
  const height = 0.06 + 0.55 * Math.sqrt(total / max)
  const segs = [["granted", "#16a34a"], ["active", "#f59e0b"], ["other", "#94a3b8"]] as const
  const group = useRef<THREE.Group>(null)
  useEffect(() => {
    const base = toVec(lat, lon, R)
    group.current?.position.copy(base)
    group.current?.lookAt(base.clone().multiplyScalar(2))
  }, [lat, lon])
  let offset = 0
  const w = selected ? 0.05 : 0.036
  return (
    <group ref={group} onPointerOver={(e) => { e.stopPropagation(); onHover(code, e) }} onPointerMove={(e) => onHover(code, e)}
      onPointerOut={() => onHover(null)} onClick={(e) => { e.stopPropagation(); onSelect(code) }}>
      {segs.map(([key, color]) => {
        const h = total ? (counts[key] / total) * height : 0
        if (h <= 0) return null
        const z = offset + h / 2
        offset += h
        return (
          <mesh key={key} position={[0, 0, z]}>
            <boxGeometry args={[w, w, h]} />
            <meshStandardMaterial color={color} emissive={selected ? color : "#000"} emissiveIntensity={selected ? 0.45 : 0} roughness={0.35} metalness={0.15} />
          </mesh>
        )
      })}
      <mesh position={[0, 0, 0.002]}>
        <circleGeometry args={[w * 1.4, 20]} />
        <meshBasicMaterial color={selected ? "#d4a017" : "#ffffff"} transparent opacity={0.55} />
      </mesh>
    </group>
  )
}

function Globe({ props, rot, dragging, onHover }: {
  props: GlobeProps; rot: React.MutableRefObject<{ x: number; y: number }>; dragging: React.MutableRefObject<boolean>
  onHover: (code: string | null, e?: ThreeEvent<PointerEvent>) => void
}) {
  const group = useRef<THREE.Group>(null)
  const codes = useMemo(() => Array.from(new Set([...Object.keys(props.active), ...Object.keys(props.granted), ...Object.keys(props.other)])).filter((c) => OFFICES[c]), [props])
  const counts = (c: string) => ({ granted: props.granted[c] ?? 0, active: props.active[c] ?? 0, other: props.other[c] ?? 0 })
  const max = Math.max(1, ...codes.map((c) => { const k = counts(c); return k.granted + k.active + k.other }))

  useFrame((_, delta) => {
    if (!dragging.current) rot.current.y += delta * 0.08
    if (group.current) { group.current.rotation.y = rot.current.y; group.current.rotation.x = rot.current.x }
  })

  return (
    <group ref={group}>
      <mesh>
        <sphereGeometry args={[R, 64, 64]} />
        <meshStandardMaterial color="#eff6ff" emissive="#bfdbfe" emissiveIntensity={0.35} roughness={0.85} metalness={0} />
      </mesh>
      <mesh scale={1.08}>
        <sphereGeometry args={[R, 48, 48]} />
        <meshBasicMaterial color="#93c5fd" transparent opacity={0.12} side={THREE.BackSide} />
      </mesh>
      <LandDots />
      {codes.map((c) => (
        <OfficeBar key={c} code={c} counts={counts(c)} max={max} selected={props.selected === c} onHover={onHover} onSelect={props.onSelect} />
      ))}
    </group>
  )
}

/**
 * Rotating 3D globe with a column per patent office (height ∝ cases; green granted, amber
 * active, grey other). Drag to turn, buttons to zoom, click a column to filter the page.
 */
export default function OfficeGlobe(props: GlobeProps) {
  const wrap = useRef<HTMLDivElement>(null)
  const rot = useRef({ x: 0.55, y: Math.PI + 0.35 })   // India slightly off-centre and tilted, so its column stands up
  const dragging = useRef(false)
  const last = useRef<{ x: number; y: number } | null>(null)
  const [zoom, setZoom] = useState(3.6)
  const [tip, setTip] = useState<{ code: string; x: number; y: number } | null>(null)

  const hover = (code: string | null, e?: ThreeEvent<PointerEvent>) => {
    if (!code || !e || !wrap.current) { setTip(null); document.body.style.cursor = ""; return }
    const r = wrap.current.getBoundingClientRect()
    setTip({ code, x: e.nativeEvent.clientX - r.left, y: e.nativeEvent.clientY - r.top })
    document.body.style.cursor = "pointer"
  }
  useEffect(() => () => { document.body.style.cursor = "" }, [])

  const down = (e: React.PointerEvent) => { dragging.current = true; last.current = { x: e.clientX, y: e.clientY } }
  const move = (e: React.PointerEvent) => {
    if (!dragging.current || !last.current) return
    rot.current.y += (e.clientX - last.current.x) * 0.006
    rot.current.x = Math.max(-1.1, Math.min(1.1, rot.current.x + (e.clientY - last.current.y) * 0.006))
    last.current = { x: e.clientX, y: e.clientY }
  }
  const up = () => { dragging.current = false; last.current = null }
  const t = tip ? { g: props.granted[tip.code] ?? 0, a: props.active[tip.code] ?? 0, o: props.other[tip.code] ?? 0 } : null

  return (
    <div ref={wrap} className="relative h-[380px] fit:h-auto fit:min-h-[300px] fit:flex-1 w-full touch-none select-none overflow-hidden rounded-xl bg-gradient-to-b from-sky-50 to-white dark:from-slate-900 dark:to-slate-950"
      onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerLeave={up}>
      <Canvas camera={{ position: [0, 0, 3.6], fov: 40 }} dpr={[1, 1.5]} gl={{ antialias: true, alpha: true }}
        onCreated={({ camera }) => camera.lookAt(0, 0, 0)}>
        <CameraZoom z={zoom} />
        <ambientLight intensity={0.85} />
        <directionalLight position={[3, 2, 4]} intensity={1.1} />
        <directionalLight position={[-3, -1, -2]} intensity={0.3} color="#fde68a" />
        <Globe props={props} rot={rot} dragging={dragging} onHover={hover} />
      </Canvas>
      {tip && t && (
        <div className="pointer-events-none absolute z-10 rounded-lg border border-border bg-background/95 px-2.5 py-1.5 text-[11px] shadow-3d"
          style={{ left: Math.min(tip.x + 12, (wrap.current?.clientWidth ?? 400) - 170), top: Math.max(tip.y - 52, 4) }}>
          <p className="font-semibold">{props.label(tip.code)} <span className="font-mono text-muted-foreground">{tip.code}</span></p>
          <p className="text-muted-foreground">{t.a + t.g + t.o} cases · {t.a} active · {t.g} granted</p>
          <p className="text-[10px] text-gold">Click to filter</p>
        </div>
      )}
      <div className="absolute right-2 top-2 flex flex-col gap-1">
        {[{ icon: Plus, fn: () => setZoom((z) => Math.max(2.2, z - 0.35)), label: "Zoom in" },
          { icon: Minus, fn: () => setZoom((z) => Math.min(5, z + 0.35)), label: "Zoom out" },
          { icon: RotateCcw, fn: () => { rot.current = { x: 0.55, y: Math.PI + 0.35 }; setZoom(3.6) }, label: "Reset view" }].map(({ icon: Icon, fn, label }) => (
          <button key={label} title={label} aria-label={label} onPointerDown={(e) => e.stopPropagation()} onClick={fn}
            className="glass flex h-7 w-7 items-center justify-center rounded-lg text-muted-foreground hover:text-foreground"><Icon className="h-3.5 w-3.5" /></button>
        ))}
      </div>
      <div className="pointer-events-none absolute bottom-2 left-3 flex gap-3 text-[10px] text-muted-foreground">
        <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-green-600" />Granted</span>
        <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-amber-500" />Active</span>
        <span className="flex items-center gap-1"><span className="h-2 w-2 rounded-sm bg-slate-400" />Other</span>
        <span>· drag to turn</span>
      </div>
    </div>
  )
}

function CameraZoom({ z }: { z: number }) {
  useFrame(({ camera }) => {
    camera.position.z += (z - camera.position.z) * 0.12
    camera.lookAt(0, 0, 0)
  })
  return null
}
