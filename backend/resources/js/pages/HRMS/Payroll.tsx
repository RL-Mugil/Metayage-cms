import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import {
  Download, FileText, X, Loader2,
  Receipt, Plus, Paperclip, Landmark, FileSignature, ShieldCheck, Upload, FileCheck2,
} from "lucide-react";
import { api } from "@/lib/api-client";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { TooltipProvider } from "@/components/ui/tooltip";
import { FieldHelp } from "@/components/field-help";

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

function periodLabel(period: string): string {
  return new Date(period).toLocaleString("default", { month: "long", year: "numeric" });
}

function statusBadge(status: string) {
  if (status === "Paid") return "bg-green-100 text-green-700 border-green-200";
  if (status === "Finalized") return "bg-blue-100 text-blue-700 border-blue-200";
  if (status === "PendingApproval") return "bg-amber-100 text-amber-700 border-amber-200";
  if (status === "Rejected") return "bg-red-100 text-red-700 border-red-200";
  return "bg-muted text-muted-foreground border-border";
}

function claimStatusBadge(status: string) {
  if (status === "Approved" || status === "Paid") return "bg-green-100 text-green-700 border-green-200";
  if (status === "Rejected") return "bg-red-100 text-red-700 border-red-200";
  return "bg-amber-100 text-amber-700 border-amber-200";
}

/**
 * Earning breakdown for a slip's CSV row — dynamic per the admin-configured
 * salary component template when present, falling back to the legacy fixed
 * Basic/HRA/Special Allowance columns for runs generated before the template
 * existed (those slips have no `components` rows).
 */
function componentColumns(s: any): Record<string, number> {
  if (Array.isArray(s.components) && s.components.length > 0) {
    const cols: Record<string, number> = {};
    s.components.forEach((c: any) => { cols[c.name] = Number(c.amount); });
    return cols;
  }
  return { Basic: Number(s.basic ?? 0), HRA: Number(s.hra ?? 0), "Special Allowance": Number(s.special_allowance ?? 0) };
}

/** Apr–Mar fiscal year, matching the firm's `fiscal_month: 'April'` convention. */
function currentFiscalYear(): number {
  const now = new Date();
  return now.getMonth() + 1 >= 4 ? now.getFullYear() : now.getFullYear() - 1;
}

export default function HRMSPayroll() {
  const { props } = usePage() as any;
  const [mySlips, setMySlips] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [fiscalYear, setFiscalYear] = useState(currentFiscalYear());
  const [activeTab, setActiveTab] = useState("payslips");

  // ── Payslip summary download (Monthly is per-row; this covers Quarterly /
  // Half-Yearly / Annual / Custom Range, all via the one flexible endpoint) ──
  const [summaryPeriodType, setSummaryPeriodType] = useState<"quarterly" | "half-yearly" | "annual" | "custom">("annual");
  const [summaryQuarter, setSummaryQuarter] = useState(1);
  const [summaryHalf, setSummaryHalf] = useState<1 | 2>(1);
  const [customFrom, setCustomFrom] = useState(`${fiscalYear}-04`);
  const [customTo, setCustomTo] = useState(`${fiscalYear}-04`);

  function summaryRange(): { from: string; to: string } {
    const pad = (n: number) => String(n).padStart(2, "0");
    if (summaryPeriodType === "quarterly") {
      const starts: Record<number, [number, number]> = { 1: [fiscalYear, 4], 2: [fiscalYear, 7], 3: [fiscalYear, 10], 4: [fiscalYear + 1, 1] };
      const [y, m] = starts[summaryQuarter];
      const endM = m + 2 > 12 ? m + 2 - 12 : m + 2;
      const endY = m + 2 > 12 ? y + 1 : y;
      return { from: `${y}-${pad(m)}`, to: `${endY}-${pad(endM)}` };
    }
    if (summaryPeriodType === "half-yearly") {
      return summaryHalf === 1 ? { from: `${fiscalYear}-04`, to: `${fiscalYear}-09` } : { from: `${fiscalYear}-10`, to: `${fiscalYear + 1}-03` };
    }
    if (summaryPeriodType === "annual") {
      return { from: `${fiscalYear}-04`, to: `${fiscalYear + 1}-03` };
    }
    return { from: customFrom, to: customTo };
  }

  // ── My Investment Declaration (employee self-service) ──
  const currentFy = new Date().getMonth() + 1 >= 4 ? new Date().getFullYear() : new Date().getFullYear() - 1;
  const [declaration, setDeclaration] = useState<any | null>(null);
  const [declarationForm, setDeclarationForm] = useState({
    regime_choice: "new", section_80c_amount: "0", section_80ccd1b_amount: "0", section_80d_amount: "0",
    section_80e_amount: "0", section_80g_amount: "0", section_80tta_amount: "0", hra_rent_paid: "0",
    housing_type: "rent", home_loan_interest_declared: "0", other_income_declared: "0", previous_employer_salary: "0",
    lta_exemption_claimed: "0", section_80gg_amount: "0", section_80dd_amount: "0", section_80ddb_amount: "0",
    section_80ee_amount: "0", section_80eea_amount: "0", section_80gga_amount: "0", section_80ggc_amount: "0",
  });
  const [declarationBusy, setDeclarationBusy] = useState(false);
  const [declarationError, setDeclarationError] = useState("");

  function loadDeclaration() {
    api.getMyInvestmentDeclarations().then((rows) => {
      const current = rows.find((d: any) => d.financial_year === currentFy) || null;
      setDeclaration(current);
      if (current) {
        setDeclarationForm({
          regime_choice: current.regime_choice,
          section_80c_amount: String(current.section_80c_amount ?? 0),
          section_80ccd1b_amount: String(current.section_80ccd1b_amount ?? 0),
          section_80d_amount: String(current.section_80d_amount ?? 0),
          section_80e_amount: String(current.section_80e_amount ?? 0),
          section_80g_amount: String(current.section_80g_amount ?? 0),
          section_80tta_amount: String(current.section_80tta_amount ?? 0),
          hra_rent_paid: String(current.hra_rent_paid ?? 0),
          housing_type: current.housing_type || "rent",
          home_loan_interest_declared: String(current.home_loan_interest_declared ?? 0),
          other_income_declared: String(current.other_income_declared ?? 0),
          previous_employer_salary: String(current.previous_employer_salary ?? 0),
          lta_exemption_claimed: String(current.lta_exemption_claimed ?? 0),
          section_80gg_amount: String(current.section_80gg_amount ?? 0),
          section_80dd_amount: String(current.section_80dd_amount ?? 0),
          section_80ddb_amount: String(current.section_80ddb_amount ?? 0),
          section_80ee_amount: String(current.section_80ee_amount ?? 0),
          section_80eea_amount: String(current.section_80eea_amount ?? 0),
          section_80gga_amount: String(current.section_80gga_amount ?? 0),
          section_80ggc_amount: String(current.section_80ggc_amount ?? 0),
        });
      }
    }).catch(() => {});
  }

  async function saveDeclaration() {
    setDeclarationBusy(true);
    setDeclarationError("");
    try {
      const saved = await api.saveInvestmentDeclaration({
        financial_year: currentFy,
        regime_choice: declarationForm.regime_choice as "old" | "new",
        section_80c_amount: parseFloat(declarationForm.section_80c_amount) || 0,
        section_80ccd1b_amount: parseFloat(declarationForm.section_80ccd1b_amount) || 0,
        section_80d_amount: parseFloat(declarationForm.section_80d_amount) || 0,
        section_80e_amount: parseFloat(declarationForm.section_80e_amount) || 0,
        section_80g_amount: parseFloat(declarationForm.section_80g_amount) || 0,
        section_80tta_amount: parseFloat(declarationForm.section_80tta_amount) || 0,
        hra_rent_paid: parseFloat(declarationForm.hra_rent_paid) || 0,
        housing_type: declarationForm.housing_type as "rent" | "self_occupied_loan",
        home_loan_interest_declared: parseFloat(declarationForm.home_loan_interest_declared) || 0,
        other_income_declared: parseFloat(declarationForm.other_income_declared) || 0,
        previous_employer_salary: parseFloat(declarationForm.previous_employer_salary) || 0,
        lta_exemption_claimed: parseFloat(declarationForm.lta_exemption_claimed) || 0,
        section_80gg_amount: parseFloat(declarationForm.section_80gg_amount) || 0,
        section_80dd_amount: parseFloat(declarationForm.section_80dd_amount) || 0,
        section_80ddb_amount: parseFloat(declarationForm.section_80ddb_amount) || 0,
        section_80ee_amount: parseFloat(declarationForm.section_80ee_amount) || 0,
        section_80eea_amount: parseFloat(declarationForm.section_80eea_amount) || 0,
        section_80gga_amount: parseFloat(declarationForm.section_80gga_amount) || 0,
        section_80ggc_amount: parseFloat(declarationForm.section_80ggc_amount) || 0,
      });
      setDeclaration(saved);
      flash(setNotice, "Draft saved.");
    } catch (e: any) {
      setDeclarationError(e.message || "Failed to save.");
    } finally {
      setDeclarationBusy(false);
    }
  }

  // ── Proof of Investments (employee self-service — document uploads against the declaration above) ──
  const [poiWindow, setPoiWindow] = useState<any | null>(null);
  const [poi, setPoi] = useState<any | null>(null);
  const [poiBusySection, setPoiBusySection] = useState<string | null>(null);
  const [poiError, setPoiError] = useState("");
  const POI_SECTIONS = [
    { key: "80C", label: "Section 123 (80C)" },
    { key: "80CCD1B", label: "Section 124(3) (80CCD(1B))" },
    { key: "80D", label: "Section 126 (80D)" },
    { key: "80E", label: "Section 129 (80E)" },
    { key: "80G", label: "Section 133 (80G)" },
    { key: "80TTA", label: "Section 153 (80TTA)" },
    { key: "HRA", label: "HRA Rent Paid" },
    { key: "HomeLoanInterest", label: "Home Loan Interest" },
    { key: "LTA", label: "LTA / Travel Concession" },
    { key: "80GG", label: "Section 134 (80GG)" },
    { key: "80DD", label: "Section 127 (80DD)" },
    { key: "80DDB", label: "Section 128 (80DDB)" },
    { key: "80EE", label: "Section 130 (80EE)" },
    { key: "80EEA", label: "Section 131 (80EEA)" },
    { key: "80GGA", label: "Section 135 (80GGA)" },
    { key: "80GGC", label: "Section 137 (80GGC)" },
    { key: "PreviousEmployerSalary", label: "Previous Employer Salary" },
    { key: "Other", label: "Other Income" },
  ];

  function loadPoi() {
    api.getPoiWindow(currentFy).then(setPoiWindow).catch(() => {});
    api.getMyProofOfInvestment(currentFy).then(setPoi).catch(() => {});
  }

  async function handlePoiUpload(section: string, file: File) {
    setPoiBusySection(section);
    setPoiError("");
    try {
      await api.uploadPoiDocument(currentFy, section, file);
      loadPoi();
    } catch (e: any) {
      setPoiError(e.message || "Failed to upload document.");
    } finally {
      setPoiBusySection(null);
    }
  }

  async function handlePoiDelete(documentId: number) {
    try {
      await api.deletePoiDocument(documentId);
      loadPoi();
    } catch (e: any) {
      setPoiError(e.message || "Failed to remove document.");
    }
  }

  // ── My Identity Verification (self-service Aadhaar — DigiLocker consent is personally
  // granted by the Aadhaar holder, so this stays separate from HR's own verify action) ──
  const [myDigilockerStatus, setMyDigilockerStatus] = useState<{ has_employee_record: boolean; has_aadhaar?: boolean; aadhaar_verification_status?: string } | null>(null);
  const [myDigilockerBusy, setMyDigilockerBusy] = useState(false);
  const [myDigilockerMessage, setMyDigilockerMessage] = useState("");

  function loadMyDigilockerStatus() {
    api.getMyDigiLockerStatus().then(setMyDigilockerStatus).catch(() => {});
  }

  async function startMyAadhaarVerification() {
    setMyDigilockerBusy(true);
    setMyDigilockerMessage("");
    try {
      const { consent_url } = await api.startMyDigiLockerConsent();
      window.location.href = consent_url;
    } catch (e: any) {
      setMyDigilockerMessage(e.message || "Failed to start DigiLocker verification.");
      setMyDigilockerBusy(false);
    }
  }

  async function submitDeclaration() {
    if (!declaration || !confirm("Submit for HR review? You won't be able to edit it until HR responds.")) return;
    setDeclarationBusy(true);
    setDeclarationError("");
    try {
      const updated = await api.submitInvestmentDeclaration(declaration.id);
      setDeclaration(updated);
      flash(setNotice, "Investment declaration submitted for review.");
    } catch (e: any) {
      setDeclarationError(e.message || "Failed to submit.");
    } finally {
      setDeclarationBusy(false);
    }
  }

  // ── Reimbursements (employee self-service) ──
  const [reimbursementTypes, setReimbursementTypes] = useState<any[]>([]);
  const [myReimbursements, setMyReimbursements] = useState<any[]>([]);
  const [showReimburse, setShowReimburse] = useState(false);
  const [reimburseForm, setReimburseForm] = useState({
    reimbursement_type_id: "", amount: "", claim_date: new Date().toISOString().slice(0, 10), description: "",
  });
  const [reimburseFile, setReimburseFile] = useState<File | null>(null);
  const [reimburseBusy, setReimburseBusy] = useState(false);
  const [reimburseError, setReimburseError] = useState("");

  // ── Loans (employee self-service — apply, view status) ──
  const [myLoans, setMyLoans] = useState<any[]>([]);
  const [showLoanRequest, setShowLoanRequest] = useState(false);
  const [loanForm, setLoanForm] = useState({ principal_amount: "", monthly_installment: "", reason: "" });
  const [loanBusy, setLoanBusy] = useState(false);
  const [loanError, setLoanError] = useState("");

  function loadMyLoans() {
    api.getMyLoans().then(setMyLoans).catch(() => {});
  }

  async function handleRequestLoan() {
    const principal = parseFloat(loanForm.principal_amount);
    const installment = parseFloat(loanForm.monthly_installment);
    if (!principal || principal <= 0) { setLoanError("Enter a valid loan amount."); return; }
    if (!installment || installment <= 0) { setLoanError("Enter a valid monthly installment."); return; }
    setLoanBusy(true);
    setLoanError("");
    try {
      await api.requestLoan({ principal_amount: principal, monthly_installment: installment, reason: loanForm.reason || undefined });
      setShowLoanRequest(false);
      setLoanForm({ principal_amount: "", monthly_installment: "", reason: "" });
      flash(setNotice, "Loan request submitted — accounts will review it.");
      loadMyLoans();
    } catch (e: any) {
      setLoanError(e.message || "Failed to submit loan request.");
    } finally {
      setLoanBusy(false);
    }
  }

  // ── Form 16 (employee self-service — request accounts to prepare it) ──
  const [form16Fy, setForm16Fy] = useState(currentFiscalYear());
  const [myForm16, setMyForm16] = useState<any | null>(null);
  const [form16Busy, setForm16Busy] = useState(false);

  async function handleRequestForm16() {
    setForm16Busy(true);
    try {
      const doc = await api.requestForm16(form16Fy);
      setMyForm16(doc);
      flash(setNotice, doc.status === "Requested" ? "Form 16 requested — accounts will prepare and publish it." : "Form 16 status refreshed.");
    } catch (e: any) {
      flash(setError, e.message || "Failed to request Form 16.");
    } finally {
      setForm16Busy(false);
    }
  }

  function flash(setter: (s: string) => void, msg: string) {
    setter(msg);
    setTimeout(() => setter(""), 4000);
  }

  function loadReimbursements() {
    api.getMyReimbursements().then((res) => setMyReimbursements(res.data || [])).catch(() => {});
  }

  useEffect(() => {
    api.getMyPayslips().then(setMySlips).catch(() => {}).finally(() => setLoading(false));
    api.getReimbursementTypes().then(setReimbursementTypes).catch(() => {});
    loadReimbursements();
    loadDeclaration();
    loadPoi();
    loadMyLoans();
    loadMyDigilockerStatus();
  }, []);

  async function handleSubmitReimbursement() {
    setReimburseError("");
    const typeId = parseInt(reimburseForm.reimbursement_type_id, 10);
    const amount = parseFloat(reimburseForm.amount);
    if (!typeId) { setReimburseError("Pick a reimbursement type."); return; }
    if (!amount || amount <= 0) { setReimburseError("Enter a valid amount."); return; }
    if (!reimburseFile) { setReimburseError("Attach a receipt."); return; }

    setReimburseBusy(true);
    try {
      await api.submitReimbursement({
        reimbursement_type_id: typeId,
        amount,
        claim_date: reimburseForm.claim_date,
        description: reimburseForm.description || undefined,
        receipt: reimburseFile,
      });
      setShowReimburse(false);
      setReimburseForm({ reimbursement_type_id: "", amount: "", claim_date: new Date().toISOString().slice(0, 10), description: "" });
      setReimburseFile(null);
      flash(setNotice, "Reimbursement submitted — pending approval.");
      loadReimbursements();
    } catch (e: any) {
      setReimburseError(e.message || "Failed to submit reimbursement.");
    } finally {
      setReimburseBusy(false);
    }
  }

  const TABS = [
    { value: "payslips", label: "Payslips", icon: FileText },
    { value: "reimbursements", label: "Reimbursements", icon: Receipt },
    { value: "investment-declaration", label: "Investment Declaration", icon: FileSignature },
    { value: "proof-of-investments", label: "Proof of Investments", icon: Upload },
    ...(myDigilockerStatus?.has_employee_record && myDigilockerStatus.has_aadhaar
      ? [{ value: "identity-verification", label: "Identity Verification", icon: ShieldCheck }]
      : []),
    { value: "loans", label: "Loans", icon: Landmark },
    { value: "form16", label: "Form 16", icon: FileCheck2 },
  ];

  return (
    <TooltipProvider>
    <AppLayout>
      <Head title="Payroll" />
      <PageHeader eyebrow="HRMS" title="Payroll"
        description="Your pay slips, loans, investment declarations, and statutory deductions"
      />

      <div className="flex items-center gap-1 border-b border-border px-8 -mt-2 mb-2 overflow-x-auto">
        {TABS.map((t) => (
          <button
            key={t.value}
            onClick={() => setActiveTab(t.value)}
            className={`flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors ${
              activeTab === t.value
                ? "border-gold text-gold"
                : "border-transparent text-muted-foreground hover:text-foreground"
            }`}
          >
            <t.icon className="h-3.5 w-3.5" />
            {t.label}
          </button>
        ))}
      </div>

      {showReimburse && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Submit Reimbursement</h2>
              <button onClick={() => setShowReimburse(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {reimburseError && (
              <div className="mb-3 rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">{reimburseError}</div>
            )}
            <section>
              <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">1</span>
                Expense Details
              </h3>
              <div className="grid grid-cols-4 gap-2">
                <div className="col-span-2">
                  <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Type<FieldHelp text="The expense category — some types have a monthly cap shown in the dropdown." /></div>
                  <select value={reimburseForm.reimbursement_type_id}
                    onChange={(e) => setReimburseForm((p) => ({ ...p, reimbursement_type_id: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold">
                    <option value="">Select a type…</option>
                    {reimbursementTypes.map((t) => (
                      <option key={t.id} value={t.id}>
                        {t.name}{t.monthly_cap ? ` (cap ₹${Number(t.monthly_cap).toLocaleString("en-IN")}/mo)` : ""}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Amount (₹)<FieldHelp text="The amount you're claiming, in rupees." /></div>
                  <input type="number" min="0.01" step="0.01" value={reimburseForm.amount}
                    onChange={(e) => setReimburseForm((p) => ({ ...p, amount: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
                <div>
                  <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Expense Date<FieldHelp text="The date you actually incurred the expense (not today's date)." /></div>
                  <input type="date" max={new Date().toISOString().slice(0, 10)} value={reimburseForm.claim_date}
                    onChange={(e) => setReimburseForm((p) => ({ ...p, claim_date: e.target.value }))}
                    className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                </div>
                <div className="col-span-2">
                  <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Description (optional)<FieldHelp text="A short note on what this was for — helps the approver review it faster." /></div>
                  <textarea value={reimburseForm.description} rows={2}
                    onChange={(e) => setReimburseForm((p) => ({ ...p, description: e.target.value }))}
                    className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                </div>
                <div className="col-span-2">
                  <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Receipt<FieldHelp text="A photo or PDF of the receipt/invoice — required for most expense types." /></div>
                  <input type="file" accept=".pdf,.jpg,.jpeg,.png"
                    onChange={(e) => setReimburseFile(e.target.files?.[0] ?? null)}
                    className="w-full text-xs file:mr-3 file:rounded-md file:border file:border-border file:bg-muted file:px-3 file:py-1.5 file:text-xs" />
                </div>
              </div>
            </section>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={reimburseBusy} onClick={handleSubmitReimbursement}>
                {reimburseBusy ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Submit
              </Button>
              <Button variant="outline" onClick={() => setShowReimburse(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-6">
        {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>}
        {notice && <div className="rounded-md border border-green-500/40 bg-green-500/10 px-4 py-2 text-sm text-green-400">{notice}</div>}
        {props.flash?.success && <div className="rounded-md border border-green-500/40 bg-green-500/10 px-4 py-2 text-sm text-green-500">{props.flash.success}</div>}
        {props.flash?.error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{props.flash.error}</div>}

        {/* My Payslips */}
        {activeTab === "payslips" && (mySlips.length > 0 ? (
          <div>
            <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
              <h3 className="text-sm font-semibold">My Payslips</h3>
              <span className="flex items-center text-xs text-muted-foreground">
                Fiscal Year<FieldHelp text="The financial year (Apr–Mar) to view payslips for." />
              </span>
              <select value={fiscalYear} onChange={(e) => { setFiscalYear(Number(e.target.value)); setCustomFrom(`${Number(e.target.value)}-04`); setCustomTo(`${Number(e.target.value)}-04`); }}
                className="h-7 rounded-md border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold">
                {[currentFiscalYear(), currentFiscalYear() - 1, currentFiscalYear() - 2].map((fy) => (
                  <option key={fy} value={fy}>FY {fy}-{String(fy + 1).slice(-2)}</option>
                ))}
              </select>
            </div>

            <div className="flex items-center gap-2 mb-3 flex-wrap text-xs">
              <span className="text-muted-foreground flex items-center">
                Download payslip for:
                <FieldHelp text="How payslips should be grouped in the combined PDF — quarter, half-year, full year, or a custom range." />
              </span>
              <select value={summaryPeriodType} onChange={(e) => setSummaryPeriodType(e.target.value as any)}
                className="h-7 rounded-md border border-border bg-background px-2 focus:outline-none focus:ring-1 focus:ring-gold">
                <option value="quarterly">Quarterly</option>
                <option value="half-yearly">Half-Yearly</option>
                <option value="annual">Annual</option>
                <option value="custom">Custom Range</option>
              </select>
              {summaryPeriodType === "quarterly" && (
                <select value={summaryQuarter} onChange={(e) => setSummaryQuarter(Number(e.target.value))}
                  className="h-7 rounded-md border border-border bg-background px-2 focus:outline-none focus:ring-1 focus:ring-gold">
                  <option value={1}>Q1 (Apr–Jun)</option>
                  <option value={2}>Q2 (Jul–Sep)</option>
                  <option value={3}>Q3 (Oct–Dec)</option>
                  <option value={4}>Q4 (Jan–Mar)</option>
                </select>
              )}
              {summaryPeriodType === "half-yearly" && (
                <select value={summaryHalf} onChange={(e) => setSummaryHalf(Number(e.target.value) as 1 | 2)}
                  className="h-7 rounded-md border border-border bg-background px-2 focus:outline-none focus:ring-1 focus:ring-gold">
                  <option value={1}>H1 (Apr–Sep)</option>
                  <option value={2}>H2 (Oct–Mar)</option>
                </select>
              )}
              {summaryPeriodType === "custom" && (
                <>
                  <input type="month" value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} className="h-7 rounded-md border border-border bg-background px-2 focus:outline-none focus:ring-1 focus:ring-gold" />
                  <span className="text-muted-foreground">to</span>
                  <input type="month" value={customTo} onChange={(e) => setCustomTo(e.target.value)} className="h-7 rounded-md border border-border bg-background px-2 focus:outline-none focus:ring-1 focus:ring-gold" />
                </>
              )}
              <button onClick={() => { const r = summaryRange(); api.downloadPdf(api.payslipSummaryUrl(mySlips[0].employee_id, r.from, r.to), `payslip-summary-${r.from}-to-${r.to}.pdf`).catch((e) => setError(e.message)); }}>
                <Button size="sm" variant="outline" className="h-7 text-xs">
                  <Download className="h-3 w-3 mr-1" />Download PDF
                </Button>
              </button>
            </div>
            <Card className="border-border">
              <CardContent className="p-0">
                <table className="w-full text-xs">
                  <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-3 py-2.5 text-left">Month</th>
                      <th className="px-3 py-2.5 text-right">Gross</th>
                      <th className="px-3 py-2.5 text-right">Deductions</th>
                      <th className="px-3 py-2.5 text-right">Net Pay</th>
                      <th className="px-3 py-2.5 text-left">Status</th>
                      <th className="px-3 py-2.5 text-center">Slip</th>
                    </tr>
                  </thead>
                  <tbody>
                    {mySlips.map((s) => (
                      <tr key={s.id} className="border-t border-border hover:bg-muted/20">
                        <td className="px-3 py-2.5 font-medium">{s.run ? periodLabel(s.run.period) : "—"}</td>
                        <td className="px-3 py-2.5 text-right">{fmt(s.gross_salary)}</td>
                        <td className="px-3 py-2.5 text-right text-red-500">-{fmt(s.total_deductions)}</td>
                        <td className="px-3 py-2.5 text-right font-semibold text-green-600">{fmt(s.net_pay)}</td>
                        <td className="px-3 py-2.5">
                          <Badge variant="outline" className={`text-[10px] ${statusBadge(s.run?.status || "")}`}>{s.run?.status}</Badge>
                        </td>
                        <td className="px-3 py-2.5 text-center">
                          <button className="inline-flex p-1 rounded hover:bg-muted" title="Download payslip PDF"
                            onClick={() => api.downloadPdf(api.payslipPdfUrl(s.id), `payslip-${s.id}.pdf`).catch((e) => setError(e.message))}>
                            <FileText className="h-3 w-3 text-muted-foreground" />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          </div>
        ) : !loading ? (
          <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
            <FileText className="h-10 w-10 opacity-20" />
            <p className="text-sm">No payslips yet. They appear here once HR finalizes a payroll run.</p>
          </div>
        ) : null)}

        {/* My Reimbursements */}
        {activeTab === "reimbursements" && (
        <div>
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-sm font-semibold flex items-center gap-2"><Receipt className="h-4 w-4 text-gold" />My Reimbursements</h3>
            <Button size="sm" className="h-7 text-xs bg-gold hover:bg-gold/90 text-black" onClick={() => setShowReimburse(true)}>
              <Plus className="h-3 w-3 mr-1" />Submit Reimbursement
            </Button>
          </div>
          <Card className="border-border">
            <CardContent className="p-0">
              {myReimbursements.length === 0 ? (
                <p className="text-xs text-muted-foreground text-center py-8">No reimbursement claims yet.</p>
              ) : (
                <table className="w-full text-xs">
                  <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-3 py-2.5 text-left">Type</th>
                      <th className="px-3 py-2.5 text-right">Amount</th>
                      <th className="px-3 py-2.5 text-left">Date</th>
                      <th className="px-3 py-2.5 text-left">Status</th>
                      <th className="px-3 py-2.5 text-left">Paid In</th>
                      <th className="px-3 py-2.5 text-center">Receipt</th>
                    </tr>
                  </thead>
                  <tbody>
                    {myReimbursements.map((c) => (
                      <tr key={c.id} className="border-t border-border hover:bg-muted/20">
                        <td className="px-3 py-2.5 font-medium">{c.reimbursement_type?.name || c.category}</td>
                        <td className="px-3 py-2.5 text-right">{fmt(c.amount)}</td>
                        <td className="px-3 py-2.5 text-muted-foreground">
                          {new Date(c.claim_date).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" })}
                        </td>
                        <td className="px-3 py-2.5">
                          <Badge variant="outline" className={`text-[10px] ${claimStatusBadge(c.status)}`}>{c.status}</Badge>
                        </td>
                        <td className="px-3 py-2.5 text-muted-foreground">{c.payslip?.run ? periodLabel(c.payslip.run.period) : "—"}</td>
                        <td className="px-3 py-2.5 text-center">
                          <a href={api.reimbursementReceiptUrl(c.id)} target="_blank" rel="noreferrer"
                            className="inline-flex p-1 rounded hover:bg-muted" title="View receipt">
                            <Paperclip className="h-3 w-3 text-muted-foreground" />
                          </a>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </CardContent>
          </Card>
        </div>
        )}

        {/* My Investment Declaration */}
        {activeTab === "investment-declaration" && (
        <div>
          <h3 className="text-sm font-semibold mb-3">My Investment Declaration (FY {currentFy}-{String(currentFy + 1).slice(-2)})</h3>
          <Card className="border-border">
            <CardContent className="p-4 space-y-3">
              {declarationError && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">{declarationError}</div>}
              {declaration && declaration.status !== "Draft" ? (
                <div className="flex items-center gap-2 text-sm">
                  <span className="text-muted-foreground">Status:</span>
                  <Badge variant="outline" className={`text-[10px] ${claimStatusBadge(declaration.status)}`}>{declaration.status}</Badge>
                </div>
              ) : (
                <div className="space-y-4">
                  <section>
                    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">1</span>
                      Regime &amp; Housing
                    </h3>
                    <div className="grid grid-cols-4 gap-2">
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Tax Regime<FieldHelp text="Old Regime lets you claim deductions (80C, 80D, HRA, etc.) but has higher slab rates. New Regime (Income Tax Act 2025, section 202 — the default unless you opt out) has lower rates but almost no deductions — pick whichever gives you lower tax." /></div>
                        <select value={declarationForm.regime_choice} onChange={(e) => setDeclarationForm((p) => ({ ...p, regime_choice: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold">
                          <option value="new">New Regime</option>
                          <option value="old">Old Regime</option>
                        </select>
                      </div>
                      <div className="col-span-3">
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Housing<FieldHelp text="Choose 'I pay rent' to claim HRA exemption (section 11, Schedule III Sl. No. 11 — erstwhile section 10(13A)), or 'Self-occupied home loan' to claim home loan interest instead (section 22(1)(b) — erstwhile section 24(b)) — only one applies, not both. If you pay rent but receive no HRA at all, use the Section 80GG field below instead." /></div>
                        <div className="flex gap-4 text-xs h-8 items-center">
                          <label className="flex items-center gap-1.5">
                            <input type="radio" checked={declarationForm.housing_type === "rent"} onChange={() => setDeclarationForm((p) => ({ ...p, housing_type: "rent" }))} />
                            I pay rent (HRA)
                          </label>
                          <label className="flex items-center gap-1.5">
                            <input type="radio" checked={declarationForm.housing_type === "self_occupied_loan"} onChange={() => setDeclarationForm((p) => ({ ...p, housing_type: "self_occupied_loan" }))} />
                            Self-occupied home loan
                          </label>
                        </div>
                      </div>
                      {declarationForm.housing_type === "self_occupied_loan" ? (
                        <div>
                          <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Home Loan Interest (₹/yr, max 2,00,000)<FieldHelp text="Interest paid on your home loan this year, up to ₹2,00,000 — deductible under Income Tax Act 2025, section 22(1)(b) and 22(2)(a) (erstwhile section 24(b)) (Old Regime only)." /></div>
                          <input type="number" max={200000} value={declarationForm.home_loan_interest_declared} onChange={(e) => setDeclarationForm((p) => ({ ...p, home_loan_interest_declared: e.target.value }))}
                            className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                        </div>
                      ) : (
                        <div>
                          <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">HRA Rent Paid (₹/yr)<FieldHelp text="Total rent you'll pay this financial year — used to calculate your HRA exemption under Income Tax Act 2025, section 11 and Schedule III Sl. No. 11 (erstwhile section 10(13A)) (Old Regime only)." /></div>
                          <input type="number" value={declarationForm.hra_rent_paid} onChange={(e) => setDeclarationForm((p) => ({ ...p, hra_rent_paid: e.target.value }))}
                            className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                        </div>
                      )}
                    </div>
                  </section>

                  <section>
                    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">2</span>
                      Chapter VIII Deductions (erstwhile Chapter VI-A)
                    </h3>
                    <div className="grid grid-cols-4 gap-2">
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 123 (₹, max 1,50,000)<FieldHelp text="Erstwhile section 80C — life insurance, PF, ELSS, children's tuition, etc. — deductible up to ₹1,50,000 (Old Regime only)." /></div>
                        <input type="number" max={150000} value={declarationForm.section_80c_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80c_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 124(3) (₹, max 50,000)<FieldHelp text="Erstwhile section 80CCD(1B) — additional voluntary NPS contribution — deductible up to ₹50,000, on top of the ₹1,50,000 section 123 limit (Old Regime only)." /></div>
                        <input type="number" max={50000} value={declarationForm.section_80ccd1b_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80ccd1b_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 126 (₹, max 1,00,000)<FieldHelp text="Erstwhile section 80D — health insurance premiums for yourself and family — deductible up to ₹1,00,000 (Old Regime only)." /></div>
                        <input type="number" max={100000} value={declarationForm.section_80d_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80d_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 129 (₹/yr)<FieldHelp text="Erstwhile section 80E — interest paid on an education loan for yourself, spouse, or children — fully deductible, no cap (Old Regime only)." /></div>
                        <input type="number" value={declarationForm.section_80e_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80e_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 133 (₹/yr)<FieldHelp text="Erstwhile section 80G — donations to eligible charities/relief funds — deductible subject to limits that depend on the specific fund (Old Regime only); HR will confirm the eligible amount during review." /></div>
                        <input type="number" value={declarationForm.section_80g_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80g_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 153 (₹, max 10,000)<FieldHelp text="Erstwhile section 80TTA — interest earned on your savings bank account — deductible up to ₹10,000 (Old Regime only; ₹50,000 and all deposit types if you're a senior citizen — contact HR if that applies to you)." /></div>
                        <input type="number" max={10000} value={declarationForm.section_80tta_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80tta_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                    </div>
                  </section>

                  <section>
                    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">3</span>
                      Other Deductions
                    </h3>
                    <div className="grid grid-cols-4 gap-2">
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">LTA / Travel Concession (₹/yr)<FieldHelp text="Section 11, Schedule III Sl. No. 8 (erstwhile section 10(5)) — leave travel concession/assistance from your employer, exempt up to the actual travel expense incurred, subject to conditions on number of journeys (Old Regime only)." /></div>
                        <input type="number" value={declarationForm.lta_exemption_claimed} onChange={(e) => setDeclarationForm((p) => ({ ...p, lta_exemption_claimed: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 134 — Rent, No HRA (₹/yr)<FieldHelp text="Erstwhile section 80GG — only use this if your salary has no HRA component at all but you still pay rent. Deduction = least of (rent − 10% of income), ₹60,000/yr, or 25% of income (Old Regime only)." /></div>
                        <input type="number" max={60000} value={declarationForm.section_80gg_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80gg_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 127 (₹, max 1,25,000)<FieldHelp text="Erstwhile section 80DD — maintenance/medical treatment of a dependant with a disability — ₹75,000 (normal disability) or ₹1,25,000 (severe disability, ≥80%); requires a medical certificate (Old Regime only)." /></div>
                        <input type="number" max={125000} value={declarationForm.section_80dd_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80dd_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 128 (₹, max 1,00,000)<FieldHelp text="Erstwhile section 80DDB — medical treatment for specified diseases (self or dependant) — ₹40,000, or ₹1,00,000 if the patient is a senior citizen; reduced by any insurance/employer reimbursement (Old Regime only)." /></div>
                        <input type="number" max={100000} value={declarationForm.section_80ddb_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80ddb_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 130 (₹, max 50,000)<FieldHelp text="Erstwhile section 80EE — additional home loan interest for first-time buyers, but only for loans sanctioned between 1 Apr 2016 - 31 Mar 2017 (≤₹35L loan, ≤₹50L property). Only relevant if you're still repaying such a loan (Old Regime only)." /></div>
                        <input type="number" max={50000} value={declarationForm.section_80ee_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80ee_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 131 (₹, max 1,50,000)<FieldHelp text="Erstwhile section 80EEA — additional home loan interest for affordable housing, time-limited eligibility window. Only relevant if you're still repaying a qualifying loan from that window (Old Regime only)." /></div>
                        <input type="number" max={150000} value={declarationForm.section_80eea_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80eea_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 135 (₹/yr)<FieldHelp text="Erstwhile section 80GGA — donations for scientific research or rural development, generally for those with no business/profession income — fully deductible, no cap (Old Regime only)." /></div>
                        <input type="number" value={declarationForm.section_80gga_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80gga_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Section 137 (₹/yr)<FieldHelp text="Erstwhile section 80GGC — contribution to a registered political party or electoral trust — must not be paid in cash — fully deductible, no cap (Old Regime only)." /></div>
                        <input type="number" value={declarationForm.section_80ggc_amount} onChange={(e) => setDeclarationForm((p) => ({ ...p, section_80ggc_amount: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                    </div>
                  </section>

                  <section>
                    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">4</span>
                      Other Income
                    </h3>
                    <div className="grid grid-cols-4 gap-2">
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Previous Employer Salary (₹/yr)<FieldHelp text="If you joined partway through this financial year, your salary from the employer before this one — added to your total income here, same as item 1(e) of this certificate (reported under section 392(4), erstwhile section 192(2B))." /></div>
                        <input type="number" value={declarationForm.previous_employer_salary} onChange={(e) => setDeclarationForm((p) => ({ ...p, previous_employer_salary: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Other Income (₹/yr)<FieldHelp text="Any other income to consider for TDS, e.g. freelance or bank interest — this increases your taxable income (reported under section 392(4), erstwhile section 192(2B))." /></div>
                        <input type="number" value={declarationForm.other_income_declared} onChange={(e) => setDeclarationForm((p) => ({ ...p, other_income_declared: e.target.value }))}
                          className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                    </div>
                  </section>
                </div>
              )}
              {(!declaration || declaration.status === "Draft") && (
                <div className="flex gap-2">
                  <Button size="sm" variant="outline" disabled={declarationBusy} onClick={saveDeclaration}>
                    {declarationBusy ? <Loader2 className="h-3.5 w-3.5 animate-spin mr-1.5" /> : null}Save Draft
                  </Button>
                  {declaration && (
                    <Button size="sm" className="bg-gold hover:bg-gold/90 text-black" disabled={declarationBusy} onClick={submitDeclaration}>
                      Submit for Review
                    </Button>
                  )}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
        )}

        {/* Proof of Investments */}
        {activeTab === "proof-of-investments" && (
        <div>
          <h3 className="text-sm font-semibold mb-3">Proof of Investments (FY {currentFy}-{String(currentFy + 1).slice(-2)})</h3>
          <Card className="border-border">
            <CardContent className="p-4 space-y-3">
              {poiError && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">{poiError}</div>}
              {(() => {
                // Defensive parse — a window row with a missing/unparseable deadline_at
                // must never render "Invalid Date"; treat it the same as "not configured".
                const deadline = poiWindow?.deadline_at ? new Date(poiWindow.deadline_at) : null;
                const hasValidDeadline = !!deadline && !Number.isNaN(deadline.getTime());
                return !poiWindow || !hasValidDeadline ? (
                  <p className="text-sm text-muted-foreground">Proof of investment submission window has not been configured yet.</p>
                ) : poi?.consider_for_it_at ? (
                  <div className="flex items-center gap-2 text-sm">
                    <Badge variant="outline" className="text-[10px] bg-green-100 text-green-700 border-green-200">Considered</Badge>
                    <span className="text-muted-foreground">Your proof of investments has been reviewed and locked in for tax computation.</span>
                  </div>
                ) : deadline! < new Date() ? (
                  <p className="text-sm text-muted-foreground">
                    The submission window closed on {deadline!.toLocaleDateString("en-IN")}.
                  </p>
                ) : (
                <>
                  <p className="text-xs text-muted-foreground">Upload proof documents against your declared sections. Deadline: {deadline!.toLocaleDateString("en-IN")}.</p>
                  <div className="space-y-2">
                    {POI_SECTIONS.map((s) => {
                      const docs = (poi?.documents || []).filter((d: any) => d.section === s.key);
                      return (
                        <div key={s.key} className="flex items-center gap-3 rounded-md border border-border px-3 py-2">
                          <span className="text-xs font-medium w-36 flex items-center">
                            {s.label}
                            <FieldHelp text="Upload supporting documents (receipts, policy statement, rent agreement, etc.) proving this declared amount — HR reviews these before your final TDS is locked in." />
                          </span>
                          <div className="flex-1 flex flex-wrap gap-2">
                            {docs.map((d: any) => (
                              <span key={d.id} className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted/30 px-2 py-0.5 text-[11px]">
                                <Paperclip className="h-3 w-3" />{d.original_filename}
                                <button onClick={() => handlePoiDelete(d.id)} title="Remove"><X className="h-3 w-3 text-muted-foreground hover:text-red-500" /></button>
                              </span>
                            ))}
                          </div>
                          <label className="text-xs text-gold hover:underline cursor-pointer">
                            {poiBusySection === s.key ? <Loader2 className="h-3.5 w-3.5 animate-spin inline" /> : "+ Upload"}
                            <input type="file" className="hidden" disabled={poiBusySection === s.key}
                              onChange={(e) => { const f = e.target.files?.[0]; if (f) handlePoiUpload(s.key, f); e.target.value = ""; }} />
                          </label>
                        </div>
                      );
                    })}
                  </div>
                </>
                );
              })()}
            </CardContent>
          </Card>
        </div>
        )}

        {/* My Identity Verification */}
        {activeTab === "identity-verification" && myDigilockerStatus?.has_employee_record && myDigilockerStatus.has_aadhaar && (
          <div>
            <h3 className="text-sm font-semibold mb-3 flex items-center gap-2"><ShieldCheck className="h-4 w-4 text-gold" />My Identity Verification</h3>
            <Card className="border-border">
              <CardContent className="p-4 space-y-2">
                {myDigilockerMessage && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">{myDigilockerMessage}</div>}
                <div className="flex items-center justify-between">
                  <div>
                    <div className="text-sm font-medium flex items-center">
                      Aadhaar
                      <FieldHelp text="Starts DigiLocker's consent flow — you approve access there, then return here. We never see or store your Aadhaar document, only the verification result." />
                    </div>
                    <div className="mt-1">
                      <Badge variant="outline" className={`text-[10px] ${
                        myDigilockerStatus.aadhaar_verification_status === "Verified" ? "bg-green-100 text-green-700 border-green-200"
                        : myDigilockerStatus.aadhaar_verification_status === "Failed" ? "bg-red-100 text-red-700 border-red-200"
                        : "bg-muted text-muted-foreground border-border"
                      }`}>{myDigilockerStatus.aadhaar_verification_status ?? "Unverified"}</Badge>
                    </div>
                  </div>
                  {myDigilockerStatus.aadhaar_verification_status !== "Verified" && (
                    <Button size="sm" variant="outline" disabled={myDigilockerBusy} onClick={startMyAadhaarVerification}>
                      {myDigilockerBusy ? <Loader2 className="h-3.5 w-3.5 animate-spin mr-1.5" /> : null}Verify my Aadhaar via DigiLocker
                    </Button>
                  )}
                </div>
                <p className="text-[11px] text-muted-foreground">DigiLocker consent is personal — only you can grant it for your own Aadhaar.</p>
              </CardContent>
            </Card>
          </div>
        )}

        {/* My Loans */}
        {activeTab === "loans" && (
        <div>
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-sm font-semibold flex items-center gap-2"><Landmark className="h-4 w-4 text-gold" />My Loans</h3>
            <Button size="sm" className="h-7 text-xs bg-gold hover:bg-gold/90 text-black" onClick={() => setShowLoanRequest(true)}>
              <Plus className="h-3 w-3 mr-1" />Apply for Loan
            </Button>
          </div>
          <Card className="border-border">
            <CardContent className="p-0">
              {myLoans.length === 0 ? (
                <p className="text-xs text-muted-foreground text-center py-8">No loans yet.</p>
              ) : (
                <table className="w-full text-xs">
                  <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-3 py-2.5 text-right">Principal</th>
                      <th className="px-3 py-2.5 text-right">Installment</th>
                      <th className="px-3 py-2.5 text-right">Remaining</th>
                      <th className="px-3 py-2.5 text-left">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {myLoans.map((l) => (
                      <tr key={l.id} className="border-t border-border hover:bg-muted/20">
                        <td className="px-3 py-2.5 text-right">{fmt(l.principal_amount)}</td>
                        <td className="px-3 py-2.5 text-right">{fmt(l.monthly_installment)}</td>
                        <td className="px-3 py-2.5 text-right font-medium">{fmt(l.remaining_balance)}</td>
                        <td className="px-3 py-2.5">
                          <Badge variant="outline" className={`text-[10px] ${claimStatusBadge(l.status)}`}>{l.status}</Badge>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </CardContent>
          </Card>
        </div>
        )}

        {/* Form 16 */}
        {activeTab === "form16" && (
        <div>
          <h3 className="text-sm font-semibold mb-3 flex items-center gap-2"><FileSignature className="h-4 w-4 text-gold" />Form 16</h3>
          <Card className="border-border">
            <CardContent className="p-4 flex items-center gap-3 flex-wrap">
              <span className="flex items-center text-xs text-muted-foreground">
                Fiscal Year<FieldHelp text="The financial year to request or download your Form 16 for." />
              </span>
              <select value={form16Fy} onChange={(e) => setForm16Fy(Number(e.target.value))}
                className="h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                {[currentFiscalYear() - 1, currentFiscalYear() - 2].map((fy) => (
                  <option key={fy} value={fy}>FY {fy}-{String(fy + 1).slice(-2)}</option>
                ))}
              </select>
              <Button size="sm" variant="outline" disabled={form16Busy} onClick={handleRequestForm16}>
                {form16Busy ? <Loader2 className="h-3.5 w-3.5 animate-spin mr-1.5" /> : null}Request Form 16
              </Button>
              {mySlips[0]?.employee_id && (
                <a href={api.form16DownloadUrl(mySlips[0].employee_id, form16Fy)} target="_blank" rel="noreferrer">
                  <Button size="sm" variant="outline"><Download className="h-3.5 w-3.5 mr-1.5" />Download</Button>
                </a>
              )}
              {myForm16 && myForm16.financial_year === form16Fy && (
                <Badge variant="outline" className={`text-[10px] ${claimStatusBadge(myForm16.status)}`}>{myForm16.status}</Badge>
              )}
            </CardContent>
          </Card>
          <p className="text-[11px] text-muted-foreground mt-2">
            Request prepares your Form 16 for accounts to review; Download only works once it's published.
          </p>
        </div>
        )}

        {showLoanRequest && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
            <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-xl p-6 m-4">
              <div className="flex items-center justify-between mb-4">
                <h2 className="font-display text-lg font-semibold">Apply for Loan</h2>
                <button onClick={() => setShowLoanRequest(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
              </div>
              {loanError && <div className="mb-3 rounded-md border border-red-500/40 bg-red-500/10 px-3 py-2 text-xs text-red-400">{loanError}</div>}
              <section>
                <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
                  <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">1</span>
                  Loan Details
                </h3>
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Loan Amount (₹)<FieldHelp text="The total amount you're requesting — subject to HR/management approval." /></div>
                    <input type="number" min="0.01" value={loanForm.principal_amount}
                      onChange={(e) => setLoanForm((p) => ({ ...p, principal_amount: e.target.value }))}
                      className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                  <div>
                    <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Proposed Monthly Installment (₹)<FieldHelp text="How much you propose to repay each month via payroll deduction — HR may adjust this." /></div>
                    <input type="number" min="0.01" value={loanForm.monthly_installment}
                      onChange={(e) => setLoanForm((p) => ({ ...p, monthly_installment: e.target.value }))}
                      className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                  <div className="col-span-2">
                    <div className="text-[10px] text-muted-foreground mb-0.5 flex items-center">Reason (optional)<FieldHelp text="A short note on why you need this loan — helps the approver decide." /></div>
                    <textarea value={loanForm.reason} rows={2} onChange={(e) => setLoanForm((p) => ({ ...p, reason: e.target.value }))}
                      className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                  </div>
                </div>
              </section>
              <div className="flex gap-2 mt-5">
                <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={loanBusy} onClick={handleRequestLoan}>
                  {loanBusy ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Submit Request
                </Button>
                <Button variant="outline" onClick={() => setShowLoanRequest(false)}>Cancel</Button>
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayout>
    </TooltipProvider>
  );
}
