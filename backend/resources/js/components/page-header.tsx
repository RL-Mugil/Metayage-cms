import type { ReactNode } from "react";

interface Props {
  eyebrow?: string;
  title: string;
  description?: string;
  actions?: ReactNode;
}

export function PageHeader({ eyebrow, title, description, actions }: Props) {
  return (
    <div className="flex flex-col gap-4 border-b border-border bg-card/30 px-8 py-6 md:flex-row md:items-end md:justify-between">
      <div>
        {eyebrow && (
          <div className="mb-2 text-[11px] font-medium uppercase tracking-[0.18em] text-gold">
            {eyebrow}
          </div>
        )}
        <h1 className="font-display text-3xl font-semibold text-foreground">{title}</h1>
        {description && <p className="mt-1 max-w-2xl text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}
