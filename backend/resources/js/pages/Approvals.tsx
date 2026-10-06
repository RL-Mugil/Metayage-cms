import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { CheckCircle, XCircle, Clock, DollarSign, Calendar, AlertTriangle, Filter, Loader2, Plus, X, Search, Paperclip, Pencil, Undo2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ProjectCombobox } from "@/components/project-combobox";
import { SearchCombobox } from "@/components/search-combobox";
import { DocumentPicker, type PickedDocument } from "@/components/document-picker";
import { api } from "@/lib/api-client";

type ApprovalType = "Leave" | "Expense" | "Client" | "Colleague";
type ApprovalStatus = "pending" | "approved" | "rejected" | "cancelled";

interface Approval {
  id: number;
  type: ApprovalType;
  kind?: "budget" | "technical" | null;
  requester: string;
  title?: string;
  description: string;
  amount?: string | null;
  from_date?: string | null;
  to_date?: string | null;
  submitted: string;
  urgency: "High" | "Normal";
  status: ApprovalStatus;
  comments?: string | null;
  can_resolve?: boolean;
  can_manage?: boolean;
  project?: { id: number; docket_number: string; project_name: string } | null;
  documents?: { id: number; file_name: string }[];
  client_id?: number | null;
  approver_id?: number | null;
  raw_description?: string | null;
}

const typeColors: Record<ApprovalType, string> = {
  Leave: "bg-blue-500/10 text-blue-600 border-blue-200",
  Expense: "bg-amber-500/10 text-amber-600 border-amber-200",
  Client: "bg-purple-500/10 text-purple-600 border-purple-200",
  Colleague: "bg-teal-500/10 text-teal-600 border-teal-200",
};

export default function Approvals() {
  const { props: pageProps } = usePage() as any;
  const role: string = pageProps.auth?.user?.role ?? "";
  const isClientUser = ["client", "client_admin"].includes(role);
  const isInventor = role === "inventor";
  const isClientAdmin = role === "client_admin";
  // Any internal staff may raise an approval (to a client or a colleague) —
  // inventors only view/resolve technical approvals sent to them, they don't raise any.
  const canCreateApproval = !isClientUser && !isInventor;
  // Internal approvers act on Leave/Expense; client_admin/inventor act on
  // Client type (kind-gated, see canAct() below); colleague approvals are
  // resolvable by whoever the backend flags (can_resolve).
  const canActInternal = ["super_admin", "hr", "partner"].includes(role);

  const [items, setItems] = useState<Approval[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [activeTab, setActiveTab] = useState<"pending" | "approved" | "rejected" | "cancelled">("pending");
  const [filterType, setFilterType] = useState<ApprovalType | "All">("All");

  // New/Edit approval modal (firm side) — editTarget null = creating, set = editing
  const [showNew, setShowNew] = useState(false);
  const [editTarget, setEditTarget] = useState<Approval | null>(null);
  const [recipientMode, setRecipientMode] = useState<"client" | "colleague">("client");
  const [clients, setClients] = useState<any[]>([]);
  const [colleagues, setColleagues] = useState<any[]>([]);
  const [projects, setProjects] = useState<any[]>([]);
  const [form, setForm] = useState<{ client_id: string; approver_id: string; project_id: string; title: string; description: string; kind: "budget" | "technical" }>(
    { client_id: "", approver_id: "", project_id: "", title: "", description: "", kind: "budget" }
  );
  const [docs, setDocs] = useState<PickedDocument[]>([]);
  const [colleagueSearch, setColleagueSearch] = useState("");
  const [saving, setSaving] = useState(false);
  const [newError, setNewError] = useState("");

  // Resolve modal (capture an optional comment / description with the decision)
  const [resolveTarget, setResolveTarget] = useState<{ item: Approval; action: "Approved" | "Rejected" } | null>(null);
  const [resolveComment, setResolveComment] = useState("");
  const [resolving, setResolving] = useState(false);

  // Revoke confirm modal
  const [revokeTarget, setRevokeTarget] = useState<Approval | null>(null);
  const [revoking, setRevoking] = useState(false);

  const filterTypes: (ApprovalType | "All")[] = isClientUser || isInventor
    ? ["All"]
    : ["All", "Leave", "Expense", "Client", "Colleague"];

  const load = () => {
    api.getApprovals()
      .then((data) => setItems(data as unknown as Approval[]))
      .catch((e) => setError(e.message || "Failed to load approvals."))
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    load();
    if (canCreateApproval) {
      api.getClients(new URLSearchParams({ per_page: "2000" }))
        .then((res: any) => setClients(Array.isArray(res) ? res : res?.data ?? []))
        .catch(() => {});
      api.getUsers()
        .then((res: any) => {
          const list = Array.isArray(res) ? res : res?.data ?? [];
          setColleagues(list.filter((u: any) => !["client", "client_admin"].includes(u.role)));
        })
        .catch(() => {});
      api.getProjects()
        .then((res: any) => setProjects(Array.isArray(res) ? res : []))
        .catch(() => {});
    }
  }, []);

  const doResolve = async () => {
    if (!resolveTarget) return;
    const { item, action } = resolveTarget;
    setResolving(true);
    setError("");
    try {
      await api.resolveApproval(item.type, item.id, action, resolveComment.trim() || undefined);
      setItems((prev) =>
        prev.map((a) =>
          a.id === item.id && a.type === item.type
            ? { ...a, status: action.toLowerCase() as ApprovalStatus, comments: resolveComment.trim() || a.comments }
            : a
        )
      );
      setResolveTarget(null);
      setResolveComment("");
    } catch (e: any) {
      setError(e.message || "Failed to resolve approval.");
    } finally {
      setResolving(false);
    }
  };

  function openNew() {
    setEditTarget(null);
    setRecipientMode("client");
    setForm({ client_id: "", approver_id: "", project_id: "", title: "", description: "", kind: "budget" });
    setDocs([]);
    setNewError("");
    setShowNew(true);
  }

  function openEditApproval(a: Approval) {
    const isColleague = a.type === "Colleague";
    setEditTarget(a);
    setRecipientMode(isColleague ? "colleague" : "client");
    setForm({
      client_id:   a.client_id ? String(a.client_id) : "",
      approver_id: a.approver_id ? String(a.approver_id) : "",
      project_id:  a.project?.id ? String(a.project.id) : "",
      title:       a.title ?? "",
      description: a.raw_description ?? "",
      kind:        a.kind ?? "budget",
    });
    setDocs((a.documents ?? []).map(d => ({ id: d.id, name: d.file_name })));
    setNewError("");
    setShowNew(true);
  }

  async function submitApproval() {
    const isColleague = recipientMode === "colleague";
    if ((isColleague ? !form.approver_id : !form.client_id) || !form.title.trim()) return;
    setSaving(true);
    setNewError("");
    const payload = {
      client_id: isColleague ? undefined : Number(form.client_id),
      approver_id: isColleague ? Number(form.approver_id) : undefined,
      title: form.title.trim(),
      description: form.description.trim() || undefined,
      kind: isColleague ? undefined : form.kind,
      project_id: form.project_id ? Number(form.project_id) : undefined,
      document_ids: docs.map(d => d.id),
    };
    try {
      if (editTarget) {
        await api.updateApproval(editTarget.id, payload);
      } else {
        await api.createApproval(payload);
      }
      setShowNew(false);
      setEditTarget(null);
      setForm({ client_id: "", approver_id: "", project_id: "", title: "", description: "", kind: "budget" });
      setDocs([]);
      load();
    } catch (e: any) {
      setNewError(e?.message || `Failed to ${editTarget ? "update" : "create"} approval request.`);
    } finally {
      setSaving(false);
    }
  }

  async function doRevoke() {
    if (!revokeTarget) return;
    setRevoking(true);
    setError("");
    try {
      await api.cancelApproval(revokeTarget.id);
      setItems(prev => prev.map(a => a.id === revokeTarget.id && a.type === revokeTarget.type ? { ...a, status: "cancelled" } : a));
      setRevokeTarget(null);
    } catch (e: any) {
      setError(e.message || "Failed to revoke approval.");
    } finally {
      setRevoking(false);
    }
  }

  // Can the current user act on this row? Client/Colleague rows are already
  // kind-aware from the backend (ApprovalController::canResolveClientApproval()) —
  // trust can_resolve rather than re-deriving role logic here.
  const canAct = (a: Approval) => {
    if (a.type === "Client" || a.type === "Colleague") return a.can_resolve ?? false;
    return canActInternal && !isClientUser;
  };

  const filtered = items.filter(
    (a) => a.status === activeTab && (filterType === "All" || a.type === filterType)
  );

  const pendingCount = items.filter((a) => a.status === "pending").length;
  const approvedWeek = items.filter((a) => a.status === "approved").length;
  const rejectedWeek = items.filter((a) => a.status === "rejected").length;

  const filteredColleagues = colleagues.filter((u: any) => {
    const q = colleagueSearch.toLowerCase();
    return !q || (u.name ?? "").toLowerCase().includes(q) || (u.email ?? "").toLowerCase().includes(q);
  }).slice(0, 15);

  // Compact, dense field style for the wide New/Edit Approval modal — same
  // pattern as the Indian Patent Invoice/Quotation form in Financial.tsx.
  const fInp = "w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";
  const fTa = "w-full rounded border border-border bg-background px-2 py-1.5 text-xs focus:outline-none focus:ring-1 focus:ring-gold resize-none";
  const FLbl = ({ children, req }: { children: React.ReactNode; req?: boolean }) => (
    <div className="text-[10px] text-muted-foreground mb-0.5">{children}{req && <span className="text-destructive ml-0.5">*</span>}</div>
  );
  const FSectionHeading = ({ n, children }: { n: number; children: React.ReactNode }) => (
    <h3 className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground mb-2 flex items-center gap-1.5">
      <span className="w-4 h-4 rounded-full bg-gold text-black flex items-center justify-center text-[9px] font-bold">{n}</span>
      {children}
    </h3>
  );

  return (
    <AppLayout>
      <Head title="Approvals" />
      <PageHeader
        eyebrow="Engagement"
        title="Approvals"
        description={isClientUser
          ? "Review and act on approval requests from your legal team"
          : "Raise approvals for clients or colleagues, and act on ones sent to you"}
        actions={canCreateApproval ? (
          <Button onClick={openNew}>
            <Plus className="h-4 w-4 mr-2" /> New Approval
          </Button>
        ) : undefined}
      />

      {/* New/Edit Approval modal */}
      {showNew && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <h2 className="font-display text-lg font-semibold">{editTarget ? "Edit Approval Request" : "New Approval Request"}</h2>
              <button onClick={() => { setShowNew(false); setEditTarget(null); }}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-5">
              {newError && <p className="text-xs text-red-500">{newError}</p>}

              <section>
                <FSectionHeading n={1}>Recipient</FSectionHeading>
                <div className="flex gap-1 border border-border rounded-lg p-1 bg-muted/30 mb-3">
                  {(["client", "colleague"] as const).map((m) => (
                    <button key={m} type="button" onClick={() => setRecipientMode(m)}
                      className={`flex-1 px-3 py-1.5 rounded text-sm font-medium capitalize transition-colors ${
                        recipientMode === m ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"
                      }`}>
                      {m === "client" ? "To a client" : "To a colleague"}
                    </button>
                  ))}
                </div>

                {recipientMode === "client" ? (
                  <div className="grid grid-cols-4 gap-2">
                    <div className="col-span-4">
                      <FLbl>Client</FLbl>
                      <SearchCombobox
                        value={form.client_id}
                        items={clients}
                        onSelect={(id) => setForm(p => ({ ...p, client_id: id }))}
                        getId={(c: any) => c.id}
                        filterItem={(c: any, q) => (c.company_name ?? "").toLowerCase().includes(q) || (c.legal_name ?? "").toLowerCase().includes(q) || (c.client_code ?? "").toLowerCase().includes(q)}
                        renderOption={(c: any) => (
                          <>
                            <span className="font-mono text-[11px] text-gold flex-shrink-0">{c.client_code}</span>
                            <span className="truncate text-xs text-muted-foreground">{c.company_name ?? c.legal_name}</span>
                          </>
                        )}
                        renderSelected={(c: any) => `${c.client_code} – ${c.company_name ?? c.legal_name}`}
                        placeholder="Search client name or code…"
                      />
                    </div>
                    <div className="col-span-4">
                      <FLbl>Approval kind</FLbl>
                      <div className="flex gap-1 border border-border rounded-lg p-1 bg-muted/30">
                        {([
                          { v: "budget" as const, label: "Budget / estimate" },
                          { v: "technical" as const, label: "Technical / draft" },
                        ]).map((k) => (
                          <button key={k.v} type="button" onClick={() => setForm(p => ({ ...p, kind: k.v }))}
                            className={`flex-1 px-3 py-1.5 rounded text-xs font-medium transition-colors ${
                              form.kind === k.v ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"
                            }`}>
                            {k.label}
                          </button>
                        ))}
                      </div>
                      <p className="mt-1 text-[10px] text-muted-foreground">
                        {form.kind === "budget"
                          ? "Client admin approves spend before work proceeds."
                          : "Draft ready to file — the inventor and/or client admin can sign off."}
                      </p>
                    </div>
                  </div>
                ) : (
                  <div className="grid grid-cols-4 gap-2">
                    <div className="col-span-4">
                      <FLbl>Colleague</FLbl>
                      <div className="relative mb-1">
                        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3 w-3 text-muted-foreground" />
                        <input type="text" placeholder="Filter colleagues…" value={colleagueSearch}
                          onChange={(e) => setColleagueSearch(e.target.value)}
                          className="w-full h-8 pl-7 pr-2 rounded border border-border bg-background text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <select value={form.approver_id} onChange={e => setForm(p => ({ ...p, approver_id: e.target.value }))}
                        className={fInp}>
                        <option value="">Select colleague…</option>
                        {filteredColleagues.map((u: any) => (
                          <option key={u.id} value={u.id}>{u.name} ({u.role})</option>
                        ))}
                      </select>
                    </div>
                  </div>
                )}
              </section>

              <section>
                <FSectionHeading n={2}>Request Details</FSectionHeading>
                <div className="grid grid-cols-4 gap-2">
                  <div className="col-span-2">
                    <FLbl>Case / UIN (optional)</FLbl>
                    <ProjectCombobox
                      value={form.project_id}
                      projects={projects}
                      onSelect={(id) => setForm(p => ({ ...p, project_id: id }))}
                    />
                  </div>
                  <div className="col-span-2">
                    <FLbl req>Title</FLbl>
                    <input type="text" value={form.title} onChange={e => setForm(p => ({ ...p, title: e.target.value }))}
                      placeholder="e.g., Approve draft patent specification"
                      className={fInp} />
                  </div>
                  <div className="col-span-4">
                    <FLbl>Description (optional)</FLbl>
                    <textarea value={form.description} onChange={e => setForm(p => ({ ...p, description: e.target.value }))}
                      rows={2} placeholder="Details of what needs approval…"
                      className={fTa} />
                  </div>

                  {/* Documents — upload new, or pick from what's already on the tagged case */}
                  <div className="col-span-4">
                    <DocumentPicker
                      projectId={form.project_id ? parseInt(form.project_id) : null}
                      clientId={recipientMode === "client" && form.client_id ? parseInt(form.client_id) : null}
                      selected={docs}
                      onChange={setDocs}
                    />
                  </div>
                </div>
              </section>
            </div>
            <div className="flex gap-2 px-6 py-4 border-t border-border flex-shrink-0 justify-end">
              <Button variant="outline" onClick={() => { setShowNew(false); setEditTarget(null); }}>Cancel</Button>
              <Button className="bg-gold hover:bg-gold/90 text-black min-w-[140px]"
                disabled={(recipientMode === "colleague" ? !form.approver_id : !form.client_id) || !form.title.trim() || saving}
                onClick={submitApproval}>
                {saving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />{editTarget ? "Saving…" : "Sending…"}</>
                  : editTarget ? "Save Changes"
                  : recipientMode === "colleague" ? "Send to Colleague" : "Send to Client"}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Revoke confirm modal */}
      {revokeTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Revoke Request</h2>
              <button onClick={() => setRevokeTarget(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <p className="text-sm text-muted-foreground mb-3">
              Withdraw <strong>"{revokeTarget.title ?? revokeTarget.description}"</strong>? The recipient will be notified. This cannot be undone.
            </p>
            <div className="flex gap-2 mt-2">
              <Button variant="destructive" className="flex-1" disabled={revoking} onClick={doRevoke}>
                {revoking ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Revoking…</> : "Confirm Revoke"}
              </Button>
              <Button variant="outline" onClick={() => setRevokeTarget(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {/* Resolve modal — capture an optional comment with the decision */}
      {resolveTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">
                {resolveTarget.action === "Approved" ? "Approve" : "Reject"} request
              </h2>
              <button onClick={() => setResolveTarget(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <p className="text-sm text-muted-foreground mb-3">{resolveTarget.item.description}</p>
            <label className="block text-xs text-muted-foreground mb-1">
              Comment {resolveTarget.action === "Rejected" ? "(recommended)" : "(optional)"}
            </label>
            <textarea value={resolveComment} onChange={e => setResolveComment(e.target.value)} rows={3}
              placeholder="Add a note for the requester…"
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
            <div className="flex gap-2 mt-5">
              <Button
                className={`flex-1 text-white ${resolveTarget.action === "Approved" ? "bg-green-600 hover:bg-green-700" : "bg-red-600 hover:bg-red-700"}`}
                disabled={resolving} onClick={doResolve}>
                {resolving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Submitting…</>
                  : resolveTarget.action === "Approved" ? "Confirm Approve" : "Confirm Reject"}
              </Button>
              <Button variant="outline" onClick={() => setResolveTarget(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-6">
        {error && (
          <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">
            {error}
          </div>
        )}
        {/* Stats */}
        <div className="grid grid-cols-3 gap-4">
          <Card className="border-border">
            <CardContent className="p-4 flex items-center gap-3">
              <div className="relative">
                <Clock className="h-8 w-8 text-amber-500" />
                {pendingCount > 0 && (
                  <span className="absolute -top-1 -right-1 h-3 w-3 rounded-full bg-red-500 animate-pulse" />
                )}
              </div>
              <div>
                <div className="text-2xl font-bold">{pendingCount}</div>
                <div className="text-xs text-muted-foreground">Pending</div>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="p-4 flex items-center gap-3">
              <CheckCircle className="h-8 w-8 text-green-500" />
              <div>
                <div className="text-2xl font-bold">{approvedWeek}</div>
                <div className="text-xs text-muted-foreground">Approved</div>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="p-4 flex items-center gap-3">
              <XCircle className="h-8 w-8 text-red-500" />
              <div>
                <div className="text-2xl font-bold">{rejectedWeek}</div>
                <div className="text-xs text-muted-foreground">Rejected</div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Tabs + Filter */}
        <div className="flex items-center justify-between">
          <div className="flex gap-1 border border-border rounded-lg p-1 bg-muted/30">
            {(["pending", "approved", "rejected", "cancelled"] as const).map((tab) => (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={`px-4 py-1.5 rounded text-sm font-medium capitalize transition-colors ${
                  activeTab === tab ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                {tab}
              </button>
            ))}
          </div>
          {filterTypes.length > 1 && (
            <div className="flex items-center gap-2">
              <Filter className="h-4 w-4 text-muted-foreground" />
              {filterTypes.map((t) => (
                <button
                  key={t}
                  onClick={() => setFilterType(t)}
                  className={`px-3 py-1 rounded text-xs font-medium border transition-colors ${
                    filterType === t ? "bg-gold text-black border-gold" : "border-border text-muted-foreground hover:text-foreground"
                  }`}
                >
                  {t}
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Table */}
        <Card className="border-border">
          <CardContent className="p-0">
            {loading ? (
              <div className="flex items-center justify-center py-16">
                <Loader2 className="h-8 w-8 animate-spin text-gold" />
              </div>
            ) : filtered.length === 0 ? (
              <div className="py-16 text-center text-muted-foreground text-sm">No {activeTab} approvals</div>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Type</th>
                    <th className="px-4 py-3 text-left">Requester</th>
                    <th className="px-4 py-3 text-left">Details</th>
                    <th className="px-4 py-3 text-left">Submitted</th>
                    <th className="px-4 py-3 text-left">Urgency</th>
                    {activeTab === "pending" ? (
                      <th className="px-4 py-3 text-left">Actions</th>
                    ) : (
                      <th className="px-4 py-3 text-left">Resolved</th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((a) => (
                    <tr key={`${a.type}-${a.id}`} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <span className={`px-2 py-0.5 rounded text-xs font-medium border ${typeColors[a.type]}`}>
                          {a.type}
                        </span>
                        {a.kind && (
                          <span className="ml-1 px-1.5 py-0.5 rounded text-[10px] font-medium border border-border text-muted-foreground capitalize">
                            {a.kind}
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3 font-medium">{a.requester}</td>
                      <td className="px-4 py-3">
                        <div>{a.description}</div>
                        {a.project && (
                          <div className="text-xs text-muted-foreground font-mono mt-0.5">
                            {a.project.docket_number}
                          </div>
                        )}
                        {a.amount && (
                          <div className="text-xs text-muted-foreground flex items-center gap-1 mt-0.5">
                            <DollarSign className="h-3 w-3" />{a.amount}
                          </div>
                        )}
                        {a.from_date && (
                          <div className="text-xs text-muted-foreground flex items-center gap-1 mt-0.5">
                            <Calendar className="h-3 w-3" />{String(a.from_date).slice(0, 10)} → {String(a.to_date).slice(0, 10)}
                          </div>
                        )}
                        {(a.documents?.length ?? 0) > 0 && (
                          <div className="text-xs text-muted-foreground flex items-center gap-1 mt-0.5"
                            title={a.documents!.map((d) => d.file_name).join(", ")}>
                            <Paperclip className="h-3 w-3" />{a.documents!.length} document{a.documents!.length > 1 ? "s" : ""}
                          </div>
                        )}
                        {a.comments && (
                          <div className="text-xs text-muted-foreground mt-0.5 italic">"{a.comments}"</div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground text-xs">{a.submitted}</td>
                      <td className="px-4 py-3">
                        {a.urgency === "High" ? (
                          <div className="flex items-center gap-1 text-red-500 text-xs font-medium">
                            <AlertTriangle className="h-3 w-3" /> High
                          </div>
                        ) : (
                          <span className="text-xs text-muted-foreground">Normal</span>
                        )}
                      </td>
                      {activeTab === "pending" ? (
                        <td className="px-4 py-3">
                          <div className="flex flex-col gap-1.5 items-start">
                            {canAct(a) && (
                              <div className="flex gap-2">
                                <Button size="sm" className="h-7 px-3 bg-green-600 hover:bg-green-700 text-white text-xs" onClick={() => { setResolveTarget({ item: a, action: "Approved" }); setResolveComment(""); }}>
                                  <CheckCircle className="h-3 w-3 mr-1" /> Approve
                                </Button>
                                <Button size="sm" variant="outline" className="h-7 px-3 text-xs border-red-200 text-red-600 hover:bg-red-50" onClick={() => { setResolveTarget({ item: a, action: "Rejected" }); setResolveComment(""); }}>
                                  <XCircle className="h-3 w-3 mr-1" /> Reject
                                </Button>
                              </div>
                            )}
                            {a.can_manage && (
                              <div className="flex gap-2">
                                <Button size="sm" variant="outline" className="h-7 px-3 text-xs" onClick={() => openEditApproval(a)}>
                                  <Pencil className="h-3 w-3 mr-1" /> Edit
                                </Button>
                                <Button size="sm" variant="outline" className="h-7 px-3 text-xs border-red-200 text-red-600 hover:bg-red-50" onClick={() => setRevokeTarget(a)}>
                                  <Undo2 className="h-3 w-3 mr-1" /> Revoke
                                </Button>
                              </div>
                            )}
                            {!canAct(a) && !a.can_manage && (
                              <span className="text-xs text-muted-foreground">
                                {a.type === "Client" && !isClientUser ? "Awaiting client"
                                  : a.type === "Colleague" ? "Awaiting colleague"
                                  : "View only"}
                              </span>
                            )}
                          </div>
                        </td>
                      ) : (
                        <td className="px-4 py-3 text-xs text-muted-foreground capitalize">
                          {a.status}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
