import { useEffect, useRef, useState } from "react";
import { createPortal } from "react-dom";

/**
 * Generic portal-based searchable combobox — same interaction pattern as
 * ProjectCombobox (docket/case picker), generalized so other pickers (e.g.
 * the Approvals "Client" field) get the same searchable-typeahead UX instead
 * of a plain filtered `<select>`.
 */
export function SearchCombobox<T>({
  value, items, onSelect, getId, filterItem, renderOption, renderSelected, placeholder = "Search…", allowClear = true,
}: {
  value: string;
  items: T[];
  onSelect: (id: string) => void;
  getId: (item: T) => string | number;
  filterItem: (item: T, query: string) => boolean;
  renderOption: (item: T) => React.ReactNode;
  renderSelected: (item: T) => string;
  placeholder?: string;
  allowClear?: boolean;
}) {
  const [q, setQ]       = useState("");
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const inputRef        = useRef<HTMLInputElement>(null);

  const selected = items.find((item) => String(getId(item)) === value);
  const filtered = items.filter((item) => !q || filterItem(item, q.toLowerCase())).slice(0, 30);

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
          {allowClear && value && (
            <button type="button"
              onMouseDown={(e) => { e.preventDefault(); onSelect(""); setOpen(false); }}
              className="w-full text-left px-3 py-2 text-xs text-muted-foreground hover:bg-muted/40 border-b border-border">
              Clear selection
            </button>
          )}
          {filtered.map((item) => {
            const id = String(getId(item));
            return (
              <button key={id} type="button"
                onMouseDown={(e) => { e.preventDefault(); onSelect(id); setOpen(false); }}
                className={`w-full text-left px-3 py-2 text-sm hover:bg-muted/40 flex items-baseline gap-2 ${id === value ? "bg-gold/10 text-gold font-medium" : ""}`}>
                {renderOption(item)}
              </button>
            );
          })}
          {filtered.length === 0 && <p className="px-3 py-3 text-xs text-muted-foreground text-center">No matches</p>}
        </div>,
        document.body
      )
    : null;

  return (
    <div>
      <input
        ref={inputRef}
        value={open ? q : (selected ? renderSelected(selected) : "")}
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
