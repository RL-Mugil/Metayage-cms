import { Link } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, ArrowUpRight, Clock, X, CheckCircle2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";
import { fmtDate } from "@/lib/date-utils";

export function formatCurrency(val: number) {
  if (val >= 100000) return `₹ ${(val / 100000).toFixed(1)}L`;
  return `₹ ${(val ?? 0).toLocaleString()}`;
}

export interface ActionItem {
  id: number;
  docket_number: string;
  project_name?: string | null;
  pending_action: string;
  urgency?: string | null;
  hard_deadline?: string | null;
  current_stage?: string | null;
  is_renewal: boolean;
  owner?: "client" | "internal" | null;
  finance_relevant?: boolean;
}

export function urgencyBadgeVariant(urgency?: string | null): "destructive" | "outline" | "secondary" {
  if (urgency === "Critical") return "destructive";
  if (urgency === "High") return "outline";
  return "secondary";
}

/**
 * Case detail + renewal-fee modal shared by every "upcoming due" surface
 * (client dashboard, client_finance dashboard, internal staff dashboard,
 * docket-deadlines) — clicking a renewal item anywhere opens this, showing
 * the prosecution timeline plus a fee breakdown (a lookup against Settings'
 * renewal fee rates, not a formula — see SettingsController::updateRenewalFeeRates()).
 * `canApprove` gates the Approve action; everyone else (client_finance,
 * internal staff) gets the same information read-only.
 */
export function RenewalApproveModal({ item, feeRates, canApprove, onClose, onApproved }: {
  item: ActionItem;
  feeRates: { government_fee: number; professional_fee: number; currency: string };
  canApprove: boolean;
  onClose: () => void;
  onApproved: () => void;
}) {
  const [docket, setDocket] = useState<any>(null);
  const [loadingDocket, setLoadingDocket] = useState(true);
  const [years, setYears] = useState(1);
  const [approving, setApproving] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState<any>(null);

  useEffect(() => {
    api.getProjectDocket(item.id)
      .then((d: any) => setDocket(d))
      .catch(() => setDocket(null))
      .finally(() => setLoadingDocket(false));
  }, [item.id]);

  const perYear = (feeRates.government_fee ?? 0) + (feeRates.professional_fee ?? 0);
  const total = perYear * years;
  const yearsPaid = (docket?.renewals ?? []).filter((r: any) => r.status === "Paid").length;

  async function handleApprove() {
    setApproving(true);
    setError("");
    try {
      const res = await api.approveRenewal(item.id, years);
      setSuccess(res);
      onApproved();
    } catch (e: any) {
      setError(e?.message || "Failed to approve renewal.");
    } finally {
      setApproving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-lg max-h-[88vh] overflow-y-auto">
        <div className="flex items-center justify-between px-6 py-4 border-b border-border">
          <div>
            <h2 className="font-display text-lg font-semibold">Renew {item.docket_number}</h2>
            {item.project_name && <p className="text-xs text-muted-foreground mt-0.5">{item.project_name}</p>}
          </div>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>

        <div className="p-6 space-y-4">
          {success ? (
            <div className="text-center py-4 space-y-3">
              <CheckCircle2 className="h-10 w-10 text-green-500 mx-auto" />
              <div className="font-semibold">Renewal approved</div>
              <p className="text-xs text-muted-foreground">
                Invoice <span className="font-mono font-semibold">{success.invoice_uin}</span> has been raised for {formatCurrency(Number(success.invoice_amount))}.
                It's been emailed to your team and will appear under Pending Payments.
              </p>
              <div className="flex gap-2 justify-center pt-2">
                <Button asChild variant="outline"><Link href="/pending-payments">View Pending Payments</Link></Button>
                <Button onClick={onClose}>Close</Button>
              </div>
            </div>
          ) : (
            <>
              {loadingDocket ? (
                <div className="flex items-center justify-center py-6"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
              ) : (
                <div className="rounded-md border border-border bg-muted/20 p-3 text-xs space-y-1.5">
                  {docket?.application?.title && <div><span className="text-muted-foreground">Title: </span>{docket.application.title}</div>}
                  <div className="grid grid-cols-3 gap-2 pt-1">
                    <div><div className="text-muted-foreground">Filing Date</div><div className="font-mono">{fmtDate(docket?.application?.filing_date)}</div></div>
                    <div><div className="text-muted-foreground">Publication</div><div className="font-mono">{fmtDate(docket?.application?.publication_date)}</div></div>
                    <div><div className="text-muted-foreground">Grant Date</div><div className="font-mono">{fmtDate(docket?.application?.grant_date)}</div></div>
                  </div>
                  <div className="pt-1 text-muted-foreground">{yearsPaid} year(s) already renewed</div>
                </div>
              )}

              <div>
                <label className="block text-xs font-medium text-muted-foreground mb-1">Years to renew</label>
                <select value={years} onChange={(e) => setYears(parseInt(e.target.value, 10))}
                  disabled={!canApprove}
                  className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold disabled:opacity-60">
                  {Array.from({ length: 10 }, (_, i) => i + 1).map((y) => (
                    <option key={y} value={y}>{y} year{y > 1 ? "s" : ""}</option>
                  ))}
                </select>
              </div>

              <div className="rounded-md border border-border p-3 text-sm space-y-1">
                <div className="flex justify-between"><span className="text-muted-foreground">Government fee</span><span className="font-mono">{formatCurrency(feeRates.government_fee * years)}</span></div>
                <div className="flex justify-between"><span className="text-muted-foreground">Professional fee</span><span className="font-mono">{formatCurrency(feeRates.professional_fee * years)}</span></div>
                <div className="flex justify-between border-t border-border pt-1.5 font-semibold"><span>Total payable</span><span className="font-mono">{formatCurrency(total)}</span></div>
              </div>

              {error && <p className="text-xs text-destructive">{error}</p>}

              {!canApprove && (
                <p className="text-xs text-muted-foreground">Only your account admin can approve renewals. Ask them to review this from their dashboard.</p>
              )}

              <div className="flex gap-2">
                {canApprove && (
                  <Button className="flex-1 bg-gold hover:bg-gold/90 text-black" disabled={approving} onClick={handleApprove}>
                    {approving ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : null}Approve & Generate Invoice
                  </Button>
                )}
                <Button variant="outline" onClick={onClose}>Cancel</Button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * A single action-item row shared across dashboards. Renewal items open the
 * RenewalApproveModal (via onRenewClick) for case + fee detail; everything
 * else links straight to the case overview.
 */
export function ActionItemRow({ item, onRenewClick, showOwnerBadge }: {
  item: ActionItem;
  onRenewClick?: (item: ActionItem) => void;
  /** Internal-dashboard "more intelligence" flag — show a waiting-on-client vs waiting-on-us badge. */
  showOwnerBadge?: boolean;
}) {
  const overdue = item.hard_deadline && new Date(item.hard_deadline) < new Date();
  const content = (
    <>
      <div className="min-w-0">
        <div className="flex items-center gap-2">
          <span className="font-mono text-xs font-semibold text-gold">{item.docket_number}</span>
          {item.is_renewal && <Badge className="bg-red-500/15 text-red-600 border-red-500/30 text-[10px]">Renewal</Badge>}
          {item.urgency && item.urgency !== "Normal" && (
            <Badge variant={urgencyBadgeVariant(item.urgency)} className="text-[10px]">{item.urgency}</Badge>
          )}
          {showOwnerBadge && item.owner && (
            <Badge variant="outline" className="text-[10px]">
              {item.owner === "client" ? "Waiting on client" : "Waiting on us"}
            </Badge>
          )}
        </div>
        {item.project_name && <div className="mt-0.5 text-sm font-medium truncate">{item.project_name}</div>}
        <div className="mt-1 text-xs text-muted-foreground">{item.pending_action}</div>
      </div>
      <div className="flex flex-shrink-0 items-center gap-3">
        {item.hard_deadline && (
          <span className={`flex items-center gap-1 text-xs font-mono ${overdue ? "text-destructive font-semibold" : "text-muted-foreground"}`}>
            <Clock className="h-3 w-3" />{fmtDate(item.hard_deadline)}
          </span>
        )}
        <ArrowUpRight className="h-4 w-4 text-muted-foreground" />
      </div>
    </>
  );

  if (item.is_renewal && onRenewClick) {
    return (
      <button onClick={() => onRenewClick(item)}
        className="flex w-full items-center justify-between gap-4 rounded-md border border-border px-4 py-3 hover:bg-muted/30 transition-colors text-left">
        {content}
      </button>
    );
  }

  return (
    <Link href={`/projects/${item.id}`}
      className="flex items-center justify-between gap-4 rounded-md border border-border px-4 py-3 hover:bg-muted/30 transition-colors">
      {content}
    </Link>
  );
}
