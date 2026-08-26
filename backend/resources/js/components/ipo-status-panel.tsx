import { useState } from "react";
import { Pencil, Loader2, ExternalLink, FileText } from "lucide-react";
import { api } from "@/lib/api-client";
import { ERegisterPopup } from "@/components/eregister-popup";
import type { WorkspaceApplication, WorkspaceClient, WorkspaceProject, WorkspaceDocument } from "@/types/matter-workspace";

function fmtIpoDate(d?: string | null): string {
  if (!d) return "";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}/${m}/${y}`;
}

const APPLICATION_TYPE_BY_SERVICE: Record<string, string> = {
  PRV: "Ordinary Application", CPT: "Ordinary Application", CPD: "Ordinary Application",
  CPE: "Ordinary Application", CVP: "Convention Application",
  PCT: "PCT International Application",
  NAP: "PCT National Phase Application", NPE: "PCT National Phase Application",
  NAF: "PCT National Phase Application", NPA: "PCT National Phase Application",
  DVA: "Divisional Application", PAD: "Patent of Addition",
};

function deriveApplicationType(app: WorkspaceApplication, serviceCode?: string | null): string {
  if (app.application_type) return app.application_type;
  return APPLICATION_TYPE_BY_SERVICE[(serviceCode || "").toUpperCase()] ?? "Ordinary Application";
}

const DISPOSED_STATUSES = ["Granted", "Refused", "Abandoned", "Withdrawn", "Lapsed"];

function pipelineStages(app: WorkspaceApplication) {
  const disposed = DISPOSED_STATUSES.includes(app.legal_status);
  const underExam = !!app.rfe_filed_date || disposed || app.legal_status === "Under Examination";
  return [
    { label: "Filed", reached: !!app.filing_date },
    { label: "Published", reached: !!app.publication_date },
    { label: "RQ Filed", reached: !!app.rfe_filed_date },
    { label: "Under Examination", reached: underExam },
    { label: "Disposed", reached: disposed },
  ];
}

function statusLine(app: WorkspaceApplication): string {
  switch (app.legal_status) {
    case "Granted": return `Granted Application${app.grant_number ? `, Patent Number: ${app.grant_number}` : ""}`;
    case "Refused": return "Refused Application";
    case "Abandoned": return "Abandoned Application";
    case "Withdrawn": return "Withdrawn Application";
    case "Lapsed": return "Lapsed — Renewal Fee Missed";
    case "Under Examination": return "Under Examination";
    case "Published": return "Published — Awaiting Examination";
    default: return "Filed — Awaiting Publication";
  }
}

interface EditableFields {
  application_type: string;
  fer_reply_date: string;
  certificate_issue_date: string;
  post_grant_journal_date: string;
}

export function IpoStatusPanel({
  projectId, application, project, client, events, documents, canManage, onUpdated,
}: {
  projectId: number | string;
  application: WorkspaceApplication;
  project: WorkspaceProject;
  client?: WorkspaceClient | null;
  events: Array<{ event_type: string; event_date: string }>;
  documents: WorkspaceDocument[];
  canManage: boolean;
  onUpdated: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<EditableFields>({
    application_type: application.application_type ?? "",
    fer_reply_date: application.fer_reply_date?.split("T")[0] ?? "",
    certificate_issue_date: application.certificate_issue_date?.split("T")[0] ?? "",
    post_grant_journal_date: application.post_grant_journal_date?.split("T")[0] ?? "",
  });
  const [saving, setSaving] = useState(false);
  const [showDocs, setShowDocs] = useState(false);
  const [docFilter, setDocFilter] = useState<string | null>(null);
  const [showERegister, setShowERegister] = useState(false);

  async function save() {
    setSaving(true);
    try {
      await api.updateApplicationStatusFields(projectId, {
        application_type: form.application_type || null,
        fer_reply_date: form.fer_reply_date || null,
        certificate_issue_date: form.certificate_issue_date || null,
        post_grant_journal_date: form.post_grant_journal_date || null,
      });
      setEditing(false);
      onUpdated();
    } catch { /* ignore */ }
    finally { setSaving(false); }
  }

  const ferReceivedDate = events
    .filter((e) => e.event_type === "fer_received")
    .sort((a, b) => a.event_date.localeCompare(b.event_date))[0]?.event_date;

  const rows: [string, string][] = [
    ["APPLICATION NUMBER", application.application_number || "—"],
    ["APPLICATION TYPE", deriveApplicationType(application, project.service_code)],
    ["DATE OF FILING", fmtIpoDate(application.filing_date || project.filing_date) || "—"],
    ["APPLICANT NAME", client?.company_name || client?.legal_name || "—"],
    ["TITLE OF INVENTION", application.title || project.invention_title || "—"],
    ["FIELD OF INVENTION", project.technology_field || "—"],
    ["E-MAIL (As Per Record)", client?.contact_email || "—"],
    ["PRIORITY DATE", fmtIpoDate(application.priority_date || project.priority_date) || "—"],
    ["REQUEST FOR EXAMINATION DATE", fmtIpoDate(application.rfe_filed_date) || "—"],
    ["PUBLICATION DATE (U/S 11A)", fmtIpoDate(application.publication_date) || "—"],
    ["FIRST EXAMINATION REPORT DATE", fmtIpoDate(ferReceivedDate) || "—"],
    ["REPLY TO FER DATE", fmtIpoDate(application.fer_reply_date) || "—"],
    ["DATE OF CERTIFICATE ISSUE", fmtIpoDate(application.certificate_issue_date) || "—"],
    ["POST GRANT JOURNAL DATE", fmtIpoDate(application.post_grant_journal_date) || "—"],
  ];

  const stages = pipelineStages(application);

  const filteredDocs = docFilter
    ? documents.filter((d) => d.file_name.toLowerCase().includes(docFilter))
    : documents;
  const searchStrategyDocs = documents.filter((d) => d.file_name.toLowerCase().includes("search strat"));

  return (
    <div className="overflow-hidden rounded-md border border-border">
      {/* ── Application Details ───────────────────────────────────────── */}
      <div className="flex items-center justify-between bg-[#2b6c9e] px-4 py-2">
        <h2 className="text-sm font-semibold text-white tracking-wide">Application Details</h2>
        {canManage && (
          <button onClick={() => setEditing((v) => !v)} className="text-white/80 hover:text-white">
            <Pencil className="h-3.5 w-3.5" />
          </button>
        )}
      </div>
      <table className="w-full text-sm">
        <tbody>
          {rows.map(([label, value], i) => (
            <tr key={label} className={i % 2 === 0 ? "bg-background" : "bg-muted/30"}>
              <td className="w-1/3 border-b border-border px-4 py-2 align-top text-xs font-medium text-[#2b6c9e]">{label}</td>
              <td className="border-b border-border px-4 py-2 align-top text-sm font-medium">{value}</td>
            </tr>
          ))}
        </tbody>
      </table>

      {editing && (
        <div className="border-t border-border bg-muted/20 p-4 space-y-3">
          <p className="text-xs text-muted-foreground">These four fields aren't produced by any docket event — enter them directly.</p>
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="block text-[11px] text-muted-foreground mb-1">Application Type</label>
              <input value={form.application_type} onChange={(e) => setForm((p) => ({ ...p, application_type: e.target.value }))}
                placeholder="e.g. Ordinary Application" className="w-full h-8 rounded border border-border bg-background px-2 text-xs" />
            </div>
            <div>
              <label className="block text-[11px] text-muted-foreground mb-1">Reply to FER Date</label>
              <input type="date" value={form.fer_reply_date} onChange={(e) => setForm((p) => ({ ...p, fer_reply_date: e.target.value }))}
                className="w-full h-8 rounded border border-border bg-background px-2 text-xs" />
            </div>
            <div>
              <label className="block text-[11px] text-muted-foreground mb-1">Date of Certificate Issue</label>
              <input type="date" value={form.certificate_issue_date} onChange={(e) => setForm((p) => ({ ...p, certificate_issue_date: e.target.value }))}
                className="w-full h-8 rounded border border-border bg-background px-2 text-xs" />
            </div>
            <div>
              <label className="block text-[11px] text-muted-foreground mb-1">Post Grant Journal Date</label>
              <input type="date" value={form.post_grant_journal_date} onChange={(e) => setForm((p) => ({ ...p, post_grant_journal_date: e.target.value }))}
                className="w-full h-8 rounded border border-border bg-background px-2 text-xs" />
            </div>
          </div>
          <div className="flex gap-2">
            <button onClick={save} disabled={saving} className="h-7 rounded bg-gold px-3 text-xs font-medium text-black hover:bg-gold/90 disabled:opacity-50">
              {saving ? <Loader2 className="h-3 w-3 animate-spin" /> : "Save"}
            </button>
            <button onClick={() => setEditing(false)} className="h-7 rounded border border-border px-3 text-xs">Cancel</button>
          </div>
        </div>
      )}

      {/* ── Application Status ────────────────────────────────────────── */}
      <div className="bg-[#2b6c9e] px-4 py-2">
        <h2 className="text-sm font-semibold text-white tracking-wide">Application Status</h2>
      </div>
      <table className="w-full text-sm">
        <tbody>
          <tr>
            <td className="w-1/3 border-b border-border px-4 py-3 align-top text-xs font-medium text-[#2b6c9e]">APPLICATION STATUS</td>
            <td className="border-b border-border px-4 py-3 align-top text-base font-semibold">{statusLine(application)}</td>
          </tr>
        </tbody>
      </table>

      <div className="flex flex-wrap items-center gap-2 border-b border-border p-3">
        <button onClick={() => setShowERegister(true)}
          className="rounded bg-[#2b6c9e] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#245a84]">E-Register</button>
        <button onClick={() => { setShowDocs(true); setDocFilter("order"); }}
          className="rounded bg-[#2b6c9e] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#245a84]">Order(s)/Decision(s)</button>
        <button onClick={() => { setShowDocs((v) => !v); setDocFilter(null); }}
          className="rounded bg-[#2b6c9e] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#245a84]">View Documents</button>
      </div>

      {showERegister && (
        <ERegisterPopup
          application={application}
          project={project}
          onClose={() => setShowERegister(false)}
          onViewDocuments={() => { setShowERegister(false); setShowDocs(true); setDocFilter(null); }}
        />
      )}

      {/* ── Pipeline ───────────────────────────────────────────────────── */}
      <div className="flex flex-wrap items-center gap-1 p-4">
        {stages.map((stage, i) => (
          <div key={stage.label} className="flex items-center gap-1">
            <span className={`rounded-md px-3 py-1.5 text-xs font-semibold text-white ${stage.reached ? "bg-emerald-600" : "bg-muted-foreground/40"}`}>
              {stage.label}
            </span>
            {i < stages.length - 1 && <span className="text-muted-foreground">&rarr;</span>}
          </div>
        ))}
      </div>
      <p className="px-4 pb-3 text-[11px] text-muted-foreground">Status reflects the docket events and dates recorded for this case.</p>

      {/* ── Documents ──────────────────────────────────────────────────── */}
      {showDocs && (
        <div className="border-t border-border">
          <div className="bg-[#2b6c9e] px-4 py-2">
            <h2 className="text-sm font-semibold text-white tracking-wide">Documents</h2>
          </div>
          {filteredDocs.length === 0 ? (
            <p className="p-4 text-xs text-muted-foreground">
              {docFilter ? `No documents matching "${docFilter}".` : "No documents uploaded for this case yet."}
            </p>
          ) : (
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-[#2b6c9e]">
                  <th className="px-4 py-1.5 text-left text-[11px] font-semibold text-white">Document Name</th>
                  <th className="w-40 px-4 py-1.5 text-left text-[11px] font-semibold text-white">Created / Uploaded Date</th>
                </tr>
              </thead>
              <tbody>
                {filteredDocs.filter((d) => !searchStrategyDocs.includes(d)).map((doc, i) => (
                  <tr key={doc.id} className={i % 2 === 0 ? "bg-background" : "bg-muted/30"}>
                    <td className="border-b border-border px-4 py-2">
                      <button onClick={() => api.downloadDocument(doc.storage_path ?? "", doc.file_name)}
                        className="flex items-center gap-1.5 text-left text-[#2b6c9e] hover:underline">
                        <FileText className="h-3.5 w-3.5 flex-shrink-0" />{doc.file_name}
                      </button>
                    </td>
                    <td className="border-b border-border px-4 py-2 font-mono text-xs text-muted-foreground">{fmtIpoDate(doc.updated_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          {!docFilter && searchStrategyDocs.length > 0 && (
            <>
              <div className="bg-[#2b6c9e] px-4 py-2">
                <h2 className="text-sm font-semibold text-white tracking-wide">Examiner / Search Strategy Documents</h2>
              </div>
              <table className="w-full text-sm">
                <tbody>
                  {searchStrategyDocs.map((doc, i) => (
                    <tr key={doc.id} className={i % 2 === 0 ? "bg-background" : "bg-muted/30"}>
                      <td className="border-b border-border px-4 py-2">
                        <button onClick={() => api.downloadDocument(doc.storage_path ?? "", doc.file_name)}
                          className="flex items-center gap-1.5 text-left text-[#2b6c9e] hover:underline">
                          <FileText className="h-3.5 w-3.5 flex-shrink-0" />{doc.file_name}
                        </button>
                      </td>
                      <td className="w-40 border-b border-border px-4 py-2 font-mono text-xs text-muted-foreground">{fmtIpoDate(doc.updated_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </>
          )}

          <p className="px-4 py-2 text-[11px] text-muted-foreground">
            Dates shown reflect when each document was uploaded or last updated in this portal.
          </p>
        </div>
      )}
    </div>
  );
}
