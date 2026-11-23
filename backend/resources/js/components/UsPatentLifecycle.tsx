/**
 * US (USPTO) patent process flowchart for the Patent Lifecycle page's "US Lifecycle" tab.
 * Four bands follow the firm's reference chart (Searching, Drafting, Negotiating, Patent
 * Grant), plus an After Grant band for what the firm tracks next (maintenance fees,
 * continuations, post-grant proceedings). Each box lists the service codes it counts;
 * counts come from US cases only, and clicking a box lists those cases.
 */

interface Box {
  id: string; x: number; y: number; w: number; h: number;
  lines: string[];
  codes?: string[];
  fill: string; stroke: string; text?: string;
}
interface Diamond { id: string; cx: number; cy: number; dx: number; dy: number; lines: string[] }
interface Arrow { id: string; d: string; label?: string; lx?: number; ly?: number; anchor?: "start" | "middle" | "end"; dashed?: boolean }
interface Band { label: string; y: number; h: number; fill: string; text: string }

const BLUE = { fill: "#dbeafe", stroke: "#3b82f6" };
const ORANGE = { fill: "#ffedd5", stroke: "#ea580c" };
const YELLOW = { fill: "#fef9c3", stroke: "#ca8a04" };
const GREEN = { fill: "#dcfce7", stroke: "#16a34a" };
const PURPLE = { fill: "#ede9fe", stroke: "#7c3aed" };
const GREY = { fill: "#e5e7eb", stroke: "#6b7280" };

const BANDS: Band[] = [
  { label: "SEARCHING", y: 8, h: 210, fill: "rgba(59,130,246,0.10)", text: "#60a5fa" },
  { label: "DRAFTING", y: 224, h: 206, fill: "rgba(249,115,22,0.10)", text: "#fb923c" },
  { label: "NEGOTIATING", y: 436, h: 464, fill: "rgba(234,179,8,0.08)", text: "#facc15" },
  { label: "PATENT GRANT", y: 906, h: 104, fill: "rgba(34,197,94,0.10)", text: "#4ade80" },
  { label: "AFTER GRANT", y: 1016, h: 200, fill: "rgba(124,58,237,0.10)", text: "#a78bfa" },
];

const BOXES: Box[] = [
  { id: "start", x: 24, y: 100, w: 82, h: 40, lines: ["START"], fill: "#1f2937", stroke: "#1f2937", text: "#ffffff" },
  { id: "search", x: 300, y: 90, w: 150, h: 60, lines: ["PRIOR ART /", "PATENTABILITY SEARCH"], codes: ["PAS", "SRH", "PAT", "FTO"], ...BLUE },
  { id: "keep", x: 650, y: 84, w: 190, h: 72, lines: ["KEEP INVENTING OR", "GO TO MARKET WITHOUT", "A PATENT APPLICATION"], ...GREY },

  { id: "draft", x: 40, y: 250, w: 180, h: 60, lines: ["DRAFT PATENT", "APPLICATION"], codes: ["PRD", "DFT"], ...ORANGE },
  { id: "review", x: 262, y: 250, w: 136, h: 60, lines: ["CLIENT", "REVIEW"], ...GREY },
  { id: "prv", x: 590, y: 240, w: 120, h: 56, lines: ["FILE PROVISIONAL", "APPLICATION"], codes: ["PRV"], ...ORANGE },
  { id: "pct", x: 290, y: 348, w: 210, h: 54, lines: ["PCT → US NATIONAL PHASE", "(30 MONTHS FROM PRIORITY)"], codes: ["PCT", "NPE"], ...ORANGE },
  { id: "npv", x: 590, y: 340, w: 240, h: 62, lines: ["FILE NON-PROVISIONAL", "APPLICATION"], codes: ["NPV", "CPT", "PIA"], ...ORANGE },

  { id: "wait", x: 90, y: 470, w: 170, h: 56, lines: ["WAIT FOR USPTO", "OFFICE ACTION"], ...GREY },
  { id: "ids", x: 282, y: 470, w: 190, h: 56, lines: ["IDS / MISSING PARTS /", "TRACK ONE / PRELIM. AMDT"], codes: ["IDS", "FMP", "TRC", "PAM"], ...YELLOW },
  { id: "elect", x: 8, y: 560, w: 72, h: 56, lines: ["ELECT"], codes: ["RRT"], ...YELLOW },
  { id: "study", x: 330, y: 560, w: 150, h: 50, lines: ["STUDY OFFICE", "ACTION"], codes: ["NFO", "FOA", "OAR"], ...YELLOW },
  { id: "respond", x: 520, y: 540, w: 160, h: 56, lines: ["DRAFT & FILE RESPONSE", "(OR RCE)"], codes: ["NFO", "FOA", "OAR", "RCE", "QOA"], ...YELLOW },
  { id: "drop", x: 704, y: 556, w: 122, h: 54, lines: ["DROP /", "ABANDON"], codes: ["WTH"], ...GREY },
  { id: "advise", x: 330, y: 640, w: 150, h: 50, lines: ["ADVISE", "OPTIONS"], ...GREY },
  { id: "appeal-notice", x: 690, y: 690, w: 150, h: 50, lines: ["NOTICE OF", "APPEAL"], codes: ["APL"], ...YELLOW },
  { id: "interview", x: 470, y: 770, w: 150, h: 50, lines: ["EXAMINER", "INTERVIEW"], codes: ["EXI"], ...YELLOW },
  { id: "appeal", x: 690, y: 774, w: 150, h: 56, lines: ["APPEAL PROCESS", "(PTAB → CAFC)"], codes: ["APR"], ...YELLOW },

  { id: "issue-fee", x: 90, y: 922, w: 170, h: 52, lines: ["PAY ISSUE FEE"], codes: ["NOA"], ...GREEN },
  { id: "grant", x: 330, y: 922, w: 160, h: 52, lines: ["PATENT GRANT"], codes: ["GRT"], ...GREEN },

  { id: "continuing", x: 24, y: 1050, w: 240, h: 62, lines: ["CONTINUATION / DIVISIONAL /", "CIP (WHILE PARENT PENDING)"], codes: ["CON", "DIV", "CIP"], ...PURPLE },
  { id: "maintenance", x: 300, y: 1050, w: 220, h: 62, lines: ["MAINTENANCE FEES", "3.5 / 7.5 / 11.5 YEARS"], codes: ["MFE", "REN"], ...PURPLE },
  { id: "post-grant", x: 556, y: 1050, w: 280, h: 62, lines: ["POST-GRANT: REISSUE, REEXAM,", "IPR, PGR (9 MONTHS)"], codes: ["REI", "XPR", "IPR", "PGV"], ...PURPLE },
  { id: "expiry", x: 300, y: 1150, w: 220, h: 48, lines: ["EXPIRY: 20 YEARS FROM", "FILING (+ PTA)"], ...GREY },
];

const DIAMONDS: Diamond[] = [
  { id: "q-search", cx: 200, cy: 120, dx: 62, dy: 44, lines: ["PATENT", "SEARCH?"] },
  { id: "q-results", cx: 545, cy: 120, dx: 62, dy: 44, lines: ["SEARCH", "RESULTS OK?"] },
  { id: "q-ready", cx: 490, cy: 280, dx: 62, dy: 44, lines: ["READY", "TO FILE?"] },
  { id: "q-revise", cx: 780, cy: 268, dx: 50, dy: 36, lines: ["REVISE?"] },
  { id: "q-action", cx: 175, cy: 640, dx: 85, dy: 60, lines: ["REJECTION,", "ALLOWANCE OR", "RESTRICTION?"] },
  { id: "q-option", cx: 590, cy: 665, dx: 70, dy: 50, lines: ["CHOOSE", "OPTION"] },
  { id: "q-interview", cx: 330, cy: 795, dx: 72, dy: 46, lines: ["INTERVIEW", "SUCCESS?"] },
];

const ARROWS: Arrow[] = [
  { id: "a1", d: "M106,120 L136,120" },
  { id: "a2", d: "M262,120 L298,120", label: "Y", lx: 280, ly: 112 },
  { id: "a3", d: "M450,120 L481,120" },
  { id: "a4", d: "M607,120 L648,120", label: "N", lx: 628, ly: 112 },
  { id: "a5", d: "M200,164 L200,248", label: "N", lx: 207, ly: 186, anchor: "start" },
  { id: "a6", d: "M545,164 L545,202 L150,202 L150,248", label: "Y", lx: 552, ly: 186, anchor: "start" },

  { id: "a7", d: "M220,280 L260,280" },
  { id: "a8", d: "M398,280 L426,280" },
  { id: "a9", d: "M490,236 L490,230 L120,230 L120,248", label: "N (revise draft)", lx: 300, ly: 226 },
  { id: "a10", d: "M552,280 L572,280 L572,268 L588,268", label: "Y", lx: 562, ly: 274 },
  { id: "a11", d: "M572,280 L572,371 L588,371" },
  { id: "a12", d: "M710,268 L728,268" },
  { id: "a13", d: "M780,232 L780,230 L490,230", label: "Y", lx: 788, ly: 238, anchor: "start" },
  { id: "a14", d: "M780,304 L780,338", label: "N", lx: 788, ly: 322, anchor: "start" },
  { id: "a15", d: "M500,375 L588,375" },

  { id: "a16", d: "M710,402 L710,452 L175,452 L175,468" },
  { id: "a17", d: "M175,526 L175,578" },
  { id: "a18", d: "M90,640 L44,640 L44,618", label: "Restriction", lx: 46, ly: 652, anchor: "start" },
  { id: "a19", d: "M44,560 L44,498 L88,498" },
  { id: "a20", d: "M260,640 L300,640 L300,585 L328,585", label: "Reject", lx: 262, ly: 632, anchor: "start" },
  { id: "a21", d: "M405,610 L405,638" },
  { id: "a22", d: "M480,665 L518,665" },
  { id: "a23", d: "M590,615 L590,598" },
  { id: "a24", d: "M640,540 L640,454" },
  { id: "a25", d: "M660,665 L765,665 L765,612" },
  { id: "a26", d: "M765,665 L765,688" },
  { id: "a27", d: "M765,740 L765,772" },
  { id: "a28", d: "M590,715 L590,745 L545,745 L545,768" },
  { id: "a29", d: "M470,795 L404,795" },
  { id: "a30", d: "M330,749 L330,722 L405,722 L405,692", label: "N", lx: 337, ly: 740, anchor: "start" },
  { id: "a31", d: "M330,841 L330,948 L262,948", label: "Y", lx: 337, ly: 860, anchor: "start" },
  { id: "a32", d: "M175,700 L175,920", label: "Allowance", lx: 182, ly: 760, anchor: "start" },

  { id: "a33", d: "M260,948 L328,948" },
  { id: "a34", d: "M410,974 L410,1048" },
  { id: "a35", d: "M410,1112 L410,1148" },
  { id: "a36", d: "M490,948 L696,948 L696,1048" },
];

/** Statutory time limits shown next to the boxes they govern. */
const NOTES = [
  { x: 680, y: 318, text: "12 months to convert · not extendable" },
  { x: 405, y: 543, text: "Reply: 3 months, extendable to 6" },
  { x: 765, y: 756, text: "Brief: 2 months" },
  { x: 175, y: 990, text: "3 months · NOT extendable" },
  { x: 410, y: 1128, text: "Window opens 6 mo before · 6 mo grace" },
];

function Lines({ lines, cx, cy, fill, size = 9.5, gap = 11.5 }: { lines: string[]; cx: number; cy: number; fill: string; size?: number; gap?: number }) {
  const top = cy - ((lines.length - 1) * gap) / 2;
  return (
    <>
      {lines.map((line, i) => (
        <text key={i} x={cx} y={top + i * gap} textAnchor="middle" dominantBaseline="middle"
          fontSize={size} fontWeight={700} fill={fill} fontFamily="system-ui, sans-serif">{line}</text>
      ))}
    </>
  );
}

export function UsPatentLifecycle({ counts = {}, onNodeClick }: {
  counts?: Record<string, number>
  onNodeClick?: (nodeId: string, label: string, serviceCodes: string[]) => void
}) {
  const countFor = (codes: string[] = []) => [...new Set(codes)].reduce((sum, code) => sum + (counts[code] ?? 0), 0);

  return (
    <div className="space-y-2">
      <p className="text-[11px] text-muted-foreground">
        USPTO prosecution flow. Counts are active US cases by service code; click a box to list them.
        Deadlines follow 35 USC / 37 CFR and are docketed automatically from USPTO events.
      </p>
      <div className="overflow-auto rounded-lg border border-border bg-card">
        <svg viewBox="0 0 860 1224" width="100%" style={{ minWidth: 620, maxWidth: 860, display: "block" }}
          role="img" aria-label="US patent application process flowchart">
          <defs>
            <marker id="us-lc-arrow" markerWidth="7" markerHeight="7" refX="5" refY="3" orient="auto">
              <path d="M 0 0 L 6 3 L 0 6 Z" fill="#9ca3af" />
            </marker>
          </defs>
          <rect x="0" y="0" width="860" height="1224" fill="#0f172a" rx="8" />

          {BANDS.map((band) => (
            <g key={band.label}>
              <rect x={4} y={band.y} width={852} height={band.h} rx={6} fill={band.fill} />
              <text x={848} y={band.y + 14} textAnchor="end" fontSize={8} fontWeight={700} letterSpacing={1.5}
                fill={band.text} fontFamily="system-ui, sans-serif">{band.label}</text>
            </g>
          ))}

          {ARROWS.map((arrow) => (
            <g key={arrow.id}>
              <path d={arrow.d} fill="none" stroke="#6b7280" strokeWidth={1.5}
                strokeDasharray={arrow.dashed ? "4,3" : undefined} markerEnd="url(#us-lc-arrow)" />
              {arrow.label && (
                <text x={arrow.lx} y={arrow.ly} textAnchor={arrow.anchor ?? "middle"} fontSize={8} fontWeight={600}
                  fill="#cbd5e1" fontFamily="system-ui, sans-serif">{arrow.label}</text>
              )}
            </g>
          ))}

          {DIAMONDS.map((diamond) => (
            <g key={diamond.id}>
              <polygon
                points={`${diamond.cx},${diamond.cy - diamond.dy} ${diamond.cx + diamond.dx},${diamond.cy} ${diamond.cx},${diamond.cy + diamond.dy} ${diamond.cx - diamond.dx},${diamond.cy}`}
                fill="#fef3c7" stroke="#d97706" strokeWidth={1.5} />
              <Lines lines={diamond.lines} cx={diamond.cx} cy={diamond.cy} fill="#78350f" size={8.5} gap={12} />
            </g>
          ))}

          {BOXES.map((box) => {
            const codes = box.codes ?? [];
            const clickable = !!onNodeClick && codes.length > 0;
            const count = countFor(codes);
            const cx = box.x + box.w / 2;
            const badge = codes.join(" / ");
            const badgeWidth = Math.max(badge.length * 5.6 + 14, 36);
            return (
              <g key={box.id} style={{ cursor: clickable ? "pointer" : "default" }}
                onClick={() => clickable && onNodeClick?.(box.id, box.lines.join(" "), [...new Set(codes)])}>
                <rect x={box.x} y={box.y} width={box.w} height={box.h} rx={6} fill={box.fill} stroke={box.stroke} strokeWidth={1.5}
                  className={clickable ? "transition-opacity hover:opacity-85" : ""} />
                <Lines lines={box.lines} cx={cx} cy={box.y + box.h / 2 - (codes.length ? 8 : 0)} fill={box.text ?? "#1e293b"} />
                {codes.length > 0 && (
                  <g>
                    <rect x={cx - badgeWidth / 2} y={box.y + box.h - 17} width={badgeWidth} height={13} rx={4} fill="rgba(0,0,0,0.12)" />
                    <text x={cx} y={box.y + box.h - 10.5} textAnchor="middle" dominantBaseline="middle" fontSize={7.2} fontWeight={700}
                      fill="#1e293b" fontFamily="monospace, system-ui">{badge}</text>
                  </g>
                )}
                {count > 0 && (
                  <g>
                    <circle cx={box.x + box.w - 6} cy={box.y + 6} r={11} fill="#b45309" stroke="#0f172a" strokeWidth={1.5} />
                    <text x={box.x + box.w - 6} y={box.y + 6.5} textAnchor="middle" dominantBaseline="middle" fontSize={9} fontWeight={700}
                      fill="#ffffff" fontFamily="system-ui, sans-serif">{count}</text>
                  </g>
                )}
              </g>
            );
          })}

          {NOTES.map((note) => (
            <g key={note.text}>
              <rect x={note.x - note.text.length * 2.45 - 8} y={note.y - 8} width={note.text.length * 4.9 + 16} height={16} rx={8}
                fill="#1e293b" stroke="#374151" strokeWidth={1} />
              <text x={note.x} y={note.y + 0.5} textAnchor="middle" dominantBaseline="middle" fontSize={7.5} fontWeight={700}
                fill="#94a3b8" fontFamily="system-ui, sans-serif">{note.text}</text>
            </g>
          ))}
        </svg>
      </div>
    </div>
  );
}
