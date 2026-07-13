import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { Users, Plus, X, Loader2, Trash2, KeyRound, Pencil, Search, ShieldCheck, CheckCircle, Ban, ShieldOff } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

interface StaffUser {
  id: number;
  name: string;
  email: string;
  role: string;
  status: string;
  created_at: string;
}

const STAFF_ROLES = ["super_admin", "partner", "manager", "hr", "finance", "associate", "paralegal", "galvanizer"];
const ROLE_LABEL: Record<string, string> = {
  super_admin: "System Admin", partner: "Director", manager: "Patent Attorney",
  hr: "HR", finance: "Accountant", associate: "Patent Analyst", paralegal: "Paralegal",
  galvanizer: "Galvanizer",
};
const ROLE_COLOR: Record<string, string> = {
  super_admin: "bg-red-500/10 text-red-500 border-red-500/30",
  partner: "bg-orange-500/10 text-orange-500 border-orange-500/30",
  manager: "bg-amber-500/10 text-amber-600 border-amber-500/30",
  hr: "bg-purple-500/10 text-purple-500 border-purple-500/30",
  finance: "bg-blue-500/10 text-blue-500 border-blue-500/30",
  associate: "bg-green-500/10 text-green-600 border-green-500/30",
  paralegal: "bg-teal-500/10 text-teal-600 border-teal-500/30",
  galvanizer: "bg-cyan-500/10 text-cyan-600 border-cyan-500/30",
};

const inputCls = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";

export default function StaffUsers() {
  const { props: pageProps } = usePage() as any;
  const myId = pageProps.auth?.user?.id;
  const isAdmin = pageProps.auth?.user?.role === "super_admin";

  const [users, setUsers] = useState<StaffUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");
  const [error, setError] = useState("");
  const [toast, setToast] = useState("");

  // Create/edit modal
  const [modal, setModal] = useState<{ mode: "create" | "edit"; user?: StaffUser } | null>(null);
  const [form, setForm] = useState({ name: "", email: "", role: "associate", status: "Active", password: "" });
  const [saving, setSaving] = useState(false);
  const [modalError, setModalError] = useState("");

  // Reset password modal
  const [pwTarget, setPwTarget] = useState<StaffUser | null>(null);
  const [newPw, setNewPw] = useState("");
  const [pwSaving, setPwSaving] = useState(false);
  const [pwError, setPwError] = useState("");

  // Delete confirm
  const [delTarget, setDelTarget] = useState<StaffUser | null>(null);
  const [deleting, setDeleting] = useState(false);

  // Inline pickers
  const [roleMenu, setRoleMenu] = useState<{ userId: number; rect: DOMRect } | null>(null);
  const [statusMenu, setStatusMenu] = useState<{ userId: number; rect: DOMRect } | null>(null);
  const [pickerSearch, setPickerSearch] = useState("");
  useEffect(() => { if (!roleMenu && !statusMenu) setPickerSearch(""); }, [roleMenu, statusMenu]);

  const showToast = (msg: string) => { setToast(msg); setTimeout(() => setToast(""), 3500); };

  const load = () =>
    api.getStaffUsers()
      .then((u) => { setUsers(u); setError(""); })
      .catch((e: any) => setError(e?.message || "Failed to load users."))
      .finally(() => setLoading(false));

  useEffect(() => { load(); }, []);

  const filtered = users.filter((u) => {
    const q = search.toLowerCase();
    return !q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q) || (ROLE_LABEL[u.role] ?? u.role).toLowerCase().includes(q);
  });

  function openCreate() {
    setModal({ mode: "create" });
    setForm({ name: "", email: "", role: "associate", status: "Active", password: "" });
    setModalError("");
  }

  function openEdit(u: StaffUser) {
    setModal({ mode: "edit", user: u });
    setForm({ name: u.name, email: u.email, role: u.role, status: u.status, password: "" });
    setModalError("");
  }

  async function save() {
    if (!form.name.trim() || !form.email.trim()) { setModalError("Name and email are required."); return; }
    if (modal?.mode === "create" && form.password.length < 6) { setModalError("Password must be at least 6 characters."); return; }
    setSaving(true);
    setModalError("");
    try {
      if (modal?.mode === "create") {
        await api.createStaffUser({ name: form.name.trim(), email: form.email.trim(), role: form.role, password: form.password });
        showToast(`${form.name.trim()} added.`);
      } else if (modal?.user) {
        await api.updateStaffUser(modal.user.id, { name: form.name.trim(), email: form.email.trim(), role: form.role, status: form.status });
        showToast(`${form.name.trim()} updated.`);
      }
      setModal(null);
      load();
    } catch (e: any) {
      setModalError(e?.message || "Failed to save.");
    } finally { setSaving(false); }
  }

  async function resetPassword() {
    if (!pwTarget || newPw.length < 6) { setPwError("Password must be at least 6 characters."); return; }
    setPwSaving(true);
    setPwError("");
    try {
      await api.resetUserPassword(pwTarget.id, newPw);
      showToast(`Password reset for ${pwTarget.name}. Their sessions were logged out.`);
      setPwTarget(null);
      setNewPw("");
    } catch (e: any) {
      setPwError(e?.message || "Failed to reset password.");
    } finally { setPwSaving(false); }
  }

  async function remove() {
    if (!delTarget) return;
    setDeleting(true);
    try {
      await api.deleteStaffUser(delTarget.id);
      showToast(`${delTarget.name} deleted.`);
      setDelTarget(null);
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to delete user.");
      setDelTarget(null);
    } finally { setDeleting(false); }
  }

  async function quickUpdate(userId: number, patch: Partial<{ role: string; status: string }>) {
    setUsers((prev) => prev.map((u) => u.id === userId ? { ...u, ...patch } : u));
    try { await api.updateStaffUser(userId, patch); }
    catch { load(); }
  }

  if (!isAdmin) {
    return (
      <AppLayout>
        <Head title="Staff Users" />
        <div className="flex flex-col items-center justify-center h-[70vh] gap-3 text-muted-foreground">
          <ShieldCheck className="h-12 w-12 opacity-30" />
          <p className="text-sm">Staff user administration is limited to the System Admin.</p>
        </div>
      </AppLayout>
    );
  }

  return (
    <AppLayout>
      <Head title="Staff Users" />
      <PageHeader
        eyebrow="Operations"
        title="Staff Users"
        description="All internal user accounts — create, edit, reset passwords, and remove access."
        actions={
          <Button onClick={openCreate}>
            <Plus className="h-4 w-4 mr-2" /> Add User
          </Button>
        }
      />

      {/* Create / Edit modal */}
      {modal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">{modal.mode === "create" ? "Add Staff User" : "Edit Staff User"}</h2>
              <button onClick={() => setModal(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            {modalError && <p className="text-xs text-red-500 mb-3">{modalError}</p>}
            <div className="space-y-3">
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Full Name</label>
                <input type="text" value={form.name} onChange={e => setForm(p => ({ ...p, name: e.target.value }))} className={inputCls} />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Email</label>
                <input type="email" value={form.email} onChange={e => setForm(p => ({ ...p, email: e.target.value }))} className={inputCls} />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Role</label>
                  <select value={form.role} onChange={e => setForm(p => ({ ...p, role: e.target.value }))} className={inputCls}>
                    {STAFF_ROLES.map(r => <option key={r} value={r}>{ROLE_LABEL[r]}</option>)}
                  </select>
                </div>
                {modal.mode === "edit" && (
                  <div>
                    <label className="block text-xs text-muted-foreground mb-1">Status</label>
                    <select value={form.status} onChange={e => setForm(p => ({ ...p, status: e.target.value }))} className={inputCls}>
                      <option>Active</option><option>Suspended</option><option>Inactive</option>
                    </select>
                  </div>
                )}
              </div>
              {modal.mode === "create" && (
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Set Password</label>
                  <input type="text" value={form.password} onChange={e => setForm(p => ({ ...p, password: e.target.value }))}
                    placeholder="Min. 6 characters" className={`${inputCls} font-mono`} />
                </div>
              )}
            </div>
            <div className="flex gap-2 mt-5">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={saving} onClick={save}>
                {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : modal.mode === "create" ? "Add User" : "Save Changes"}
              </Button>
              <Button variant="outline" onClick={() => setModal(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {/* Reset password modal */}
      {pwTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center justify-between mb-2">
              <h2 className="font-display text-lg font-semibold">Reset Password</h2>
              <button onClick={() => { setPwTarget(null); setNewPw(""); setPwError(""); }}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <p className="text-sm text-muted-foreground mb-3">
              Set a new password for <strong>{pwTarget.name}</strong>. All their active sessions will be logged out.
            </p>
            {pwError && <p className="text-xs text-red-500 mb-2">{pwError}</p>}
            <input type="text" value={newPw} onChange={e => setNewPw(e.target.value)}
              placeholder="New password (min. 6 chars)" className={`${inputCls} font-mono mb-4`} />
            <div className="flex gap-2">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={newPw.length < 6 || pwSaving} onClick={resetPassword}>
                {pwSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <><KeyRound className="h-4 w-4 mr-2" />Reset Password</>}
              </Button>
              <Button variant="outline" onClick={() => { setPwTarget(null); setNewPw(""); }}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {/* Delete confirm */}
      {delTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <h2 className="font-display text-lg font-semibold mb-2">Delete User?</h2>
            <p className="text-sm text-muted-foreground mb-4">
              <strong>{delTarget.name}</strong> ({delTarget.email}) will be permanently deleted and logged out everywhere. If they only need to be blocked, use Suspend instead.
            </p>
            <div className="flex gap-2">
              <Button className="bg-destructive text-white hover:bg-destructive/90 flex-1" disabled={deleting} onClick={remove}>
                {deleting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Delete"}
              </Button>
              <Button variant="outline" onClick={() => setDelTarget(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-4">
        {toast && (
          <div className="flex items-center gap-2 rounded-lg border border-green-500/30 bg-green-500/10 px-4 py-3 text-sm text-green-600">
            <CheckCircle className="h-4 w-4" /> {toast}
          </div>
        )}
        {error && (
          <div className="rounded-lg border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm text-destructive">{error}</div>
        )}

        <Card className="border-border">
          <CardHeader className="flex flex-row items-center justify-between gap-4">
            <CardTitle className="font-display flex items-center gap-2">
              <Users className="h-4 w-4 text-gold" /> Internal Users ({filtered.length})
            </CardTitle>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
              <input type="text" placeholder="Search name, email, role…" value={search}
                onChange={e => setSearch(e.target.value)}
                className="h-8 w-60 rounded-md border border-border bg-background pl-8 pr-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {loading ? (
              <div className="flex items-center justify-center py-16">
                <Loader2 className="h-6 w-6 animate-spin text-gold" />
              </div>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Name</th>
                    <th className="px-4 py-3 text-left">Email</th>
                    <th className="px-4 py-3 text-left">Role</th>
                    <th className="px-4 py-3 text-left">Status</th>
                    <th className="px-4 py-3 text-left">Created</th>
                    <th className="px-4 py-3 text-right">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((u) => (
                    <tr key={u.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-4 py-3 font-medium">
                        {u.name}{u.id === myId && <span className="ml-2 text-[10px] text-gold">(you)</span>}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground">{u.email}</td>
                      <td className="px-4 py-3">
                        <button
                          title="Click to change role"
                          onClick={(e) => { e.stopPropagation(); const rect = (e.currentTarget as HTMLElement).getBoundingClientRect(); setRoleMenu({ userId: u.id, rect }); }}
                          className={`px-2 py-0.5 rounded text-[11px] font-medium border cursor-pointer hover:opacity-80 transition-opacity ${ROLE_COLOR[u.role] ?? "bg-muted text-muted-foreground"}`}
                        >
                          {ROLE_LABEL[u.role] ?? u.role}
                        </button>
                      </td>
                      <td className="px-4 py-3">
                        <button
                          title="Click to change status"
                          onClick={(e) => { e.stopPropagation(); const rect = (e.currentTarget as HTMLElement).getBoundingClientRect(); setStatusMenu({ userId: u.id, rect }); }}
                          className="cursor-pointer hover:opacity-80 transition-opacity"
                        >
                          <Badge
                            variant={u.status === "Active" ? "default" : "outline"}
                            className={`text-[10px] ${u.status === "Suspended" ? "border-red-400 text-red-500" : u.status === "Inactive" ? "border-muted text-muted-foreground" : ""}`}
                          >
                            {u.status}
                          </Badge>
                        </button>
                      </td>
                      <td className="px-4 py-3 text-muted-foreground text-xs font-mono">
                        {u.created_at ? new Date(u.created_at).toLocaleDateString("en-IN", { day: "numeric", month: "short", year: "numeric" }) : "—"}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex items-center justify-end gap-1">
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0" title="Edit" onClick={() => openEdit(u)}>
                            <Pencil className="h-3.5 w-3.5 text-muted-foreground" />
                          </Button>
                          <Button variant="ghost" size="sm" className="h-8 w-8 p-0" title="Reset password"
                            onClick={() => { setPwTarget(u); setNewPw(""); setPwError(""); }}>
                            <KeyRound className="h-3.5 w-3.5 text-amber-500" />
                          </Button>
                          {u.id !== myId && (
                            <Button variant="ghost" size="sm" className="h-8 w-8 p-0"
                              title={u.status === "Suspended" ? "Unsuspend (set Active)" : "Suspend"}
                              onClick={() => quickUpdate(u.id, { status: u.status === "Suspended" ? "Active" : "Suspended" })}>
                              {u.status === "Suspended"
                                ? <ShieldOff className="h-3.5 w-3.5 text-green-500" />
                                : <Ban className="h-3.5 w-3.5 text-orange-400" />}
                            </Button>
                          )}
                          {u.id !== myId && (
                            <Button variant="ghost" size="sm" className="h-8 w-8 p-0" title="Delete" onClick={() => setDelTarget(u)}>
                              <Trash2 className="h-3.5 w-3.5 text-red-400" />
                            </Button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                  {filtered.length === 0 && (
                    <tr><td colSpan={6} className="px-4 py-12 text-center text-muted-foreground text-sm">No users found.</td></tr>
                  )}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
      {/* ── Role picker portal ──────────────────────────────────────────────── */}
      {roleMenu && createPortal(
        <>
          <div className="fixed inset-0 z-[9998]" onMouseDown={() => setRoleMenu(null)} />
          <div
            className="z-[9999] bg-white rounded-lg shadow-xl border border-border py-1"
            style={(() => {
              const MENU_H = 300;
              const { rect } = roleMenu;
              const spaceBelow = window.innerHeight - rect.bottom;
              const openUp = spaceBelow < MENU_H && rect.top > spaceBelow;
              return openUp
                ? { position: "fixed" as const, bottom: window.innerHeight - rect.top + 4, left: rect.left, minWidth: Math.max(rect.width, 180) }
                : { position: "fixed" as const, top: rect.bottom + 4, left: rect.left, minWidth: Math.max(rect.width, 180) };
            })()}
            onMouseDown={(e) => e.stopPropagation()}
          >
            <div className="px-2 pt-2 pb-1 border-b border-border">
              <input autoFocus type="text" placeholder="Search role…" value={pickerSearch}
                onChange={(e) => setPickerSearch(e.target.value)}
                className="w-full text-[11px] px-2 py-1 rounded border border-border bg-white outline-none focus:border-blue-400 placeholder:text-gray-400"
                onMouseDown={(e) => e.stopPropagation()} />
            </div>
            <div className="overflow-y-auto" style={{ maxHeight: 256 }}>
              {STAFF_ROLES.filter((r) => (ROLE_LABEL[r] ?? r).toLowerCase().includes(pickerSearch.toLowerCase())).map((r) => {
                const current = users.find((u) => u.id === roleMenu.userId)?.role;
                const isCurrent = current === r;
                return (
                  <button key={r}
                    className={`w-full text-left px-3 py-2 text-[12px] flex items-center gap-2 hover:bg-blue-50 transition-colors ${isCurrent ? "bg-blue-50 font-medium text-blue-700" : "text-gray-700"}`}
                    onMouseDown={async (e) => {
                      e.preventDefault();
                      const uid = roleMenu.userId;
                      setRoleMenu(null);
                      await quickUpdate(uid, { role: r });
                    }}
                  >
                    <span className={`px-1.5 py-0.5 rounded text-[10px] font-medium border ${ROLE_COLOR[r] ?? "bg-muted"}`}>{ROLE_LABEL[r] ?? r}</span>
                    {isCurrent && <span className="ml-auto text-blue-500">✓</span>}
                  </button>
                );
              })}
            </div>
          </div>
        </>,
        document.body
      )}

      {/* ── Status picker portal ─────────────────────────────────────────────── */}
      {statusMenu && createPortal(
        <>
          <div className="fixed inset-0 z-[9998]" onMouseDown={() => setStatusMenu(null)} />
          <div
            className="z-[9999] bg-white rounded-lg shadow-xl border border-border py-1"
            style={(() => {
              const MENU_H = 160;
              const { rect } = statusMenu;
              const spaceBelow = window.innerHeight - rect.bottom;
              const openUp = spaceBelow < MENU_H && rect.top > spaceBelow;
              return openUp
                ? { position: "fixed" as const, bottom: window.innerHeight - rect.top + 4, left: rect.left, minWidth: Math.max(rect.width, 140) }
                : { position: "fixed" as const, top: rect.bottom + 4, left: rect.left, minWidth: Math.max(rect.width, 140) };
            })()}
            onMouseDown={(e) => e.stopPropagation()}
          >
            <div className="px-3 py-1.5 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider border-b border-border bg-muted/30">
              Set Status
            </div>
            <div className="overflow-y-auto" style={{ maxHeight: 120 }}>
              {(["Active", "Suspended", "Inactive"] as const).map((s) => {
                const current = users.find((u) => u.id === statusMenu.userId)?.status;
                const isCurrent = current === s;
                const cls = s === "Active" ? "text-green-700" : s === "Suspended" ? "text-red-500" : "text-muted-foreground";
                return (
                  <button key={s}
                    className={`w-full text-left px-3 py-2 text-[12px] flex items-center gap-2 hover:bg-blue-50 transition-colors ${isCurrent ? "bg-blue-50 font-medium text-blue-700" : cls}`}
                    onMouseDown={async (e) => {
                      e.preventDefault();
                      const uid = statusMenu.userId;
                      setStatusMenu(null);
                      await quickUpdate(uid, { status: s });
                    }}
                  >
                    {s}
                    {isCurrent && <span className="ml-auto text-blue-500">✓</span>}
                  </button>
                );
              })}
            </div>
          </div>
        </>,
        document.body
      )}
    </AppLayout>
  );
}
