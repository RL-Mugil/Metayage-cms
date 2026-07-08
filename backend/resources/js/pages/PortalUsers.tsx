import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Users, Plus, X, Loader2, CheckCircle, Trash2, Mail, ShieldCheck } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

interface PortalUser {
  id: number;
  name: string;
  email: string;
  role: string;
  status: string;
  created_at: string;
}

export default function PortalUsers() {
  const [users, setUsers] = useState<PortalUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  // Add user modal
  const [showAdd, setShowAdd] = useState(false);
  const [form, setForm] = useState({ name: "", email: "" });
  const [saving, setSaving] = useState(false);
  const [addError, setAddError] = useState("");
  const [created, setCreated] = useState<{ email: string; password: string; mail_sent: boolean } | null>(null);

  // Remove confirm
  const [confirmRemove, setConfirmRemove] = useState<PortalUser | null>(null);
  const [removing, setRemoving] = useState(false);

  const load = () =>
    api.getMyPortalUsers()
      .then((u) => { setUsers(u); setError(""); })
      .catch((e: any) => setError(e?.message || "Failed to load portal users."))
      .finally(() => setLoading(false));

  useEffect(() => { load(); }, []);

  async function addUser() {
    if (!form.name.trim() || !form.email.trim()) return;
    setSaving(true);
    setAddError("");
    try {
      const res = await api.createMyPortalUser({ name: form.name.trim(), email: form.email.trim() });
      setCreated({ email: res.user?.email ?? form.email, password: res.password, mail_sent: res.mail_sent });
      load();
    } catch (e: any) {
      setAddError(e?.message || "Failed to add user.");
    } finally {
      setSaving(false);
    }
  }

  async function removeUser() {
    if (!confirmRemove) return;
    setRemoving(true);
    try {
      await api.deleteMyPortalUser(confirmRemove.id);
      setConfirmRemove(null);
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to remove user.");
      setConfirmRemove(null);
    } finally {
      setRemoving(false);
    }
  }

  return (
    <AppLayout>
      <Head title="Portal Users" />
      <PageHeader
        eyebrow="Account"
        title="Portal Users"
        description="Add or remove people from your company who can access this portal."
        actions={
          <Button onClick={() => { setShowAdd(true); setForm({ name: "", email: "" }); setCreated(null); setAddError(""); }}>
            <Plus className="h-4 w-4 mr-2" /> Add User
          </Button>
        }
      />

      {/* Add user modal */}
      {showAdd && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Add Portal User</h2>
              <button onClick={() => setShowAdd(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {created ? (
              <div className="py-4 space-y-4">
                <div className="text-center">
                  <CheckCircle className="h-10 w-10 text-green-500 mx-auto mb-3" />
                  <div className="font-semibold">User Added!</div>
                  <div className="text-sm mt-1">
                    {created.mail_sent
                      ? <span className="text-green-500">Invite email sent to {created.email}</span>
                      : <span className="text-amber-500">Email failed — share the credentials below manually</span>}
                  </div>
                </div>
                <div className="rounded-lg border border-gold/30 bg-gold/5 p-4 space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Email</span>
                    <span className="font-mono font-medium">{created.email}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Password</span>
                    <span className="font-mono font-medium text-gold">{created.password}</span>
                  </div>
                </div>
                <Button className="w-full" variant="outline" onClick={() => setShowAdd(false)}>Close</Button>
              </div>
            ) : (
              <>
                {addError && <p className="text-xs text-red-500 mb-3">{addError}</p>}
                <div className="space-y-3">
                  <div>
                    <label className="block text-xs text-muted-foreground mb-1">Full Name</label>
                    <input type="text" value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))}
                      placeholder="Jane Smith"
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                  <div>
                    <label className="block text-xs text-muted-foreground mb-1">Work Email</label>
                    <input type="email" value={form.email} onChange={e => setForm(p => ({ ...p, email: e.target.value }))}
                      placeholder="jane@yourcompany.com"
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                </div>
                <div className="flex gap-2 mt-5">
                  <Button className="bg-gold hover:bg-gold/90 text-black flex-1"
                    disabled={!form.name.trim() || !form.email.trim() || saving} onClick={addUser}>
                    {saving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Adding…</> : <>Add &amp; Send Invite</>}
                  </Button>
                  <Button variant="outline" onClick={() => setShowAdd(false)}>Cancel</Button>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {/* Remove confirm modal */}
      {confirmRemove && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <h2 className="font-display text-lg font-semibold mb-2">Remove User?</h2>
            <p className="text-sm text-muted-foreground mb-4">
              <strong>{confirmRemove.name}</strong> ({confirmRemove.email}) will lose access to the portal immediately.
            </p>
            <div className="flex gap-2">
              <Button className="bg-destructive text-white hover:bg-destructive/90 flex-1" disabled={removing} onClick={removeUser}>
                {removing ? <Loader2 className="h-4 w-4 animate-spin" /> : "Remove"}
              </Button>
              <Button variant="outline" onClick={() => setConfirmRemove(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-6">
        {error && (
          <div className="rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">{error}</div>
        )}

        <Card className="border-border">
          <CardHeader>
            <CardTitle className="font-display flex items-center gap-2">
              <Users className="h-4 w-4 text-gold" /> Your Team ({users.length})
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            {loading ? (
              <div className="flex items-center justify-center py-16">
                <Loader2 className="h-6 w-6 animate-spin text-gold" />
              </div>
            ) : users.length === 0 ? (
              <p className="text-sm text-muted-foreground px-4 py-10 text-center">No portal users yet.</p>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Name</th>
                    <th className="px-4 py-3 text-left">Email</th>
                    <th className="px-4 py-3 text-left">Role</th>
                    <th className="px-4 py-3 text-left">Added</th>
                    <th className="px-4 py-3 text-left">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {users.map((u) => (
                    <tr key={u.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-4 py-3 font-medium">{u.name}</td>
                      <td className="px-4 py-3 text-muted-foreground">
                        <span className="flex items-center gap-1.5"><Mail className="h-3 w-3" />{u.email}</span>
                      </td>
                      <td className="px-4 py-3">
                        {u.role === "client_admin" ? (
                          <Badge className="bg-gold/15 text-gold border-gold/30 flex items-center gap-1 w-fit">
                            <ShieldCheck className="h-3 w-3" /> Admin
                          </Badge>
                        ) : (
                          <Badge variant="secondary">Member</Badge>
                        )}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground text-xs">
                        {u.created_at ? new Date(u.created_at).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "—"}
                      </td>
                      <td className="px-4 py-3">
                        {u.role === "client" && (
                          <Button size="sm" variant="outline"
                            className="text-xs h-7 text-destructive border-destructive/40 hover:bg-destructive/10"
                            onClick={() => setConfirmRemove(u)}>
                            <Trash2 className="h-3 w-3 mr-1" /> Remove
                          </Button>
                        )}
                      </td>
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
