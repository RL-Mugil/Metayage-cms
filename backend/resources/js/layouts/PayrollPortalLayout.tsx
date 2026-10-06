import type { ReactNode } from "react";
import { useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import {
  LayoutDashboard, Users, Briefcase, Wallet, CheckCircle2, ChevronDown, ChevronRight,
  Landmark, FileBarChart2, Settings, ExternalLink, ArrowLeft, Receipt,
} from "lucide-react";

interface Props {
  children: ReactNode;
}

const NAV = [
  { to: "/payroll-portal", title: "Dashboard", icon: LayoutDashboard },
  { to: "/payroll-portal/employees", title: "Employees", icon: Users },
  { to: "/payroll-portal/contractors", title: "Contractors", icon: Briefcase },
  { to: "/payroll-portal/pay-runs", title: "Pay Runs", icon: Wallet },
];

const APPROVALS_NAV = [
  { to: "/payroll-portal/approvals/reimbursements", title: "Reimbursements" },
  { to: "/payroll-portal/approvals/proof-of-investments", title: "Proof of Investments" },
  { to: "/payroll-portal/approvals/salary-revisions", title: "Salary Revisions" },
];

const TAXES_NAV = [
  { to: "/payroll-portal/taxes/tds-liabilities", title: "TDS Liabilities" },
  { to: "/payroll-portal/taxes/challans", title: "Challans" },
  { to: "/payroll-portal/taxes/form-24q", title: "Form 24Q" },
  { to: "/payroll-portal/form-16", title: "Form 16" },
];

const NAV_2 = [
  { to: "/payroll-portal/loans", title: "Loans", icon: Landmark },
];

/**
 * Sibling shell to AppLayout, not a variant of it — a dedicated Zoho-Payroll-style
 * left nav + top bar, opened in its own tab from the main sidebar. Reads/writes
 * the same database/auth session as the main app (see PayrollPortalController).
 */
export default function PayrollPortalLayout({ children }: Props) {
  const { url, props } = usePage() as any;
  const user = props.auth?.user;
  const [approvalsOpen, setApprovalsOpen] = useState(url.startsWith("/payroll-portal/approvals"));
  const [taxesOpen, setTaxesOpen] = useState(url.startsWith("/payroll-portal/taxes") || url.startsWith("/payroll-portal/form-16"));

  const isActive = (p: string) => (p === "/payroll-portal" ? url === p : url === p || url.startsWith(p + "/"));

  return (
    <div className="flex min-h-screen w-full bg-background">
      <aside className="w-60 shrink-0 border-r border-border bg-sidebar flex flex-col sticky top-0 h-screen">
        <div className="flex items-center gap-2 px-4 py-4 border-b border-sidebar-border">
          <img src="/favicon.png" alt="Metayage" className="h-8 w-8 rounded-md object-cover" />
          <div className="flex flex-col leading-tight">
            <span className="font-display text-sm font-semibold text-sidebar-foreground">Payroll Portal</span>
            <span className="text-[10px] uppercase tracking-widest text-sidebar-foreground/60">Metayage</span>
          </div>
        </div>

        <nav className="flex-1 overflow-y-auto px-2 py-3 space-y-0.5">
          {NAV.map((item) => (
            <Link
              key={item.to}
              href={item.to}
              className={`flex items-center gap-2.5 rounded-md px-3 py-2 text-sm transition-colors ${
                isActive(item.to)
                  ? "bg-gold/15 text-gold font-medium"
                  : "text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground"
              }`}
            >
              <item.icon className="h-4 w-4 shrink-0" />
              {item.title}
            </Link>
          ))}

          <button
            onClick={() => setApprovalsOpen((v: boolean) => !v)}
            className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground transition-colors"
          >
            <CheckCircle2 className="h-4 w-4 shrink-0" />
            <span className="flex-1 text-left">Approvals</span>
            {approvalsOpen ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
          </button>
          {approvalsOpen && (
            <div className="ml-5 space-y-0.5 border-l border-sidebar-border pl-3">
              {APPROVALS_NAV.map((item) => (
                <Link
                  key={item.to}
                  href={item.to}
                  className={`block rounded-md px-3 py-1.5 text-xs transition-colors ${
                    isActive(item.to)
                      ? "bg-gold/15 text-gold font-medium"
                      : "text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground"
                  }`}
                >
                  {item.title}
                </Link>
              ))}
            </div>
          )}

          <button
            onClick={() => setTaxesOpen((v: boolean) => !v)}
            className="flex w-full items-center gap-2.5 rounded-md px-3 py-2 text-sm text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground transition-colors"
          >
            <Receipt className="h-4 w-4 shrink-0" />
            <span className="flex-1 text-left">Taxes & Forms</span>
            {taxesOpen ? <ChevronDown className="h-3.5 w-3.5" /> : <ChevronRight className="h-3.5 w-3.5" />}
          </button>
          {taxesOpen && (
            <div className="ml-5 space-y-0.5 border-l border-sidebar-border pl-3">
              {TAXES_NAV.map((item) => (
                <Link
                  key={item.to}
                  href={item.to}
                  className={`block rounded-md px-3 py-1.5 text-xs transition-colors ${
                    isActive(item.to)
                      ? "bg-gold/15 text-gold font-medium"
                      : "text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground"
                  }`}
                >
                  {item.title}
                </Link>
              ))}
            </div>
          )}

          {NAV_2.map((item) => (
            <Link
              key={item.to}
              href={item.to}
              className={`flex items-center gap-2.5 rounded-md px-3 py-2 text-sm transition-colors ${
                isActive(item.to)
                  ? "bg-gold/15 text-gold font-medium"
                  : "text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground"
              }`}
            >
              <item.icon className="h-4 w-4 shrink-0" />
              {item.title}
            </Link>
          ))}

          <a
            href="/reports"
            target="_blank"
            rel="noreferrer"
            className="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground transition-colors"
          >
            <FileBarChart2 className="h-4 w-4 shrink-0" />
            Reports
            <ExternalLink className="h-3 w-3 ml-auto opacity-50" />
          </a>
          <a
            href="/settings"
            target="_blank"
            rel="noreferrer"
            className="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground transition-colors"
          >
            <Settings className="h-4 w-4 shrink-0" />
            Settings
            <ExternalLink className="h-3 w-3 ml-auto opacity-50" />
          </a>
        </nav>

        <div className="border-t border-sidebar-border p-3">
          <Link href="/hrms/payroll" className="flex items-center gap-2 text-xs text-sidebar-foreground/60 hover:text-sidebar-foreground transition-colors">
            <ArrowLeft className="h-3.5 w-3.5" />
            Back to main app
          </Link>
        </div>
      </aside>

      <div className="flex flex-1 flex-col min-w-0">
        <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-border bg-background/85 px-6 backdrop-blur">
          <span className="font-display text-sm font-semibold">Metayage Private Limited</span>
          <div className="ml-auto flex items-center gap-3">
            <span className="text-xs text-muted-foreground">{user?.name}</span>
            {user?.avatar_url ? (
              <img src={user.avatar_url} alt={user.name} className="h-7 w-7 rounded-full object-cover ring-2 ring-gold/30" />
            ) : (
              <div className="h-7 w-7 rounded-full bg-gold/20 flex items-center justify-center text-gold text-xs font-bold ring-2 ring-gold/30">
                {(user?.name ?? "U").charAt(0).toUpperCase()}
              </div>
            )}
          </div>
        </header>
        <main className="flex-1 min-w-0">{children}</main>
      </div>
    </div>
  );
}
