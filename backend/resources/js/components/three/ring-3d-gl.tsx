import { useMemo, useRef, useState } from "react"
import { Canvas, useFrame } from "@react-three/fiber"
import * as THREE from "three"
import type { Ring3DProps } from "./chart-3d"

/** A donut slice as an extruded shape between two arcs. */
function sliceGeometry(start: number, end: number, inner: number, outer: number) {
  const shape = new THREE.Shape()
  shape.absarc(0, 0, outer, start, end, false)
  shape.absarc(0, 0, inner, end, start, true)
  shape.closePath()
  const geo = new THREE.ExtrudeGeometry(shape, { depth: 0.38, bevelEnabled: true, bevelThickness: 0.04, bevelSize: 0.03, bevelSegments: 3, curveSegments: 48 })
  geo.translate(0, 0, -0.19)
  return geo
}

function Slice({ start, end, color, selected, onHover, onClick }: {
  start: number; end: number; color: string; selected: boolean; onHover: (on: boolean) => void; onClick: () => void
}) {
  const mesh = useRef<THREE.Mesh>(null)
  const [hover, setHover] = useState(false)
  const geo = useMemo(() => sliceGeometry(start, end, 0.95, 1.6), [start, end])
  const mid = (start + end) / 2
  useFrame(() => {
    if (!mesh.current) return
    const out = hover || selected ? 0.16 : 0
    mesh.current.position.x = THREE.MathUtils.lerp(mesh.current.position.x, Math.cos(mid) * out, 0.2)
    mesh.current.position.y = THREE.MathUtils.lerp(mesh.current.position.y, Math.sin(mid) * out, 0.2)
    mesh.current.position.z = THREE.MathUtils.lerp(mesh.current.position.z, hover || selected ? 0.12 : 0, 0.2)
  })
  return (
    <mesh ref={mesh} geometry={geo} castShadow
      onPointerOver={(e) => { e.stopPropagation(); setHover(true); onHover(true); document.body.style.cursor = "pointer" }}
      onPointerOut={() => { setHover(false); onHover(false); document.body.style.cursor = "" }}
      onClick={(e) => { e.stopPropagation(); onClick() }}>
      <meshStandardMaterial color={color} roughness={0.3} metalness={0.2} emissive={color} emissiveIntensity={hover || selected ? 0.35 : 0.05} />
    </mesh>
  )
}

function Spin({ children, drag }: { children: React.ReactNode; drag: React.MutableRefObject<number> }) {
  const g = useRef<THREE.Group>(null)
  useFrame(() => { if (g.current) g.current.rotation.z = THREE.MathUtils.lerp(g.current.rotation.z, drag.current, 0.15) })
  return <group ref={g}>{children}</group>
}

/** Extruded 3D donut (WebGL): hover pulls a slice out, click selects it, drag spins the ring. */
export default function Ring3DGL({ data, total, label, format = (n) => n.toLocaleString("en-IN"), activeCode, onSelect, size = 220 }: Ring3DProps) {
  const [hover, setHover] = useState<string | null>(null)
  const spin = useRef(0)
  const last = useRef<number | null>(null)
  const sum = data.reduce((a, d) => a + Math.max(0, d.value), 0) || 1
  let angle = Math.PI / 2
  const slices = data.filter((d) => d.value > 0).map((d) => {
    const span = (d.value / sum) * Math.PI * 2
    const s = { ...d, start: angle - span, end: angle }
    angle -= span
    return s
  })
  const shown = data.find((d) => d.code === (hover ?? activeCode))

  return (
    <div className="flex flex-wrap items-center gap-4">
      <div className="relative touch-none select-none" style={{ width: size, height: size }}
        onPointerDown={(e) => { last.current = e.clientX }}
        onPointerMove={(e) => { if (last.current !== null) { spin.current += (e.clientX - last.current) * 0.01; last.current = e.clientX } }}
        onPointerUp={() => { last.current = null }} onPointerLeave={() => { last.current = null }}>
        <Canvas shadows camera={{ position: [0, -3.4, 3.4], fov: 40 }} dpr={[1, 1.5]} gl={{ antialias: true, alpha: true }}
          onCreated={({ camera }) => camera.lookAt(0, 0, 0)}>
          <ambientLight intensity={0.7} />
          <directionalLight position={[3, -2, 5]} intensity={1.3} castShadow />
          <directionalLight position={[-3, 2, 2]} intensity={0.4} color="#bfdbfe" />
          <Spin drag={spin}>
            {slices.map((s) => (
              <Slice key={s.code} start={s.start} end={s.end} color={s.color} selected={activeCode === s.code}
                onHover={(on) => setHover(on ? s.code : null)} onClick={() => onSelect?.(activeCode === s.code ? null : s.code)} />
            ))}
          </Spin>
        </Canvas>
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
          <span className="font-display text-base font-semibold">{format(shown ? shown.value : total)}</span>
          <span className="max-w-[60%] truncate text-[10px] uppercase tracking-wider text-muted-foreground">{shown ? shown.name : label}</span>
        </div>
      </div>
      <div className="min-w-[140px] space-y-1 text-xs">
        {data.map((d) => (
          <button key={d.code} onClick={() => onSelect?.(activeCode === d.code ? null : d.code)}
            className={`flex w-full items-center justify-between gap-3 rounded-md px-2 py-1 text-left hover:bg-muted ${activeCode === d.code ? "bg-muted" : ""}`}>
            <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm" style={{ background: d.color, boxShadow: `0 0 6px ${d.color}` }} />{d.name}</span>
            <span className="font-mono">{format(d.value)}</span>
          </button>
        ))}
      </div>
    </div>
  )
}
