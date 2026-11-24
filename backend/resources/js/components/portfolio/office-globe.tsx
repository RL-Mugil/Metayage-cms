import { useEffect, useMemo, useRef, useState } from "react"
import { Canvas, useFrame, type ThreeEvent } from "@react-three/fiber"
import * as THREE from "three"
import { Minus, Plus, RotateCcw } from "lucide-react"
import WORLD from "./world-110m.json"

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

type Ring = [number, number][]
const GEO = WORLD as { land: Ring[][]; borders: Ring[] }

/** Make a ring's longitudes continuous (no ±180 jumps) so it can be drawn at -360 / 0 / +360. */
const unwrap = (ring: Ring): Ring => {
  const out: Ring = []
  let shift = 0
  ring.forEach(([lon, lat], i) => {
    if (i > 0) {
      const prev = ring[i - 1][0]
      if (lon - prev > 180) shift -= 360
      else if (prev - lon > 180) shift += 360
    }
    out.push([lon + shift, lat])
  })
  return out
}

/** Draw every land polygon (with holes) onto an equirectangular canvas, wrapped across the date line. */
function traceLand(ctx: CanvasRenderingContext2D, w: number, h: number) {
  const x = (lon: number) => ((lon + 180) / 360) * w
  const y = (lat: number) => ((90 - lat) / 180) * h
  ctx.beginPath()
  for (const poly of GEO.land) {
    for (const ring of poly) {
      const u = unwrap(ring)
      for (const off of [-360, 0, 360]) {
        u.forEach(([lon, lat], i) => (i ? ctx.lineTo(x(lon + off), y(lat)) : ctx.moveTo(x(lon + off), y(lat))))
        ctx.closePath()
      }
    }
  }
}

function strokeLines(ctx: CanvasRenderingContext2D, w: number, h: number, lines: Ring[]) {
  const x = (lon: number) => ((lon + 180) / 360) * w
  const y = (lat: number) => ((90 - lat) / 180) * h
  ctx.beginPath()
  for (const line of lines) {
    const u = unwrap(line)
    for (const off of [-360, 0, 360]) u.forEach(([lon, lat], i) => (i ? ctx.lineTo(x(lon + off), y(lat)) : ctx.moveTo(x(lon + off), y(lat))))
  }
  ctx.stroke()
}

/** Colour, height and roughness maps for a natural-colour globe, drawn once. */
function buildTextures() {
  const W = 4096, H = 2048
  const canvas = (w: number, h: number) => { const c = document.createElement("canvas"); c.width = w; c.height = h; return c }

  // Colour: ocean gradient, shallow-shelf glow, land tinted by latitude, coastlines, borders, graticule.
  const color = canvas(W, H)
  const c = color.getContext("2d")!
  const ocean = c.createLinearGradient(0, 0, 0, H)
  ocean.addColorStop(0, "#0a2e55"); ocean.addColorStop(0.3, "#0f4c8a"); ocean.addColorStop(0.5, "#1666ad")
  ocean.addColorStop(0.7, "#0f4c8a"); ocean.addColorStop(1, "#0a2e55")
  c.fillStyle = ocean; c.fillRect(0, 0, W, H)
  c.save(); c.shadowColor = "rgba(125, 211, 252, 0.75)"; c.shadowBlur = 28; c.fillStyle = "#2a7fc0"; traceLand(c, W, H); c.fill("evenodd"); c.restore()

  const land = canvas(W, H)
  const l = land.getContext("2d")!
  traceLand(l, W, H); l.fillStyle = "#fff"; l.fill("evenodd")
  l.globalCompositeOperation = "source-in"
  const tint = l.createLinearGradient(0, 0, 0, H)
  const stop = (lat: number, col: string) => tint.addColorStop((90 - lat) / 180, col)
  stop(90, "#f1f5f4"); stop(72, "#c9d6c3"); stop(62, "#6f8f58"); stop(50, "#4f8a3c"); stop(40, "#7f9a4a"); stop(30, "#c8a96a")
  stop(22, "#c49a5a"); stop(12, "#4c8a36"); stop(0, "#2f7a2e"); stop(-12, "#4c8a36"); stop(-25, "#b8955a"); stop(-38, "#6e9a4c")
  stop(-55, "#8aa37a"); stop(-64, "#e8eeec"); stop(-90, "#f8fafc")
  l.fillStyle = tint; l.fillRect(0, 0, W, H)
  c.drawImage(land, 0, 0)

  c.lineJoin = "round"
  c.strokeStyle = "rgba(22, 60, 30, 0.55)"; c.lineWidth = 2.2; traceLand(c, W, H); c.stroke()
  c.strokeStyle = "rgba(255, 255, 255, 0.55)"; c.lineWidth = 1.4; strokeLines(c, W, H, GEO.borders)
  c.strokeStyle = "rgba(255, 255, 255, 0.16)"; c.lineWidth = 1.6
  c.beginPath()
  for (let lon = -180; lon <= 180; lon += 15) { const px = ((lon + 180) / 360) * W; c.moveTo(px, 0); c.lineTo(px, H) }
  for (let lat = -75; lat <= 75; lat += 15) { const py = ((90 - lat) / 180) * H; c.moveTo(0, py); c.lineTo(W, py) }
  c.stroke()

  // Height: land raised, softened coast. Roughness: glossy sea, matte land.
  const height = canvas(2048, 1024)
  const hc = height.getContext("2d")!
  hc.fillStyle = "#000"; hc.fillRect(0, 0, 2048, 1024)
  hc.filter = "blur(3px)"; hc.fillStyle = "#fff"; traceLand(hc, 2048, 1024); hc.fill("evenodd"); hc.filter = "none"
  const rough = canvas(1024, 512)
  const rc = rough.getContext("2d")!
  rc.fillStyle = "#4a4a4a"; rc.fillRect(0, 0, 1024, 512)
  rc.fillStyle = "#e0e0e0"; traceLand(rc, 1024, 512); rc.fill("evenodd")

  const tex = (cv: HTMLCanvasElement, srgb = false) => {
    const t = new THREE.CanvasTexture(cv)
    if (srgb) t.colorSpace = THREE.SRGBColorSpace
    t.anisotropy = 4
    t.needsUpdate = true
    return t
  }
  return { map: tex(color, true), height: tex(height), rough: tex(rough) }
}

/** How far land stands above the sea (scene units); office columns start on top of it. */
const LIFT = 0.022

function Earth() {
  const t = useMemo(buildTextures, [])
  useEffect(() => () => { t.map.dispose(); t.height.dispose(); t.rough.dispose() }, [t])
  return (
    <mesh>
      <sphereGeometry args={[R, 256, 128]} />
      <meshStandardMaterial map={t.map} displacementMap={t.height} displacementScale={LIFT} bumpMap={t.height} bumpScale={2.5}
        roughnessMap={t.rough} roughness={1} metalness={0.05} />
    </mesh>
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
    const base = toVec(lat, lon, R + LIFT)
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
            <meshStandardMaterial color={color} emissive={color} emissiveIntensity={selected ? 0.7 : 0.28} roughness={0.35} metalness={0.15} />
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
      <Earth />
      <mesh scale={1.1}>
        <sphereGeometry args={[R, 64, 64]} />
        <meshBasicMaterial color="#60a5fa" transparent opacity={0.16} side={THREE.BackSide} blending={THREE.AdditiveBlending} depthWrite={false} />
      </mesh>
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
    <div ref={wrap} className="relative h-[380px] fit:h-auto fit:min-h-[300px] fit:flex-1 w-full touch-none select-none overflow-hidden rounded-xl bg-[radial-gradient(circle_at_50%_45%,#1e293b_0%,#0b1220_55%,#020617_100%)]"
      onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerLeave={up}>
      <Canvas camera={{ position: [0, 0, 3.6], fov: 40 }} dpr={[1, 1.5]} gl={{ antialias: true, alpha: true }}
        onCreated={({ camera }) => camera.lookAt(0, 0, 0)}>
        <CameraZoom z={zoom} />
        <ambientLight intensity={0.55} />
        <directionalLight position={[-3, 2.5, 4]} intensity={1.6} />
        <directionalLight position={[3, -1, 2]} intensity={0.35} color="#bfdbfe" />
        <directionalLight position={[0, 1, -4]} intensity={0.6} color="#fbbf24" />
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
      <div className="pointer-events-none absolute bottom-2 left-3 flex gap-3 text-[10px] text-slate-300">
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
