import { Head } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { Loader2, FileText, Upload, Download, CheckCircle2, AlertTriangle, Send, RefreshCw } from "lucide-react";
import PayrollPortalLayout from "@/layouts/PayrollPortalLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

function currentFiscalYear(): number {
  const now = new Date();
  return now.getMonth() + 1 >= 4 ? now.getFullYear() : now.getFullYear() - 1;
}

function statusBadge(status: string) {
  if (status === "Merged") return "bg-green-100 text-green-700 border-green-200";
  if (status === "Failed" || status === "MissingPan") return "bg-red-100 text-red-700 border-red-200";
  if (status === "Requested") return "bg-blue-100 text-blue-700 border-blue-200";
  return "bg-amber-100 text-amber-700 border-amber-200"; // PartBOnly
}

/** Read-only — PAN verification itself happens on the Employee pages, not here. */
function panVerificationBadgeClass(status: string | undefined) {
  if (status === "Verified") return "bg-green-100 text-green-700 border-green-200";
  if (status === "NameMismatch") return "bg-amber-100 text-amber-700 border-amber-200";
  if (status === "Failed") return "bg-red-100 text-red-700 border-red-200";
  return "bg-muted text-muted-foreground border-border";
}

export default function PayrollPortalForm16() {
  const [fiscalYear, setFiscalYear] = useState(currentFiscalYear());
  const [docs, setDocs] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [uploadResult, setUploadResult] = useState<{ matched: any[]; unmatched: string[] } | null>(null);
  const [error, setError] = useState("");
  const [busyId, setBusyId] = useState<number | null>(null);
  const [publishingAll, setPublishingAll] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  function load() {
    setLoading(true);
    api.getForm16Documents(fiscalYear).then(setDocs).catch(() => setDocs([])).finally(() => setLoading(false));
  }

  async function handleGenerate(employeeId: number) {
    setBusyId(employeeId);
    try {
      await api.generateForm16(employeeId, fiscalYear);
      load();
    } catch (e: any) {
      setError(e.message || "Failed to generate.");
    } finally {
      setBusyId(null);
    }
  }

  async function handlePublish(employeeId: number) {
    setBusyId(employeeId);
    try {
      await api.publishForm16(employeeId, fiscalYear);
      load();
    } catch (e: any) {
      setError(e.message || "Failed to publish.");
    } finally {
      setBusyId(null);
    }
  }

  async function handlePublishAll() {
    if (!confirm("Publish every generated Form 16 for this financial year? Employees will be notified.")) return;
    setPublishingAll(true);
    try {
      const res = await api.publishAllForm16(fiscalYear);
      load();
      alert(`Published ${res.published_count} document(s).`);
    } catch (e: any) {
      setError(e.message || "Failed to publish.");
    } finally {
      setPublishingAll(false);
    }
  }

  useEffect(() => { load(); }, [fiscalYear]);

  async function handleUpload(file: File) {
    setUploading(true);
    setError("");
    setUploadResult(null);
    try {
      const result = await api.uploadForm16PartAZip(fiscalYear, file);
      setUploadResult(result);
      load();
    } catch (e: any) {
      setError(e.message || "Upload failed.");
    } finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  }

  return (
    <PayrollPortalLayout>
      <Head title="Payroll Portal — Form 16" />
      <PageHeader eyebrow="Payroll Portal" title="Form 16" description="Annual tax computation (Part B) and TRACES Part A merge"
        actions={
          <>
            <select value={fiscalYear} onChange={(e) => setFiscalYear(Number(e.target.value))}
              className="h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
              {[currentFiscalYear(), currentFiscalYear() - 1, currentFiscalYear() - 2].map((fy) => (
                <option key={fy} value={fy}>FY {fy}-{String(fy + 1).slice(-2)}</option>
              ))}
            </select>
            <a href={api.form24QExportUrl(fiscalYear)}>
              <Button variant="outline"><Download className="h-4 w-4 mr-2" />Export 24Q Annexure</Button>
            </a>
            <Button variant="outline" disabled={publishingAll} onClick={handlePublishAll}>
              {publishingAll ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Send className="h-4 w-4 mr-2" />}Publish All
            </Button>
          </>
        }
      />

      <div className="px-8 py-6 space-y-6">
        <Card className="border-border">
          <CardContent className="p-5">
            <h3 className="text-sm font-semibold mb-2">Upload TRACES Part A ZIP</h3>
            <p className="text-xs text-muted-foreground mb-4 max-w-2xl">
              Download the Form 16 Part A ZIP from the TRACES utility app (tdscpc.gov.in), containing one PDF per
              employee named by PAN (e.g. <code className="font-mono">ABCDE1234F.pdf</code>). PDF format only, not
              digitally signed, ZIP size ≤ 25MB. We'll match each file to an employee by PAN and merge it with our
              own Part B computation into one combined document.
            </p>
            {error && <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400 mb-3">{error}</div>}
            <div className="flex items-center gap-3">
              <input ref={fileInputRef} type="file" accept=".zip" className="hidden" id="form16-zip-input"
                onChange={(e) => { const f = e.target.files?.[0]; if (f) handleUpload(f); }} />
              <Button disabled={uploading} onClick={() => fileInputRef.current?.click()}>
                {uploading ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Upload className="h-4 w-4 mr-2" />}
                {uploading ? "Uploading…" : "Choose ZIP File"}
              </Button>
            </div>
            {uploadResult && (
              <div className="mt-4 grid grid-cols-2 gap-4 text-xs">
                <div className="rounded-md border border-green-500/30 bg-green-500/5 p-3">
                  <div className="flex items-center gap-1.5 font-medium text-green-700 mb-1"><CheckCircle2 className="h-3.5 w-3.5" />Matched ({uploadResult.matched.length})</div>
                  {uploadResult.matched.map((m) => <div key={m.employee_code} className="text-muted-foreground">{m.full_name} ({m.employee_code}) — {m.pan}</div>)}
                </div>
                <div className="rounded-md border border-amber-500/30 bg-amber-500/5 p-3">
                  <div className="flex items-center gap-1.5 font-medium text-amber-700 mb-1"><AlertTriangle className="h-3.5 w-3.5" />No PAN Match ({uploadResult.unmatched.length})</div>
                  {uploadResult.unmatched.map((f) => <div key={f} className="text-muted-foreground">{f}</div>)}
                  {uploadResult.unmatched.length === 0 && <div className="text-muted-foreground">All files matched.</div>}
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : docs.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
            <FileText className="h-10 w-10 opacity-20" />
            <p className="text-sm">No Form 16 documents generated for this year yet.</p>
          </div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-xs">
                <thead className="bg-muted/40 text-[11px] uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-3 py-2.5 text-left">Employee</th>
                    <th className="px-3 py-2.5 text-left">PAN</th>
                    <th className="px-3 py-2.5 text-left">Status</th>
                    <th className="px-3 py-2.5 text-left">Published</th>
                    <th className="px-3 py-2.5 text-center">Action</th>
                  </tr>
                </thead>
                <tbody>
                  {docs.map((d) => (
                    <tr key={d.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-3 py-2.5">
                        <div className="font-medium">{d.employee?.full_name}</div>
                        <div className="text-muted-foreground text-[10px]">{d.employee?.employee_code}</div>
                      </td>
                      <td className="px-3 py-2.5">
                        {d.employee?.pan_tax_id || "—"}
                        {d.employee?.pan_tax_id && (
                          <Badge variant="outline" className={`ml-1.5 text-[9px] ${panVerificationBadgeClass(d.employee?.pan_verification_status)}`}>
                            {d.employee?.pan_verification_status ?? "Unverified"}
                          </Badge>
                        )}
                      </td>
                      <td className="px-3 py-2.5"><Badge variant="outline" className={`text-[10px] ${statusBadge(d.status)}`}>{d.status}</Badge></td>
                      <td className="px-3 py-2.5 text-muted-foreground">
                        {d.published_at ? new Date(d.published_at).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" }) : "—"}
                      </td>
                      <td className="px-3 py-2.5 text-center whitespace-nowrap">
                        {(d.status === "Requested" || d.status === "Failed") && (
                          <Button size="sm" variant="outline" className="h-6 px-2 text-[10px] mr-1" disabled={busyId === d.employee_id} onClick={() => handleGenerate(d.employee_id)}>
                            {busyId === d.employee_id ? <Loader2 className="h-3 w-3 animate-spin" /> : <RefreshCw className="h-3 w-3" />}
                          </Button>
                        )}
                        {(d.status === "Merged" || d.status === "PartBOnly") && !d.published_at && (
                          <Button size="sm" className="h-6 px-2 text-[10px] bg-gold hover:bg-gold/90 text-black mr-1" disabled={busyId === d.employee_id} onClick={() => handlePublish(d.employee_id)}>Publish</Button>
                        )}
                        <a className="inline-flex p-1 rounded hover:bg-muted" title="Download" href={api.form16DownloadUrl(d.employee_id, fiscalYear)} target="_blank" rel="noreferrer">
                          <Download className="h-3.5 w-3.5 text-muted-foreground" />
                        </a>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
    </PayrollPortalLayout>
  );
}
