import { Head, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { Loader2, Plus, X, Edit2, Trash2, Search, Download } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api, downloadCSV } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";

const DEPTS = ["Legal", "Operations", "HR", "Finance", "Engineering", "Business Development", "Administration"];
const ROLES = ["Director", "HR", "Patent Attorney", "Patent Analyst", "Patent Engineer", "Finance Manager", "Paralegal", "Consultant", "System Admin"];
const LOCATIONS = ["Office", "Remote", "Coimbatore", "Chennai", "Bengaluru", "Hyderabad", "Pollachi"];
const EMPLOYMENT_STATUSES = ["Active", "On Probation", "On Leave", "Suspended", "Resigned", "Terminated"];
const GENDERS = ["Male", "Female", "Other", "Prefer not to say"];
const DIFFERENTLY_ABLED_TYPES = ["None", "Locomotor Disability", "Visual Impairment", "Hearing Impairment", "Intellectual Disability", "Multiple Disabilities", "Other"];

const blankForm = {
  employee_code: "", full_name: "", father_name: "", work_email: "", phone: "", mobile: "", gender: "",
  department_name: "", designation_title: "",
  work_location: "Coimbatore", date_of_joining: "", employment_type: "Full-time", base_pay_monthly: "", hra_monthly: "",
  professional_tax_exempt: false, exclude_from_payroll: false,
  date_of_birth: "", differently_abled_type: "", current_address: "", permanent_address: "",
  aadhaar_ssn_encrypted: "", pan_tax_id: "", uan_pf_number: "", esi_number: "",
  nps_number: "", bank_account_number: "", bank_name: "", bank_ifsc_code: "",
};
const HR_CRUD_ROLES = ["super_admin", "partner", "hr", "finance"];
// Salary/payroll data — director (partner), accountant (finance), and
// system admin only. Mirrors App\Models\User::PAYROLL_ACCESS_ROLES.
const PAYROLL_ACCESS_ROLES = ["super_admin", "partner", "finance"];

/** Same status->color mapping used on Payroll Portal's Employee Detail page. */
function verificationBadgeClass(status: string | undefined) {
  if (status === "Verified") return "bg-green-100 text-green-700 border-green-200";
  if (status === "NameMismatch") return "bg-amber-100 text-amber-700 border-amber-200";
  if (status === "Failed") return "bg-red-100 text-red-700 border-red-200";
  return "bg-muted text-muted-foreground border-border";
}

/** Compact, color-coded status badge that opens a small dropdown on click — same
 *  interaction as the status/stage badges on the Projects table. */
function StatusBadgeMenu({ status, disabled, onChange }: { status: string; disabled?: boolean; onChange: (s: string) => void }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    function onDocClick(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, [open]);

  return (
    <div ref={ref} className="relative inline-block">
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen(o => !o)}
        className="disabled:opacity-50"
      >
        <Badge variant={statusColor(status)} className="text-[10px] whitespace-nowrap cursor-pointer hover:opacity-80 transition-opacity">
          {status}
        </Badge>
      </button>
      {open && (
        <div className="absolute left-0 top-full mt-1 z-30 w-36 rounded-md border border-border bg-background shadow-lg py-1">
          {EMPLOYMENT_STATUSES.map(s => (
            <button
              key={s}
              onClick={() => { setOpen(false); if (s !== status) onChange(s); }}
              className={`w-full text-left px-2.5 py-1.5 text-xs hover:bg-muted flex items-center gap-1.5 ${s === status ? "font-semibold" : ""}`}
            >
              <Badge variant={statusColor(s)} className="text-[10px]">{s}</Badge>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

/** Enable/disable this employee's login access — writes immediately, same
 *  pattern as StatusBadgeMenu's status change (not batched into form Save). */
function PortalAccessToggle({ employee, onChanged }: { employee: any; onChanged: (status: string) => void }) {
  const [busy, setBusy] = useState(false);
  const enabled = (employee.user?.status ?? "Active") === "Active";

  async function toggle() {
    setBusy(true);
    try {
      const res = await api.updatePortalAccess(employee.id, !enabled);
      onChanged(res.status);
    } catch (e: any) {
      alert(e.message || "Failed to update portal access.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <button type="button" onClick={toggle} disabled={busy} className="flex items-center gap-2">
      {busy ? <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" /> : (
        <span className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${enabled ? "bg-gold" : "bg-muted"}`}>
          <span className="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform" style={{ transform: enabled ? "translateX(18px)" : "translateX(2px)" }} />
        </span>
      )}
      <span className="text-xs font-medium">{enabled ? "Enabled" : "Disabled"}</span>
    </button>
  );
}

export default function HRMSEmployees() {
  const { props } = usePage() as any;
  const userRole: string = props.auth?.user?.role ?? "";
  const canCRUD = HR_CRUD_ROLES.includes(userRole);
  const canSeePayroll = PAYROLL_ACCESS_ROLES.includes(userRole);

  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [statusSaving, setStatusSaving] = useState<number | null>(null);

  const [showModal, setShowModal] = useState(false);
  const [editEmp, setEditEmp] = useState<any | null>(null);
  const [form, setForm] = useState(blankForm);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");

  const [panBusy, setPanBusy] = useState(false);
  const [panMessage, setPanMessage] = useState("");
  const [digilockerBusy, setDigilockerBusy] = useState(false);
  const [digilockerMessage, setDigilockerMessage] = useState("");

  async function verifyPan() {
    if (!editEmp) return;
    setPanBusy(true);
    setPanMessage("");
    try {
      const result = await api.verifyEmployeePan(editEmp.id);
      setPanMessage(result.message);
      const patch = { pan_verification_status: result.status, pan_verified_name: result.fetched?.name ?? result.name_on_record ?? null, pan_verified_dob: result.fetched?.dob ?? result.dob_on_record ?? null };
      setEditEmp((prev: any) => ({ ...prev, ...patch }));
      setEmployees(prev => prev.map(e => e.id === editEmp.id ? { ...e, ...patch } : e));
    } catch (e: any) {
      setPanMessage(e.message || "Failed to verify PAN.");
    } finally {
      setPanBusy(false);
    }
  }

  async function startDigilockerVerification() {
    if (!editEmp) return;
    setDigilockerBusy(true);
    setDigilockerMessage("");
    try {
      const { consent_url } = await api.startDigiLockerConsent(editEmp.id, "AADHAAR");
      window.location.href = consent_url;
    } catch (e: any) {
      setDigilockerMessage(e.message || "Failed to start DigiLocker verification.");
      setDigilockerBusy(false);
    }
  }

  useEffect(() => {
    api.getEmployees().then(setEmployees).catch(() => {}).finally(() => setLoading(false));
  }, []);

  const filtered = employees.filter(e => {
    if (statusFilter && (e.employment_status || "Active") !== statusFilter) return false;
    const q = search.toLowerCase();
    return !search ||
      (e.user?.name || e.full_name || "").toLowerCase().includes(q) ||
      (e.designation?.title || "").toLowerCase().includes(q) ||
      (e.department?.name || "").toLowerCase().includes(q);
  });

  async function handleStatusChange(id: number, employment_status: string) {
    setStatusSaving(id);
    try {
      const updated = await api.updateEmployee(id, { employment_status } as any);
      setEmployees(prev => prev.map(e => e.id === id ? { ...e, ...updated } : e));
    } catch (e: any) { alert(e.message || "Failed to update status."); }
    finally { setStatusSaving(null); }
  }

  function openNew() {
    setEditEmp(null);
    setForm(blankForm);
    setSaveError("");
    setShowModal(true);
  }

  function openEdit(e: any) {
    setEditEmp(e);
    setForm({
      employee_code: e.employee_code || "",
      full_name: e.user?.name || e.full_name || "",
      father_name: e.father_name || "",
      work_email: e.user?.email || e.work_email || "",
      phone: e.phone || "",
      mobile: e.mobile || "",
      gender: e.gender || "",
      department_name: e.department?.name || "",
      designation_title: e.designation?.title || "",
      work_location: e.work_location || "Coimbatore",
      date_of_joining: e.date_of_joining || "",
      employment_type: e.employment_type || "Full-time",
      base_pay_monthly: e.base_pay_monthly?.toString() || "",
      hra_monthly: e.hra_monthly?.toString() || "",
      professional_tax_exempt: !!e.professional_tax_exempt,
      exclude_from_payroll: !!e.exclude_from_payroll,
      date_of_birth: e.date_of_birth || "",
      differently_abled_type: e.differently_abled_type || "",
      current_address: e.current_address || "",
      permanent_address: e.permanent_address || "",
      aadhaar_ssn_encrypted: e.aadhaar_ssn_encrypted || "",
      pan_tax_id: e.pan_tax_id || "",
      uan_pf_number: e.uan_pf_number || "",
      esi_number: e.esi_number || "",
      nps_number: e.nps_number || "",
      bank_account_number: e.bank_account_number || "",
      bank_name: e.bank_name || "",
      bank_ifsc_code: e.bank_ifsc_code || "",
    });
    setSaveError("");
    setShowModal(true);
  }

  async function handleSave() {
    if (!form.full_name.trim() || !form.work_email.trim()) { setSaveError("Name and email are required."); return; }
    setSaving(true); setSaveError("");
    try {
      // Nullable validation rules (digits:12, PAN/IFSC regex) reject an empty string —
      // only `null` satisfies "nullable" on the backend, so blank fields must convert.
      const blankToNull = (v: string) => v.trim() ? v.trim() : null;
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
      if (editEmp) {
        const updated = await api.updateEmployee(editEmp.id, payload as any);
        setEmployees(prev => prev.map(e => e.id === editEmp.id ? { ...e, ...updated } : e));
      } else {
        const created = await api.createEmployee(payload as any);
        setEmployees(prev => [created, ...prev]);
      }
      setShowModal(false);
    } catch (e: any) { setSaveError(e.message || "Failed to save employee."); }
    finally { setSaving(false); }
  }

  async function handleDelete(id: number) {
    if (!confirm("This will set the employee as Terminated. Continue?")) return;
    try {
      await api.deleteEmployee(id);
      setEmployees(prev => prev.map(e => e.id === id ? { ...e, employment_status: "Terminated" } : e));
    } catch (e: any) { alert(e.message || "Failed."); }
  }

  function handleExport() {
    const rows = filtered.map(e => ({
      Code: e.employee_code, Name: e.user?.name || e.full_name, Email: e.user?.email,
      Department: e.department?.name, Role: e.designation?.title,
      Location: e.work_location, Status: e.employment_status,
    }));
    downloadCSV(`employees-${new Date().toISOString().slice(0,10)}.csv`, rows);
  }

  // Compact, dense field style for the wide Add/Edit Employee modal — same
  // pattern as the Indian Patent Invoice/Quotation form in Financial.tsx.
  const inp = "w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";
  const Lbl = ({ children, req }: { children: ReactNode; req?: boolean }) => (
    <div className="text-[10px] text-muted-foreground mb-0.5">{children}{req && <span className="text-destructive ml-0.5">*</span>}</div>
  );
  const SectionHeading = ({ n, children }: { n: number; children: ReactNode }) => (
    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">{n}</span>
      {children}
    </h3>
  );

  /** One row of a government-verification pre-fill panel: shows the fetched
   *  value next to a "Use this value" button when it differs from the form's
   *  current value. Hidden entirely once the two already match. */
  const GovFieldRow = ({ label, current, fetched, onUse }: { label: string; current: string; fetched?: string | null; onUse: () => void }) => {
    if (!fetched) return null;
    if (current.trim().toLowerCase() === fetched.trim().toLowerCase()) return null;
    return (
      <div className="flex items-center justify-between gap-2 text-[10px] py-0.5">
        <span className="text-muted-foreground truncate">{label} on record: <span className="text-foreground">{fetched}</span></span>
        <button type="button" onClick={onUse} className="shrink-0 text-gold hover:underline font-medium">Use this value</button>
      </div>
    );
  };

  return (
    <AppLayout>
      <Head title="Employees" />
      <PageHeader
        eyebrow="HRMS"
        title="Employees"
        description={`${employees.length} team members`}
        actions={
          <>
            <Button variant="outline" onClick={handleExport}><Download className="h-4 w-4 mr-2" />Export CSV</Button>
            {canCRUD && <Button onClick={openNew}><Plus className="h-4 w-4 mr-2" />Add Employee</Button>}
          </>
        }
      />

      {showModal && (
        <div className="fixed inset-0 z-50 flex items-start justify-center bg-black/60 backdrop-blur-sm overflow-y-auto py-6 px-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-5xl flex flex-col">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-border sticky top-0 bg-background z-10 rounded-t-xl">
              <h2 className="font-display text-base font-semibold">{editEmp ? "Edit Employee" : "Add Employee"}</h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>

            <div className="overflow-y-auto px-6 py-4 space-y-5">
              {saveError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive">{saveError}</div>}

              {/* ── 1. Basic Info ── */}
              <section>
                <SectionHeading n={1}>Basic Info</SectionHeading>
                <div className="grid grid-cols-4 gap-2">
                  <div>
                    <Lbl>Employee Code{!editEmp && <span className="normal-case"> (auto if blank)</span>}</Lbl>
                    <input value={form.employee_code} onChange={e => setForm(p => ({ ...p, employee_code: e.target.value }))} placeholder="e.g. EMP-2026-0042" className={inp} />
                  </div>
                  <div>
                    <Lbl req>Full Name</Lbl>
                    <input value={form.full_name} onChange={e => setForm(p => ({ ...p, full_name: e.target.value }))} placeholder="e.g. Priya Sharma" className={inp} />
                  </div>
                  <div>
                    <Lbl req>Work Email</Lbl>
                    <input type="email" value={form.work_email} onChange={e => setForm(p => ({ ...p, work_email: e.target.value }))} placeholder="priya@firm.com" className={inp} />
                  </div>
                  <div>
                    <Lbl>Father's Name</Lbl>
                    <input value={form.father_name} onChange={e => setForm(p => ({ ...p, father_name: e.target.value }))} className={inp} />
                  </div>
                  <div>
                    <Lbl>Mobile Number</Lbl>
                    <input value={form.mobile} onChange={e => setForm(p => ({ ...p, mobile: e.target.value }))} className={inp} />
                  </div>
                  <div>
                    <Lbl>Gender</Lbl>
                    <select value={form.gender} onChange={e => setForm(p => ({ ...p, gender: e.target.value }))} className={inp}>
                      <option value="">Select…</option>
                      {GENDERS.map(g => <option key={g}>{g}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Department</Lbl>
                    <select value={form.department_name} onChange={e => setForm(p => ({ ...p, department_name: e.target.value }))} className={inp}>
                      <option value="">Select dept</option>
                      {DEPTS.map(d => <option key={d}>{d}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Designation</Lbl>
                    <select value={form.designation_title} onChange={e => setForm(p => ({ ...p, designation_title: e.target.value }))} className={inp}>
                      <option value="">Select role</option>
                      {ROLES.map(r => <option key={r}>{r}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Location</Lbl>
                    <select value={form.work_location} onChange={e => setForm(p => ({ ...p, work_location: e.target.value }))} className={inp}>
                      {/* Always include the current value as an option — even a legacy one like
                          "Office" that predates this list — so the select never silently falls
                          back to the first option while the underlying value stays unchanged
                          (that mismatch was why picking "Remote" could appear to save as "Office"). */}
                      {!LOCATIONS.includes(form.work_location) && form.work_location && (
                        <option value={form.work_location}>{form.work_location}</option>
                      )}
                      {LOCATIONS.map(l => <option key={l}>{l}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Date of Joining</Lbl>
                    <input type="date" value={form.date_of_joining} onChange={e => setForm(p => ({ ...p, date_of_joining: e.target.value }))} className={inp} />
                  </div>
                  <div>
                    <Lbl>Employment Type</Lbl>
                    <select value={form.employment_type} onChange={e => setForm(p => ({ ...p, employment_type: e.target.value }))} className={inp}>
                      {["Full-time", "Part-time", "Contract", "Internship"].map(t => <option key={t}>{t}</option>)}
                    </select>
                  </div>
                  {canSeePayroll && (
                    <div>
                      <Lbl>Base Pay (₹, monthly)</Lbl>
                      <input type="number" value={form.base_pay_monthly} onChange={e => setForm(p => ({ ...p, base_pay_monthly: e.target.value }))} placeholder="e.g. 150000" className={inp} />
                    </div>
                  )}
                  {canSeePayroll && (
                    <div>
                      <Lbl>HRA (₹, monthly, flat)</Lbl>
                      <input type="number" value={form.hra_monthly} onChange={e => setForm(p => ({ ...p, hra_monthly: e.target.value }))} placeholder="e.g. 25000" className={inp} />
                    </div>
                  )}
                </div>
              </section>

              {/* ── 2. Personal & Address ── */}
              <section>
                <SectionHeading n={2}>Personal &amp; Address</SectionHeading>
                <div className="grid grid-cols-4 gap-2">
                  <div>
                    <Lbl>Date of Birth</Lbl>
                    <input type="date" value={form.date_of_birth} onChange={e => setForm(p => ({ ...p, date_of_birth: e.target.value }))} className={inp} />
                  </div>
                  <div>
                    <Lbl>Differently Abled Type</Lbl>
                    <select value={form.differently_abled_type} onChange={e => setForm(p => ({ ...p, differently_abled_type: e.target.value }))} className={inp}>
                      <option value="">Select…</option>
                      {DIFFERENTLY_ABLED_TYPES.map(t => <option key={t}>{t}</option>)}
                    </select>
                  </div>
                  <div className="col-span-4">
                    <Lbl>Current Address</Lbl>
                    <textarea value={form.current_address} rows={2} onChange={e => setForm(p => ({ ...p, current_address: e.target.value }))} className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                  </div>
                  <div className="col-span-4">
                    <Lbl>Permanent Address</Lbl>
                    <textarea value={form.permanent_address} rows={2} onChange={e => setForm(p => ({ ...p, permanent_address: e.target.value }))} className="w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                  </div>
                </div>
              </section>

              {/* ── 3. Statutory & Bank Details ── */}
              {canSeePayroll && (
                <section>
                  <SectionHeading n={3}>Statutory &amp; Bank Details</SectionHeading>
                  <div className="grid grid-cols-4 gap-2">
                    <div>
                      <Lbl>NPS Number</Lbl>
                      <input value={form.nps_number} onChange={e => setForm(p => ({ ...p, nps_number: e.target.value }))} placeholder="e.g. 110012345678" className={inp} />
                    </div>
                    <div className="flex items-end pb-1.5">
                      <label className="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer">
                        <input type="checkbox" checked={form.professional_tax_exempt} onChange={e => setForm(p => ({ ...p, professional_tax_exempt: e.target.checked }))} className="h-3.5 w-3.5 rounded border-border" />
                        Exempt from PT
                      </label>
                    </div>
                    <div className="flex items-end pb-1.5">
                      <label className="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer" title="This person will never be included in a payroll run (e.g. a system/admin account that doesn't draw a salary).">
                        <input type="checkbox" checked={form.exclude_from_payroll} onChange={e => setForm(p => ({ ...p, exclude_from_payroll: e.target.checked }))} className="h-3.5 w-3.5 rounded border-border" />
                        Exclude from Payroll
                      </label>
                    </div>
                    <div>
                      <Lbl>UAN / PF Number</Lbl>
                      <input value={form.uan_pf_number} onChange={e => setForm(p => ({ ...p, uan_pf_number: e.target.value }))} className={inp} />
                    </div>
                    <div>
                      <Lbl>ESI Number</Lbl>
                      <input value={form.esi_number} onChange={e => setForm(p => ({ ...p, esi_number: e.target.value }))} className={inp} />
                    </div>

                    <div className="col-span-2">
                      <Lbl>Aadhaar Number</Lbl>
                      <div className="flex gap-2">
                        <input value={form.aadhaar_ssn_encrypted} onChange={e => setForm(p => ({ ...p, aadhaar_ssn_encrypted: e.target.value }))} placeholder="12-digit number" className={inp} />
                        {editEmp ? (
                          <button type="button" disabled={digilockerBusy} onClick={startDigilockerVerification}
                            className="shrink-0 h-8 px-2 rounded border border-border text-[11px] text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-50">
                            {digilockerBusy ? "Redirecting…" : "Verify via DigiLocker"}
                          </button>
                        ) : (
                          <button type="button" disabled title="Save the employee first"
                            className="shrink-0 h-8 px-2 rounded border border-border text-[11px] text-muted-foreground cursor-not-allowed">
                            Verify via DigiLocker
                          </button>
                        )}
                      </div>
                      {editEmp && (
                        <div className="mt-1 flex items-center gap-2">
                          <Badge variant="outline" className={`text-[10px] ${verificationBadgeClass(editEmp.aadhaar_verification_status)}`}>
                            {editEmp.aadhaar_verification_status ?? "Unverified"}
                          </Badge>
                          {digilockerMessage && <span className="text-[10px] text-muted-foreground">{digilockerMessage}</span>}
                        </div>
                      )}
                      {editEmp && (editEmp.aadhaar_verified_name || editEmp.aadhaar_verified_dob || editEmp.aadhaar_verified_address) && (
                        <div className="mt-1 rounded border border-border bg-muted/30 px-2 py-1 space-y-0.5">
                          <GovFieldRow label="Name" current={form.full_name} fetched={editEmp.aadhaar_verified_name}
                            onUse={() => setForm(p => ({ ...p, full_name: editEmp.aadhaar_verified_name }))} />
                          <GovFieldRow label="DOB" current={form.date_of_birth} fetched={editEmp.aadhaar_verified_dob}
                            onUse={() => setForm(p => ({ ...p, date_of_birth: editEmp.aadhaar_verified_dob }))} />
                          <GovFieldRow label="Address" current={form.current_address} fetched={editEmp.aadhaar_verified_address}
                            onUse={() => setForm(p => ({ ...p, current_address: editEmp.aadhaar_verified_address }))} />
                        </div>
                      )}
                    </div>
                    <div className="col-span-2">
                      <Lbl>PAN</Lbl>
                      <div className="flex gap-2">
                        <input value={form.pan_tax_id} onChange={e => setForm(p => ({ ...p, pan_tax_id: e.target.value.toUpperCase() }))} placeholder="ABCDE1234F" className={inp} />
                        {editEmp ? (
                          <button type="button" disabled={panBusy} onClick={verifyPan}
                            className="shrink-0 h-8 px-2 rounded border border-border text-[11px] text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-50">
                            {panBusy ? "Verifying…" : "Verify PAN"}
                          </button>
                        ) : (
                          <button type="button" disabled title="Save the employee first"
                            className="shrink-0 h-8 px-2 rounded border border-border text-[11px] text-muted-foreground cursor-not-allowed">
                            Verify PAN
                          </button>
                        )}
                      </div>
                      {editEmp && (
                        <div className="mt-1 flex items-center gap-2">
                          <Badge variant="outline" className={`text-[10px] ${verificationBadgeClass(editEmp.pan_verification_status)}`}>
                            {editEmp.pan_verification_status ?? "Unverified"}
                          </Badge>
                          {panMessage && <span className="text-[10px] text-muted-foreground">{panMessage}</span>}
                        </div>
                      )}
                      {editEmp && (editEmp.pan_verified_name || editEmp.pan_verified_dob) && (
                        <div className="mt-1 rounded border border-border bg-muted/30 px-2 py-1 space-y-0.5">
                          <GovFieldRow label="Name" current={form.full_name} fetched={editEmp.pan_verified_name}
                            onUse={() => setForm(p => ({ ...p, full_name: editEmp.pan_verified_name }))} />
                          <GovFieldRow label="DOB" current={form.date_of_birth} fetched={editEmp.pan_verified_dob}
                            onUse={() => setForm(p => ({ ...p, date_of_birth: editEmp.pan_verified_dob }))} />
                        </div>
                      )}
                    </div>

                    <div className="col-span-2">
                      <Lbl>Bank Name</Lbl>
                      <input value={form.bank_name} onChange={e => setForm(p => ({ ...p, bank_name: e.target.value }))} placeholder="e.g. HDFC Bank" className={inp} />
                    </div>
                    <div>
                      <Lbl>Bank Account Number</Lbl>
                      <input value={form.bank_account_number} onChange={e => setForm(p => ({ ...p, bank_account_number: e.target.value }))} className={inp} />
                    </div>
                    <div>
                      <Lbl>Bank IFSC Code</Lbl>
                      <input value={form.bank_ifsc_code} onChange={e => setForm(p => ({ ...p, bank_ifsc_code: e.target.value.toUpperCase() }))} placeholder="HDFC0001234" className={inp} />
                    </div>
                  </div>
                </section>
              )}

              {/* ── 4. Portal Access ── */}
              {canSeePayroll && editEmp && (
                <section>
                  <SectionHeading n={4}>Portal Access</SectionHeading>
                  <div className="flex items-center justify-between rounded border border-border px-3 py-2">
                    <p className="text-[11px] text-muted-foreground">Whether this employee can log in to the app.</p>
                    <PortalAccessToggle employee={editEmp} onChanged={(status) => setEditEmp((p: any) => ({ ...p, user: { ...p.user, status } }))} />
                  </div>
                </section>
              )}
            </div>

            <div className="flex gap-2 px-6 py-4 border-t border-border">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleSave} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}{editEmp ? "Save Changes" : "Add Employee"}
              </Button>
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-4">
        <div className="flex items-center gap-3">
          <div className="relative max-w-sm flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search employees…"
              className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
          </div>
          <select value={statusFilter} onChange={e => setStatusFilter(e.target.value)}
            className="h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
            <option value="">All statuses</option>
            {EMPLOYMENT_STATUSES.map(s => <option key={s} value={s}>{s}</option>)}
          </select>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Employee</th>
                    <th className="px-4 py-3 text-left">Code</th>
                    <th className="px-4 py-3 text-left">Role</th>
                    <th className="px-4 py-3 text-left">Department</th>
                    <th className="px-4 py-3 text-left">Location</th>
                    <th className="px-4 py-3 text-left">Status</th>
                    {canCRUD && <th className="px-4 py-3 text-left">Actions</th>}
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((e) => (
                    <tr key={e.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2.5">
                          {e.user?.avatar_url ? (
                            <img src={e.user.avatar_url} alt={e.user?.name || e.full_name} className="h-8 w-8 rounded-full object-cover flex-shrink-0" />
                          ) : (
                            <div className="h-8 w-8 rounded-full bg-gold/20 flex items-center justify-center text-gold font-semibold text-xs flex-shrink-0">
                              {(e.user?.name || e.full_name || "?").charAt(0)}
                            </div>
                          )}
                          <div>
                            <div className="font-medium">{e.user?.name || e.full_name || "—"}</div>
                            <div className="text-xs text-muted-foreground">{e.user?.email || e.work_email}</div>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{e.employee_code}</td>
                      <td className="px-4 py-3 text-muted-foreground">{e.designation?.title || "—"}</td>
                      <td className="px-4 py-3">{e.department?.name || "—"}</td>
                      <td className="px-4 py-3 text-muted-foreground">{e.work_location || "—"}</td>
                      <td className="px-4 py-3">
                        {canCRUD ? (
                          <StatusBadgeMenu
                            status={e.employment_status || "Active"}
                            disabled={statusSaving === e.id}
                            onChange={s => handleStatusChange(e.id, s)}
                          />
                        ) : (
                          <Badge variant={statusColor(e.employment_status || "Active")} className="text-[10px] whitespace-nowrap">{e.employment_status || "Active"}</Badge>
                        )}
                      </td>
                      {canCRUD && (
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-1">
                            <button onClick={() => openEdit(e)} className="p-1.5 rounded hover:bg-muted text-muted-foreground hover:text-foreground"><Edit2 className="h-3.5 w-3.5" /></button>
                            <button onClick={() => handleDelete(e.id)} className="p-1.5 rounded hover:bg-destructive/10 text-muted-foreground hover:text-destructive"><Trash2 className="h-3.5 w-3.5" /></button>
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                  {filtered.length === 0 && (
                    <tr><td colSpan={canCRUD ? 7 : 6} className="px-4 py-12 text-center text-muted-foreground">
                      {employees.length === 0 ? "No employees yet. Add your first team member." : "No employees match your search."}
                    </td></tr>
                  )}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
