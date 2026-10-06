import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

/**
 * Portal-based searchable case/docket combobox. Filters an already-fetched
 * `projects[]` array by docket number, project code, or case name — no
 * network round-trip per keystroke. Shared by Tasks (required "Case /
 * Project" field) and Approvals (optional "Case / UIN" field).
 */
export function ProjectCombobox({ value, projects, onSelect, placeholder = "Search docket number or case name…" }: {
  value: string;
  projects: any[];
  onSelect: (id: string) => void;
  placeholder?: string;
}) {
  const [q, setQ]       = useState("");
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const inputRef        = useRef<HTMLInputElement>(null);

  const selected = projects.find((p) => String(p.id) === value);
  const filtered = projects
    .filter((p) => {
      if (!q) return true;
      const lq = q.toLowerCase();
      return (
        (p.docket_number ?? "").toLowerCase().includes(lq) ||
        (p.project_code ?? "").toLowerCase().includes(lq) ||
        (p.project_name ?? "").toLowerCase().includes(lq)
      );
    })
    .slice(0, 30);

  useEffect(() => {
    if (!open) return;
    const updatePos = () => { if (inputRef.current) setRect(inputRef.current.getBoundingClientRect()); };
    window.addEventListener("scroll", updatePos, true);
    return () => window.removeEventListener("scroll", updatePos, true);
  }, [open]);

  const ic = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

  const dropdown = open && rect
    ? createPortal(
        <div
          style={{ position: "fixed", top: rect.bottom + 2, left: rect.left, width: rect.width, zIndex: 9999 }}
          className="bg-background border border-border rounded-md shadow-2xl max-h-56 overflow-y-auto"
        >
          {value && (
            <button type="button"
              onMouseDown={(e) => { e.preventDefault(); onSelect(""); setOpen(false); }}
              className="w-full text-left px-3 py-2 text-xs text-muted-foreground hover:bg-muted/40 border-b border-border">
              Clear selection
            </button>
          )}
          {filtered.map((p) => (
            <button key={p.id} type="button"
              onMouseDown={(e) => { e.preventDefault(); onSelect(String(p.id)); setOpen(false); }}
              className={`w-full text-left px-3 py-2 text-sm hover:bg-muted/40 flex items-baseline gap-2 ${String(p.id) === value ? "bg-gold/10 text-gold font-medium" : ""}`}>
              <span className="font-mono text-[11px] text-gold flex-shrink-0">{p.docket_number ?? p.project_code}</span>
              <span className="truncate text-xs text-muted-foreground">{p.project_name}</span>
            </button>
          ))}
          {filtered.length === 0 && <p className="px-3 py-3 text-xs text-muted-foreground text-center">No matches</p>}
        </div>,
        document.body
      )
    : null;

  return (
    <div>
      <input
        ref={inputRef}
        value={open ? q : (selected ? `${selected.docket_number ?? selected.project_code} – ${selected.project_name}` : "")}
        onFocus={() => { if (inputRef.current) setRect(inputRef.current.getBoundingClientRect()); setOpen(true); setQ(""); }}
        onBlur={() => setTimeout(() => setOpen(false), 200)}
        onChange={(e) => setQ(e.target.value)}
        placeholder={placeholder}
        className={ic}
      />
      {dropdown}
    </div>
  );
}
