import type { ReactNode } from "react";

interface Props {
  eyebrow?: string;
  title: string;
  description?: string;
  actions?: ReactNode;
}

/** Compact page header used across the portal: eyebrow, title and a one-line description on the left, actions on the right. */
export function PageHeader({ eyebrow, title, description, actions }: Props) {
  return (
    <div className="flex flex-col gap-3 border-b border-[var(--glass-border)] bg-background/60 px-4 py-4 backdrop-blur-xl sm:px-8 md:flex-row md:items-center md:justify-between">
      <div className="min-w-0">
        {eyebrow && (
          <div className="text-[10px] font-semibold uppercase tracking-[0.18em] text-gold">
            {eyebrow}
          </div>
        )}
        <h1 className="font-display text-2xl font-semibold tracking-tight text-foreground">{title}</h1>
        {description && <p className="mt-0.5 max-w-3xl truncate text-xs text-muted-foreground" title={description}>{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}
