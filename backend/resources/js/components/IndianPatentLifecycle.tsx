import React, { useRef, useState } from "react";
import { Edit2, X, ChevronDown, RotateCcw } from "lucide-react";

// ── All available service codes (master list) ─────────────────────────────────
export const ALL_SERVICE_CODES = [
  "PAS", "SRH", "FTO",
  "PRV",
  "CPT", "CPD", "CVP", "CPE",
  "PCT", "NAP", "NPE", "NAF", "NPA",
  "DVA", "PAD",
  "9EP", "98A",
  "18F", "18A",
  "FER", "SER", "TER",
  "HRG", "GRT",
  "RNF", "OPP", "PGO", "27F", "24F",
  "ROA", "ERH", "RPO", "ABN", "WDR",
] as const;

// ── Default service code assignments per node ─────────────────────────────────
export const NODE_SERVICE_CODES_DEFAULT: Record<string, string[]> = {
  prv:       ["PRV"],
  pct:       ["PCT"],
  cvp:       ["CVP"],
  dva:       ["DVA"],
  pad:       ["PAD"],
  cpt:       ["CPT", "CPE"],
  nap:       ["NAP", "NPE", "NAF", "NPA"],
  cpd:       ["CPD"],
  "9ep-a":   ["9EP", "98A"],
  "9ep-b":   ["9EP", "98A"],
  "18f":     ["18F", "18A"],
  fer:       ["FER", "SER", "TER"],
  amd:       ["FER", "SER", "TER"],
  rpo:       ["RPO"],
  hrg:       ["HRG"],
  grt:       ["GRT"],
  roa:       ["ROA"],
  "27f":     ["27F"],
  "grt-pub": ["GRT"],
  rnf:       ["RNF"],
  opp:       ["OPP"],
  "24f-rev": ["24F"],
  erh:       ["ERH"],
  "24f-rvw": ["24F"],
};

// ── Node default label (fallback when no codes selected) ──────────────────────
const NODE_DEFAULT_LABEL: Record<string, string> = {
  prv:       "PRV",
  pct:       "PCT",
  cvp:       "CVP",
  dva:       "DVA",
  pad:       "PAD",
  cpt:       "CPT / CPE",
  nap:       "NAP / NPE / NAF",
  cpd:       "CPD",
  "9ep-a":   "9EP / 98A",
  "9ep-b":   "9EP / 98A",
  "18f":     "18F / 18A",
  fer:       "FER",
  amd:       "AMD / 13F",
  rpo:       "RPO",
  hrg:       "HRG",
  grt:       "GRT",
  roa:       "ROA",
  "27f":     "27F",
  "grt-pub": "GRT",
  rnf:       "RNF",
  opp:       "OPP",
  "24f-rev": "24F",
  erh:       "ERH",
  "24f-rvw": "24F",
};

const LS_KEY = "patent_lifecycle_node_codes_v2";
function loadCustomCodes(): Record<string, string[]> {
  try { return JSON.parse(localStorage.getItem(LS_KEY) ?? "{}"); } catch { return {}; }
}

// ── Types ─────────────────────────────────────────────────────────────────────
interface RNode {
  id: string; x: number; y: number; w: number; h: number;
  lines: string[];
  codeId?: string;
  fill: string; stroke: string; textFill?: string;
}
interface DNode {
  id: string; cx: number; cy: number; dx: number; dy: number;
  lines: string[];
  fill: string; stroke: string;
}
interface Arrow {
  id: string; d: string;
  label?: string; lx?: number; ly?: number; la?: "middle" | "start" | "end";
}

// ── Node definitions ──────────────────────────────────────────────────────────
const RNODES: RNode[] = [
  { id:"app",     x:280, y:10,  w:300, h:44,
    lines:["PATENT APPLICATION"],
    fill:"#1f2937", stroke:"#1f2937", textFill:"#ffffff" },
  { id:"prv",     x:10,  y:82,  w:148, h:78,
    lines:["PROVISIONAL","APPLICATION"], codeId:"prv",
    fill:"#dbeafe", stroke:"#3b82f6" },
  { id:"pct",     x:168, y:82,  w:152, h:78,
    lines:["PCT INTERNATIONAL","APPLICATION"], codeId:"pct",
    fill:"#d1fae5", stroke:"#059669" },
  { id:"cvp",     x:330, y:82,  w:148, h:78,
    lines:["BASIC CONVENTION","APPLICATION"], codeId:"cvp",
    fill:"#fef3c7", stroke:"#d97706" },
  { id:"dva",     x:528, y:82,  w:138, h:78,
    lines:["DIVISIONAL","APPLICATION"], codeId:"dva",
    fill:"#ede9fe", stroke:"#7c3aed" },
  { id:"pad",     x:676, y:82,  w:174, h:78,
    lines:["PATENT OF","ADDITION"], codeId:"pad",
    fill:"#fce7f3", stroke:"#be185d" },
  { id:"cpt",     x:10,  y:198, w:148, h:78,
    lines:["COMPLETE","APPLICATION"], codeId:"cpt",
    fill:"#dbeafe", stroke:"#3b82f6" },
  { id:"nap",     x:168, y:198, w:152, h:78,
    lines:["INDIAN NATIONAL","PHASE APPLICATION"], codeId:"nap",
    fill:"#d1fae5", stroke:"#059669" },
  { id:"cpd",     x:330, y:198, w:148, h:78,
    lines:["INDIAN CONVENTION","APPLICATION"], codeId:"cpd",
    fill:"#fef3c7", stroke:"#d97706" },
  { id:"9ep-a",   x:48,  y:336, w:210, h:88,
    lines:["PRE GRANT PUBLICATION","(After pub, 18 yrs can","still be new in level)"],
    codeId:"9ep-a", fill:"#bae6fd", stroke:"#0284c7" },
  { id:"9ep-b",   x:450, y:336, w:220, h:88,
    lines:["PUBLICATION AT 18TH MONTH","(18 months from Priority Date,","early pub - 1 month from request)"],
    codeId:"9ep-b", fill:"#bae6fd", stroke:"#0284c7" },
  { id:"18f",     x:188, y:476, w:324, h:72,
    lines:["REQUEST FOR EXAMINATION (Form 18)","within 48 months from Priority Date / Filing date"],
    codeId:"18f", fill:"#fecaca", stroke:"#dc2626" },
  { id:"fer",     x:270, y:600, w:208, h:56,
    lines:["FIRST EXAMINATION REPORT (FER)"],
    codeId:"fer", fill:"#bfdbfe", stroke:"#3b82f6" },
  { id:"amd",     x:270, y:710, w:208, h:60,
    lines:["RESPONSE TO FER","(6 months, ext. +3 months paid)"],
    codeId:"amd", fill:"#bfdbfe", stroke:"#3b82f6" },
  { id:"rpo",     x:14,  y:804, w:162, h:56,
    lines:["DEEMED","ABANDONED"], codeId:"rpo",
    fill:"#e5e7eb", stroke:"#6b7280" },
  { id:"hrg",     x:610, y:804, w:196, h:56,
    lines:["HEARING u/s 14"], codeId:"hrg",
    fill:"#bfdbfe", stroke:"#3b82f6" },
  { id:"grt",     x:164, y:1020, w:162, h:56,
    lines:["GRANT u/s 43"], codeId:"grt",
    fill:"#bbf7d0", stroke:"#059669", textFill:"#064e3b" },
  { id:"roa",     x:372, y:1020, w:120, h:52,
    lines:["REFUSED"], codeId:"roa",
    fill:"#fecaca", stroke:"#dc2626" },
  { id:"27f",     x:14,  y:1106, w:164, h:72,
    lines:["FORM 27 u/s 146","Working of patents in India","every financial year"],
    codeId:"27f", fill:"#fef3c7", stroke:"#d97706" },
  { id:"grt-pub", x:196, y:1106, w:162, h:72,
    lines:["PUBLICATION OF GRANT &","GRANT CERTIFICATE","RECORDS u/s 43(2)"],
    codeId:"grt-pub", fill:"#bbf7d0", stroke:"#059669", textFill:"#064e3b" },
  { id:"rnf",     x:196, y:1198, w:162, h:52,
    lines:["ANNUITY PAYMENT u/s 53"],
    codeId:"rnf", fill:"#fed7aa", stroke:"#c2410c" },
  { id:"opp",     x:382, y:1108, w:194, h:60,
    lines:["POST GRANT OPPOSITION u/s 25(2)","within 1 Year from Grant"],
    codeId:"opp", fill:"#bfdbfe", stroke:"#3b82f6" },
  { id:"24f-rev", x:382, y:1190, w:194, h:52,
    lines:["REVOCATION OF PATENT u/s 64"],
    codeId:"24f-rev", fill:"#bfdbfe", stroke:"#3b82f6" },
  { id:"erh",     x:610, y:1090, w:196, h:52,
    lines:["APPEAL u/s 117A"], codeId:"erh",
    fill:"#fce7f3", stroke:"#be185d" },
  { id:"24f-rvw", x:610, y:1164, w:196, h:52,
    lines:["REVIEW PETITION u/s 72(2)(f)"],
    codeId:"24f-rvw", fill:"#fce7f3", stroke:"#be185d" },
];

const DNODES: DNode[] = [
  { id:"comp1", cx:430, cy:824, dx:118, dy:56,
    lines:["COMPLIANCE","(Response filed but","satisfactory/objections)"],
    fill:"#fde68a", stroke:"#d97706" },
  { id:"comp2", cx:430, cy:952, dx:116, dy:54,
    lines:["COMPLIANCE","(Response filed but","not satisfactory)"],
    fill:"#fde68a", stroke:"#d97706" },
];

const ARROWS: Arrow[] = [
  { id:"a1",  d:"M 430 54 C 430 68 84 68 84 82" },
  { id:"a2",  d:"M 430 54 C 430 68 244 68 244 82" },
  { id:"a3",  d:"M 430 54 C 430 68 404 68 404 82" },
  { id:"a4",  d:"M 430 54 C 430 68 597 68 597 82" },
  { id:"a5",  d:"M 430 54 C 430 68 763 68 763 82" },
  { id:"a6",  d:"M 84 160 L 84 198" },
  { id:"a7",  d:"M 244 160 L 244 198" },
  { id:"a8",  d:"M 404 160 L 404 198" },
  { id:"a9",  d:"M 84 276 C 84 306 153 306 153 336" },
  { id:"a10", d:"M 244 276 C 244 306 153 306 153 336" },
  { id:"a11", d:"M 404 276 C 404 306 560 306 560 336" },
  { id:"a12", d:"M 597 160 C 597 248 560 248 560 336" },
  { id:"a13", d:"M 763 160 C 763 248 560 248 560 336" },
  { id:"a14", d:"M 153 424 C 153 450 280 450 280 476" },
  { id:"a15", d:"M 560 424 C 560 450 512 450 512 476" },
  { id:"a16", d:"M 350 548 L 374 600" },
  { id:"a17", d:"M 374 656 L 374 710" },
  { id:"a18", d:"M 374 770 L 374 780 L 430 780 L 430 768" },
  { id:"a19", d:"M 312 824 L 176 832",   label:"NO COMPLIANCE",  lx:236, ly:816, la:"middle" },
  { id:"a20", d:"M 548 824 L 610 824",   label:"HEARING",        lx:572, ly:816, la:"middle" },
  { id:"a21", d:"M 430 880 L 430 898",   label:"Response filed & objections complied", lx:470, ly:892, la:"start" },
  { id:"a22", d:"M 708 860 C 708 902 546 902 546 952", label:"Written submission", lx:636, ly:884, la:"middle" },
  { id:"a22b",d:"", label:"filed within 30 days", lx:636, ly:895, la:"middle" },
  { id:"a23", d:"M 314 952 C 275 986 245 986 245 1020", label:"YES", lx:286, ly:970, la:"middle" },
  { id:"a24", d:"M 430 1006 L 432 1020", label:"NO",  lx:448, ly:1014, la:"start" },
  { id:"a25", d:"M 164 1048 C 96 1048 96 1078 96 1106" },
  { id:"a26", d:"M 245 1076 L 277 1106" },
  { id:"a27", d:"M 277 1178 L 277 1198" },
  { id:"a28", d:"M 326 1048 C 479 1048 479 1078 479 1108" },
  { id:"a29", d:"M 479 1168 L 479 1190" },
  { id:"a30", d:"M 492 1046 C 550 1046 610 1090 610 1116", label:"w/i 3 months", lx:548, ly:1060, la:"middle" },
  { id:"a31", d:"M 492 1046 L 510 1046 L 510 1190 L 610 1190", label:"w/i 1 month", lx:514, ly:1122, la:"start" },
];

// ── SVG helpers ───────────────────────────────────────────────────────────────
function SvgText({ lines, cx, cy, dy = 12, fill = "#1e3a5f", fs = 9.5 }: {
  lines: string[]; cx: number; cy: number; dy?: number; fill?: string; fs?: number;
}) {
  const startY = cy - ((lines.length - 1) * dy) / 2;
  return <>
    {lines.map((l, i) => (
      <text key={i} x={cx} y={startY + i * dy}
        textAnchor="middle" dominantBaseline="middle"
        fontSize={fs} fontWeight={600} fill={fill}
        fontFamily="system-ui, -apple-system, sans-serif">{l}</text>
    ))}
  </>;
}

function CountBadge({ count, cx, nodeY }: { count: number; cx: number; nodeY: number }) {
  if (count <= 0) return null;
  const label = count > 99 ? "99+" : String(count);
  const bw = Math.max(label.length * 7 + 10, 22);
  return (
    <g>
      <rect x={cx - bw / 2} y={nodeY - 8} width={bw} height={16} rx={8}
        fill="#16a34a" stroke="#14532d" strokeWidth={0.5} />
      <text x={cx} y={nodeY} textAnchor="middle" dominantBaseline="middle"
        fontSize={8} fontWeight={700} fill="#ffffff"
        fontFamily="system-ui, sans-serif">{label}</text>
    </g>
  );
}

// ── Multi-select dropdown ─────────────────────────────────────────────────────
function CodePicker({
  nodeId, nodeName, current, onSave, onCancel,
}: {
  nodeId: string; nodeName: string; current: string[];
  onSave: (codes: string[]) => void; onCancel: () => void;
}) {
  const [selected, setSelected] = useState<string[]>(current);
  const [search, setSearch] = useState("");
  const filtered = ALL_SERVICE_CODES.filter(c =>
    c.toLowerCase().includes(search.toLowerCase())
  );

  function toggle(code: string) {
    setSelected(sel => sel.includes(code) ? sel.filter(c => c !== code) : [...sel, code]);
  }

  return (
    <div
      className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 z-50 bg-background border border-border rounded-xl shadow-2xl w-80"
      onClick={e => e.stopPropagation()}
    >
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 border-b border-border">
        <div>
          <p className="text-[10px] text-muted-foreground uppercase tracking-wide">Service codes for</p>
          <p className="text-sm font-semibold">{nodeName}</p>
        </div>
        <button onClick={onCancel} className="p-1 rounded hover:bg-muted text-muted-foreground">
          <X className="h-4 w-4" />
        </button>
      </div>

      {/* Search */}
      <div className="px-3 pt-3">
        <input
          autoFocus
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Filter codes…"
          className="w-full h-7 rounded border border-border bg-muted/30 px-2 text-xs font-mono
            focus:outline-none focus:ring-1 focus:ring-primary placeholder:text-muted-foreground"
        />
      </div>

      {/* Code grid */}
      <div className="grid grid-cols-4 gap-1.5 px-3 py-2 max-h-44 overflow-y-auto">
        {filtered.map(code => {
          const checked = selected.includes(code);
          return (
            <button
              key={code}
              onClick={() => toggle(code)}
              className={`rounded px-1.5 py-1 text-[11px] font-mono font-semibold transition-colors border ${
                checked
                  ? "bg-primary text-primary-foreground border-primary"
                  : "bg-muted/30 text-muted-foreground border-border hover:border-primary/50 hover:text-foreground"
              }`}
            >
              {code}
            </button>
          );
        })}
      </div>

      {/* Selected summary */}
      <div className="px-3 pb-1 min-h-[24px]">
        {selected.length > 0 ? (
          <p className="text-[10px] text-muted-foreground font-mono">
            Selected: {selected.join(", ")}
          </p>
        ) : (
          <p className="text-[10px] text-muted-foreground italic">No codes selected</p>
        )}
      </div>

      {/* Footer */}
      <div className="flex items-center justify-between px-3 py-3 border-t border-border gap-2">
        <button
          onClick={() => setSelected(NODE_SERVICE_CODES_DEFAULT[nodeId] ?? [])}
          className="flex items-center gap-1 text-[11px] text-muted-foreground hover:text-foreground transition-colors"
        >
          <RotateCcw className="h-3 w-3" /> Reset default
        </button>
        <div className="flex gap-2">
          <button onClick={onCancel}
            className="px-3 py-1.5 text-xs rounded border border-border hover:bg-muted transition-colors">
            Cancel
          </button>
          <button
            onClick={() => onSave(selected)}
            className="px-3 py-1.5 text-xs rounded bg-primary text-primary-foreground hover:bg-primary/90 transition-colors font-medium"
          >
            Save{selected.length > 0 ? ` (${selected.length})` : ""}
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Main export ───────────────────────────────────────────────────────────────
export function IndianPatentLifecycle({
  isAdmin,
  counts = {},
  onNodeClick,
}: {
  isAdmin: boolean;
  counts?: Record<string, number>;
  onNodeClick?: (nodeId: string, label: string, serviceCodes: string[]) => void;
}) {
  const [customCodes, setCustomCodes] = useState<Record<string, string[]>>(loadCustomCodes);
  const [editId, setEditId] = useState<string | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  function getNodeCodes(nodeId: string): string[] {
    return customCodes[nodeId] ?? NODE_SERVICE_CODES_DEFAULT[nodeId] ?? [];
  }

  function getBadgeText(nodeId: string): string {
    const codes = getNodeCodes(nodeId);
    return codes.length ? codes.join(" / ") : (NODE_DEFAULT_LABEL[nodeId] ?? nodeId.toUpperCase());
  }

  function saveEdit(codes: string[]) {
    if (!editId) return;
    const next = { ...customCodes, [editId]: codes };
    setCustomCodes(next);
    localStorage.setItem(LS_KEY, JSON.stringify(next));
    setEditId(null);
  }

  function resetAll() {
    setCustomCodes({});
    localStorage.removeItem(LS_KEY);
  }

  function handleNodeClick(nodeId: string, label: string) {
    if (!onNodeClick) return;
    onNodeClick(nodeId, label, getNodeCodes(nodeId));
  }

  function nodeCount(nodeId: string): number {
    return getNodeCodes(nodeId).reduce((sum, svc) => sum + (counts[svc] ?? 0), 0);
  }

  const hasCustom = Object.keys(customCodes).length > 0;

  return (
    <div className="relative" ref={containerRef}>
      {/* Admin hint bar */}
      {isAdmin && (
        <div className="flex items-center justify-between mb-2">
          <p className="text-[10px] text-muted-foreground flex items-center gap-1">
            <Edit2 className="h-3 w-3" />
            Super Admin: click any service code badge on a node to change its assigned codes.
          </p>
          {hasCustom && (
            <button onClick={resetAll}
              className="flex items-center gap-1 text-[10px] text-muted-foreground hover:text-destructive transition-colors">
              <RotateCcw className="h-3 w-3" /> Reset all to defaults
            </button>
          )}
        </div>
      )}

      {/* SVG container — relative so the picker overlay can be centred inside it */}
      <div className="relative overflow-auto rounded-lg border border-border bg-card">
        {/* Code picker overlay */}
        {editId && (
          <CodePicker
            nodeId={editId}
            nodeName={RNODES.find(n => n.codeId === editId)?.lines[0] ?? editId}
            current={getNodeCodes(editId)}
            onSave={saveEdit}
            onCancel={() => setEditId(null)}
          />
        )}

        <svg viewBox="0 0 860 1260" width="100%"
          style={{ minWidth: 620, maxWidth: 860, display: "block" }}
          onClick={() => editId && setEditId(null)}>
          <defs>
            <marker id="lc-arrow" markerWidth="7" markerHeight="7" refX="5" refY="3" orient="auto">
              <path d="M 0 0 L 6 3 L 0 6 Z" fill="#9ca3af" />
            </marker>
          </defs>
          <rect x="0" y="0" width="860" height="1260" fill="#0f172a" rx="8" />

          {/* Arrows */}
          {ARROWS.filter(a => a.d).map(a => (
            <g key={a.id}>
              <path d={a.d} fill="none" stroke="#4b5563" strokeWidth={1.5}
                markerEnd="url(#lc-arrow)" />
              {a.label && a.lx !== undefined && (
                <text x={a.lx} y={a.ly} textAnchor={a.la ?? "middle"}
                  fontSize={7.5} fill="#6b7280" fontFamily="system-ui, sans-serif">{a.label}</text>
              )}
            </g>
          ))}

          {/* Time bubbles */}
          {[
            { x:84, y:179, label:"12 Months" },
            { x:244, y:179, label:"31 Months" },
            { x:404, y:179, label:"12 Months" },
          ].map(b => (
            <g key={b.x}>
              <rect x={b.x-36} y={b.y-10} width={72} height={20} rx={10}
                fill="#1e293b" stroke="#374151" strokeWidth={1} />
              <text x={b.x} y={b.y+1} textAnchor="middle" dominantBaseline="middle"
                fontSize={8.5} fontWeight={700} fill="#94a3b8"
                fontFamily="system-ui, sans-serif">{b.label}</text>
            </g>
          ))}

          {/* Rect nodes */}
          {RNODES.map(n => {
            const cx = n.x + n.w / 2;
            const cy = n.y + n.h / 2;
            const isHdr = n.id === "app";
            const isClickable = !!onNodeClick && !isHdr;
            const badgeText = n.codeId ? getBadgeText(n.codeId) : null;
            const textCy = badgeText ? cy - 9 : cy;
            const tf = n.textFill ?? "#1e3a5f";
            const nc = isHdr ? 0 : nodeCount(n.id);

            // Badge layout
            const bw = badgeText ? Math.max(badgeText.length * 5.8 + 16, 40) : 0;
            const bx = cx - bw / 2;
            const by = n.y + n.h - 18;

            return (
              <g key={n.id}
                onClick={() => !isHdr && !editId && handleNodeClick(n.id, n.lines[0])}
                style={{ cursor: isClickable ? "pointer" : "default" }}>
                <rect x={n.x} y={n.y} width={n.w} height={n.h}
                  rx={6} fill={n.fill} stroke={n.stroke} strokeWidth={1.5}
                  className={isClickable ? "hover:opacity-85 transition-opacity" : ""} />
                <SvgText lines={n.lines} cx={cx} cy={textCy}
                  fill={isHdr ? "#fff" : tf}
                  fs={isHdr ? 13 : 9.5} dy={isHdr ? 16 : 11.5} />

                {/* Service code badge */}
                {badgeText && (
                  <g onClick={(e) => { e.stopPropagation(); if (isAdmin && n.codeId) setEditId(n.codeId); }}
                    style={{ cursor: isAdmin ? "pointer" : "default" }}>
                    <rect x={bx} y={by} width={bw} height={14} rx={4}
                      fill="rgba(0,0,0,0.15)"
                      stroke={isAdmin ? "#6b7280" : "none"}
                      strokeWidth={isAdmin ? 0.7 : 0}
                      strokeDasharray={isAdmin ? "2,2" : "none"} />
                    <text x={cx} y={by + 7}
                      textAnchor="middle" dominantBaseline="middle"
                      fontSize={7.5} fontWeight={700} fill="#1e3a5f"
                      fontFamily="monospace, system-ui" letterSpacing={0.3}>
                      {badgeText}
                    </text>
                    {isAdmin && (
                      <text x={bx + bw - 2} y={by + 7}
                        textAnchor="end" dominantBaseline="middle"
                        fontSize={6} fill="#6b7280">▾</text>
                    )}
                  </g>
                )}

                {/* Count badge */}
                {nc > 0 && <CountBadge count={nc} cx={n.x + n.w - 14} nodeY={n.y + 8} />}
              </g>
            );
          })}

          {/* Diamond nodes */}
          {DNODES.map(n => {
            const pts = [
              `${n.cx},${n.cy - n.dy}`,
              `${n.cx + n.dx},${n.cy}`,
              `${n.cx},${n.cy + n.dy}`,
              `${n.cx - n.dx},${n.cy}`,
            ].join(" ");
            const mid = n.cy - (n.lines.length - 1) * 6.5;
            return (
              <g key={n.id}>
                <polygon points={pts} fill={n.fill} stroke={n.stroke} strokeWidth={1.5} />
                {n.lines.map((l, i) => (
                  <text key={i} x={n.cx} y={mid + i * 13}
                    textAnchor="middle" dominantBaseline="middle"
                    fontSize={8.5} fontWeight={600} fill="#78350f"
                    fontFamily="system-ui, sans-serif">{l}</text>
                ))}
              </g>
            );
          })}

          {/* Section labels */}
          <text x={430} y={72} textAnchor="middle" fontSize={7} fill="#4b5563"
            letterSpacing={1.5} fontFamily="system-ui, sans-serif">FILING TYPES</text>
          <text x={430} y={466} textAnchor="middle" fontSize={7} fill="#4b5563"
            letterSpacing={1.5} fontFamily="system-ui, sans-serif">EXAMINATION</text>
          <text x={280} y={1096} textAnchor="middle" fontSize={7} fill="#4b5563"
            letterSpacing={1.5} fontFamily="system-ui, sans-serif">POST-GRANT</text>
        </svg>
      </div>
    </div>
  );
}
