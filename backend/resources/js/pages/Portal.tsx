import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Globe, Eye, EyeOff, Clock, Download, LogIn, Mail, Loader2, Plus, X, CheckCircle } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

const HARDCODED_LAST_LOGINS = [
  "2 hours ago",
  "Yesterday",
  "3 days ago",
  "1 week ago",
  "Today, 9:41 AM",
  "Today, 11:05 AM",
  "2 days ago",
  "Never",
];

const HARDCODED_DOCS_SHARED = [4, 7, 2, 11, 3, 9, 1, 6];

const ACTIVITY_FEED = [
  { icon: Download, text: "Acme Corp downloaded Patent_Filing_2024.pdf", time: "2 hours ago" },
  { icon: LogIn,    text: "Tech Solutions logged in",                    time: "Yesterday" },
  { icon: Download, text: "Bright Innovations downloaded Trademark_App_TM221.pdf", time: "Yesterday" },
  { icon: Mail,     text: "Portal invite sent to GlobalTech Inc",        time: "2 days ago" },
  { icon: LogIn,    text: "Nexgen Partners logged in",                   time: "3 days ago" },
  { icon: Download, text: "Sunrise Brands downloaded NDA_Signed_Copy.pdf", time: "3 days ago" },
  { icon: LogIn,    text: "Crestview LLC logged in",                    time: "4 days ago" },
  { icon: Mail,     text: "Portal invite resent to Delta Systems",       time: "1 week ago" },
];

export default function Portal() {
  const [clients, setClients] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);

  const [showNewPortal, setShowNewPortal] = useState(false);
  const [newPortalClient, setNewPortalClient] = useState("");
  const [newPortalEmail, setNewPortalEmail] = useState("");
  const [portalCreated, setPortalCreated] = useState(false);
  const [saving, setSaving] = useState(false);

  const [showInviteAll, setShowInviteAll] = useState(false);
  const [inviteAllDone, setInviteAllDone] = useState(false);

  const load = () => api.getPortalClients().then(setClients).catch(() => {}).finally(() => setLoading(false));
  useEffect(() => { load(); }, []);

  function toggleStatus(id: number) {
    setClients((prev) => prev.map((c) => (c.id === id ? { ...c, portal_enabled: !c.portal_enabled } : c)));
    api.togglePortal(id).catch(() => load());
  }

  async function createPortal() {
    if (!newPortalClient || !newPortalEmail) return;
    setSaving(true);
    try {
      await api.createPortal({ client_id: parseInt(newPortalClient), email: newPortalEmail });
      setPortalCreated(true);
      load();
    } catch { /* keep modal open */ }
    finally { setSaving(false); }
  }

  async function inviteAll() {
    setSaving(true);
    try {
      await api.portalInviteAll();
      setInviteAllDone(true);
      load();
    } catch { /* keep modal open */ }
    finally { setSaving(false); }
  }

  const activePortals = clients.filter((c) => c.portal_enabled).length;
  const pendingInvites = clients.filter((c) => !c.portal_enabled && c.portal_invited_at).length;
  const inactiveCount = clients.filter((c) => !c.portal_enabled).length;

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
            <Button onClick={() => { setShowNewPortal(true); setPortalCreated(false); setNewPortalClient(""); setNewPortalEmail(""); }}>
              <Globe className="h-4 w-4 mr-2" />New Portal
            </Button>
          </>
        }
      />

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
                <div className="text-sm text-muted-foreground mt-1">Access credentials have been sent to {newPortalEmail}</div>
                <Button className="mt-4" variant="outline" onClick={() => setShowNewPortal(false)}>Close</Button>
              </div>
            ) : (
              <>
                <div className="space-y-3">
                  <div>
                    <label className="block text-xs text-muted-foreground mb-1">Client</label>
                    <select value={newPortalClient} onChange={e => setNewPortalClient(e.target.value)}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                      <option value="">Select client</option>
                      {clients.map(c => <option key={c.id} value={c.id}>{c.company_name}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs text-muted-foreground mb-1">Contact Email</label>
                    <input type="email" value={newPortalEmail} onChange={e => setNewPortalEmail(e.target.value)}
                      placeholder="client@company.com"
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                </div>
                <div className="flex gap-2 mt-5">
                  <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={!newPortalClient || !newPortalEmail || saving}
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
                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-green-500/10">
                  <LogIn className="h-5 w-5 text-green-500" />
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
          <CardHeader>
            <CardTitle className="font-display">Client Portals</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left">Client Name</th>
                  <th className="px-4 py-3 text-left">Company</th>
                  <th className="px-4 py-3 text-left">Portal Status</th>
                  <th className="px-4 py-3 text-left">Last Login</th>
                  <th className="px-4 py-3 text-left">Docs Shared</th>
                  <th className="px-4 py-3 text-left">Actions</th>
                </tr>
              </thead>
              <tbody>
                {clients.map((client, idx) => {
                  const isActive = !!client.portal_enabled;
                  const lastLogin = isActive ? HARDCODED_LAST_LOGINS[idx % HARDCODED_LAST_LOGINS.length] : "Never";
                  const docsShared = isActive ? HARDCODED_DOCS_SHARED[idx % HARDCODED_DOCS_SHARED.length] : 0;
                  const contactName =
                    client.client_code ?? `Client #${client.id}`;
                  const company = client.company_name ?? client.company ?? "—";

                  return (
                    <tr key={client.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3 font-medium">{contactName}</td>
                      <td className="px-4 py-3 text-muted-foreground">{company}</td>
                      <td className="px-4 py-3">
                        <button
                          onClick={() => toggleStatus(client.id)}
                          className="flex items-center gap-1.5 focus:outline-none"
                          title="Toggle portal status"
                        >
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
                      <td className="px-4 py-3 text-muted-foreground text-xs">
                        <div className="flex items-center gap-1.5">
                          <Clock className="h-3.5 w-3.5" />
                          {lastLogin}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground text-center">{docsShared}</td>
                      <td className="px-4 py-3">
                        {isActive ? (
                          <Button
                            size="sm"
                            variant="outline"
                            className="text-xs h-7 text-destructive border-destructive/40 hover:bg-destructive/10"
                            onClick={() => toggleStatus(client.id)}
                          >
                            Disable
                          </Button>
                        ) : (
                          <Button
                            size="sm"
                            variant="outline"
                            className="text-xs h-7"
                            onClick={() => toggleStatus(client.id)}
                          >
                            <Mail className="h-3 w-3 mr-1" /> Invite
                          </Button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
            {clients.length === 0 && (
              <div className="py-12 text-center text-muted-foreground text-sm">
                No clients found.
              </div>
            )}
          </CardContent>
        </Card>

        {/* Recent Activity */}
        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display">Recent Activity</CardTitle>
          </CardHeader>
          <CardContent className="space-y-1 pb-4">
            {ACTIVITY_FEED.map((item, idx) => {
              const Icon = item.icon;
              return (
                <div
                  key={idx}
                  className="flex items-center gap-3 rounded-md px-3 py-2.5 hover:bg-muted/30 border-t border-border first:border-t-0"
                >
                  <div className="flex h-7 w-7 items-center justify-center rounded-full bg-muted flex-shrink-0">
                    <Icon className="h-3.5 w-3.5 text-gold" />
                  </div>
                  <span className="flex-1 text-sm text-foreground">{item.text}</span>
                  <span className="text-xs text-muted-foreground whitespace-nowrap">{item.time}</span>
                </div>
              );
            })}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
