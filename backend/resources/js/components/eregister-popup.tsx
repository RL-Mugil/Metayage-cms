import { X } from "lucide-react";
import type { WorkspaceApplication, WorkspaceProject } from "@/types/matter-workspace";

function fmtIpoDate(d?: string | null): string {
  if (!d) return "—";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}/${m}/${y}`;
}

/** Add months to a YYYY-MM-DD (or ISO) date string, returned the same way. */
function addMonths(d: string, months: number): string {
  const date = new Date(d.split("T")[0]);
  date.setMonth(date.getMonth() + months);
  return date.toISOString().split("T")[0];
}

/** Add years to a YYYY-MM-DD (or ISO) date string. */
function addYears(d: string, years: number): string {
  const date = new Date(d.split("T")[0]);
  date.setFullYear(date.getFullYear() + years);
  return date.toISOString().split("T")[0];
}

/**
 * Normal due date for a renewal year, per the client's exact rule:
 * - Year 3 is anchored to the grant date (3 months after grant), since it's
 *   the first fee that can actually fall due post-grant.
 * - Year 4 onward is anchored to the filing date: due date for year N is
 *   filing_date + (N-1) years — this is also, not coincidentally, the start
 *   of that year's Renewal Period below (the due date opens the period).
 */
function normalDueDate(year: number, filingDate?: string | null, grantDate?: string | null): string | null {
  if (year === 3) {
    return grantDate ? addMonths(grantDate, 3) : null;
  }
  return filingDate ? addYears(filingDate, year - 1) : null;
}

interface RenewalRow {
  year: number;
  dueDateNormal: string;
  dueDateExtended: string;
  cbrDate: string;
  amount: string;
  dateOfRenewal: string;
  periodFrom: string;
  periodTo: string;
}

export function ERegisterPopup({
  application, project, onClose, onViewDocuments,
}: {
  application: WorkspaceApplication;
  project: WorkspaceProject;
  onClose: () => void;
  onViewDocuments: () => void;
}) {
  const filingDate = application.filing_date || project.filing_date;
  const grantDate = application.grant_date;
  const renewals = application.renewals ?? [];

  // Every column for a year is populated only once that year is actually paid —
  // an unpaid/future year shows "—" straight across, matching the reference view.
  const rows: RenewalRow[] = [];
  for (let year = 3; year <= 20; year++) {
    const schedule = renewals.find((r) => r.renewal_year === year);
    const paid = schedule?.status === "Paid" && schedule.invoice;
    const dueNormal = normalDueDate(year, filingDate, grantDate);
    if (!paid || !dueNormal) {
      rows.push({ year, dueDateNormal: "—", dueDateExtended: "—", cbrDate: "—", amount: "—", dateOfRenewal: "—", periodFrom: "—", periodTo: "—" });
      continue;
    }
    // CBR Date and Date of Renewal are the same value — the payment-confirmed date — per instruction.
    const paidDate = schedule.invoice!.payment_confirmed_at || schedule.paid_at || "";
    rows.push({
      year,
      dueDateNormal: fmtIpoDate(dueNormal),
      dueDateExtended: fmtIpoDate(addMonths(dueNormal, 6)),
      cbrDate: fmtIpoDate(paidDate),
      amount: schedule.invoice!.patent_office_fees != null ? `₹${Number(schedule.invoice!.patent_office_fees).toLocaleString()}` : "—",
      dateOfRenewal: fmtIpoDate(paidDate),
      // Renewal period is calculated from the filing date, not the grant date.
      periodFrom: filingDate ? fmtIpoDate(addYears(filingDate, year - 1)) : "—",
      periodTo: filingDate ? fmtIpoDate(addYears(filingDate, year)) : "—",
    });
  }

  const nextDue = renewals
    .filter((r) => r.status !== "Paid")
    .map((r) => ({ r, due: normalDueDate(r.renewal_year, filingDate, grantDate) }))
    .filter((x): x is { r: typeof renewals[number]; due: string } => !!x.due)
    .sort((a, b) => a.due.localeCompare(b.due))[0];

  const legalStatusLabel = application.legal_status === "Granted" ? "In Force" : application.legal_status;

  const summary: [string, string][] = [
    ["Legal Status", legalStatusLabel],
    ["Due Date of Next Renewal", fmtIpoDate(nextDue?.due)],
    ["Patent Number", application.grant_number || "—"],
    ["Application Number", application.application_number || "—"],
    ["Type of Application", application.application_type || "—"],
    ["Date of Patent", fmtIpoDate(filingDate)],
    ["Date of Grant", fmtIpoDate(grantDate)],
    ["Date of Recordal", "—"],
    ["Appropriate Office", "—"],
    ["Grant Title", application.title || project.invention_title || "—"],
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between border-b border-border px-5 py-3">
          <h2 className="font-display text-lg font-semibold">E-Register</h2>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>

        <div className="p-5 space-y-5">
          <div className="overflow-hidden rounded-md border border-border">
            <table className="w-full text-sm">
              <tbody>
                {summary.map(([label, value], i) => (
                  <tr key={label} className={i % 2 === 0 ? "bg-background" : "bg-muted/30"}>
                    <td className="w-1/3 border-b border-border px-4 py-2 text-xs font-medium text-[#2b6c9e]">{label.toUpperCase()}</td>
                    <td className="border-b border-border px-4 py-2 text-sm font-medium">{value}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div>
            <h3 className="mb-2 text-sm font-semibold">Renewal Schedule</h3>
            <div className="overflow-x-auto rounded-md border border-border">
              <table className="w-full min-w-[760px] text-xs">
                <thead>
                  <tr className="bg-[#2b6c9e] text-white">
                    <th rowSpan={2} className="border border-border/30 px-2 py-1.5">Year</th>
                    <th colSpan={2} className="border border-border/30 px-2 py-1.5">Due Date for Renewal</th>
                    <th rowSpan={2} className="border border-border/30 px-2 py-1.5">CBR Date</th>
                    <th rowSpan={2} className="border border-border/30 px-2 py-1.5">Renewal Amount</th>
                    <th rowSpan={2} className="border border-border/30 px-2 py-1.5">Date of Renewal</th>
                    <th colSpan={2} className="border border-border/30 px-2 py-1.5">Renewal Period</th>
                  </tr>
                  <tr className="bg-[#2b6c9e] text-white">
                    <th className="border border-border/30 px-2 py-1.5 font-normal">Normal Due Date</th>
                    <th className="border border-border/30 px-2 py-1.5 font-normal">Due Date with Extension</th>
                    <th className="border border-border/30 px-2 py-1.5 font-normal">From</th>
                    <th className="border border-border/30 px-2 py-1.5 font-normal">To</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row, i) => (
                    <tr key={row.year} className={i % 2 === 0 ? "bg-background" : "bg-muted/30"}>
                      <td className="border border-border/30 px-2 py-1 text-center font-semibold">{row.year} year</td>
                      <td className="border border-border/30 px-2 py-1 text-center font-mono">{row.dueDateNormal}</td>
                      <td className="border border-border/30 px-2 py-1 text-center font-mono">{row.dueDateExtended}</td>
                      <td className="border border-border/30 px-2 py-1 text-center font-mono">{row.cbrDate}</td>
                      <td className="border border-border/30 px-2 py-1 text-center font-mono">{row.amount}</td>
                      <td className="border border-border/30 px-2 py-1 text-center font-mono">{row.dateOfRenewal}</td>
                      <td className="border border-border/30 px-2 py-1 text-center font-mono">{row.periodFrom}</td>
                      <td className="border border-border/30 px-2 py-1 text-center font-mono">{row.periodTo}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="mt-2 text-[11px] text-muted-foreground">
              A year's row populates once that renewal has actually been paid and confirmed. Year 3's due date is 3 months
              after grant; year 4 onward is filing date + (year − 1) years. Due date with extension is 6 months after the
              normal due date. CBR Date and Date of Renewal are the payment-confirmed date; Renewal Amount is the
              government fee portion of that invoice; Renewal Period is calculated from the filing date.
            </p>
          </div>

          <div className="flex justify-end gap-2 border-t border-border pt-4">
            <button onClick={onViewDocuments} className="rounded bg-[#2b6c9e] px-4 py-2 text-xs font-semibold text-white hover:bg-[#245a84]">
              View Documents
            </button>
            <button onClick={onClose} className="rounded border border-border px-4 py-2 text-xs font-medium hover:bg-muted/40">
              Close
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
