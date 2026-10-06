import { Head, Link, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Search, Edit2, X } from "lucide-react";
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
const HR_CRUD_ROLES = ["super_admin", "partner", "hr"];
// Mirrors App\Models\User::PAYROLL_ACCESS_ROLES — these new verify actions touch
// the same statutory fields the Payroll Portal's Employee Detail page already gates.
const PAYROLL_ACCESS_ROLES = ["super_admin", "partner", "finance"];
function verificationBadgeClass(status: string | undefined) {
  if (status === "Verified") return "bg-green-100 text-green-700 border-green-200";
  if (status === "NameMismatch") return "bg-amber-100 text-amber-700 border-amber-200";
  if (status === "Failed") return "bg-red-100 text-red-700 border-red-200";
  return "bg-muted text-muted-foreground border-border";
}

/** One row of a government-verification pre-fill panel: shows the fetched
 *  value next to a "Use this value" button when it differs from the form's
 *  current value. Hidden entirely once the two already match. */
function GovFieldRow({ label, current, fetched, onUse }: { label: string; current: string; fetched?: string | null; onUse: () => void }) {
  if (!fetched) return null;
  if (current.trim().toLowerCase() === fetched.trim().toLowerCase()) return null;
  return (
    <div className="flex items-center justify-between gap-2 text-[10px] py-0.5">
      <span className="text-muted-foreground truncate">{label} on record: <span className="text-foreground">{fetched}</span></span>
      <button type="button" onClick={onUse} className="shrink-0 text-gold hover:underline font-medium">Use this value</button>
    </div>
  );
}

const blankForm = {
  employee_code: "", full_name: "", work_email: "", department_name: "", designation_title: "",
  work_location: "Coimbatore", date_of_joining: "", employment_type: "Full-time", base_pay_monthly: "", hra_monthly: "",
  date_of_birth: "", aadhaar_ssn_encrypted: "", pan_tax_id: "", uan_pf_number: "", esi_number: "",
  nps_number: "", bank_account_number: "", bank_name: "", bank_ifsc_code: "",
  professional_tax_exempt: false, exclude_from_payroll: false,
};

export default function PayrollPortalEmployees() {
  const { props } = usePage() as any;
  const userRole: string = props.auth?.user?.role ?? "";
  const canEdit = HR_CRUD_ROLES.includes(userRole);
  const canVerify = PAYROLL_ACCESS_ROLES.includes(userRole);

  const [employees, setEmployees] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");

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
      setEmployees((prev) => prev.map((e) => (e.id === editEmp.id ? { ...e, ...patch } : e)));
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
    api.getEmployees().then(setEmployees).catch(() => setEmployees([])).finally(() => setLoading(false));
  }, []);

  const filtered = employees.filter((e) => {
    const q = search.toLowerCase();
    return !q || e.full_name?.toLowerCase().includes(q) || e.employee_code?.toLowerCase().includes(q) || e.work_email?.toLowerCase().includes(q);
  });

  function openEdit(e: any) {
    setEditEmp(e);
    setForm({
      employee_code: e.employee_code || "",
      full_name: e.user?.name || e.full_name || "",
      work_email: e.user?.email || e.work_email || "",
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
    if (!editEmp) return;
    if (!form.full_name.trim() || !form.work_email.trim()) { setSaveError("Name and email are required."); return; }
    setSaving(true);
    setSaveError("");
    try {
      // Nullable validation rules (digits:12, PAN/IFSC regex) reject an empty string —
      // only `null` satisfies "nullable" on the backend, so blank fields must convert.
      const blankToNull = (v: string) => (v.trim() ? v.trim() : null);
      const payload = {
        ...form,
        base_pay_monthly: form.base_pay_monthly ? parseFloat(form.base_pay_monthly) : null,
        hra_monthly: form.hra_monthly ? parseFloat(form.hra_monthly) : null,
        date_of_birth: blankToNull(form.date_of_birth),
        aadhaar_ssn_encrypted: blankToNull(form.aadhaar_ssn_encrypted),
        pan_tax_id: blankToNull(form.pan_tax_id),
        uan_pf_number: blankToNull(form.uan_pf_number),
        esi_number: blankToNull(form.esi_number),
        nps_number: blankToNull(form.nps_number),
        bank_account_number: blankToNull(form.bank_account_number),
        bank_name: blankToNull(form.bank_name),
        bank_ifsc_code: blankToNull(form.bank_ifsc_code),
      };
      // Same endpoint HRMS → Employees uses, writing to the one `employees` table —
      // this save is immediately reflected everywhere else (HRMS, payslips, reports).
      const updated = await api.updateEmployee(editEmp.id, payload as any);
      setEmployees((prev) => prev.map((e) => (e.id === editEmp.id ? { ...e, ...updated } : e)));
      setShowModal(false);
    } catch (e: any) {
      setSaveError(e.message || "Failed to save employee.");
    } finally {
      setSaving(false);
    }
  }

  const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

  // Compact, dense field style for the wide Edit Employee modal — same
  // pattern as the Indian Patent Invoice/Quotation form in Financial.tsx and
  // the Add/Edit Employee modal in HRMS/Employees.tsx.
  const fInp = "w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";
  const FLbl = ({ children }: { children: React.ReactNode }) => (
    <div className="text-[10px] text-muted-foreground mb-0.5">{children}</div>
  );
  const FSectionHeading = ({ n, children }: { n: number; children: React.ReactNode }) => (
    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">{n}</span>
      {children}
    </h3>
  );

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Employees" />
      <PageHeader eyebrow="Payroll Portal" title="Employees" description={`${employees.length} team members`} />

      {showModal && editEmp && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm overflow-y-auto py-8">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-5xl p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Edit Employee</h2>
              <button onClick={() => setShowModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {saveError && <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{saveError}</div>}
            <div className="space-y-5">
              <section>
                <FSectionHeading n={1}>Basic Information</FSectionHeading>
                <div className="grid grid-cols-4 gap-2">
                  <div><FLbl>Employee Code</FLbl><input value={form.employee_code} onChange={(e) => setForm((p) => ({ ...p, employee_code: e.target.value }))} className={fInp} /></div>
                  <div className="col-span-2"><FLbl>Full Name *</FLbl><input value={form.full_name} onChange={(e) => setForm((p) => ({ ...p, full_name: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>Work Email *</FLbl><input type="email" value={form.work_email} onChange={(e) => setForm((p) => ({ ...p, work_email: e.target.value }))} className={fInp} /></div>
                  <div>
                    <FLbl>Department</FLbl>
                    <select value={form.department_name} onChange={(e) => setForm((p) => ({ ...p, department_name: e.target.value }))} className={fInp}>
                      <option value="">Select dept</option>
                      {DEPTS.map((d) => <option key={d}>{d}</option>)}
                    </select>
                  </div>
                  <div>
                    <FLbl>Designation</FLbl>
                    <select value={form.designation_title} onChange={(e) => setForm((p) => ({ ...p, designation_title: e.target.value }))} className={fInp}>
                      <option value="">Select role</option>
                      {ROLES.map((r) => <option key={r}>{r}</option>)}
                    </select>
                  </div>
                  <div>
                    <FLbl>Location</FLbl>
                    <select value={form.work_location} onChange={(e) => setForm((p) => ({ ...p, work_location: e.target.value }))} className={fInp}>
                      {!LOCATIONS.includes(form.work_location) && form.work_location && (
                        <option value={form.work_location}>{form.work_location}</option>
                      )}
                      {LOCATIONS.map((l) => <option key={l}>{l}</option>)}
                    </select>
                  </div>
                  <div><FLbl>Date of Joining</FLbl><input type="date" value={form.date_of_joining} onChange={(e) => setForm((p) => ({ ...p, date_of_joining: e.target.value }))} className={fInp} /></div>
                  <div>
                    <FLbl>Employment Type</FLbl>
                    <select value={form.employment_type} onChange={(e) => setForm((p) => ({ ...p, employment_type: e.target.value }))} className={fInp}>
                      {["Full-time", "Part-time", "Contract", "Internship"].map((t) => <option key={t}>{t}</option>)}
                    </select>
                  </div>
                  <div><FLbl>Base Pay (₹, monthly)</FLbl><input type="number" value={form.base_pay_monthly} onChange={(e) => setForm((p) => ({ ...p, base_pay_monthly: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>HRA (₹, monthly, flat)</FLbl><input type="number" value={form.hra_monthly} onChange={(e) => setForm((p) => ({ ...p, hra_monthly: e.target.value }))} className={fInp} /></div>
                </div>
              </section>

              <section>
                <FSectionHeading n={2}>Statutory &amp; Bank Details</FSectionHeading>
                <div className="grid grid-cols-4 gap-2">
                  <div><FLbl>Date of Birth</FLbl><input type="date" value={form.date_of_birth} onChange={(e) => setForm((p) => ({ ...p, date_of_birth: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>NPS Number</FLbl><input value={form.nps_number} onChange={(e) => setForm((p) => ({ ...p, nps_number: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>UAN / PF Number</FLbl><input value={form.uan_pf_number} onChange={(e) => setForm((p) => ({ ...p, uan_pf_number: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>ESI Number</FLbl><input value={form.esi_number} onChange={(e) => setForm((p) => ({ ...p, esi_number: e.target.value }))} className={fInp} /></div>
                  <div className="flex items-end pb-1.5">
                    <label className="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer">
                      <input type="checkbox" checked={form.professional_tax_exempt} onChange={(e) => setForm((p) => ({ ...p, professional_tax_exempt: e.target.checked }))} className="h-3.5 w-3.5 rounded border-border" />
                      Exempt from PT
                    </label>
                  </div>
                  <div className="flex items-end pb-1.5">
                    <label className="flex items-center gap-1.5 text-xs text-muted-foreground cursor-pointer" title="This person will never be included in a payroll run (e.g. a system/admin account that doesn't draw a salary).">
                      <input type="checkbox" checked={form.exclude_from_payroll} onChange={(e) => setForm((p) => ({ ...p, exclude_from_payroll: e.target.checked }))} className="h-3.5 w-3.5 rounded border-border" />
                      Exclude from Payroll
                    </label>
                  </div>

                  <div className="col-span-2">
                    <FLbl>Aadhaar Number</FLbl>
                    <div className="flex gap-2">
                      <input value={form.aadhaar_ssn_encrypted} onChange={(e) => setForm((p) => ({ ...p, aadhaar_ssn_encrypted: e.target.value }))} placeholder="12-digit number" className={fInp} />
                      {canVerify && (
                        <button type="button" disabled={digilockerBusy} onClick={startDigilockerVerification}
                          className="shrink-0 h-8 px-2 rounded border border-border text-[11px] text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-50">
                          {digilockerBusy ? "Redirecting…" : "Verify via DigiLocker"}
                        </button>
                      )}
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                      <Badge variant="outline" className={`text-[10px] ${verificationBadgeClass(editEmp.aadhaar_verification_status)}`}>
                        {editEmp.aadhaar_verification_status ?? "Unverified"}
                      </Badge>
                      {digilockerMessage && <span className="text-[11px] text-muted-foreground">{digilockerMessage}</span>}
                    </div>
                    {(editEmp.aadhaar_verified_name || editEmp.aadhaar_verified_dob) && (
                      <div className="mt-1 rounded border border-border bg-muted/30 px-2 py-1 space-y-0.5">
                        <GovFieldRow label="Name" current={form.full_name} fetched={editEmp.aadhaar_verified_name}
                          onUse={() => setForm((p) => ({ ...p, full_name: editEmp.aadhaar_verified_name }))} />
                        <GovFieldRow label="DOB" current={form.date_of_birth} fetched={editEmp.aadhaar_verified_dob}
                          onUse={() => setForm((p) => ({ ...p, date_of_birth: editEmp.aadhaar_verified_dob }))} />
                      </div>
                    )}
                  </div>
                  <div className="col-span-2">
                    <FLbl>PAN</FLbl>
                    <div className="flex gap-2">
                      <input value={form.pan_tax_id} onChange={(e) => setForm((p) => ({ ...p, pan_tax_id: e.target.value.toUpperCase() }))} placeholder="ABCDE1234F" className={fInp} />
                      {canVerify && (
                        <button type="button" disabled={panBusy} onClick={verifyPan}
                          className="shrink-0 h-8 px-2 rounded border border-border text-[11px] text-muted-foreground hover:text-foreground hover:bg-muted disabled:opacity-50">
                          {panBusy ? "Verifying…" : "Verify PAN"}
                        </button>
                      )}
                    </div>
                    <div className="mt-1 flex items-center gap-2">
                      <Badge variant="outline" className={`text-[10px] ${verificationBadgeClass(editEmp.pan_verification_status)}`}>
                        {editEmp.pan_verification_status ?? "Unverified"}
                      </Badge>
                      {panMessage && <span className="text-[11px] text-muted-foreground">{panMessage}</span>}
                    </div>
                    {(editEmp.pan_verified_name || editEmp.pan_verified_dob) && (
                      <div className="mt-1 rounded border border-border bg-muted/30 px-2 py-1 space-y-0.5">
                        <GovFieldRow label="Name" current={form.full_name} fetched={editEmp.pan_verified_name}
                          onUse={() => setForm((p) => ({ ...p, full_name: editEmp.pan_verified_name }))} />
                        <GovFieldRow label="DOB" current={form.date_of_birth} fetched={editEmp.pan_verified_dob}
                          onUse={() => setForm((p) => ({ ...p, date_of_birth: editEmp.pan_verified_dob }))} />
                      </div>
                    )}
                  </div>

                  <div className="col-span-2"><FLbl>Bank Name</FLbl><input value={form.bank_name} onChange={(e) => setForm((p) => ({ ...p, bank_name: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>Bank Account Number</FLbl><input value={form.bank_account_number} onChange={(e) => setForm((p) => ({ ...p, bank_account_number: e.target.value }))} className={fInp} /></div>
                  <div><FLbl>Bank IFSC Code</FLbl><input value={form.bank_ifsc_code} onChange={(e) => setForm((p) => ({ ...p, bank_ifsc_code: e.target.value.toUpperCase() }))} className={fInp} /></div>
                </div>
              </section>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" onClick={handleSave} disabled={saving}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}Save Changes
              </Button>
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-4">
        <div className="relative max-w-sm">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search employees…"
            className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-xs">
                <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2.5 text-left">Employee</th>
                    <th className="px-3 py-2.5 text-left">Department</th>
                    <th className="px-3 py-2.5 text-left">Designation</th>
                    <th className="px-3 py-2.5 text-left">Status</th>
                    {canEdit && <th className="px-3 py-2.5 text-center">Edit</th>}
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((e) => (
                    <tr key={e.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-3 py-2.5">
                        <Link href={`/payroll-portal/employees/${e.id}`} className="hover:text-gold transition-colors">
                          <div className="font-medium">{e.user?.name || e.full_name}</div>
                          <div className="text-muted-foreground text-[10px]">{e.employee_code} · {e.work_email}</div>
                        </Link>
                      </td>
                      <td className="px-3 py-2.5">{e.department?.name || "—"}</td>
                      <td className="px-3 py-2.5">{e.designation?.title || "—"}</td>
                      <td className="px-3 py-2.5">
                        <Badge variant={statusColor(e.employment_status || "Active")} className="text-[10px]">{e.employment_status}</Badge>
                      </td>
                      {canEdit && (
                        <td className="px-3 py-2.5 text-center">
                          <button onClick={() => openEdit(e)} className="inline-flex p-1 rounded hover:bg-muted" title="Edit employee">
                            <Edit2 className="h-3 w-3 text-muted-foreground" />
                          </button>
                        </td>
                      )}
                    </tr>
                  ))}
                  {filtered.length === 0 && (
                    <tr><td colSpan={canEdit ? 5 : 4} className="px-3 py-8 text-center text-muted-foreground">No employees found.</td></tr>
                  )}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
    </PayrollPortalLayout>
  );
}
