import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { CheckCircle, XCircle, Clock, DollarSign, Calendar, AlertTriangle, Filter, Loader2, Plus, X, Search } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

type ApprovalType = "Leave" | "Expense" | "Client" | "Colleague";
type ApprovalStatus = "pending" | "approved" | "rejected";

interface Approval {
  id: number;
  type: ApprovalType;
  kind?: "budget" | "technical" | null;
  requester: string;
  description: string;
  amount?: string | null;
  from_date?: string | null;
  to_date?: string | null;
  submitted: string;
  urgency: "High" | "Normal";
  status: ApprovalStatus;
  comments?: string | null;
  can_resolve?: boolean;
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
  const [activeTab, setActiveTab] = useState<"pending" | "approved" | "rejected">("pending");
  const [filterType, setFilterType] = useState<ApprovalType | "All">("All");

  // New approval modal (firm side)
  const [showNew, setShowNew] = useState(false);
  const [recipientMode, setRecipientMode] = useState<"client" | "colleague">("client");
  const [clients, setClients] = useState<any[]>([]);
  const [colleagues, setColleagues] = useState<any[]>([]);
  const [form, setForm] = useState<{ client_id: string; approver_id: string; title: string; description: string; kind: "budget" | "technical" }>(
    { client_id: "", approver_id: "", title: "", description: "", kind: "budget" }
  );
  const [clientSearch, setClientSearch] = useState("");
  const [colleagueSearch, setColleagueSearch] = useState("");
  const [saving, setSaving] = useState(false);
  const [newError, setNewError] = useState("");

  // Resolve modal (capture an optional comment / description with the decision)
  const [resolveTarget, setResolveTarget] = useState<{ item: Approval; action: "Approved" | "Rejected" } | null>(null);
  const [resolveComment, setResolveComment] = useState("");
  const [resolving, setResolving] = useState(false);

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

  async function createApproval() {
    const isColleague = recipientMode === "colleague";
    if ((isColleague ? !form.approver_id : !form.client_id) || !form.title.trim()) return;
    setSaving(true);
    setNewError("");
    try {
      await api.createApproval({
        client_id: isColleague ? undefined : Number(form.client_id),
        approver_id: isColleague ? Number(form.approver_id) : undefined,
        title: form.title.trim(),
        description: form.description.trim() || undefined,
        kind: isColleague ? undefined : form.kind,
      });
      setShowNew(false);
      setForm({ client_id: "", approver_id: "", title: "", description: "", kind: "budget" });
      load();
    } catch (e: any) {
      setNewError(e?.message || "Failed to create approval request.");
    } finally {
      setSaving(false);
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

  const filteredClients = clients.filter((c: any) => {
    const q = clientSearch.toLowerCase();
    return !q || (c.company_name ?? "").toLowerCase().includes(q) || (c.client_code ?? "").toLowerCase().includes(q);
  }).slice(0, 15);

  const filteredColleagues = colleagues.filter((u: any) => {
    const q = colleagueSearch.toLowerCase();
    return !q || (u.name ?? "").toLowerCase().includes(q) || (u.email ?? "").toLowerCase().includes(q);
  }).slice(0, 15);

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
          <Button onClick={() => { setShowNew(true); setNewError(""); }}>
            <Plus className="h-4 w-4 mr-2" /> New Approval
          </Button>
        ) : undefined}
      />

      {/* New Client Approval modal */}
      {showNew && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">New Approval Request</h2>
              <button onClick={() => setShowNew(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {newError && <p className="text-xs text-red-500 mb-3">{newError}</p>}
            <div className="space-y-3">
              {/* Recipient toggle */}
              <div className="flex gap-1 border border-border rounded-lg p-1 bg-muted/30">
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
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Client</label>
                  <div className="relative mb-1">
                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3 w-3 text-muted-foreground" />
                    <input type="text" placeholder="Filter clients…" value={clientSearch}
                      onChange={(e) => setClientSearch(e.target.value)}
                      className="w-full h-8 pl-7 pr-3 rounded-md border border-border bg-background text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                  <select value={form.client_id} onChange={e => setForm(p => ({ ...p, client_id: e.target.value }))}
                    className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                    <option value="">Select client…</option>
                    {filteredClients.map((c: any) => (
                      <option key={c.id} value={c.id}>{c.company_name ?? c.legal_name} ({c.client_code})</option>
                    ))}
                  </select>
                  <label className="block text-xs text-muted-foreground mt-3 mb-1">Approval kind</label>
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
                  <p className="mt-1 text-[11px] text-muted-foreground">
                    {form.kind === "budget"
                      ? "Client admin approves spend before work proceeds."
                      : "Draft ready to file — the inventor and/or client admin can sign off."}
                  </p>
                </div>
              ) : (
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Colleague</label>
                  <div className="relative mb-1">
                    <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3 w-3 text-muted-foreground" />
                    <input type="text" placeholder="Filter colleagues…" value={colleagueSearch}
                      onChange={(e) => setColleagueSearch(e.target.value)}
                      className="w-full h-8 pl-7 pr-3 rounded-md border border-border bg-background text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                  <select value={form.approver_id} onChange={e => setForm(p => ({ ...p, approver_id: e.target.value }))}
                    className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                    <option value="">Select colleague…</option>
                    {filteredColleagues.map((u: any) => (
                      <option key={u.id} value={u.id}>{u.name} ({u.role})</option>
                    ))}
                  </select>
                </div>
              )}
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Title</label>
                <input type="text" value={form.title} onChange={e => setForm(p => ({ ...p, title: e.target.value }))}
                  placeholder="e.g., Approve draft patent specification"
                  className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Description (optional)</label>
                <textarea value={form.description} onChange={e => setForm(p => ({ ...p, description: e.target.value }))}
                  rows={3} placeholder="Details of what needs approval…"
                  className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
              </div>
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1"
                disabled={(recipientMode === "colleague" ? !form.approver_id : !form.client_id) || !form.title.trim() || saving}
                onClick={createApproval}>
                {saving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Sending…</>
                  : recipientMode === "colleague" ? "Send to Colleague" : "Send to Client"}
              </Button>
              <Button variant="outline" onClick={() => setShowNew(false)}>Cancel</Button>
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
            {(["pending", "approved", "rejected"] as const).map((tab) => (
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
                          {canAct(a) ? (
                            <div className="flex gap-2">
                              <Button size="sm" className="h-7 px-3 bg-green-600 hover:bg-green-700 text-white text-xs" onClick={() => { setResolveTarget({ item: a, action: "Approved" }); setResolveComment(""); }}>
                                <CheckCircle className="h-3 w-3 mr-1" /> Approve
                              </Button>
                              <Button size="sm" variant="outline" className="h-7 px-3 text-xs border-red-200 text-red-600 hover:bg-red-50" onClick={() => { setResolveTarget({ item: a, action: "Rejected" }); setResolveComment(""); }}>
                                <XCircle className="h-3 w-3 mr-1" /> Reject
                              </Button>
                            </div>
                          ) : (
                            <span className="text-xs text-muted-foreground">
                              {a.type === "Client" && !isClientUser ? "Awaiting client"
                                : a.type === "Colleague" ? "Awaiting colleague"
                                : "View only"}
                            </span>
                          )}
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
