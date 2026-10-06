import { PieChart, Pie, Cell, Tooltip as ReTooltip } from "recharts";

export const OFFICE_LABELS: Record<string, string> = {
  IN: "India", US: "USA", JP: "Japan", EP: "EP", KR: "Korea",
  WO: "PCT/WIPO", AU: "Australia", CN: "China", DE: "Germany",
};
export const OFFICE_COLORS: Record<string, string> = {
  IN: "#1e3a5f", US: "#4a9fd4", JP: "#7ec8e3", EP: "#e05c3a", KR: "#6b7280",
  WO: "#8b5cf6", AU: "#f59e0b", CN: "#ef4444", DE: "#10b981",
};
export const FALLBACK_COLORS = ["#1e3a5f", "#4a9fd4", "#7ec8e3", "#e05c3a", "#6b7280", "#8b5cf6"];

export function officeLabel(code: string) { return OFFICE_LABELS[code] ?? code; }
export function officeColor(code: string, idx = 0) { return OFFICE_COLORS[code] ?? FALLBACK_COLORS[idx % FALLBACK_COLORS.length]; }

export interface DonutDatum {
  code: string;
  name: string;
  value: number;
  color: string;
}

/** Interactive donut used by the portfolio-wide status breakdowns. */
export function DonutChart({
  data, total, label, sublabel, activeCode, onSelect,
}: {
  data: DonutDatum[];
  total: number;
  label: string;
  sublabel?: string;
  activeCode: string | null;
  onSelect: (code: string | null) => void;
}) {
  const display = data.map(d => ({
    ...d,
    opacity: activeCode && activeCode !== d.code ? 0.35 : 1,
  }));

  return (
    <div className="flex flex-col items-center gap-2">
      <div className="text-center">
        <div className="font-medium text-sm text-foreground">{label}</div>
        {sublabel && <div className="text-[10px] text-muted-foreground">{sublabel}</div>}
      </div>
      <div className="relative cursor-pointer">
        <PieChart width={190} height={190}>
          <Pie
            data={display}
            cx={95} cy={95}
            innerRadius={54} outerRadius={82}
            dataKey="value"
            strokeWidth={activeCode ? 2 : 1}
            stroke="transparent"
            onClick={(entry: any) => onSelect(activeCode === entry.code ? null : entry.code)}
          >
            {display.map((d, i) => (
              <Cell
                key={i}
                fill={d.color}
                opacity={d.opacity}
                stroke={activeCode === d.code ? "#fff" : "transparent"}
                strokeWidth={activeCode === d.code ? 2 : 0}
                style={{ cursor: "pointer", transition: "opacity 0.2s" }}
              />
            ))}
          </Pie>
          <ReTooltip
            formatter={(v: number, _: string, props: any) => {
              const pct = total > 0 ? ((v / total) * 100).toFixed(1) : "0";
              return [`${v} (${pct}%)`, props.payload?.name ?? ""];
            }}
            contentStyle={{ background: "var(--background)", border: "1px solid var(--border)", borderRadius: 8, fontSize: 12 }}
          />
        </PieChart>
        <div className="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
          <span className="font-display text-3xl font-bold text-foreground">
            {activeCode ? (data.find(d => d.code === activeCode)?.value ?? 0) : total}
          </span>
          {activeCode && <span className="text-[10px] text-muted-foreground mt-0.5">{data.find(d => d.code === activeCode)?.name}</span>}
        </div>
      </div>
      <div className="flex flex-wrap justify-center gap-x-3 gap-y-1">
        {data.map(d => (
          <button
            key={d.code}
            onClick={() => onSelect(activeCode === d.code ? null : d.code)}
            className={`flex items-center gap-1 text-xs transition-opacity ${activeCode && activeCode !== d.code ? "opacity-40" : "opacity-100"}`}
          >
            <span className="inline-block h-2 w-2 rounded-full" style={{ background: d.color }} />
            <span className={activeCode === d.code ? "font-semibold text-foreground" : "text-muted-foreground"}>
              {d.name}
            </span>
          </button>
        ))}
      </div>
    </div>
  );
}

/** Single-series horizontal bar, used for pipeline/stage breakdowns. */
export function StatusBar({
  name, count, max, active, onClick,
}: {
  name: string; count: number; max: number; active: boolean; onClick: () => void;
}) {
  const pct = Math.max(6, (count / max) * 100);
  return (
    <button
      onClick={onClick}
      className={`flex items-center gap-3 w-full group rounded-md px-1 py-0.5 transition-colors ${active ? "bg-gold/10" : "hover:bg-muted/30"}`}
    >
      <span className={`text-xs w-40 shrink-0 text-right truncate ${active ? "text-foreground font-medium" : "text-muted-foreground"}`}>
        {name}
      </span>
      <div className="flex-1 h-6 bg-muted rounded-sm overflow-hidden">
        <div
          className="h-full rounded-sm flex items-center px-2 transition-all duration-300"
          style={{ width: `${pct}%`, background: active ? "#e8a020" : "#4a9fd4" }}
        >
          <span className="text-[10px] font-semibold text-white">{count}</span>
        </div>
      </div>
    </button>
  );
}

/** Two-series (granted/pending) horizontal stacked bar, used for by-jurisdiction breakdowns. */
export function JurisdictionStackedBar({
  name, granted, pending, max,
}: {
  name: string; granted: number; pending: number; max: number;
}) {
  const total = granted + pending;
  const grantedPct = max > 0 ? (granted / max) * 100 : 0;
  const pendingPct = max > 0 ? (pending / max) * 100 : 0;
  // Widths are purely proportional to `max` — never forced wider. A per-segment
  // minimum width used to be applied here, which compounds on rows with two small
  // segments (e.g. 4+4) versus rows with one (e.g. a lone 6 or 7), making bars of
  // different true size render at the same length. Instead, a segment only shows
  // its number inline when it's wide enough to actually fit it; thin segments stay
  // a plain sliver (full detail is still on hover via the title tooltip) rather than
  // being stretched to make room for text.
  const LEGIBLE_PCT = 9;
  return (
    <div className="flex items-center gap-3 w-full">
      <span className="text-xs w-28 shrink-0 text-right truncate text-muted-foreground">{name}</span>
      <div className="flex-1 h-5 bg-muted rounded-sm overflow-hidden flex" title={`${granted} granted · ${pending} pending`}>
        {granted > 0 && (
          <div className="h-full flex items-center justify-center" style={{ width: `${grantedPct}%`, background: "#22c55e" }}>
            {grantedPct >= LEGIBLE_PCT && <span className="text-[10px] font-semibold text-white">{granted}</span>}
          </div>
        )}
        {pending > 0 && (
          <div className="h-full flex items-center justify-center" style={{ width: `${pendingPct}%`, background: "#f59e0b" }}>
            {pendingPct >= LEGIBLE_PCT && <span className="text-[10px] font-semibold text-white">{pending}</span>}
          </div>
        )}
      </div>
      <span className="text-xs w-6 shrink-0 text-muted-foreground">{total}</span>
    </div>
  );
}
