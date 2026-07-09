import { usePage } from "@inertiajs/react";
import { useState, useEffect } from "react";

const STORAGE_KEY = "analyst_role_filter";

export type RoleFilter = "all" | "pcm" | "scm" | "pr";

export function useAnalystRoleFilter(): [RoleFilter, (v: RoleFilter) => void] {
  const [value, setValue] = useState<RoleFilter>(() => {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      return (stored as RoleFilter) ?? "all";
    } catch {
      return "all";
    }
  });

  const set = (v: RoleFilter) => {
    try { localStorage.setItem(STORAGE_KEY, v); } catch {}
    setValue(v);
  };

  return [value, set];
}

const OPTIONS: { value: RoleFilter; label: string; short: string }[] = [
  { value: "all", label: "All Roles",           short: "All" },
  { value: "pcm", label: "PCM – Case Manager",  short: "PCM" },
  { value: "scm", label: "SCM – Sec. Manager",  short: "SCM" },
  { value: "pr",  label: "PR – Patent Rep",      short: "PR" },
];

interface Props {
  value: RoleFilter;
  onChange: (v: RoleFilter) => void;
}

export function AnalystRoleFilter({ value, onChange }: Props) {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;

  if (role !== "associate") return null;

  return (
    <div className="flex items-center gap-2">
      <span className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider whitespace-nowrap">
        View as
      </span>
      <div className="flex items-center rounded-lg border border-border bg-background overflow-hidden">
        {OPTIONS.map((opt) => (
          <button
            key={opt.value}
            type="button"
            onClick={() => onChange(opt.value)}
            title={opt.label}
            className={`px-3 py-1.5 text-xs font-medium transition-colors ${
              value === opt.value
                ? "bg-gold text-black"
                : "text-muted-foreground hover:text-foreground hover:bg-muted/40"
            }`}
          >
            {opt.short}
          </button>
        ))}
      </div>
    </div>
  );
}
