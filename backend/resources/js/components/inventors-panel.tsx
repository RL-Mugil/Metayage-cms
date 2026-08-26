import { useEffect, useState } from "react";
import { Loader2, UserPlus, X, Users } from "lucide-react";
import { api } from "@/lib/api-client";

interface Inventor {
  id: number;
  name: string;
  email: string;
}

/**
 * Staff-managed list of a case's inventors (project_inventors pivot) — powers
 * the inventor role's dashboard scoping (User::projectsAsInventor()). Adding
 * a new email creates an inventor-role login for them (no auto-email — the
 * password is shared directly, same pattern as PortalUsers.tsx).
 */
export function InventorsPanel({ projectId, canManage }: { projectId: number | string; canManage: boolean }) {
  const [inventors, setInventors] = useState<Inventor[]>([]);
  const [loading, setLoading] = useState(true);
  const [showAdd, setShowAdd] = useState(false);
  const [form, setForm] = useState({ name: "", email: "", password: "" });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const load = () => {
    setLoading(true);
    api.getProjectInventors(projectId)
      .then((rows: any) => setInventors(rows ?? []))
      .catch(() => setInventors([]))
      .finally(() => setLoading(false));
  };

  useEffect(() => { load(); /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [projectId]);

  async function addInventor() {
    if (!form.name.trim() || !form.email.trim()) return;
    setSaving(true);
    setError("");
    try {
      await api.addProjectInventor(projectId, form);
      setForm({ name: "", email: "", password: "" });
      setShowAdd(false);
      load();
    } catch (e: any) {
      setError(e?.message || "Failed to add inventor.");
    } finally {
      setSaving(false);
    }
  }

  async function removeInventor(userId: number) {
    try {
      await api.removeProjectInventor(projectId, userId);
      load();
    } catch { /* ignore */ }
  }

  return (
    <div>
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-sm font-semibold flex items-center gap-1.5"><Users className="h-3.5 w-3.5" /> Inventors</h2>
        {canManage && (
          <button onClick={() => setShowAdd((v) => !v)} className="text-xs font-medium text-gold hover:underline flex items-center gap-1">
            <UserPlus className="h-3 w-3" /> Add inventor
          </button>
        )}
      </div>

      {showAdd && (
        <div className="mb-3 space-y-2 rounded-md border border-border bg-muted/20 p-3">
          {error && <p className="text-xs text-destructive">{error}</p>}
          <div className="grid gap-2 sm:grid-cols-3">
            <input value={form.name} onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
              placeholder="Name" className="h-8 rounded border border-border bg-background px-2 text-xs" />
            <input value={form.email} onChange={(e) => setForm((p) => ({ ...p, email: e.target.value }))}
              placeholder="Email" type="email" className="h-8 rounded border border-border bg-background px-2 text-xs" />
            <input value={form.password} onChange={(e) => setForm((p) => ({ ...p, password: e.target.value }))}
              placeholder="Password (new login only)" className="h-8 rounded border border-border bg-background px-2 text-xs font-mono" />
          </div>
          <div className="flex gap-2">
            <button onClick={addInventor} disabled={saving}
              className="h-7 rounded bg-gold px-3 text-xs font-medium text-black hover:bg-gold/90 disabled:opacity-50">
              {saving ? <Loader2 className="h-3 w-3 animate-spin" /> : "Add"}
            </button>
            <button onClick={() => setShowAdd(false)} className="h-7 rounded border border-border px-3 text-xs">Cancel</button>
          </div>
        </div>
      )}

      {loading ? (
        <div className="flex justify-center py-4"><Loader2 className="h-4 w-4 animate-spin text-gold" /></div>
      ) : inventors.length === 0 ? (
        <p className="text-xs text-muted-foreground py-2">No inventors added to this case yet.</p>
      ) : (
        <div className="flex flex-wrap gap-2">
          {inventors.map((inv) => (
            <span key={inv.id} className="inline-flex items-center gap-1.5 rounded-md bg-muted px-2.5 py-1 text-xs">
              <span className="font-medium">{inv.name}</span>
              <span className="text-muted-foreground">{inv.email}</span>
              {canManage && (
                <button onClick={() => removeInventor(inv.id)} className="text-muted-foreground hover:text-destructive">
                  <X className="h-3 w-3" />
                </button>
              )}
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
