import { Head } from "@inertiajs/react";
import { useEffect, useState, useRef } from "react";
import { Globe, Eye, EyeOff, Mail, Loader2, Plus, X, CheckCircle, Search, KeyRound, ChevronLeft, ChevronRight, AlertCircle, Trash2, ToggleLeft, ToggleRight, CheckCheck, Minus } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const PAGE_SIZE = 10;

type PortalKpiKey = "active" | "pending" | "inactive";

function PortalKpiModal({ kpiKey, clients, onClose }: { kpiKey: PortalKpiKey; clients: any[]; onClose: () => void }) {
  const [search, setSearch] = useState("");

  const titles: Record<PortalKpiKey, string> = {
    active: "Active Portals",
    pending: "Pending Invites",
    inactive: "Inactive Portals",
  };

  const rows = (() => {
    if (kpiKey === "active") return clients.filter((c) => c.portal_enabled);
    if (kpiKey === "pending") return clients.filter((c) => !c.portal_enabled && c.portal_invited_at);
    return clients.filter((c) => !c.portal_enabled);
  })();

  const filtered = rows.filter((c: any) => {
    const q = search.toLowerCase();
    return !q || c.company_name?.toLowerCase().includes(q) || c.client_code?.toLowerCase().includes(q);
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-xl max-h-[88vh] flex flex-col">
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <h2 className="font-display text-lg font-semibold">{titles[kpiKey]}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{filtered.length} client{filtered.length !== 1 ? "s" : ""}</p>
          </div>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>
        <div className="px-6 py-3 border-b border-border flex-shrink-0">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold"
              placeholder="Search by name or code…" value={search} onChange={(e) => setSearch(e.target.value)} />
          </div>
        </div>
        <div className="flex-1 overflow-auto">
          <table className="w-full text-sm">
            <thead className="sticky top-0 bg-muted/60 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
              <tr>
                <th className="px-4 py-3 text-left">Client</th>
                <th className="px-4 py-3 text-left">Code</th>
                <th className="px-4 py-3 text-left">Status</th>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 && <tr><td colSpan={3} className="px-4 py-10 text-center text-muted-foreground">No clients found.</td></tr>}
              {filtered.map((c: any) => (
                <tr key={c.id} className="border-t border-border hover:bg-muted/30">
                  <td className="px-4 py-2.5 font-medium">{c.company_name}</td>
                  <td className="px-4 py-2.5 text-xs font-mono text-muted-foreground">{c.client_code}</td>
                  <td className="px-4 py-2.5 text-xs">
                    {c.portal_enabled
                      ? <span className="text-green-600 font-medium flex items-center gap-1"><Eye className="h-3 w-3" />Active</span>
                      : c.portal_invited_at
                        ? <span className="text-amber-600 font-medium flex items-center gap-1"><Mail className="h-3 w-3" />Invited</span>
                        : <span className="text-muted-foreground flex items-center gap-1"><EyeOff className="h-3 w-3" />Inactive</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

export default function Portal() {
  const [clients, setClients] = useState<any[]>([]);
  const [allClients, setAllClients] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [tableSearch, setTableSearch] = useState("");
  const [page, setPage] = useState(1);

  // New Portal modal
  const [showNewPortal, setShowNewPortal] = useState(false);
  const [portalSearch, setPortalSearch] = useState("");
  const [showDropdown, setShowDropdown] = useState(false);
  const [selectedClient, setSelectedClient] = useState<any | null>(null);
  const [emailList, setEmailList] = useState<string[]>([""]);
  const [newPortalPw, setNewPortalPw] = useState("");
  const [portalCreated, setPortalCreated] = useState(false);
  const [createdCreds, setCreatedCreds] = useState<{ email: string }[] | null>(null);
  const [createError, setCreateError] = useState("");
  const [saving, setSaving] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  // Invite All modal
  const [showInviteAll, setShowInviteAll] = useState(false);
  const [inviteAllDone, setInviteAllDone] = useState(false);

  const [kpiModal, setKpiModal] = useState<PortalKpiKey | null>(null);

  // Bulk selection
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [bulkLoading, setBulkLoading] = useState(false);
  const [confirmBulkDelete, setConfirmBulkDelete] = useState(false);
  const [bulkError, setBulkError] = useState("");
  const [bulkSuccess, setBulkSuccess] = useState("");

  // Reset Password modal
  const [resetTarget, setResetTarget] = useState<any | null>(null);
  const [resetPw, setResetPw] = useState("");
  const [resetSaving, setResetSaving] = useState(false);
  const [resetDone, setResetDone] = useState(false);
  const [resetError, setResetError] = useState("");

  const loadPortal = () => api.getPortalClients().then(setClients).catch(() => {}).finally(() => setLoading(false));
  const loadAll = () => api.getClients(new URLSearchParams({ per_page: '2000' })).then((res: any) => setAllClients(Array.isArray(res) ? res : res?.data ?? [])).catch(() => {});

  useEffect(() => { loadPortal(); loadAll(); }, []);

  // Close dropdown on outside click
  useEffect(() => {
    function handler(e: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setShowDropdown(false);
      }
    }
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  function toggleStatus(id: number) {
    setClients((prev) => prev.map((c) => (c.id === id ? { ...c, portal_enabled: !c.portal_enabled } : c)));
    api.togglePortal(id).catch(() => loadPortal());
  }

  function openInviteModal(client: any) {
    setSelectedClient(client);
    setPortalSearch("");
    setPortalCreated(false);
    setCreatedCreds(null);
    setNewPortalPw("");
    setCreateError("");
    setShowNewPortal(true);
    // contact_email is already on the client object from the allClients list
    const pre = client?.contact_email ? [client.contact_email] : [""];
    setEmailList(pre);
  }

  async function createPortal() {
    const emails = emailList.map(e => e.trim()).filter(Boolean);
    if (!selectedClient || emails.length === 0 || newPortalPw.length < 6) return;
    setSaving(true);
    setCreateError("");
    try {
      const res = await api.createPortal({ client_id: selectedClient.id, emails, password: newPortalPw });
      setCreatedCreds(res.results ?? []);
      setPortalCreated(true);
      loadPortal();
    } catch (e: any) { setCreateError(e?.message || "Failed to create portal."); }
    finally { setSaving(false); }
  }

  async function inviteAll() {
    setSaving(true);
    try {
      await api.portalInviteAll();
      setInviteAllDone(true);
      loadPortal();
    } catch { /* keep modal open */ }
    finally { setSaving(false); }
  }

  async function resetPassword() {
    if (!resetTarget || !resetPw) return;
    if (resetPw.length < 6) { setResetError("Password must be at least 6 characters."); return; }
    if (!resetTarget.portal_user_id) {
      setResetError("No portal account linked. Create the portal first using the + New Portal button.");
      return;
    }
    setResetSaving(true);
    setResetError("");
    try {
      await api.resetPortalPassword(resetTarget.id, resetPw);
      setResetDone(true);
    } catch (e: any) {
      setResetError(e.message || "Failed to reset password.");
    } finally {
      setResetSaving(false);
    }
  }

  async function runBulk(action: 'enable' | 'disable' | 'delete') {
    if (selected.size === 0) return;
    const count = selected.size;
    setBulkLoading(true);
    setBulkError("");
    setBulkSuccess("");
    try {
      const ids = Array.from(selected).map(Number);
      await api.portalBulk(action, ids);
      setSelected(new Set());
      setConfirmBulkDelete(false);
      await loadPortal();
      const label = action === 'enable' ? 'enabled' : action === 'disable' ? 'disabled' : 'deleted';
      setBulkSuccess(`${count} portal${count !== 1 ? 's' : ''} ${label} successfully.`);
      setTimeout(() => setBulkSuccess(""), 4000);
    } catch (e: any) {
      setBulkError(e?.message || "Bulk action failed. Please try again.");
    } finally {
      setBulkLoading(false);
    }
  }

  const toggleRow = (id: number) => {
    setBulkError("");
    setBulkSuccess("");
    setSelected(prev => { const n = new Set(prev); n.has(id) ? n.delete(id) : n.add(id); return n; });
  };

  const someSelected = selected.size > 0;

  const activePortals = clients.filter((c) => c.portal_enabled).length;
  const pendingInvites = clients.filter((c) => !c.portal_enabled && c.portal_invited_at).length;
  const inactiveCount = clients.filter((c) => !c.portal_enabled).length;

  const filteredClients = clients.filter((c) => {
    const q = tableSearch.toLowerCase();
    return !q || c.company_name?.toLowerCase().includes(q) || c.client_code?.toLowerCase().includes(q);
  });
  const totalPages = Math.max(1, Math.ceil(filteredClients.length / PAGE_SIZE));
  const pagedClients = filteredClients.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  const filteredDropdown = allClients.filter((c) => {
    const q = portalSearch.toLowerCase();
    return !q || c.company_name?.toLowerCase().includes(q) || c.client_code?.toLowerCase().includes(q);
  }).slice(0, 20);

  if (loading) {
    return (
      <AppLayout>
        <Head title="Client Portal" />
        <div className="flex h-screen items-center justify-center bg-background">
          <div className="flex flex-col items-center gap-2">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
            <p className="text-sm text-muted-foreground">Loading client portals...</p>
          </div>
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <Head title="Client Portal" />
      <PageHeader
        eyebrow="Engagement"
        title="Client Portal"
        description="Manage client portal access and activity."
        actions={
          <>
            <Button variant="outline" onClick={() => { setShowInviteAll(true); setInviteAllDone(false); }}>
              <Mail className="h-4 w-4 mr-2" />Invite All Inactive
            </Button>
            <Button onClick={() => { setShowNewPortal(true); setPortalCreated(false); setSelectedClient(null); setPortalSearch(""); setEmailList([""]); setNewPortalPw(""); setCreateError(""); }}>
              <Globe className="h-4 w-4 mr-2" />New Portal
            </Button>
          </>
        }
      />

      {/* Create Portal Modal */}
      {showNewPortal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Create Client Portal</h2>
              <button onClick={() => setShowNewPortal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {portalCreated ? (
              <div className="py-4 space-y-4">
                <div className="text-center">
                  <CheckCircle className="h-10 w-10 text-green-500 mx-auto mb-3" />
                  <div className="font-semibold">Portal Account{(createdCreds?.length ?? 0) > 1 ? "s" : ""} Created!</div>
                  <p className="text-xs text-muted-foreground mt-2">
                    Share the password you set with the client directly.<br />
                    They can change it later in Settings → Security.
                  </p>
                </div>
                {createdCreds && createdCreds.map((cred, i) => (
                  <div key={i} className="rounded-lg border border-gold/30 bg-gold/5 px-4 py-2.5 flex justify-between text-sm">
                    <span className="text-muted-foreground">Login email</span>
                    <span className="font-mono font-medium">{cred.email}</span>
                  </div>
                ))}
                <div className="text-xs text-muted-foreground text-center">Login: mypl-cms.139-59-85-216.sslip.io</div>
                <Button className="w-full" variant="outline" onClick={() => { setShowNewPortal(false); setCreatedCreds(null); setPortalCreated(false); }}>Close</Button>
              </div>
            ) : (
              <>
                <div className="space-y-3">
                  <div ref={dropdownRef}>
                    <label className="block text-xs text-muted-foreground mb-1">Client</label>
                    <div className="relative">
                      <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
                      <input
                        type="text"
                        placeholder="Search by name or client code…"
                        value={selectedClient ? selectedClient.company_name : portalSearch}
                        onFocus={() => { setShowDropdown(true); if (selectedClient) { setPortalSearch(""); setSelectedClient(null); } }}
                        onChange={(e) => { setPortalSearch(e.target.value); setSelectedClient(null); setShowDropdown(true); }}
                        className="w-full h-9 rounded-md border border-border bg-background pl-8 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                      />
                    </div>
                    {showDropdown && (
                      <div className="absolute z-50 mt-1 w-full max-w-sm rounded-md border border-border bg-background shadow-lg max-h-48 overflow-y-auto">
                        {filteredDropdown.length === 0 ? (
                          <div className="px-3 py-2 text-sm text-muted-foreground">No clients found</div>
                        ) : filteredDropdown.map((c) => (
                          <button key={c.id} className="w-full text-left px-3 py-2 text-sm hover:bg-muted/40 flex items-center gap-2"
                            onClick={() => { setSelectedClient(c); setPortalSearch(""); setShowDropdown(false); setEmailList(c.contact_email ? [c.contact_email] : [""]); }}>
                            <span className="font-medium">{c.company_name}</span>
                            <span className="text-xs text-muted-foreground ml-auto">{c.client_code}</span>
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                  <div>
                    <div className="flex items-center justify-between mb-1">
                      <label className="text-xs text-muted-foreground">Contact Email{emailList.length > 1 ? "s" : ""}</label>
                    </div>
                    <div className="space-y-2">
                      {emailList.map((email, idx) => (
                        <div key={idx} className="flex gap-2">
                          <input
                            type="email"
                            value={email}
                            onChange={e => {
                              const next = [...emailList];
                              next[idx] = e.target.value;
                              setEmailList(next);
                            }}
                            placeholder="client@company.com"
                            className="flex-1 h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                          />
                          {emailList.length > 1 && (
                            <button
                              type="button"
                              onClick={() => setEmailList(emailList.filter((_, i) => i !== idx))}
                              className="h-9 w-9 flex items-center justify-center rounded-md border border-border hover:bg-destructive/10 hover:border-destructive/40 text-muted-foreground hover:text-destructive transition-colors"
                            >
                              <Minus className="h-3.5 w-3.5" />
                            </button>
                          )}
                        </div>
                      ))}
                      <button
                        type="button"
                        onClick={() => setEmailList([...emailList, ""])}
                        className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
                      >
                        <Plus className="h-3.5 w-3.5" /> Add another email
                      </button>
                    </div>
                  </div>
                  <div>
                    <label className="block text-xs text-muted-foreground mb-1">Set Password</label>
                    <input
                      type="text"
                      value={newPortalPw}
                      onChange={e => setNewPortalPw(e.target.value)}
                      placeholder="Min. 6 characters — share this with the client"
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm font-mono focus:outline-none focus:ring-1 focus:ring-gold"
                    />
                    <p className="text-[10px] text-muted-foreground mt-1">The client can change it later in Settings → Security.</p>
                  </div>
                  {createError && (
                    <p className="text-xs text-red-500">{createError}</p>
                  )}
                </div>
                <div className="flex gap-2 mt-5">
                  <Button className="bg-gold hover:bg-gold/90 text-black flex-1"
                    disabled={!selectedClient || emailList.every(e => !e.trim()) || newPortalPw.length < 6 || saving}
                    onClick={createPortal}>
                    {saving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Creating…</> : <>Create Portal</>}
                  </Button>
                  <Button variant="outline" onClick={() => setShowNewPortal(false)}>Cancel</Button>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* Invite All Modal */}
      {showInviteAll && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Invite All Inactive Clients</h2>
              <button onClick={() => setShowInviteAll(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {inviteAllDone ? (
              <div className="text-center py-4">
                <CheckCircle className="h-10 w-10 text-green-500 mx-auto mb-3" />
                <div className="font-semibold">Invitations Sent!</div>
                <div className="text-sm text-muted-foreground mt-1">{inactiveCount} inactive clients have been notified.</div>
                <Button className="mt-4" variant="outline" onClick={() => setShowInviteAll(false)}>Close</Button>
              </div>
            ) : (
              <>
                <p className="text-sm text-muted-foreground mb-4">
                  This will send portal access invitations to all <strong>{inactiveCount}</strong> inactive clients.
                </p>
                <div className="flex gap-2">
                  <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={saving} onClick={inviteAll}>
                    {saving ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Mail className="h-4 w-4 mr-2" />}Send All Invitations
                  </Button>
                  <Button variant="outline" onClick={() => setShowInviteAll(false)}>Cancel</Button>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* Reset Password Modal */}
      {resetTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Reset Portal Password</h2>
              <button onClick={() => { setResetTarget(null); setResetPw(""); setResetDone(false); setResetError(""); }}>
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>
            {resetDone ? (
              <div className="text-center py-4">
                <CheckCircle className="h-10 w-10 text-green-500 mx-auto mb-3" />
                <div className="font-semibold">Password Reset!</div>
                <div className="text-sm text-muted-foreground mt-1">Password updated for {resetTarget.company_name}.</div>
                <Button className="mt-4" variant="outline" onClick={() => { setResetTarget(null); setResetPw(""); setResetDone(false); }}>Close</Button>
              </div>
            ) : (
              <>
                <p className="text-sm text-muted-foreground mb-3">Set a new password for <strong>{resetTarget.company_name}</strong>.</p>
                {resetError && <p className="text-xs text-red-500 mb-2">{resetError}</p>}
                <input
                  type="password"
                  value={resetPw}
                  onChange={e => setResetPw(e.target.value)}
                  placeholder="New password (min. 6 chars)"
                  className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold mb-4"
                />
                <div className="flex gap-2">
                  <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={!resetPw || resetSaving} onClick={resetPassword}>
                    {resetSaving ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <KeyRound className="h-4 w-4 mr-2" />}Reset Password
                  </Button>
                  <Button variant="outline" onClick={() => { setResetTarget(null); setResetPw(""); setResetError(""); }}>Cancel</Button>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-6">
        {kpiModal && <PortalKpiModal kpiKey={kpiModal} clients={clients} onClose={() => setKpiModal(null)} />}
        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-3">
          {([
            { label: "Portals Active", value: activePortals, icon: Globe, color: "text-gold", bg: "bg-gold/10", kpiKey: "active" as PortalKpiKey },
            { label: "Pending Invites", value: pendingInvites, icon: Mail, color: "text-amber-500", bg: "bg-amber-500/10", kpiKey: "pending" as PortalKpiKey },
            { label: "Portals Inactive", value: inactiveCount, icon: EyeOff, color: "text-muted-foreground", bg: "bg-muted", kpiKey: "inactive" as PortalKpiKey },
          ]).map(({ label, value, icon: Icon, color, bg, kpiKey }) => (
            <button key={label} onClick={() => setKpiModal(kpiKey)}
              className="rounded-xl border border-border bg-card p-4 text-left transition-all hover:shadow-md hover:border-gold/40 cursor-pointer">
              <div className="flex items-center gap-4">
                <div className={`flex h-10 w-10 items-center justify-center rounded-full ${bg} flex-shrink-0`}>
                  <Icon className={`h-5 w-5 ${color}`} />
                </div>
                <div>
                  <div className="font-display text-2xl font-semibold">{value}</div>
                  <div className="text-xs text-muted-foreground">{label}</div>
                </div>
              </div>
              <div className="mt-1 text-xs text-muted-foreground">Click to view</div>
            </button>
          ))}
        </div>

        {/* Bulk success toast */}
        {bulkSuccess && (
          <div className="flex items-center gap-2 rounded-lg border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm text-green-600">
            <CheckCheck className="h-4 w-4 flex-shrink-0" />
            {bulkSuccess}
          </div>
        )}

        {/* Client portal table */}
        <Card className="border-border">
          <CardHeader className="flex flex-row items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <CardTitle className="font-display">Client Portals</CardTitle>
              {someSelected && (
                <span className="text-xs font-medium px-2 py-0.5 rounded-full bg-gold/15 text-gold border border-gold/30">
                  {selected.size} selected
                </span>
              )}
            </div>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
              <input
                type="text"
                placeholder="Search by name or code…"
                value={tableSearch}
                onChange={e => { setTableSearch(e.target.value); setPage(1); }}
                className="h-8 w-52 rounded-md border border-border bg-background pl-8 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
              />
            </div>
          </CardHeader>

          {/* Bulk action bar */}
          {someSelected && (
            <div className="mx-4 mb-3 space-y-2">
              {bulkError && (
                <div className="flex items-center gap-2 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
                  <AlertCircle className="h-3.5 w-3.5 flex-shrink-0" />
                  {bulkError}
                </div>
              )}
              <div className="flex items-center gap-2 rounded-lg border border-gold/30 bg-gold/5 px-4 py-2.5">
              <span className="text-xs text-muted-foreground mr-1">
                {selected.size === filteredClients.length
                  ? `All ${selected.size} portals selected`
                  : `${selected.size} of ${filteredClients.length} selected`}
              </span>
              <div className="flex-1" />
              <Button size="sm" variant="outline" className="h-7 text-xs gap-1.5" disabled={bulkLoading}
                onClick={() => runBulk('enable')}>
                <ToggleRight className="h-3.5 w-3.5 text-green-500" /> Enable
              </Button>
              <Button size="sm" variant="outline" className="h-7 text-xs gap-1.5" disabled={bulkLoading}
                onClick={() => runBulk('disable')}>
                <ToggleLeft className="h-3.5 w-3.5 text-muted-foreground" /> Disable
              </Button>
              {!confirmBulkDelete ? (
                <Button size="sm" variant="outline" className="h-7 text-xs gap-1.5 text-destructive border-destructive/40 hover:bg-destructive/10"
                  disabled={bulkLoading} onClick={() => setConfirmBulkDelete(true)}>
                  <Trash2 className="h-3.5 w-3.5" /> Remove Access
                </Button>
              ) : (
                <div className="flex items-center gap-1.5">
                  <span className="text-xs text-destructive font-medium">Remove access for {selected.size} portal{selected.size !== 1 ? "s" : ""}?</span>
                  <Button size="sm" className="h-7 text-xs bg-destructive text-white hover:bg-destructive/90" disabled={bulkLoading}
                    onClick={() => runBulk('delete')}>
                    {bulkLoading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : "Confirm"}
                  </Button>
                  <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => setConfirmBulkDelete(false)}>
                    Cancel
                  </Button>
                </div>
              )}
              <Button size="sm" variant="ghost" className="h-7 w-7 p-0 ml-1" onClick={() => { setSelected(new Set()); setConfirmBulkDelete(false); setBulkError(""); }}>
                <X className="h-3.5 w-3.5" />
              </Button>
            </div>
            </div>
          )}

          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 w-10">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-border accent-gold cursor-pointer"
                      checked={filteredClients.length > 0 && filteredClients.every(c => selected.has(c.id))}
                      ref={el => { if (el) el.indeterminate = someSelected && !filteredClients.every(c => selected.has(c.id)); }}
                      onChange={() => {
                        const allSel = filteredClients.every(c => selected.has(c.id));
                        setSelected(allSel ? new Set() : new Set(filteredClients.map(c => c.id)));
                        setConfirmBulkDelete(false);
                      }}
                    />
                  </th>
                  <th className="px-4 py-3 text-left">Client</th>
                  <th className="px-4 py-3 text-left">Code</th>
                  <th className="px-4 py-3 text-left">Portal Status</th>
                  <th className="px-4 py-3 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                {pagedClients.map((client) => {
                  const isActive = !!client.portal_enabled;
                  const isSelected = selected.has(client.id);
                  return (
                    <tr key={client.id} className={`border-t border-border hover:bg-muted/30 ${isSelected ? "bg-gold/5" : ""}`}>
                      <td className="px-4 py-3">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-border accent-gold cursor-pointer"
                          checked={isSelected}
                          onChange={() => toggleRow(client.id)}
                        />
                      </td>
                      <td className="px-4 py-3 font-medium">{client.company_name ?? "—"}</td>
                      <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{client.client_code}</td>
                      <td className="px-4 py-3">
                        <button onClick={() => toggleStatus(client.id)} className="focus:outline-none">
                          {isActive ? (
                            <Badge className="cursor-pointer flex items-center gap-1 bg-green-500/10 text-green-600 border-green-500/30 hover:bg-green-500/20">
                              <Eye className="h-3 w-3" /> Active
                            </Badge>
                          ) : (
                            <Badge variant="outline" className="cursor-pointer flex items-center gap-1 text-muted-foreground hover:bg-muted/50">
                              <EyeOff className="h-3 w-3" /> Inactive
                            </Badge>
                          )}
                        </button>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2">
                          {isActive ? (
                            <Button size="sm" variant="outline" className="text-xs h-7 text-destructive border-destructive/40 hover:bg-destructive/10"
                              onClick={() => toggleStatus(client.id)}>
                              Disable
                            </Button>
                          ) : client.portal_user_id ? (
                            <Button size="sm" variant="outline" className="text-xs h-7"
                              onClick={() => toggleStatus(client.id)}>
                              Enable
                            </Button>
                          ) : (
                            <Button size="sm" variant="outline" className="text-xs h-7"
                              onClick={() => openInviteModal(client)}>
                              <Mail className="h-3 w-3 mr-1" /> Invite
                            </Button>
                          )}
                          {client.portal_user_id && (
                            <Button size="sm" variant="outline" className="text-xs h-7"
                              onClick={() => { setResetTarget(client); setResetPw(""); setResetDone(false); setResetError(""); }}>
                              <KeyRound className="h-3 w-3 mr-1" /> Reset PW
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
            {filteredClients.length === 0 && (
              <div className="py-12 text-center text-muted-foreground text-sm">
                {clients.length === 0 ? "No clients found." : "No results match your search."}
              </div>
            )}
            {/* Pagination */}
            {totalPages > 1 && (
              <div className="flex items-center justify-between px-4 py-3 border-t border-border">
                <span className="text-xs text-muted-foreground">
                  {filteredClients.length} clients · Page {page} of {totalPages}
                  {someSelected && ` · ${selected.size} selected`}
                </span>
                <div className="flex items-center gap-1">
                  <Button size="sm" variant="outline" className="h-7 w-7 p-0" disabled={page === 1} onClick={() => setPage(p => p - 1)}>
                    <ChevronLeft className="h-4 w-4" />
                  </Button>
                  <Button size="sm" variant="outline" className="h-7 w-7 p-0" disabled={page === totalPages} onClick={() => setPage(p => p + 1)}>
                    <ChevronRight className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Recent Activity — empty state (no hardcoded data) */}
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display">Recent Activity</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="py-8 text-center text-muted-foreground text-sm">
              No recent activity to display.
            </div>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
