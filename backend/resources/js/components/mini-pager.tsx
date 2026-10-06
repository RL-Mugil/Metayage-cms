import { useMemo, useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";

/**
 * Client-side pagination over an already-fetched array. Dashboard widgets (Action
 * Required, Cases needing attention, My tasks) load their full list once and just
 * slice it for display — this keeps that slice stable across page/size changes
 * without refetching.
 */
export function useMiniPager<T>(items: T[], defaultPageSize = 5) {
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(defaultPageSize);

  const totalPages = Math.max(1, Math.ceil(items.length / pageSize));
  const safePage = Math.min(page, totalPages);
  const start = (safePage - 1) * pageSize;
  const pageItems = useMemo(() => items.slice(start, start + pageSize), [items, start, pageSize]);

  return {
    page: safePage,
    setPage,
    pageSize,
    setPageSize: (size: number) => { setPageSize(size); setPage(1); },
    totalPages,
    pageItems,
    total: items.length,
  };
}

export function MiniPager({
  page, totalPages, pageSize, total, onPageChange, onPageSizeChange, sizeOptions = [5, 10, 20, 50],
}: {
  page: number;
  totalPages: number;
  pageSize: number;
  total: number;
  onPageChange: (page: number) => void;
  onPageSizeChange: (size: number) => void;
  sizeOptions?: number[];
}) {
  if (total === 0) return null;
  const rangeStart = (page - 1) * pageSize + 1;
  const rangeEnd = Math.min(page * pageSize, total);

  return (
    <div className="flex items-center justify-between gap-3 border-t border-border px-4 py-2 text-xs text-muted-foreground">
      <label className="flex items-center gap-1.5">
        <span>Show</span>
        <select
          value={pageSize}
          onChange={(e) => onPageSizeChange(Number(e.target.value))}
          className="h-6 rounded border border-border bg-background px-1 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
        >
          {sizeOptions.map((n) => <option key={n} value={n}>{n}</option>)}
        </select>
      </label>
      <div className="flex items-center gap-2">
        <span>{rangeStart}–{rangeEnd} of {total}</span>
        <div className="flex items-center gap-1">
          <button
            onClick={() => onPageChange(Math.max(1, page - 1))}
            disabled={page <= 1}
            aria-label="Previous page"
            className="rounded border border-border p-1 hover:bg-muted/40 disabled:opacity-40 disabled:hover:bg-transparent"
          >
            <ChevronLeft className="h-3.5 w-3.5" />
          </button>
          <span className="px-1 tabular-nums">{page}/{totalPages}</span>
          <button
            onClick={() => onPageChange(Math.min(totalPages, page + 1))}
            disabled={page >= totalPages}
            aria-label="Next page"
            className="rounded border border-border p-1 hover:bg-muted/40 disabled:opacity-40 disabled:hover:bg-transparent"
          >
            <ChevronRight className="h-3.5 w-3.5" />
          </button>
        </div>
      </div>
    </div>
  );
}
