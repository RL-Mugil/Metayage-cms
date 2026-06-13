import type { LucideIcon } from "lucide-react";
import { cn } from "@/lib/utils";

interface Props {
  label: string;
  value: string | number;
  delta?: string;
  trend?: "up" | "down" | "flat";
  icon?: LucideIcon;
  accent?: "primary" | "gold" | "info" | "success";
  onClick?: () => void;
}

export function StatCard({ label, value, delta, trend = "flat", icon: Icon, accent = "primary", onClick }: Props) {
  const accents: Record<string, string> = {
    primary: "bg-primary/5 text-primary",
    gold: "bg-gold/10 text-gold",
    info: "bg-info/10 text-info",
    success: "bg-success/10 text-success",
  };
  return (
    <div
      onClick={onClick}
      role={onClick ? "button" : undefined}
      tabIndex={onClick ? 0 : undefined}
      onKeyDown={onClick ? (e) => { if (e.key === "Enter" || e.key === " ") onClick(); } : undefined}
      className={cn("group rounded-xl border border-border bg-card p-5 transition-shadow hover:shadow-sm", onClick && "cursor-pointer hover:shadow-md hover:border-gold/40")}
    >
      <div className="flex items-start justify-between">
        <div className="text-xs font-medium uppercase tracking-wider text-muted-foreground">{label}</div>
        {Icon && (
          <div className={cn("flex h-9 w-9 items-center justify-center rounded-lg", accents[accent])}>
            <Icon className="h-4 w-4" />
          </div>
        )}
      </div>
      <div className="mt-3 font-display text-3xl font-semibold tracking-tight text-foreground">{value}</div>
      {delta && (
        <div className={cn("mt-1 text-xs font-medium",
          trend === "up" && "text-success",
          trend === "down" && "text-destructive",
          trend === "flat" && "text-muted-foreground")}>
          {delta}
        </div>
      )}
    </div>
  );
}
