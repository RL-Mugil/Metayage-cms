import { Head, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Trash2, RotateCcw, AlertTriangle, Briefcase, Users, FolderOpen } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

type RBType = "project" | "client" | "document";

interface RBItem {
  id: number;
  label: string;
  sub: string;
  deleted_at: string;
  type: RBType;
}

function fmtDate(d: string) {
  return new Date(d).toLocaleDateString("en-IN", { day: "2-digit", month: "short", year: "numeric" });
}

const TAB_META: { key: RBType; label: string; icon: React.ComponentType<any> }[] = [
  { key: "project",  label: "Projects",  icon: Briefcase },
  { key: "client",   label: "Clients",   icon: Users },
  { key: "document", label: "Documents", icon: FolderOpen },
];

export default function RecycleBin() {
  const { props } = usePage() as any;
  const role = props.auth?.user?.role;

  const canRestore    = ["super_admin", "partner"].includes(role);
  const canHardDelete = role === "super_admin";

  const [tab,       setTab]       = useState<RBType>("project");
  const [items,     setItems]     = useState<Record<RBType, RBItem[]>>({ project: [], client: [], document: [] });
  const [loading,   setLoading]   = useState(true);
  const [banner,    setBanner]    = useState<{ kind: "ok" | "err"; text: string } | null>(null);
  const [working,   setWorking]   = useState<number | null>(null);
  const [bulkWorking, setBulkWorking] = useState(false);
  const [selected,  setSelected]  = useState<Set<number>>(new Set());

  function showBanner(kind: "ok" | "err", text: string) {
    setBanner({ kind, text });
    setTimeout(() => setBanner(null), 3500);
  }

  function load() {
    setLoading(true);
    setSelected(new Set());
    api.getRecycleBin()
      .then((res) => {
        const toRBItem = (type: RBType, raw: any[]): RBItem[] =>
          raw.map((r) => ({
            id: r.id,
            type,
            deleted_at: r.deleted_at,
            label: type === "project"
              ? (r.docket_number ?? r.project_code ?? `#${r.id}`)
              : type === "client"
              ? (r.client_code ? `${r.client_code} — ${r.company_name ?? r.legal_name}` : (r.company_name ?? r.legal_name ?? `#${r.id}`))
              : (r.file_name ?? `#${r.id}`),
            sub: type === "project"
              ? (r.project_type ?? r.status ?? "")
              : type === "client"
              ? (r.client_type ?? r.status ?? "")
              : (r.category ?? ""),
          }));
        setItems({
          project:  toRBItem("project",  res.projects  as any[]),
          client:   toRBItem("client",   res.clients   as any[]),
          document: toRBItem("document", res.documents as any[]),
        });
      })
      .catch(() => showBanner("err", "Failed to load recycle bin"))
      .finally(() => setLoading(false));
  }

  useEffect(load, []);

  // Clear selection when tab changes
  useEffect(() => setSelected(new Set()), [tab]);

  async function handleRestore(item: RBItem) {
    setWorking(item.id);
    try {
      await api.recycleBinRestore(item.type, item.id);
      showBanner("ok", `${item.label} restored successfully.`);
      load();
    } catch (e: any) {
      showBanner("err", e.message || "Restore failed");
    } finally {
      setWorking(null);
    }
  }

  async function handleHardDelete(item: RBItem) {
    if (!confirm(`Permanently delete "${item.label}"?\n\nThis CANNOT be undone.`)) return;
    setWorking(item.id);
    try {
      await api.recycleBinHardDelete(item.type, item.id);
      showBanner("ok", `${item.label} permanently deleted.`);
      load();
    } catch (e: any) {
      showBanner("err", e.message || "Delete failed");
    } finally {
      setWorking(null);
    }
  }

  async function handleBulkRestore() {
    if (selected.size === 0) return;
    setBulkWorking(true);
    try {
      const res = await api.recycleBinBulkRestore(tab, Array.from(selected));
      showBanner("ok", res.message);
      load();
    } catch (e: any) {
      showBanner("err", e.message || "Bulk restore failed");
    } finally {
      setBulkWorking(false);
    }
  }

  async function handleBulkHardDelete() {
    if (selected.size === 0) return;
    if (!confirm(`Permanently delete ${selected.size} item(s)?\n\nThis CANNOT be undone.`)) return;
    setBulkWorking(true);
    try {
      const res = await api.recycleBinBulkHardDelete(tab, Array.from(selected));
      showBanner("ok", res.message);
      load();
    } catch (e: any) {
      showBanner("err", e.message || "Bulk delete failed");
    } finally {
      setBulkWorking(false);
    }
  }

  const displayed = items[tab];
  const allSelected = displayed.length > 0 && selected.size === displayed.length;

  function toggleAll() {
    if (allSelected) {
      setSelected(new Set());
    } else {
      setSelected(new Set(displayed.map((i) => i.id)));
    }
  }

  function toggleOne(id: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  return (
    <AppLayout>
      <Head title="Recycle Bin" />
      <PageHeader
        eyebrow="Operations"
        title="Recycle Bin"
        description="Soft-deleted records — restore or permanently erase"
      />

      {banner && (
        <div className={`mx-8 mb-2 rounded-md border px-4 py-2 text-sm ${
          banner.kind === "ok"
            ? "border-green-500/40 bg-green-500/10 text-green-400"
            : "border-red-500/40 bg-red-500/10 text-red-400"
        }`}>
          {banner.text}
        </div>
      )}

      <div className="px-8 py-6 space-y-4">
        {/* Tabs */}
        <div className="flex gap-1 border-b border-border">
          {TAB_META.map(({ key, label, icon: Icon }) => (
            <button
              key={key}
              onClick={() => setTab(key)}
              className={`flex items-center gap-1.5 px-4 py-2.5 text-sm font-medium transition-colors border-b-2 -mb-px ${
                tab === key
                  ? "border-gold text-gold"
                  : "border-transparent text-muted-foreground hover:text-foreground"
              }`}
            >
              <Icon className="h-3.5 w-3.5" />
              {label}
              <Badge variant="outline" className="text-[10px] h-4 px-1.5 ml-0.5">
                {items[key].length}
              </Badge>
            </button>
          ))}
        </div>

        {/* Content */}
        {loading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
          </div>
        ) : displayed.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-24 text-center">
            <Trash2 className="h-12 w-12 text-muted-foreground mb-4 opacity-40" />
            <p className="text-muted-foreground">No deleted {tab}s in recycle bin</p>
          </div>
        ) : (
          <div className="rounded-lg border border-border overflow-hidden">
            <table className="w-full text-sm">
              <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 w-8">
                    <input
                      type="checkbox"
                      checked={allSelected}
                      onChange={toggleAll}
                      className="h-3.5 w-3.5 rounded border-border accent-gold"
                    />
                  </th>
                  <th className="px-4 py-3 text-left">Item</th>
                  <th className="px-4 py-3 text-left">Type / Category</th>
                  <th className="px-4 py-3 text-left">Deleted On</th>
                  <th className="px-4 py-3 text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                {displayed.map((item) => (
                  <tr key={item.id} className={`border-t border-border hover:bg-muted/20 ${selected.has(item.id) ? "bg-gold/5" : ""}`}>
                    <td className="px-4 py-3">
                      <input
                        type="checkbox"
                        checked={selected.has(item.id)}
                        onChange={() => toggleOne(item.id)}
                        className="h-3.5 w-3.5 rounded border-border accent-gold"
                      />
                    </td>
                    <td className="px-4 py-3 font-medium">{item.label}</td>
                    <td className="px-4 py-3 text-muted-foreground text-xs">{item.sub || "—"}</td>
                    <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{fmtDate(item.deleted_at)}</td>
                    <td className="px-4 py-3">
                      <div className="flex items-center justify-end gap-2">
                        {canRestore && (
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-7 text-xs gap-1"
                            disabled={working === item.id || bulkWorking}
                            onClick={() => handleRestore(item)}
                          >
                            {working === item.id
                              ? <Loader2 className="h-3 w-3 animate-spin" />
                              : <RotateCcw className="h-3 w-3" />}
                            Restore
                          </Button>
                        )}
                        {canHardDelete && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-7 text-xs gap-1 text-red-400 hover:text-red-300 hover:bg-red-500/10"
                            disabled={working === item.id || bulkWorking}
                            onClick={() => handleHardDelete(item)}
                          >
                            <AlertTriangle className="h-3 w-3" />
                            Delete Forever
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Bulk action bar */}
        {selected.size > 0 && (
          <div className="sticky bottom-4 flex items-center gap-3 rounded-lg border border-border bg-card/95 backdrop-blur px-4 py-3 shadow-xl">
            <span className="text-sm font-medium text-gold">{selected.size} selected</span>
            <span className="flex-1" />
            {canRestore && (
              <Button
                size="sm"
                variant="outline"
                className="gap-1.5 h-8 text-xs"
                disabled={bulkWorking}
                onClick={handleBulkRestore}
              >
                {bulkWorking ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RotateCcw className="h-3.5 w-3.5" />}
                Restore All Selected
              </Button>
            )}
            {canHardDelete && (
              <Button
                size="sm"
                variant="ghost"
                className="gap-1.5 h-8 text-xs text-red-400 hover:text-red-300 hover:bg-red-500/10"
                disabled={bulkWorking}
                onClick={handleBulkHardDelete}
              >
                <AlertTriangle className="h-3.5 w-3.5" />
                Delete All Forever
              </Button>
            )}
            <Button
              size="sm"
              variant="ghost"
              className="h-8 text-xs text-muted-foreground"
              onClick={() => setSelected(new Set())}
            >
              Clear
            </Button>
          </div>
        )}

        {canHardDelete && displayed.length > 0 && selected.size === 0 && (
          <p className="text-xs text-muted-foreground flex items-center gap-1.5">
            <AlertTriangle className="h-3 w-3 text-red-400" />
            "Delete Forever" is irreversible — the record will be removed from the database permanently.
          </p>
        )}
      </div>
    </AppLayout>
  );
}
