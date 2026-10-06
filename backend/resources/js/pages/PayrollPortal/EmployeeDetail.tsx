import { Head, usePage, Link } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import {
  Loader2, ArrowLeft, Edit2, X, Check, Plus, Download, ChevronDown, ChevronRight,
  User as UserIcon, FileText, Landmark, Wallet, FileBarChart2,
} from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";

const DEPTS = ["Legal", "Operations", "HR", "Finance", "Engineering", "Business Development", "Administration"];
const ROLES = ["Director", "HR", "Patent Attorney", "Patent Analyst", "Patent Engineer", "Finance Manager", "Paralegal", "Consultant", "System Admin"];
const LOCATIONS = ["Office", "Remote", "Coimbatore", "Chennai", "Bengaluru", "Hyderabad", "Pollachi"];
const GENDERS = ["Male", "Female", "Other", "Prefer not to say"];
const DIFFERENTLY_ABLED_TYPES = ["None", "Locomotor Disability", "Visual Impairment", "Hearing Impairment", "Intellectual Disability", "Multiple Disabilities", "Other"];

const fmt = (n: number | string) => `₹${Number(n).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`;

function currentFiscalYear(): number {
  const now = new Date();
  return now.getMonth() + 1 >= 4 ? now.getFullYear() : now.getFullYear() - 1;
}

function periodLabel(period: string): string {
  return new Date(period).toLocaleString("default", { month: "long", year: "numeric" });
}

function statusBadgeClass(status: string) {
  if (status === "Approved" || status === "Paid" || status === "Merged") return "bg-green-100 text-green-700 border-green-200";
  if (status === "Rejected" || status === "Failed") return "bg-red-100 text-red-700 border-red-200";
  if (status === "Finalized") return "bg-blue-100 text-blue-700 border-blue-200";
  return "bg-amber-100 text-amber-700 border-amber-200";
}

/** Mirrors PayrollService::computeComponents()'s math (employee_field / flat
 *  / percentage — earnings sum to Gross, then CTC add-ons resolve against
 *  them) — a client-side preview only, not a write path. Gross is a derived
 *  total now, not an input, so only a CTC Addon row can be "a percentage of
 *  Gross"; an Earning row can't (matches SalaryComponentValidator). */
function computeSalaryBreakdown(components: any[], basePay: number, hra: number) {
  const active = [...components].filter((c) => c.active).sort((a, b) => a.sequence - b.sequence);
  const amounts: Record<string, number> = {};
  const earningRows: { name: string; amount: number; label?: string | null }[] = [];
  const ctcAddonRows: { name: string; amount: number; label?: string | null }[] = [];
  let earningsTotal = 0;

  const fieldLabel = (f: string) => f === "hra_monthly" ? "HRA" : "Base Pay";
  const pctLabel = (c: any) => c.calc_type === "percentage" ? `${c.value}% of ${c.percentage_of === "_GROSS_" ? "Gross" : (active.find((x) => x.code === c.percentage_of)?.name ?? c.percentage_of)}` : c.calc_type === "employee_field" ? `Declared ${fieldLabel(c.percentage_of)}` : null;

  for (const c of active.filter((c) => (c.category || "earning") === "earning")) {
    if (c.calc_type === "remainder") { earningRows.push({ name: c.name, amount: 0 }); continue; }
    const base = c.calc_type === "flat" ? Number(c.value || 0)
      : c.calc_type === "employee_field" ? (c.percentage_of === "hra_monthly" ? hra : basePay)
      : (amounts[c.percentage_of] ?? 0) * (Number(c.value || 0) / 100);
    const amount = Math.round(base * 100) / 100;
    amounts[c.code] = amount;
    earningsTotal += amount;
    earningRows.push({ name: c.name, amount, label: pctLabel(c) });
  }

  for (const c of active.filter((c) => c.category === "ctc_addon")) {
    const of = c.percentage_of === "_GROSS_" ? earningsTotal : (amounts[c.percentage_of] ?? 0);
    const amount = Math.round((c.calc_type === "flat" ? Number(c.value || 0) : of * (Number(c.value || 0) / 100)) * 100) / 100;
    ctcAddonRows.push({ name: c.name, amount, label: pctLabel(c) });
  }

  const ctcAddonTotal = ctcAddonRows.reduce((s, r) => s + r.amount, 0);
  return { earningRows, ctcAddonRows, earningsTotal, ctcAddonTotal, totalCtc: earningsTotal + ctcAddonTotal };
}

const TABS = [
  { id: "overview", label: "Overview", icon: UserIcon },
  { id: "salary", label: "Salary Details", icon: Wallet },
  { id: "investments", label: "Investments", icon: FileBarChart2 },
  { id: "payslips", label: "Payslips & Forms", icon: FileText },
  { id: "loans", label: "Loans", icon: Landmark },
];

export default function PayrollPortalEmployeeDetail() {
  const { props } = usePage() as any;
  const employeeId: number = props.employeeId;
  const userRole: string = props.auth?.user?.role ?? "";
  // Salary/payroll data — director (partner), accountant (finance), and
  // system admin only. Mirrors App\Models\User::PAYROLL_ACCESS_ROLES.
  const canEdit = ["super_admin", "partner", "finance"].includes(userRole);

  const [employee, setEmployee] = useState<any | null>(null);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState("overview");

  function loadEmployee() {
    api.getEmployees().then((rows) => setEmployee(rows.find((e: any) => e.id === employeeId) ?? null)).finally(() => setLoading(false));
  }
  useEffect(() => { loadEmployee(); }, [employeeId]);

  if (loading) {
    return (
      <PayrollPortalLayout>
        <Head title="Payroll Portal — Employee" />
        <div className="flex items-center justify-center py-24"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
      </PayrollPortalLayout>
    );
  }

  if (!employee) {
    return (
      <PayrollPortalLayout>
        <Head title="Payroll Portal — Employee" />
        <div className="flex flex-col items-center justify-center py-24 gap-3 text-muted-foreground">
          <UserIcon className="h-10 w-10 opacity-20" />
          <p className="text-sm">Employee not found.</p>
          <Link href="/payroll-portal/employees"><Button variant="outline"><ArrowLeft className="h-4 w-4 mr-2" />Back to Employees</Button></Link>
        </div>
      </PayrollPortalLayout>
    );
  }

  return (
    <PayrollPortalLayout>
      <Head title={`Payroll Portal — ${employee.user?.name || employee.full_name}`} />
      <div className="flex flex-col gap-4 border-b border-border bg-card/30 px-8 py-6">
        <Link href="/payroll-portal/employees" className="flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground w-fit">
          <ArrowLeft className="h-3 w-3" />Back to Employees
        </Link>
        <div className="flex items-center gap-3">
          <div className="h-12 w-12 rounded-full bg-gold/20 flex items-center justify-center text-gold font-bold text-lg shrink-0">
            {(employee.user?.name || employee.full_name || "?").charAt(0).toUpperCase()}
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="font-display text-2xl font-semibold">{employee.employee_code} — {employee.user?.name || employee.full_name}</h1>
              <Badge variant={statusColor(employee.employment_status || "Active")} className="text-[10px]">{employee.employment_status}</Badge>
            </div>
            <p className="text-sm text-muted-foreground">{employee.designation?.title || "—"}</p>
          </div>
        </div>
        <div className="flex items-center gap-1 -mb-6 pt-2">
          {TABS.map((t) => (
            <button key={t.id} onClick={() => setTab(t.id)}
              className={`flex items-center gap-1.5 px-3 py-2 text-sm border-b-2 transition-colors ${tab === t.id ? "border-gold text-gold font-medium" : "border-transparent text-muted-foreground hover:text-foreground"}`}>
              <t.icon className="h-3.5 w-3.5" />{t.label}
            </button>
          ))}
        </div>
      </div>

      <div className="px-8 py-6">
        {props.flash?.success && <div className="mb-4 rounded-md border border-green-500/40 bg-green-500/10 px-4 py-2 text-sm text-green-500">{props.flash.success}</div>}
        {props.flash?.error && <div className="mb-4 rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{props.flash.error}</div>}
        {tab === "overview" && <OverviewTab employee={employee} canEdit={canEdit} onSaved={loadEmployee} />}
        {tab === "salary" && <SalaryTab employee={employee} />}
        {tab === "investments" && <InvestmentsTab employee={employee} canEdit={canEdit} />}
        {tab === "payslips" && <PayslipsTab employee={employee} />}
        {tab === "loans" && <LoansTab employee={employee} canEdit={canEdit} />}
      </div>
    </PayrollPortalLayout>
  );
}

/* ───────────────────────── Overview ───────────────────────── */

/** Enable/disable this employee's login access — writes immediately via its
 *  own endpoint, same pattern as the main HRMS Employees.tsx toggle. */
function PortalAccessInlineToggle({ employee, onChanged }: { employee: any; onChanged: () => void }) {
  const [busy, setBusy] = useState(false);
  const enabled = (employee.user?.status ?? "Active") === "Active";

  async function toggle() {
    setBusy(true);
    try {
      await api.updatePortalAccess(employee.id, !enabled);
      onChanged();
    } catch (e: any) {
      alert(e.message || "Failed to update portal access.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <button type="button" onClick={toggle} disabled={busy} className="flex items-center gap-2 mt-0.5">
      {busy ? <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" /> : (
        <span className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${enabled ? "bg-gold" : "bg-muted"}`}>
          <span className="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform" style={{ transform: enabled ? "translateX(18px)" : "translateX(2px)" }} />
        </span>
      )}
      <span className="text-sm font-medium">{enabled ? "Enabled" : "Disabled"}</span>
    </button>
  );
}

function OverviewTab({ employee, canEdit, onSaved }: { employee: any; canEdit: boolean; onSaved: () => void }) {
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<any>({});
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  function startEdit() {
    setForm({
      full_name: employee.user?.name || employee.full_name || "",
      father_name: employee.father_name || "",
      work_email: employee.user?.email || employee.work_email || "",
      phone: employee.phone || "",
      mobile: employee.mobile || "",
      gender: employee.gender || "",
      department_name: employee.department?.name || "",
      designation_title: employee.designation?.title || "",
      work_location: employee.work_location || "Coimbatore",
      date_of_joining: employee.date_of_joining || "",
      employment_type: employee.employment_type || "Full-time",
      base_pay_monthly: employee.base_pay_monthly?.toString() || "",
      hra_monthly: employee.hra_monthly?.toString() || "",
      professional_tax_exempt: !!employee.professional_tax_exempt,
      exclude_from_payroll: !!employee.exclude_from_payroll,
      date_of_birth: employee.date_of_birth || "",
      differently_abled_type: employee.differently_abled_type || "",
      current_address: employee.current_address || "",
      permanent_address: employee.permanent_address || "",
      aadhaar_ssn_encrypted: employee.aadhaar_ssn_encrypted || "",
      pan_tax_id: employee.pan_tax_id || "",
      uan_pf_number: employee.uan_pf_number || "",
      esi_number: employee.esi_number || "",
      nps_number: employee.nps_number || "",
      bank_account_number: employee.bank_account_number || "",
      bank_name: employee.bank_name || "",
      bank_ifsc_code: employee.bank_ifsc_code || "",
    });
    setError("");
    setEditing(true);
  }

  async function handleSave() {
    if (!form.full_name?.trim() || !form.work_email?.trim()) { setError("Name and email are required."); return; }
    setSaving(true);
    setError("");
    try {
      const blankToNull = (v: string) => (v?.trim() ? v.trim() : null);
      const payload = {
        ...form,
        base_pay_monthly: form.base_pay_monthly ? parseFloat(form.base_pay_monthly) : null,
        hra_monthly: form.hra_monthly ? parseFloat(form.hra_monthly) : null,
        date_of_birth: blankToNull(form.date_of_birth),
        father_name: blankToNull(form.father_name),
        differently_abled_type: blankToNull(form.differently_abled_type),
        current_address: blankToNull(form.current_address),
        permanent_address: blankToNull(form.permanent_address),
        aadhaar_ssn_encrypted: blankToNull(form.aadhaar_ssn_encrypted),
        pan_tax_id: blankToNull(form.pan_tax_id),
        uan_pf_number: blankToNull(form.uan_pf_number),
        esi_number: blankToNull(form.esi_number),
        nps_number: blankToNull(form.nps_number),
        bank_account_number: blankToNull(form.bank_account_number),
        bank_name: blankToNull(form.bank_name),
        bank_ifsc_code: blankToNull(form.bank_ifsc_code),
      };
      await api.updateEmployee(employee.id, payload);
      setEditing(false);
      onSaved();
    } catch (e: any) {
      setError(e.message || "Failed to save.");
    } finally {
      setSaving(false);
    }
  }

  const [panBusy, setPanBusy] = useState(false);
  const [panMessage, setPanMessage] = useState("");
  async function verifyPan() {
    setPanBusy(true);
    setPanMessage("");
    try {
      const result = await api.verifyEmployeePan(employee.id);
      setPanMessage(result.message);
      onSaved();
    } catch (e: any) {
      setPanMessage(e.message || "Failed to verify PAN.");
    } finally {
      setPanBusy(false);
    }
  }

  const [digilockerBusy, setDigilockerBusy] = useState(false);
  const [digilockerMessage, setDigilockerMessage] = useState("");
  async function startDigilockerVerification() {
    setDigilockerBusy(true);
    setDigilockerMessage("");
    try {
      const { consent_url } = await api.startDigiLockerConsent(employee.id, "AADHAAR");
      window.location.href = consent_url;
    } catch (e: any) {
      setDigilockerMessage(e.message || "Failed to start DigiLocker verification.");
      setDigilockerBusy(false);
    }
  }

  const [portalBusy, setPortalBusy] = useState(false);
  async function togglePortalAccess() {
    setPortalBusy(true);
    try {
      const enabled = (employee.user?.status ?? "Active") !== "Active";
      await api.updatePortalAccess(employee.id, enabled);
      onSaved();
    } catch (e: any) {
      alert(e.message || "Failed to update portal access.");
    } finally {
      setPortalBusy(false);
    }
  }

  const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";
  const field = (label: string, value: any) => (
    <div>
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="text-sm font-medium">{value ?? "—"}</div>
    </div>
  );

  // Compact, dense field style for the wide Edit Employee panel — same
  // pattern as the Indian Patent Invoice/Quotation form in Financial.tsx and
  // the Add/Edit Employee modal in HRMS/Employees.tsx.
  const fInp = "w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";
  const fTa = "w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none";
  const FLbl = ({ children }: { children: React.ReactNode }) => (
    <div className="text-[10px] text-muted-foreground mb-0.5">{children}</div>
  );
  const FSectionHeading = ({ n, children }: { n: number; children: React.ReactNode }) => (
    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">{n}</span>
      {children}
    </h3>
  );

  if (editing) {
    return (
      <Card className="border-border max-w-5xl">
        <CardContent className="p-6 space-y-5">
          <div className="flex items-center justify-between">
            <h3 className="font-display text-base font-semibold">Edit Employee</h3>
            <button onClick={() => setEditing(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
          </div>
          {error && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive">{error}</div>}

          <section>
            <FSectionHeading n={1}>Basic Information</FSectionHeading>
            <div className="grid grid-cols-4 gap-2">
              <div className="col-span-2"><FLbl>Full Name *</FLbl><input value={form.full_name} onChange={(e) => setForm((p: any) => ({ ...p, full_name: e.target.value }))} className={fInp} /></div>
              <div className="col-span-2"><FLbl>Work Email *</FLbl><input type="email" value={form.work_email} onChange={(e) => setForm((p: any) => ({ ...p, work_email: e.target.value }))} className={fInp} /></div>
              <div className="col-span-2"><FLbl>Father's Name</FLbl><input value={form.father_name} onChange={(e) => setForm((p: any) => ({ ...p, father_name: e.target.value }))} className={fInp} /></div>
              <div><FLbl>Phone</FLbl><input value={form.phone} onChange={(e) => setForm((p: any) => ({ ...p, phone: e.target.value }))} className={fInp} /></div>
              <div><FLbl>Mobile</FLbl><input value={form.mobile} onChange={(e) => setForm((p: any) => ({ ...p, mobile: e.target.value }))} className={fInp} /></div>
              <div>
                <FLbl>Gender</FLbl>
                <select value={form.gender} onChange={(e) => setForm((p: any) => ({ ...p, gender: e.target.value }))} className={fInp}>
                  <option value="">Select…</option>
                  {GENDERS.map((g) => <option key={g}>{g}</option>)}
                </select>
              </div>
              <div>
                <FLbl>Department</FLbl>
                <select value={form.department_name} onChange={(e) => setForm((p: any) => ({ ...p, department_name: e.target.value }))} className={fInp}>
                  <option value="">Select dept</option>{DEPTS.map((d) => <option key={d}>{d}</option>)}
                </select>
              </div>
              <div>
                <FLbl>Designation</FLbl>
                <select value={form.designation_title} onChange={(e) => setForm((p: any) => ({ ...p, designation_title: e.target.value }))} className={fInp}>
                  <option value="">Select role</option>{ROLES.map((r) => <option key={r}>{r}</option>)}
                </select>
              </div>
              <div>
                <FLbl>Location</FLbl>
                <select value={form.work_location} onChange={(e) => setForm((p: any) => ({ ...p, work_location: e.target.value }))} className={fInp}>
                  {!LOCATIONS.includes(form.work_location) && form.work_location && <option value={form.work_location}>{form.work_location}</option>}
                  {LOCATIONS.map((l) => <option key={l}>{l}</option>)}
                </select>
              </div>
              <div><FLbl>Date of Joining</FLbl><input type="date" value={form.date_of_joining} onChange={(e) => setForm((p: any) => ({ ...p, date_of_joining: e.target.value }))} className={fInp} /></div>
              <div>
                <FLbl>Employment Type</FLbl>
                <select value={form.employment_type} onChange={(e) => setForm((p: any) => ({ ...p, employment_type: e.target.value }))} className={fInp}>
                  {["Full-time", "Part-time", "Contract", "Internship"].map((t) => <option key={t}>{t}</option>)}
                </select>
              </div>
              <div><FLbl>Base Pay (₹, monthly)</FLbl><input type="number" value={form.base_pay_monthly} onChange={(e) => setForm((p: any) => ({ ...p, base_pay_monthly: e.target.value }))} className={fInp} /></div>
              <div><FLbl>HRA (₹, monthly, flat)</FLbl><input type="number" value={form.hra_monthly} onChange={(e) => setForm((p: any) => ({ ...p, hra_monthly: e.target.value }))} className={fInp} /></div>
            </div>
          </section>

          <section>
            <FSectionHeading n={2}>Personal &amp; Address</FSectionHeading>
            <div className="grid grid-cols-4 gap-2">
              <div><FLbl>Date of Birth</FLbl><input type="date" value={form.date_of_birth} onChange={(e) => setForm((p: any) => ({ ...p, date_of_birth: e.target.value }))} className={fInp} /></div>
              <div className="col-span-3">
                <FLbl>Differently Abled Type</FLbl>
                <select value={form.differently_abled_type} onChange={(e) => setForm((p: any) => ({ ...p, differently_abled_type: e.target.value }))} className={fInp}>
                  <option value="">Select…</option>
                  {DIFFERENTLY_ABLED_TYPES.map((t) => <option key={t}>{t}</option>)}
                </select>
              </div>
              <div className="col-span-2"><FLbl>Current Address</FLbl><textarea value={form.current_address} rows={2} onChange={(e) => setForm((p: any) => ({ ...p, current_address: e.target.value }))} className={fTa} /></div>
              <div className="col-span-2"><FLbl>Permanent Address</FLbl><textarea value={form.permanent_address} rows={2} onChange={(e) => setForm((p: any) => ({ ...p, permanent_address: e.target.value }))} className={fTa} /></div>
            </div>
          </section>

          <section>
            <FSectionHeading n={3}>Statutory &amp; Bank Details</FSectionHeading>
            <div className="grid grid-cols-4 gap-2">
              <div><FLbl>Aadhaar Number</FLbl><input value={form.aadhaar_ssn_encrypted} onChange={(e) => setForm((p: any) => ({ ...p, aadhaar_ssn_encrypted: e.target.value }))} className={fInp} /></div>
              <div><FLbl>PAN</FLbl><input value={form.pan_tax_id} onChange={(e) => setForm((p: any) => ({ ...p, pan_tax_id: e.target.value.toUpperCase() }))} className={fInp} /></div>
              <div><FLbl>UAN / PF Number</FLbl><input value={form.uan_pf_number} onChange={(e) => setForm((p: any) => ({ ...p, uan_pf_number: e.target.value }))} className={fInp} /></div>
              <div><FLbl>ESI Number</FLbl><input value={form.esi_number} onChange={(e) => setForm((p: any) => ({ ...p, esi_number: e.target.value }))} className={fInp} /></div>
              <div><FLbl>NPS Number</FLbl><input value={form.nps_number} onChange={(e) => setForm((p: any) => ({ ...p, nps_number: e.target.value }))} className={fInp} /></div>
              <div className="col-span-2"><FLbl>Bank Name</FLbl><input value={form.bank_name} onChange={(e) => setForm((p: any) => ({ ...p, bank_name: e.target.value }))} className={fInp} /></div>
              <div><FLbl>Bank Account Number</FLbl><input value={form.bank_account_number} onChange={(e) => setForm((p: any) => ({ ...p, bank_account_number: e.target.value }))} className={fInp} /></div>
              <div><FLbl>Bank IFSC Code</FLbl><input value={form.bank_ifsc_code} onChange={(e) => setForm((p: any) => ({ ...p, bank_ifsc_code: e.target.value.toUpperCase() }))} className={fInp} /></div>
              <div className="col-span-2">
                <label className="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer">
                  <input type="checkbox" checked={form.professional_tax_exempt} onChange={(e) => setForm((p: any) => ({ ...p, professional_tax_exempt: e.target.checked }))} className="h-4 w-4 rounded border-border" />
                  Exempt from Professional Tax
                </label>
              </div>
              <div className="col-span-2">
                <label className="flex items-center gap-2 text-xs text-muted-foreground cursor-pointer" title="This person will never be included in a payroll run (e.g. a system/admin account that doesn't draw a salary).">
                  <input type="checkbox" checked={form.exclude_from_payroll} onChange={(e) => setForm((p: any) => ({ ...p, exclude_from_payroll: e.target.checked }))} className="h-4 w-4 rounded border-border" />
                  Exclude from Payroll
                </label>
              </div>
            </div>
          </section>

          <div className="flex gap-2 pt-2">
            <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleSave} disabled={saving}>
              {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Save Changes
            </Button>
            <Button variant="outline" onClick={() => setEditing(false)}>Cancel</Button>
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl">
      <Card className="border-border">
        <CardContent className="p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-display text-base font-semibold">Basic Information</h3>
            {canEdit && <button onClick={startEdit} className="p-1 rounded hover:bg-muted"><Edit2 className="h-4 w-4 text-muted-foreground" /></button>}
          </div>
          <div className="grid grid-cols-2 gap-x-8 gap-y-4">
            {field("Name", employee.user?.name || employee.full_name)}
            {field("Work Location", employee.work_location)}
            {field("Father's Name", employee.father_name)}
            {field("Designation", employee.designation?.title)}
            {field("Email Address", employee.user?.email || employee.work_email)}
            {field("Department", employee.department?.name)}
            {field("Phone", employee.phone)}
            {field("Employment Type", employee.employment_type)}
            {field("Mobile Number", employee.mobile)}
            {field("Date of Joining", employee.date_of_joining ? new Date(employee.date_of_joining).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" }) : null)}
            {field("Gender", employee.gender)}
            {canEdit && (
              <div>
                <div className="text-xs text-muted-foreground">Portal Access</div>
                <PortalAccessInlineToggle employee={employee} onChanged={onSaved} />
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      <Card className="border-border">
        <CardContent className="p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-display text-base font-semibold">Personal Information</h3>
            {canEdit && <button onClick={startEdit} className="p-1 rounded hover:bg-muted"><Edit2 className="h-4 w-4 text-muted-foreground" /></button>}
          </div>
          <div className="grid grid-cols-2 gap-x-8 gap-y-4">
            {field("Date of Birth", employee.date_of_birth ? new Date(employee.date_of_birth).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" }) : null)}
            <div>
              <div className="text-xs text-muted-foreground">PAN</div>
              <div className="text-sm font-medium flex items-center gap-2 flex-wrap">
                {employee.pan_tax_id ?? "—"}
                {employee.pan_tax_id && (
                  <Badge variant="outline" className={`text-[10px] ${
                    employee.pan_verification_status === "Verified" ? "bg-green-100 text-green-700 border-green-200"
                    : employee.pan_verification_status === "NameMismatch" ? "bg-amber-100 text-amber-700 border-amber-200"
                    : employee.pan_verification_status === "Failed" ? "bg-red-100 text-red-700 border-red-200"
                    : "bg-muted text-muted-foreground border-border"
                  }`}>{employee.pan_verification_status ?? "Unverified"}</Badge>
                )}
                {canEdit && employee.pan_tax_id && employee.pan_verification_status !== "Verified" && (
                  <Button size="sm" variant="outline" className="h-6 px-2 text-[10px]" disabled={panBusy} onClick={verifyPan}>
                    {panBusy ? "Verifying…" : "Verify"}
                  </Button>
                )}
              </div>
              {panMessage && <div className="text-[11px] text-muted-foreground mt-0.5">{panMessage}</div>}
            </div>
            <div>
              <div className="text-xs text-muted-foreground">Aadhaar Number</div>
              <div className="text-sm font-medium flex items-center gap-2 flex-wrap">
                {employee.aadhaar_ssn_encrypted ?? "—"}
                {employee.aadhaar_ssn_encrypted && (
                  <Badge variant="outline" className={`text-[10px] ${
                    employee.aadhaar_verification_status === "Verified" ? "bg-green-100 text-green-700 border-green-200"
                    : employee.aadhaar_verification_status === "Failed" ? "bg-red-100 text-red-700 border-red-200"
                    : "bg-muted text-muted-foreground border-border"
                  }`}>{employee.aadhaar_verification_status ?? "Unverified"}</Badge>
                )}
                {canEdit && employee.aadhaar_ssn_encrypted && employee.aadhaar_verification_status !== "Verified" && (
                  <Button size="sm" variant="outline" className="h-6 px-2 text-[10px]" disabled={digilockerBusy} onClick={startDigilockerVerification}>
                    {digilockerBusy ? "Redirecting…" : "Verify via DigiLocker"}
                  </Button>
                )}
              </div>
              {digilockerMessage && <div className="text-[11px] text-muted-foreground mt-0.5">{digilockerMessage}</div>}
            </div>
            {field("Differently Abled Type", employee.differently_abled_type || "None")}
            {field("Current Address", employee.current_address)}
            {field("UAN / PF Number", employee.uan_pf_number)}
            {field("Permanent Address", employee.permanent_address)}
            {field("ESI Number", employee.esi_number)}
            {field("NPS Number", employee.nps_number)}
            {field("Professional Tax", employee.professional_tax_exempt ? "Exempt" : "Applicable")}
            {field("Payroll Participation", employee.exclude_from_payroll ? "Excluded" : "Included")}
          </div>
        </CardContent>
      </Card>

      <Card className="border-border">
        <CardContent className="p-6">
          <div className="flex items-center justify-between mb-4">
            <h3 className="font-display text-base font-semibold">Payment Information</h3>
            {canEdit && <button onClick={startEdit} className="p-1 rounded hover:bg-muted"><Edit2 className="h-4 w-4 text-muted-foreground" /></button>}
          </div>
          <div className="grid grid-cols-2 gap-x-8 gap-y-4">
            {field("Bank Name", employee.bank_name)}
            {field("IFSC", employee.bank_ifsc_code)}
            {field("Account Number", employee.bank_account_number)}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

/* ───────────────────────── Salary Details ───────────────────────── */

function SalaryTab({ employee }: { employee: any }) {
  const [components, setComponents] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.getSalaryComponents().then(setComponents).catch(() => setComponents([])).finally(() => setLoading(false));
  }, []);

  const basePay = Number(employee.base_pay_monthly || 0);
  const hra = Number(employee.hra_monthly || 0);
  const breakdown = useMemo(() => computeSalaryBreakdown(components, basePay, hra), [components, basePay, hra]);

  if (loading) return <div className="flex justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>;

  return (
    <div className="space-y-6 max-w-3xl">
      <div className="grid grid-cols-2 gap-4">
        <Card className="border-border"><CardContent className="p-4">
          <div className="text-xs text-muted-foreground">Monthly CTC</div>
          <div className="text-2xl font-bold">{fmt(breakdown.totalCtc)}</div>
        </CardContent></Card>
        <Card className="border-border"><CardContent className="p-4">
          <div className="text-xs text-muted-foreground">Annual CTC</div>
          <div className="text-2xl font-bold">{fmt(breakdown.totalCtc * 12)}</div>
        </CardContent></Card>
      </div>

      <Card className="border-border">
        <CardContent className="p-0">
          <table className="w-full text-sm">
            <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
              <tr><th className="px-4 py-2.5 text-left">Salary Component</th><th className="px-4 py-2.5 text-right">Monthly</th><th className="px-4 py-2.5 text-right">Annual</th></tr>
            </thead>
            <tbody>
              <tr><td colSpan={3} className="px-4 py-2 text-xs font-semibold text-muted-foreground bg-muted/20">Earnings</td></tr>
              {breakdown.earningRows.map((r) => (
                <tr key={r.name} className="border-t border-border">
                  <td className="px-4 py-2.5">{r.name}{r.label && <div className="text-[10px] text-muted-foreground">{r.label}</div>}</td>
                  <td className="px-4 py-2.5 text-right">{fmt(r.amount)}</td>
                  <td className="px-4 py-2.5 text-right">{fmt(r.amount * 12)}</td>
                </tr>
              ))}
              {breakdown.ctcAddonRows.length > 0 && (
                <>
                  <tr><td colSpan={3} className="px-4 py-2 text-xs font-semibold text-muted-foreground bg-muted/20">CTC Add-ons (employer cost)</td></tr>
                  {breakdown.ctcAddonRows.map((r) => (
                    <tr key={r.name} className="border-t border-border">
                      <td className="px-4 py-2.5">{r.name}{r.label && <div className="text-[10px] text-muted-foreground">{r.label}</div>}</td>
                      <td className="px-4 py-2.5 text-right">{fmt(r.amount)}</td>
                      <td className="px-4 py-2.5 text-right">{fmt(r.amount * 12)}</td>
                    </tr>
                  ))}
                </>
              )}
              <tr className="border-t-2 border-border bg-muted/20 font-semibold">
                <td className="px-4 py-2.5">Cost to Company</td>
                <td className="px-4 py-2.5 text-right">{fmt(breakdown.totalCtc)}</td>
                <td className="px-4 py-2.5 text-right">{fmt(breakdown.totalCtc * 12)}</td>
              </tr>
            </tbody>
          </table>
        </CardContent>
      </Card>
      <p className="text-xs text-muted-foreground">
        Salary components are configured once for all employees in <Link href="/settings" target="_blank" className="text-gold hover:underline">Settings → Payroll</Link>.
        To change this employee's Base Pay/HRA, use Edit on the Overview tab.
      </p>
    </div>
  );
}

/* ───────────────────────── Investments ───────────────────────── */

function InvestmentsTab({ employee, canEdit }: { employee: any; canEdit: boolean }) {
  const [fiscalYear, setFiscalYear] = useState(currentFiscalYear());
  const [declarations, setDeclarations] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ regime_choice: "new", section_80c_amount: "0", section_80d_amount: "0", hra_rent_paid: "0" });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  function load() {
    setLoading(true);
    api.getInvestmentDeclarationsForEmployee(employee.id).then(setDeclarations).catch(() => setDeclarations([])).finally(() => setLoading(false));
  }
  useEffect(() => { load(); }, [employee.id]);

  const current = declarations.find((d) => d.financial_year === fiscalYear) || null;
  useEffect(() => {
    if (current) {
      setForm({
        regime_choice: current.regime_choice,
        section_80c_amount: String(current.section_80c_amount ?? 0),
        section_80d_amount: String(current.section_80d_amount ?? 0),
        hra_rent_paid: String(current.hra_rent_paid ?? 0),
      });
    } else {
      setForm({ regime_choice: "new", section_80c_amount: "0", section_80d_amount: "0", hra_rent_paid: "0" });
    }
  }, [current?.id, fiscalYear]);

  async function saveDraft() {
    setBusy(true);
    setError("");
    try {
      await api.saveInvestmentDeclarationForEmployee(employee.id, {
        financial_year: fiscalYear,
        regime_choice: form.regime_choice as "old" | "new",
        section_80c_amount: parseFloat(form.section_80c_amount) || 0,
        section_80d_amount: parseFloat(form.section_80d_amount) || 0,
        hra_rent_paid: parseFloat(form.hra_rent_paid) || 0,
      });
      load();
    } catch (e: any) {
      setError(e.message || "Failed to save.");
    } finally {
      setBusy(false);
    }
  }

  async function submit() {
    if (!current || !confirm("Submit this declaration? HR review will be required to change it after this.")) return;
    setBusy(true);
    setError("");
    try {
      await api.submitInvestmentDeclarationForEmployee(employee.id, current.id);
      load();
    } catch (e: any) {
      setError(e.message || "Failed to submit.");
    } finally {
      setBusy(false);
    }
  }

  const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";
  const editable = canEdit && (!current || current.status === "Draft");

  return (
    <div className="max-w-2xl space-y-4">
      <div className="flex items-center gap-3">
        <label className="text-xs text-muted-foreground">Financial Year</label>
        <select value={fiscalYear} onChange={(e) => setFiscalYear(Number(e.target.value))}
          className="h-8 rounded-md border border-border bg-background px-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
          {[currentFiscalYear() + 1, currentFiscalYear(), currentFiscalYear() - 1].map((fy) => (
            <option key={fy} value={fy}>FY {fy}-{String(fy + 1).slice(-2)}</option>
          ))}
        </select>
        {current && <Badge variant="outline" className={`text-[10px] ${statusBadgeClass(current.status)}`}>{current.status}</Badge>}
      </div>

      {loading ? (
        <div className="flex justify-center py-10"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
      ) : (
        <Card className="border-border">
          <CardContent className="p-5 space-y-4">
            {error && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive">{error}</div>}
            {!editable && !current && <p className="text-sm text-muted-foreground">No declaration submitted for this year.</p>}
            {!editable && current && (
              <p className="text-xs text-muted-foreground">
                This declaration is {current.status.toLowerCase()} and can no longer be edited here.
                {current.status === "Submitted" && (
                  <> Approve or reject it from <Link href="/payroll-portal/approvals/proof-of-investments" className="text-gold hover:underline">Approvals → Proof of Investments</Link>.</>
                )}
              </p>
            )}
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Tax Regime</label>
                <select value={form.regime_choice} disabled={!editable} onChange={(e) => setForm((p) => ({ ...p, regime_choice: e.target.value }))} className={`${inputCls} disabled:opacity-60`}>
                  <option value="new">New Regime</option>
                  <option value="old">Old Regime</option>
                </select>
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">HRA Rent Paid (₹/yr)</label>
                <input type="number" disabled={!editable} value={form.hra_rent_paid} onChange={(e) => setForm((p) => ({ ...p, hra_rent_paid: e.target.value }))} className={`${inputCls} disabled:opacity-60`} />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Section 80C (max 1,50,000)</label>
                <input type="number" disabled={!editable} value={form.section_80c_amount} onChange={(e) => setForm((p) => ({ ...p, section_80c_amount: e.target.value }))} className={`${inputCls} disabled:opacity-60`} />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Section 80D (max 1,00,000)</label>
                <input type="number" disabled={!editable} value={form.section_80d_amount} onChange={(e) => setForm((p) => ({ ...p, section_80d_amount: e.target.value }))} className={`${inputCls} disabled:opacity-60`} />
              </div>
            </div>
            {editable && (
              <div className="flex gap-2">
                <Button size="sm" variant="outline" disabled={busy} onClick={saveDraft}>{busy ? <Loader2 className="h-3.5 w-3.5 animate-spin mr-1.5" /> : null}Save Draft</Button>
                {current && <Button size="sm" className="bg-gold hover:bg-gold/90 text-black" disabled={busy} onClick={submit}>Submit Declaration</Button>}
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  );
}

/* ───────────────────────── Payslips & Forms ───────────────────────── */

function PayslipsTab({ employee }: { employee: any }) {
  const [fiscalYear, setFiscalYear] = useState(currentFiscalYear());
  const [slips, setSlips] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [form16, setForm16] = useState<any | null>(null);

  useEffect(() => {
    setLoading(true);
    Promise.all([
      api.getEmployeePayslips(employee.id, fiscalYear).catch(() => []),
      api.getForm16Documents(fiscalYear).catch(() => []),
    ]).then(([slipRows, docs]) => {
      setSlips(slipRows);
      setForm16(docs.find((d: any) => d.employee_id === employee.id) || null);
    }).finally(() => setLoading(false));
  }, [employee.id, fiscalYear]);

  return (
    <div className="max-w-4xl space-y-4">
      <div className="flex items-center gap-3">
        <label className="text-xs text-muted-foreground">Financial Year</label>
        <select value={fiscalYear} onChange={(e) => setFiscalYear(Number(e.target.value))}
          className="h-8 rounded-md border border-border bg-background px-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
          {[currentFiscalYear(), currentFiscalYear() - 1, currentFiscalYear() - 2].map((fy) => (
            <option key={fy} value={fy}>FY {fy}-{String(fy + 1).slice(-2)}</option>
          ))}
        </select>
        <a href={api.yearlyPayslipPdfUrl(employee.id, fiscalYear)} target="_blank" rel="noreferrer">
          <Button size="sm" variant="outline"><Download className="h-3.5 w-3.5 mr-1.5" />Yearly Payslip PDF</Button>
        </a>
      </div>

      <div className="grid grid-cols-3 gap-6">
        <div className="col-span-2">
          {loading ? (
            <div className="flex justify-center py-10"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
          ) : (
            <Card className="border-border">
              <CardContent className="p-0">
                {slips.length === 0 ? (
                  <p className="text-sm text-muted-foreground text-center py-10">No payslips for this financial year.</p>
                ) : (
                  <table className="w-full text-xs">
                    <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                      <tr><th className="px-3 py-2.5 text-left">Month</th><th className="px-3 py-2.5 text-right">Net Pay</th><th className="px-3 py-2.5 text-left">Status</th><th className="px-3 py-2.5 text-center">PDF</th></tr>
                    </thead>
                    <tbody>
                      {slips.map((s) => (
                        <tr key={s.id} className="border-t border-border hover:bg-muted/20">
                          <td className="px-3 py-2.5 font-medium">{s.run ? periodLabel(s.run.period) : "—"}</td>
                          <td className="px-3 py-2.5 text-right font-semibold text-green-600">{fmt(s.net_pay)}</td>
                          <td className="px-3 py-2.5"><Badge variant="outline" className={`text-[10px] ${statusBadgeClass(s.run?.status || "")}`}>{s.run?.status}</Badge></td>
                          <td className="px-3 py-2.5 text-center">
                            <button onClick={() => api.downloadPdf(api.payslipPdfUrl(s.id), `payslip-${s.id}.pdf`).catch((e) => window.alert(e.message))} className="inline-flex p-1 rounded hover:bg-muted"><Download className="h-3.5 w-3.5 text-muted-foreground" /></button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </CardContent>
            </Card>
          )}
        </div>
        <div>
          <h3 className="text-sm font-semibold mb-3">Form 16</h3>
          <Card className="border-border">
            <CardContent className="p-4 space-y-2">
              {form16 ? (
                <>
                  <Badge variant="outline" className={`text-[10px] ${statusBadgeClass(form16.status)}`}>{form16.status}</Badge>
                  <a href={api.form16DownloadUrl(employee.id, fiscalYear)} target="_blank" rel="noreferrer" className="block">
                    <Button size="sm" variant="outline" className="w-full mt-2"><Download className="h-3.5 w-3.5 mr-1.5" />Download</Button>
                  </a>
                </>
              ) : (
                <p className="text-xs text-muted-foreground">Form 16 hasn't been generated for this employee yet. Upload the TRACES Part A ZIP from <Link href="/payroll-portal/form-16" className="text-gold hover:underline">Form 16</Link>.</p>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}

/* ───────────────────────── Loans ───────────────────────── */

const BLANK_LOAN_FORM = { principal_amount: "", monthly_installment: "", start_period: new Date().toISOString().slice(0, 7), notes: "" };

function LoansTab({ employee, canEdit }: { employee: any; canEdit: boolean }) {
  const [loans, setLoans] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState<number | null>(null);
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState({ ...BLANK_LOAN_FORM });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  function load() {
    setLoading(true);
    api.getLoans().then((rows) => setLoans(rows.filter((l: any) => l.employee_id === employee.id))).catch(() => setLoans([])).finally(() => setLoading(false));
  }
  useEffect(() => { load(); }, [employee.id]);

  async function handleSave() {
    if (!form.principal_amount || !form.monthly_installment) { setError("Principal amount and monthly installment are required."); return; }
    setSaving(true);
    setError("");
    try {
      await api.createLoan({
        employee_id: employee.id,
        principal_amount: parseFloat(form.principal_amount),
        monthly_installment: parseFloat(form.monthly_installment),
        start_period: form.start_period,
        notes: form.notes || undefined,
      });
      setShowModal(false);
      setForm({ ...BLANK_LOAN_FORM });
      load();
    } catch (e: any) {
      setError(e.message || "Failed to create loan.");
    } finally {
      setSaving(false);
    }
  }

  async function handleClose(id: number) {
    if (!confirm("Close this loan? Any remaining balance will be waived.")) return;
    await api.closeLoan(id);
    load();
  }

  const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

  return (
    <div className="max-w-3xl space-y-4">
      {canEdit && (
        <div className="flex justify-end">
          <Button size="sm" className="bg-gold hover:bg-gold/90 text-black" onClick={() => setShowModal(true)}><Plus className="h-3.5 w-3.5 mr-1.5" />Create Loan</Button>
        </div>
      )}

      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Create Loan for {employee.user?.name || employee.full_name}</h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {error && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{error}</div>}
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <div><label className="block text-xs text-muted-foreground mb-1">Principal Amount (₹) *</label><input type="number" value={form.principal_amount} onChange={(e) => setForm((p) => ({ ...p, principal_amount: e.target.value }))} className={inputCls} /></div>
                <div><label className="block text-xs text-muted-foreground mb-1">Monthly Installment (₹) *</label><input type="number" value={form.monthly_installment} onChange={(e) => setForm((p) => ({ ...p, monthly_installment: e.target.value }))} className={inputCls} /></div>
              </div>
              <div><label className="block text-xs text-muted-foreground mb-1">Start Period</label><input type="month" value={form.start_period} onChange={(e) => setForm((p) => ({ ...p, start_period: e.target.value }))} className={inputCls} /></div>
              <div><label className="block text-xs text-muted-foreground mb-1">Notes (optional)</label><textarea value={form.notes} rows={2} onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))} className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold" /></div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleSave} disabled={saving}>{saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Give Loan</Button>
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-10"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
      ) : loans.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
          <Landmark className="h-10 w-10 opacity-20" />
          <p className="text-sm">This employee hasn't taken any loans yet.</p>
        </div>
      ) : (
        <Card className="border-border">
          <CardContent className="p-0">
            <table className="w-full text-xs">
              <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                <tr><th className="px-3 py-2.5 text-left">Loan</th><th className="px-3 py-2.5 text-right">Principal</th><th className="px-3 py-2.5 text-right">Installment</th><th className="px-3 py-2.5 text-right">Remaining</th><th className="px-3 py-2.5 text-left">Status</th>{canEdit && <th className="px-3 py-2.5 text-center">Action</th>}</tr>
              </thead>
              <tbody>
                {loans.map((loan) => (
                  <>
                    <tr key={loan.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-3 py-2.5">
                        <button className="flex items-center gap-1 hover:text-gold transition-colors" onClick={() => setExpanded((cur) => (cur === loan.id ? null : loan.id))}>
                          {expanded === loan.id ? <ChevronDown className="h-3 w-3" /> : <ChevronRight className="h-3 w-3" />}
                          Loan #{loan.id}
                        </button>
                      </td>
                      <td className="px-3 py-2.5 text-right">{fmt(loan.principal_amount)}</td>
                      <td className="px-3 py-2.5 text-right">{fmt(loan.monthly_installment)}</td>
                      <td className="px-3 py-2.5 text-right font-medium">{fmt(loan.remaining_balance)}</td>
                      <td className="px-3 py-2.5"><Badge variant="outline" className={`text-[10px] ${loan.status === "Active" ? "bg-amber-100 text-amber-700 border-amber-200" : "bg-green-100 text-green-700 border-green-200"}`}>{loan.status}</Badge></td>
                      {canEdit && <td className="px-3 py-2.5 text-center">{loan.status === "Active" && <Button size="sm" variant="outline" className="h-6 px-2 text-[10px] border-red-200 text-red-600" onClick={() => handleClose(loan.id)}>Close</Button>}</td>}
                    </tr>
                    {expanded === loan.id && (
                      <tr className="border-t border-border bg-muted/10">
                        <td colSpan={canEdit ? 6 : 5} className="px-3 py-2.5">
                          {loan.repayments?.length ? (
                            <div className="flex flex-wrap gap-x-5 gap-y-1 pl-5 text-[11px] text-muted-foreground">
                              {loan.repayments.map((r: any) => <span key={r.id}><span className="text-foreground font-medium">{new Date(r.period).toLocaleString("default", { month: "short", year: "numeric" })}:</span> {fmt(r.installment_amount)}</span>)}
                            </div>
                          ) : <p className="pl-5 text-[11px] text-muted-foreground">No repayments deducted yet.</p>}
                        </td>
                      </tr>
                    )}
                  </>
                ))}
              </tbody>
            </table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
