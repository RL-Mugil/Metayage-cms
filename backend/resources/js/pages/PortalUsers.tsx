import { Head, usePage } from "@inertiajs/react";
import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2, Plus, Trash2, Users } from "lucide-react";
import { toast } from "sonner";
import AppLayout from "@/layouts/AppLayout";
import { FitPage } from "@/components/modern/fit-page";
import { GlassCard } from "@/components/modern/glass-card";
import { Button } from "@/components/ui/button";
import { api, type PortalUser } from "@/lib/api-client";
import { AccessChips, assignDesignation, DesignationManager, DesignationSelect, useDesignations } from "@/components/clients/designation-manager";

const inputClass = "h-8 w-full rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold";

/**
 * A client's own Portal Users page (logins with "Manage users & designations"): add colleagues
 * with a designation, change what someone can use, remove a login, and create the company's own
 * designations with access checkboxes.
 */
export default function PortalUsers() {
  const { props } = usePage() as any;
  const me = props.auth?.user?.id;
  const queryClient = useQueryClient();
  const { data: users = [], isLoading, error } = useQuery<PortalUser[]>({ queryKey: ["my-portal-users"], queryFn: () => api.getMyPortalUsers() });
  const { data: designationData } = useDesignations("mine");
  const labels = Object.fromEntries((designationData?.permissions ?? []).map((p) => [p.key, p.label]));
  const [adding, setAdding] = useState(false);
  const refresh = () => { void queryClient.invalidateQueries({ queryKey: ["my-portal-users"] }); void queryClient.invalidateQueries({ queryKey: ["client-designations", "mine"] }); };

  const changeAccess = useMutation({
    mutationFn: ({ user, id }: { user: PortalUser; id: number }) => assignDesignation("mine", user.id, id),
    onSuccess: () => { toast.success("Access changed"); refresh(); },
    onError: (e: Error) => toast.error(e.message),
  });
  const remove = useMutation({
    mutationFn: (user: PortalUser) => api.deleteMyPortalUser(user.id),
    onSuccess: () => { toast.success("Login removed"); refresh(); },
    onError: (e: Error) => toast.error(e.message),
  });

  return (
    <AppLayout>
      <Head title="Portal Users" />
      <FitPage fit={false} eyebrow="Account" title="Portal Users" summary="Your colleagues who use this portal, and what each designation may use"
        actions={!adding && <Button size="sm" className="bg-gold text-black hover:bg-gold/90" onClick={() => setAdding(true)}><Plus className="mr-1.5 h-4 w-4" />Add a user</Button>}>
        <div className="space-y-4">
          {adding && <AddUser onCancel={() => setAdding(false)} onSaved={() => { setAdding(false); refresh(); }} />}

          <GlassCard className="card-3d" title="Users" icon={<Users className="h-4 w-4 text-gold" />} subtitle="Change someone's designation to change what they can use">
            <div className="p-3 pt-0">
              {isLoading ? <div className="flex justify-center py-8"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
                : error ? <p className="py-6 text-center text-sm text-red-600">{(error as Error).message}</p>
                : (
                  <div className="divide-y divide-border rounded-xl border border-border">
                    {users.map((u) => (
                      <div key={u.id} className="flex flex-wrap items-start gap-3 p-3 text-xs">
                        <div className="min-w-0 flex-1">
                          <p className="flex flex-wrap items-center gap-1.5 text-sm font-medium">
                            {u.name}
                            {u.is_primary && <span className="rounded bg-gold/15 px-1.5 py-0.5 text-[10px] font-normal text-gold">Primary</span>}
                            {u.id === me && <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-normal">You</span>}
                          </p>
                          <p className="text-muted-foreground">{[u.email, u.phone].filter(Boolean).join(" · ")}</p>
                          {u.permissions && <div className="mt-1"><AccessChips permissions={u.permissions} labels={labels} /></div>}
                        </div>
                        <label className="flex shrink-0 items-center gap-1 text-[10px] text-muted-foreground">Designation
                          {u.id === me || u.role === "inventor"
                            ? <span className="rounded border border-border px-1.5 py-1 text-foreground">{u.designation ?? "—"}</span>
                            : <DesignationSelect scope="mine" value={u.designation_id} onChange={(id) => changeAccess.mutate({ user: u, id })} />}
                        </label>
                        {!u.is_primary && u.id !== me && u.role !== "inventor" && (
                          <Button size="icon" variant="ghost" className="h-7 w-7" title="Remove login" disabled={remove.isPending}
                            onClick={() => { if (window.confirm(`Remove ${u.name}'s portal login?`)) remove.mutate(u); }}><Trash2 className="h-3.5 w-3.5" /></Button>
                        )}
                      </div>
                    ))}
                    {users.length === 0 && <p className="py-6 text-center text-muted-foreground">No users yet.</p>}
                  </div>
                )}
            </div>
          </GlassCard>

          <GlassCard className="card-3d">
            <div className="p-4"><DesignationManager scope="mine" canManage /></div>
          </GlassCard>
        </div>
      </FitPage>
    </AppLayout>
  );
}

function AddUser({ onCancel, onSaved }: { onCancel: () => void; onSaved: () => void }) {
  const [f, setF] = useState({ name: "", email: "", phone: "", password: "", designation_id: null as number | null });
  const save = useMutation({
    mutationFn: () => api.createMyPortalUser({ name: f.name.trim(), email: f.email.trim(), phone: f.phone.trim() || undefined, password: f.password, designation_id: f.designation_id }),
    onSuccess: () => { toast.success(`Login created for ${f.email}. Share the password with them directly.`); onSaved(); },
    onError: (e: Error) => toast.error(e.message),
  });
  const problem = !f.name.trim() ? "Enter a name" : !/^\S+@\S+\.\S+$/.test(f.email.trim()) ? "Enter a valid email"
    : f.password.length < 8 ? "Password: at least 8 characters, upper and lower case and a symbol" : !f.designation_id ? "Choose a designation" : null;
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => setF({ ...f, [k]: e.target.value });
  return (
    <GlassCard className="card-3d border border-gold/40" title="Add a user">
      <div className="grid gap-2 p-4 pt-0 sm:grid-cols-5">
        <label className="text-[10px] text-muted-foreground">Designation
          <DesignationSelect scope="mine" value={f.designation_id} onChange={(id) => setF({ ...f, designation_id: id })} className={inputClass} />
        </label>
        <label className="text-[10px] text-muted-foreground">Name<input value={f.name} onChange={set("name")} className={inputClass} /></label>
        <label className="text-[10px] text-muted-foreground">Email (login)<input type="email" value={f.email} onChange={set("email")} className={inputClass} /></label>
        <label className="text-[10px] text-muted-foreground">Phone<input value={f.phone} onChange={set("phone")} className={inputClass} /></label>
        <label className="text-[10px] text-muted-foreground">Password<input value={f.password} onChange={set("password")} className={`${inputClass} font-mono`} /></label>
        <div className="flex gap-2 sm:col-span-5">
          <Button size="sm" className="h-7 text-xs" disabled={save.isPending || !!problem} title={problem ?? undefined} onClick={() => save.mutate()}>
            {save.isPending ? <Loader2 className="h-3 w-3 animate-spin" /> : "Create login"}
          </Button>
          <Button size="sm" variant="outline" className="h-7 text-xs" onClick={onCancel}>Cancel</Button>
          {problem && <span className="self-center text-[10px] text-muted-foreground">{problem}</span>}
        </div>
      </div>
    </GlassCard>
  );
}
