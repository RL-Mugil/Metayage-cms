import { Head } from "@inertiajs/react";
import { useEffect, useState, useRef } from "react";
import { Globe, Eye, EyeOff, Mail, Loader2, Plus, X, CheckCircle, Search, KeyRound, ChevronLeft, ChevronRight } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const PAGE_SIZE = 10;

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
  const [newPortalEmail, setNewPortalEmail] = useState("");
  const [portalCreated, setPortalCreated] = useState(false);
  const [saving, setSaving] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  // Invite All modal
  const [showInviteAll, setShowInviteAll] = useState(false);
  const [inviteAllDone, setInviteAllDone] = useState(false);

  // Reset Password modal
  const [resetTarget, setResetTarget] = useState<any | null>(null);
  const [resetPw, setResetPw] = useState("");
  const [resetSaving, setResetSaving] = useState(false);
  const [resetDone, setResetDone] = useState(false);
  const [resetError, setResetError] = useState("");

  const loadPortal = () => api.getPortalClients().then(setClients).catch(() => {}).finally(() => setLoading(false));
  const loadAll = () => api.getClients().then((res: any) => setAllClients(Array.isArray(res) ? res : res?.data ?? [])).catch(() => {});

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

  async function createPortal() {
    if (!selectedClient || !newPortalEmail) return;
    setSaving(true);
    try {
      await api.createPortal({ client_id: selectedClient.id, email: newPortalEmail });
      setPortalCreated(true);
      loadPortal();
    } catch { /* keep modal open */ }
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
    setResetSaving(true);
    setResetError("");
    try {
      await api.resetUserPassword(resetTarget.user_id ?? resetTarget.id, resetPw);
      setResetDone(true);
    } catch (e: any) {
      setResetError(e.message || "Failed to reset password.");
    } finally {
      setResetSaving(false);
    }
  }

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
            <Button onClick={() => { setShowNewPortal(true); setPortalCreated(false); setSelectedClient(null); setPortalSearch(""); setNewPortalEmail(""); }}>
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
              <div className="text-center py-4">
                <CheckCircle className="h-10 w-10 text-green-500 mx-auto mb-3" />
                <div className="font-semibold">Portal Created!</div>
                <div className="text-sm text-muted-foreground mt-1">Credentials sent to {newPortalEmail}</div>
                <Button className="mt-4" variant="outline" onClick={() => setShowNewPortal(false)}>Close</Button>
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
                            onClick={() => { setSelectedClient(c); setPortalSearch(""); setShowDropdown(false); }}>
                            <span className="font-medium">{c.company_name}</span>
                            <span className="text-xs text-muted-foreground ml-auto">{c.client_code}</span>
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                  <div>
                    <label className="block text-xs text-muted-foreground mb-1">Contact Email</label>
                    <input type="email" value={newPortalEmail} onChange={e => setNewPortalEmail(e.target.value)}
                      placeholder="client@company.com"
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                </div>
                <div className="flex gap-2 mt-5">
                  <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={!selectedClient || !newPortalEmail || saving}
                    onClick={createPortal}>
                    {saving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Creating…</> : <>Create &amp; Send Credentials</>}
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
        {/* Stats */}
        <div className="grid gap-4 md:grid-cols-3">
          <Card className="border-border">
            <CardContent className="pt-6">
              <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gold/10">
                  <Globe className="h-5 w-5 text-gold" />
                </div>
                <div>
                  <div className="font-display text-2xl font-semibold">{activePortals}</div>
                  <div className="text-xs text-muted-foreground">Portals Active</div>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="pt-6">
              <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-amber-500/10">
                  <Mail className="h-5 w-5 text-amber-500" />
                </div>
                <div>
                  <div className="font-display text-2xl font-semibold">{pendingInvites}</div>
                  <div className="text-xs text-muted-foreground">Pending Invites</div>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="pt-6">
              <div className="flex items-center gap-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                  <EyeOff className="h-5 w-5 text-muted-foreground" />
                </div>
                <div>
                  <div className="font-display text-2xl font-semibold">{inactiveCount}</div>
                  <div className="text-xs text-muted-foreground">Portals Inactive</div>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Client portal table */}
        <Card className="border-border">
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle className="font-display">Client Portals</CardTitle>
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
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left">Client</th>
                  <th className="px-4 py-3 text-left">Code</th>
                  <th className="px-4 py-3 text-left">Portal Status</th>
                  <th className="px-4 py-3 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                {pagedClients.map((client) => {
                  const isActive = !!client.portal_enabled;
                  return (
                    <tr key={client.id} className="border-t border-border hover:bg-muted/30">
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
                          ) : (
                            <Button size="sm" variant="outline" className="text-xs h-7"
                              onClick={() => toggleStatus(client.id)}>
                              <Mail className="h-3 w-3 mr-1" /> Invite
                            </Button>
                          )}
                          <Button size="sm" variant="outline" className="text-xs h-7"
                            onClick={() => { setResetTarget(client); setResetPw(""); setResetDone(false); setResetError(""); }}>
                            <KeyRound className="h-3 w-3 mr-1" /> Reset PW
                          </Button>
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
