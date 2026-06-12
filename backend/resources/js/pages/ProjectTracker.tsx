import { Head } from "@inertiajs/react";
import { useState, useEffect, useMemo, useRef } from "react";
import { createPortal } from "react-dom";
import {
  Plus, X, Users, Download, ChevronDown, AlertTriangle,
  CheckCircle2, Clock, TrendingUp, Search,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { api } from "@/lib/api-client";
import type { User } from "@/lib/api-client";
import { fmtDate, fmtDateTime } from "@/lib/date-utils";
import { downloadCSV } from "@/lib/api-client";

// ── Constants ─────────────────────────────────────────────────────────────────

const STATUSES = [
  "Not Started", "Allocated", "Patent Drafting", "Internal Review",
  "Client Review", "Awaiting payment", "Completed", "On Hold",
  "Conducting search", "Awaiting feedback",
  "Awaiting the draft from the client", "To share the claims with client",
  "To file", "Awaiting signed forms",
  "received Comments from client - to Update", "To schedule call",
  "shared draft & drawings", "shared search report",
  "Shared key features", "Scheduled call with client",
];

const RECORD_TYPES = ["Patent", "FTO", "Design", "TM"];
const PAYMENT_STATUSES = ["Pending", "Partial", "Paid"];

// Locked: % completion per status (mirrors backend STATUS_COMPLETION)
const STATUS_COMPLETION: Record<string, number | null> = {
  "Not Started": 0, "Allocated": 5, "Conducting search": 10,
  "Shared search report": 15, "Shared key features": 15,
  "Awaiting the draft from the client": 20, "To schedule call": 20,
  "Scheduled call with client": 25, "shared draft & drawings": 30,
  "Patent Drafting": 35, "To share the claims with client": 45,
  "Internal Review": 55, "received Comments from client - to Update": 60,
  "Client Review": 65, "Awaiting feedback": 70,
  "Awaiting signed forms": 75, "To file": 80,
  "Awaiting payment": 90, "On Hold": null, "Completed": 100,
};

const STATUS_DOT: Record<string, string> = {
  "Not Started": "bg-gray-400", "Allocated": "bg-blue-500",
  "Patent Drafting": "bg-indigo-500", "Internal Review": "bg-purple-500",
  "Client Review": "bg-violet-500", "Awaiting payment": "bg-amber-500",
  "Completed": "bg-green-500", "On Hold": "bg-red-500",
  "Conducting search": "bg-blue-400", "Awaiting feedback": "bg-orange-400",
  "Awaiting the draft from the client": "bg-orange-500",
  "To share the claims with client": "bg-teal-500",
  "To file": "bg-blue-600", "Awaiting signed forms": "bg-amber-400",
  "received Comments from client - to Update": "bg-orange-500",
  "To schedule call": "bg-teal-400", "shared draft & drawings": "bg-blue-400",
  "shared search report": "bg-blue-400", "Shared key features": "bg-teal-500",
  "Scheduled call with client": "bg-teal-500",
};

// Column config — docket_number handled separately (DocketCell)
const COLS = [
  { key: "client_name",              label: "Client Name",   type: "text",   w: 168 },
  { key: "record_type",              label: "Record Type",   type: "select", w: 108, opts: RECORD_TYPES },
  { key: "pcm",                      label: "PCM",           type: "user",   w: 132 },
  { key: "scm",                      label: "SCM",           type: "user",   w: 132 },
  { key: "pr",                       label: "PR",            type: "user",   w: 132 },
  { key: "project_start_date",       label: "Start Date",    type: "date",   w: 108 },
  { key: "status",                   label: "Status",        type: "select", w: 210, opts: STATUSES },
  { key: "delivery_due_date",        label: "Delivery Due",  type: "date",   w: 108 },
  { key: "payment_status",           label: "Payment",       type: "select", w: 100, opts: PAYMENT_STATUSES },
  { key: "uin",                      label: "UIN",           type: "text",   w: 138 },
] as const;

type ColKey = typeof COLS[number]["key"];

interface TrackerRow {
  id: number;
  circle_id: number;
  project_id: number | null;
  docket_number: string | null;
  client_name: string | null;
  record_type: string | null;
  pcm_id: number | null;
  pcm: string | null;
  scm_id: number | null;
  scm: string | null;
  pr_id: number | null;
  pr: string | null;
  project_start_date: string | null;
  status: string | null;
  delivery_due_date: string | null;
  payment_status: string | null;
  percentage_of_completion: number;
  uin: string | null;
  sort_order: number;
  created_at: string;
}

interface TrackerProject {
  id: number;
  project_code: string;
  docket_number: string | null;
  client_name: string | null;
  partner_id: number | null;
  partner_name: string | null;
  manager_id: number | null;
  manager_name: string | null;
  engineer_id: number | null;
  engineer_name: string | null;
  start_date: string | null;
  record_type: string | null;
}

interface CircleInfo {
  id: number;
  name: string;
  slug: string;
  description: string;
  members: User[];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function getComputed(row: TrackerRow) {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const start = row.project_start_date ? new Date(row.project_start_date) : null;
  const due = row.delivery_due_date ? new Date(row.delivery_due_date) : null;
  const agingDays = start ? Math.floor((today.getTime() - start.getTime()) / 86400000) : null;
  const daysLeft = due ? Math.floor((due.getTime() - today.getTime()) / 86400000) : null;
  const priority =
    daysLeft === null ? "" :
    daysLeft < 0 ? "Critical" :
    daysLeft <= 7 ? "High" :
    daysLeft <= 30 ? "Medium" : "Normal";
  const remarks = daysLeft !== null && daysLeft < 0 && row.status !== "Completed" ? "Project Overdue" : "";
  return { agingDays, daysLeft, priority, remarks };
}

// ── Main Component ─────────────────────────────────────────────────────────────

export default function ProjectTracker() {
  const [activeCircle, setActiveCircle] = useState<"a" | "b">("a");
  const [circles, setCircles] = useState<CircleInfo[]>([]);
  const [rows, setRows] = useState<TrackerRow[]>([]);
  const [users, setUsers] = useState<User[]>([]);
  const [trackerProjects, setTrackerProjects] = useState<TrackerProject[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeCell, setActiveCell] = useState<{ rowId: number; col: string } | null>(null);
  const [editVal, setEditVal] = useState("");
  const [showMembers, setShowMembers] = useState(false);
  const [memberSearch, setMemberSearch] = useState("");
  const [savingIds, setSavingIds] = useState<Set<number>>(new Set());

  useEffect(() => {
    let alive = true;
    setLoading(true);
    Promise.all([
      api.getTrackerCircles(),
      api.getUsers(),
      api.getTrackerProjects(),
    ]).then(async ([circlesData, usersData, projectsData]) => {
      if (!alive) return;
      setCircles(circlesData);
      setUsers(usersData);
      setTrackerProjects(projectsData);
      const rowsData = await api.getTrackerRows(activeCircle);
      if (alive) { setRows(rowsData); setLoading(false); }
    }).catch(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [activeCircle]);

  const currentCircle = circles.find((c) => c.slug === activeCircle);

  // ── Analytics — only count rows linked to a docket ────────────────────────
  const analytics = useMemo(() => {
    const linked = rows.filter((r) => r.docket_number);
    const total = linked.length;
    const completed = linked.filter((r) => r.status === "Completed").length;
    const overdue = linked.filter((r) => {
      if (r.status === "Completed") return false;
      const { daysLeft } = getComputed(r);
      return daysLeft !== null && daysLeft < 0;
    }).length;
    const pendingPay = linked.filter((r) => r.payment_status === "Pending").length;
    const paidCount = linked.filter((r) => r.payment_status === "Paid").length;
    const partialCount = linked.filter((r) => r.payment_status === "Partial").length;
    const avgComp = total > 0
      ? Math.round(linked.reduce((s, r) => s + (r.percentage_of_completion || 0), 0) / total) : 0;
    const byStatus: Record<string, number> = {};
    linked.forEach((r) => { const s = r.status || "Not Started"; byStatus[s] = (byStatus[s] || 0) + 1; });
    return { total, completed, overdue, pendingPay, paidCount, partialCount, avgComp, byStatus };
  }, [rows]);

  // ── Cell save ─────────────────────────────────────────────────────────────
  function cancelCell() { setActiveCell(null); setEditVal(""); }

  const USER_COLS: Record<string, string> = { pcm: "pcm_id", scm: "scm_id", pr: "pr_id" };

  function commitCell(rowId: number, col: string, val?: string) {
    const value = val !== undefined ? val : editVal;
    setActiveCell(null);
    setEditVal("");
    const row = rows.find((r) => r.id === rowId);
    if (!row) return;
    const prevStr = ((row as any)[col] ?? "") === null ? "" : String((row as any)[col] ?? "");
    if (value === prevStr) return;

    // For user-type cols: send the ID, not the name string
    const idField = USER_COLS[col];
    let updates: Record<string, any>;
    if (idField) {
      const matched = users.find((u) => u.name.toLowerCase() === value.toLowerCase());
      const userId = matched ? matched.id : null;
      updates = { [idField]: userId, [col]: value || null };
    } else {
      updates = { [col]: value || null };
    }

    // For status: also update % completion locally (locked)
    if (col === "status") {
      const pct = STATUS_COMPLETION[value];
      if (pct !== null && pct !== undefined) updates.percentage_of_completion = pct;
    }

    setSavingIds((s) => new Set(s).add(rowId));
    setRows((prev) => prev.map((r) => r.id === rowId ? { ...r, ...updates } : r));
    api.updateTrackerRow(rowId, updates)
      .catch(() => {})
      .finally(() => setSavingIds((s) => { const n = new Set(s); n.delete(rowId); return n; }));
  }

  // ── Project select (docket combobox) ─────────────────────────────────────
  function clearDocket(rowId: number) {
    const updates: Record<string, any> = {
      project_id: null, docket_number: null, client_name: null,
      pcm_id: null, scm_id: null, pr_id: null,
      pcm: null, scm: null, pr: null, uin: null, record_type: null,
    };
    setSavingIds((s) => new Set(s).add(rowId));
    setRows((prev) => prev.map((r) => r.id === rowId ? { ...r, ...updates } : r));
    api.updateTrackerRow(rowId, updates)
      .catch(() => {})
      .finally(() => setSavingIds((s) => { const n = new Set(s); n.delete(rowId); return n; }));
  }

  function handleProjectSelect(rowId: number, project: TrackerProject) {
    const display = project.docket_number || project.project_code;
    const updates: Record<string, any> = {
      project_id:    project.id,
      docket_number: display,
      client_name:   project.client_name || null,
      pcm_id:        project.partner_id || null,
      pcm:           project.partner_name || null,
      scm_id:        project.manager_id || null,
      scm:           project.manager_name || null,
      pr_id:         project.engineer_id || null,
      pr:            project.engineer_name || null,
      uin:           display,
      record_type:   project.record_type || null,
    };
    if (project.start_date) updates.project_start_date = project.start_date;
    setSavingIds((s) => new Set(s).add(rowId));
    setRows((prev) => prev.map((r) => r.id === rowId ? { ...r, ...updates } : r));
    api.updateTrackerRow(rowId, updates)
      .catch(() => {})
      .finally(() => setSavingIds((s) => { const n = new Set(s); n.delete(rowId); return n; }));
  }

  // ── Row actions ────────────────────────────────────────────────────────────
  async function addRow() {
    if (!currentCircle) return;
    try {
      const newRow = await api.createTrackerRow({ circle_slug: activeCircle });
      setRows((prev) => [...prev, newRow]);
    } catch {}
  }

  function deleteRow(rowId: number) {
    setRows((prev) => prev.filter((r) => r.id !== rowId));
    api.deleteTrackerRow(rowId).catch(() => {});
  }

  // ── Member toggle ─────────────────────────────────────────────────────────
  async function toggleMember(userId: number) {
    if (!currentCircle) return;
    const isMember = currentCircle.members.some((m) => m.id === userId);
    try {
      if (isMember) {
        await api.removeCircleMember(currentCircle.id, userId);
        setCircles((prev) => prev.map((c) => c.id === currentCircle.id
          ? { ...c, members: c.members.filter((m) => m.id !== userId) } : c));
      } else {
        await api.addCircleMember(currentCircle.id, userId);
        const user = users.find((u) => u.id === userId);
        if (user) setCircles((prev) => prev.map((c) => c.id === currentCircle.id
          ? { ...c, members: [...c.members, user] } : c));
      }
    } catch {}
  }

  // ── CSV export ─────────────────────────────────────────────────────────────
  function exportCSV() {
    downloadCSV(`circle-${activeCircle}-${new Date().toISOString().slice(0, 10)}.csv`,
      rows.map((r) => {
        const c = getComputed(r);
        return {
          "Docket Number": r.docket_number || "", "Client Name": r.client_name || "",
          "Record Type": r.record_type || "", PCM: r.pcm || "", SCM: r.scm || "", PR: r.pr || "",
          "Start Date": r.project_start_date ? fmtDate(r.project_start_date) : "",
          Status: r.status || "",
          "Delivery Due": r.delivery_due_date ? fmtDate(r.delivery_due_date) : "",
          Payment: r.payment_status || "", "% Comp": `${r.percentage_of_completion}%`,
          UIN: r.uin || "", "Ageing Days": c.agingDays ?? "", "Days Left": c.daysLeft ?? "",
          Priority: c.priority, Remarks: c.remarks, "Created On": fmtDateTime(r.created_at),
        };
      })
    );
  }

  // ── Docket Number combobox cell ───────────────────────────────────────────
  function DocketCell({ row }: { row: TrackerRow }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState("");
    const tdRef = useRef<HTMLTableCellElement>(null);
    const [rect, setRect] = useState<DOMRect | null>(null);

    function openDrop() {
      if (tdRef.current) setRect(tdRef.current.getBoundingClientRect());
      setOpen(true);
      setQ("");
    }

    const filtered = trackerProjects.filter((p) => {
      if (!q) return true;
      const ql = q.toLowerCase();
      return (p.docket_number || "").toLowerCase().includes(ql) ||
        p.project_code.toLowerCase().includes(ql) ||
        (p.client_name || "").toLowerCase().includes(ql);
    }).slice(0, 25);

    return (
      <>
        <td
          ref={tdRef}
          className="border-r border-b border-[#d3d3d3] hover:bg-blue-50/40 transition-colors"
          style={{ width: 148, minWidth: 148 }}
        >
          <div className="px-2 py-1.5 flex items-center justify-between gap-1 whitespace-nowrap overflow-hidden">
            <span
              className={`truncate text-[12px] cursor-cell flex-1 min-w-0 ${row.docket_number ? "" : "text-gray-300"}`}
              onClick={openDrop}
            >
              {row.docket_number || "— Select —"}
            </span>
            {row.docket_number ? (
              <button
                className="flex-shrink-0 text-gray-300 hover:text-red-500 transition-colors p-0.5"
                title="Clear docket"
                onMouseDown={(e) => { e.preventDefault(); clearDocket(row.id); }}
              >
                <X className="h-3 w-3" />
              </button>
            ) : (
              <ChevronDown className="h-3 w-3 text-gray-400 flex-shrink-0 cursor-cell" onClick={openDrop} />
            )}
          </div>
        </td>

        {open && rect && createPortal(
          <>
            <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
            <div
              className="fixed z-50 bg-white border border-[#ccc] rounded-lg shadow-2xl overflow-hidden"
              style={{ left: rect.left, top: rect.bottom + 2, width: Math.max(rect.width, 300) }}
            >
              <div className="p-2 border-b border-[#e5e5e5] bg-[#f8f8f8]">
                <div className="relative">
                  <Search className="absolute left-2 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-gray-400" />
                  <input
                    autoFocus
                    className="w-full pl-7 pr-3 py-1.5 text-[12px] border border-[#d3d3d3] rounded bg-white outline-none focus:ring-2 focus:ring-blue-400"
                    placeholder="Search docket, project code, client..."
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    onClick={(e) => e.stopPropagation()}
                  />
                </div>
              </div>
              <div className="max-h-56 overflow-y-auto">
                {row.docket_number && (
                  <div
                    className="px-3 py-2 text-[11px] text-red-500 hover:bg-red-50 cursor-pointer border-b border-[#f0f0f0] flex items-center gap-1.5"
                    onMouseDown={(e) => { e.preventDefault(); setOpen(false); clearDocket(row.id); }}
                  >
                    <X className="h-3 w-3" /> Clear docket
                  </div>
                )}
                {filtered.length === 0 ? (
                  <div className="px-4 py-6 text-[11px] text-gray-400 text-center">No matching projects</div>
                ) : (
                  filtered.map((p) => (
                    <div
                      key={p.id}
                      className="px-3 py-2.5 hover:bg-blue-50 cursor-pointer border-b border-[#f0f0f0] last:border-0"
                      onMouseDown={(e) => { e.preventDefault(); setOpen(false); handleProjectSelect(row.id, p); }}
                    >
                      <div className="flex items-center justify-between gap-2">
                        <span className="text-[12px] font-semibold text-[#1a4731]">
                          {p.docket_number || p.project_code}
                        </span>
                        {p.record_type && (
                          <span className="text-[10px] bg-gray-100 px-1.5 py-0.5 rounded text-gray-500">{p.record_type}</span>
                        )}
                      </div>
                      <div className="text-[11px] text-gray-500 mt-0.5">{p.client_name || "—"}</div>
                    </div>
                  ))
                )}
              </div>
            </div>
          </>,
          document.body
        )}
      </>
    );
  }

  // ── Generic editable cell ─────────────────────────────────────────────────
  function Cell({ row, col, type, opts }: {
    row: TrackerRow; col: string; type: string; opts?: readonly string[];
  }) {
    const isActive = activeCell?.rowId === row.id && activeCell?.col === col;
    const rawVal: any = (row as any)[col];
    const strVal = rawVal == null ? "" : String(rawVal);
    const colDef = COLS.find((c) => c.key === col);
    const w = colDef?.w ?? 120;
    const tdBase = "border-r border-b border-[#d3d3d3] overflow-hidden align-middle";

    if (isActive) {
      const cls = "w-full h-full px-2 py-1.5 bg-white outline-none text-[12px] leading-tight";
      if (type === "select") {
        return (
          <td className={`${tdBase} ring-2 ring-inset ring-blue-500 bg-white p-0`} style={{ minWidth: w, width: w }}>
            <select autoFocus value={editVal}
              onChange={(e) => setEditVal(e.target.value)}
              onBlur={() => commitCell(row.id, col)}
              onKeyDown={(e) => { if (e.key === "Escape") cancelCell(); if (e.key === "Enter") commitCell(row.id, col); }}
              className={cls}
            >
              <option value="">—</option>
              {(opts || []).map((o) => <option key={o} value={o}>{o}</option>)}
            </select>
          </td>
        );
      }
      return (
        <td className={`${tdBase} ring-2 ring-inset ring-blue-500 bg-white p-0`} style={{ minWidth: w, width: w }}>
          <input autoFocus type={type === "date" ? "date" : type === "number" ? "number" : "text"}
            value={editVal} min={type === "number" ? "0" : undefined} max={type === "number" ? "100" : undefined}
            onChange={(e) => setEditVal(e.target.value)}
            onBlur={() => commitCell(row.id, col)}
            onKeyDown={(e) => {
              if (e.key === "Escape") cancelCell();
              if (e.key === "Enter" || e.key === "Tab") { e.preventDefault(); commitCell(row.id, col); }
            }}
            list={type === "user" ? `dl-${row.id}-${col}` : undefined}
            className={cls}
          />
          {type === "user" && (
            <datalist id={`dl-${row.id}-${col}`}>
              {users.map((u) => <option key={u.id} value={u.name} />)}
            </datalist>
          )}
        </td>
      );
    }

    // View mode
    let content: React.ReactNode;

    if (col === "status") {
      const dot = STATUS_DOT[strVal] ?? "bg-gray-400";
      content = strVal ? (
        <div className="flex items-center gap-1.5 min-w-0">
          <span className={`h-1.5 w-1.5 rounded-full flex-shrink-0 ${dot}`} />
          <span className="truncate text-[11px]">{strVal}</span>
          <ChevronDown className="h-3 w-3 text-gray-300 ml-auto flex-shrink-0" />
        </div>
      ) : (
        <div className="flex items-center gap-1 text-gray-300 text-[11px]">
          <span>— Select —</span><ChevronDown className="h-3 w-3 ml-auto" />
        </div>
      );
    } else if (col === "payment_status") {
      const cls: Record<string, string> = { Paid: "text-green-700 bg-green-100", Partial: "text-amber-700 bg-amber-100", Pending: "text-red-600 bg-red-50" };
      content = strVal ? (
        <div className="flex items-center justify-between">
          <span className={`text-[10px] font-medium px-1.5 py-0.5 rounded ${cls[strVal] ?? ""}`}>{strVal}</span>
          <ChevronDown className="h-3 w-3 text-gray-300" />
        </div>
      ) : <span className="text-gray-300 text-[11px]">—</span>;
    } else if (col === "record_type") {
      content = strVal ? (
        <div className="flex items-center justify-between gap-1">
          <span className="text-[11px] font-medium">{strVal}</span>
          <ChevronDown className="h-3 w-3 text-gray-300 flex-shrink-0" />
        </div>
      ) : <div className="flex items-center gap-1 text-gray-300 text-[11px]">— <ChevronDown className="h-3 w-3 ml-auto" /></div>;
    } else if (col === "project_start_date" || col === "delivery_due_date") {
      content = strVal
        ? <span className="font-mono text-[11px]">{fmtDate(strVal)}</span>
        : <span className="text-gray-300 text-[11px]">—</span>;
    } else {
      content = strVal
        ? <span className="text-[12px] truncate">{strVal}</span>
        : <span className="text-gray-300 text-[11px]">—</span>;
    }

    return (
      <td className={`${tdBase} hover:bg-blue-50/40 cursor-cell transition-colors`}
        style={{ minWidth: w, width: w }}
        onClick={() => { setActiveCell({ rowId: row.id, col }); setEditVal(strVal); }}
      >
        <div className="px-2 py-1.5 overflow-hidden whitespace-nowrap">{content}</div>
      </td>
    );
  }

  // ── Row background ──────────────────────────────────────────────────────────
  function rowBg(row: TrackerRow, idx: number) {
    const { daysLeft } = getComputed(row);
    if (row.status === "Completed") return "bg-green-50";
    if (daysLeft !== null && daysLeft < 0) return "bg-red-50/40";
    if (row.status === "On Hold") return "bg-yellow-50/50";
    return idx % 2 === 0 ? "bg-white" : "bg-[#f9f9f9]";
  }

  const filteredUsers = users.filter((u) =>
    u.name.toLowerCase().includes(memberSearch.toLowerCase())
  );

  // ── Render ──────────────────────────────────────────────────────────────────
  return (
    <AppLayout>
      <Head title="Project Tracker" />

      {/* Top bar */}
      <div className="flex items-center justify-between px-6 py-3 border-b border-border bg-background">
        <div>
          <p className="text-[10px] uppercase tracking-widest text-muted-foreground">Practice</p>
          <h1 className="text-lg font-semibold">Project Tracker</h1>
        </div>
        <div className="flex items-center gap-2">
          <button onClick={() => setShowMembers(true)}
            className="flex items-center gap-1.5 text-xs border border-border rounded px-3 py-1.5 text-muted-foreground hover:text-foreground hover:bg-muted/30 transition-colors">
            <Users className="h-3.5 w-3.5" />
            Members ({currentCircle?.members?.length ?? 0})
          </button>
          <button onClick={exportCSV}
            className="flex items-center gap-1.5 text-xs border border-border rounded px-3 py-1.5 text-muted-foreground hover:text-foreground hover:bg-muted/30 transition-colors">
            <Download className="h-3.5 w-3.5" />Export CSV
          </button>
          <button onClick={addRow}
            className="flex items-center gap-1.5 text-xs bg-[#1a4731] text-white rounded px-3 py-1.5 hover:bg-[#1a4731]/90 transition-colors">
            <Plus className="h-3.5 w-3.5" />Add Row
          </button>
        </div>
      </div>

      {/* Circle Tabs */}
      <div className="flex items-center gap-0 border-b border-border bg-background">
        {(["a", "b"] as const).map((slug) => {
          const c = circles.find((ci) => ci.slug === slug);
          return (
            <button key={slug} onClick={() => setActiveCircle(slug)}
              className={`px-6 py-2.5 text-sm font-medium border-b-2 transition-colors ${
                activeCircle === slug ? "border-[#1a4731] text-[#1a4731]" : "border-transparent text-muted-foreground hover:text-foreground"
              }`}>
              {c?.name ?? `Circle ${slug.toUpperCase()}`}
              {slug === activeCircle && rows.length > 0 && (
                <span className="ml-1.5 text-[10px] text-muted-foreground">({rows.length})</span>
              )}
            </button>
          );
        })}
      </div>

      {/* Analytics */}
      <div className="grid grid-cols-5 gap-3 px-6 py-3 border-b border-border bg-muted/20">
        {[
          { icon: TrendingUp, label: "Total Cases", val: analytics.total, color: "text-[#1a4731]", border: "" },
          { icon: AlertTriangle, label: "Overdue", val: analytics.overdue, color: analytics.overdue > 0 ? "text-red-500" : "text-muted-foreground", border: "border-red-200" },
          { icon: CheckCircle2, label: "Completed", val: analytics.completed, color: "text-green-600", border: "border-green-200" },
          { icon: Clock, label: "Pending Payment", val: analytics.pendingPay, color: "text-amber-600", border: "border-amber-200" },
        ].map(({ icon: Icon, label, val, color, border }) => (
          <div key={label} className={`flex items-center gap-2.5 bg-background rounded-lg border ${border || "border-border"} px-3 py-2`}>
            <Icon className={`h-5 w-5 flex-shrink-0 ${color}`} />
            <div><p className="text-[10px] text-muted-foreground">{label}</p><p className={`text-xl font-bold ${color}`}>{val}</p></div>
          </div>
        ))}
        <div className="flex items-center gap-2.5 bg-background rounded-lg border border-border px-3 py-2">
          <div className="h-5 w-5 flex items-center justify-center flex-shrink-0">
            <span className="text-[11px] font-bold text-blue-600">%</span>
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-[10px] text-muted-foreground">Avg Completion</p>
            <p className="text-xl font-bold">{analytics.avgComp}%</p>
            <div className="h-1 bg-gray-200 rounded-full mt-0.5">
              <div className="h-1 bg-blue-500 rounded-full" style={{ width: `${analytics.avgComp}%` }} />
            </div>
          </div>
        </div>
      </div>

      {/* Payment pills + status summary */}
      <div className="flex items-center gap-2 px-6 py-2 border-b border-border bg-background text-[11px]">
        <span className="text-muted-foreground">Payment:</span>
        <span className="bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-medium">Paid: {analytics.paidCount}</span>
        <span className="bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-medium">Partial: {analytics.partialCount}</span>
        <span className="bg-red-50 text-red-600 px-2 py-0.5 rounded-full font-medium">Pending: {analytics.pendingPay}</span>
        {analytics.total > 0 && (
          <div className="ml-auto flex items-center gap-1.5">
            {Object.entries(analytics.byStatus).sort((a, b) => b[1] - a[1]).slice(0, 4).map(([s, n]) => (
              <span key={s} className="bg-muted px-2 py-0.5 rounded text-muted-foreground">
                {s}: <strong className="text-foreground">{n}</strong>
              </span>
            ))}
          </div>
        )}
      </div>

      {/* Spreadsheet */}
      <div className="overflow-auto" style={{ height: "calc(100vh - 295px)" }}
        onClick={(e) => { if ((e.target as HTMLElement).closest("td") === null) cancelCell(); }}
      >
        {loading ? (
          <div className="flex items-center justify-center h-40 text-muted-foreground text-sm">Loading...</div>
        ) : (
          <table className="border-separate border-spacing-0 text-[12px]"
            style={{ minWidth: COLS.reduce((s, c) => s + c.w, 0) + 148 + 40 + 335 }}
          >
            <thead>
              <tr className="sticky top-0 z-20">
                <th className="bg-[#1a4731] text-white border-r border-b border-[#12321f] px-2 py-2 font-medium text-[11px] text-center sticky left-0 z-30"
                  style={{ width: 40, minWidth: 40 }}>#</th>
                {/* Docket Number header */}
                <th className="bg-[#1a4731] text-white border-r border-b border-[#12321f] px-2 py-2 font-medium text-[11px] text-left whitespace-nowrap"
                  style={{ minWidth: 148, width: 148 }}>Docket Number</th>
                {COLS.map((c) => (
                  <th key={c.key} className="bg-[#1a4731] text-white border-r border-b border-[#12321f] px-2 py-2 font-medium text-[11px] text-left whitespace-nowrap"
                    style={{ minWidth: c.w, width: c.w }}>{c.label}</th>
                ))}
                {/* % Comp header (locked) */}
                <th className="bg-[#2e6b4a] text-white/80 border-r border-b border-[#1e4d33] px-2 py-2 font-medium text-[11px] whitespace-nowrap" style={{ width: 82 }}>% Comp</th>
                {/* Computed headers */}
                <th className="bg-[#2e6b4a] text-white/80 border-r border-b border-[#1e4d33] px-2 py-2 font-medium text-[11px] whitespace-nowrap" style={{ width: 65 }}>Ageing</th>
                <th className="bg-[#2e6b4a] text-white/80 border-r border-b border-[#1e4d33] px-2 py-2 font-medium text-[11px] whitespace-nowrap" style={{ width: 70 }}>Days Left</th>
                <th className="bg-[#2e6b4a] text-white/80 border-r border-b border-[#1e4d33] px-2 py-2 font-medium text-[11px] whitespace-nowrap" style={{ width: 75 }}>Priority</th>
                <th className="bg-[#2e6b4a] text-white/80 border-r border-b border-[#1e4d33] px-2 py-2 font-medium text-[11px] whitespace-nowrap" style={{ width: 125 }}>Auto Remarks</th>
                <th className="bg-[#2e6b4a] text-white/80 border-b border-[#1e4d33] px-2 py-2 font-medium text-[11px] whitespace-nowrap" style={{ width: 140 }}>Created On</th>
                <th className="bg-[#1a4731] border-b border-[#12321f]" style={{ width: 40, minWidth: 40 }} />
              </tr>
            </thead>

            <tbody>
              {rows.map((row, idx) => {
                const comp = getComputed(row);
                const bg = rowBg(row, idx);
                const isSaving = savingIds.has(row.id);
                const pct = row.percentage_of_completion || 0;

                return (
                  <tr key={row.id} className={`${bg} ${isSaving ? "opacity-60" : ""}`}>
                    <td className="border-r border-b border-[#d3d3d3] text-center text-muted-foreground text-[11px] select-none sticky left-0 z-10 bg-[#f0f0f0]"
                      style={{ width: 40, minWidth: 40 }}>{idx + 1}</td>

                    {/* Docket combobox */}
                    <DocketCell row={row} />

                    {/* Editable cells */}
                    {COLS.map((c) => (
                      <Cell key={c.key} row={row} col={c.key} type={c.type}
                        opts={"opts" in c ? (c as any).opts : undefined} />
                    ))}

                    {/* % Comp — locked, auto from status */}
                    <td className="border-r border-b border-[#d3d3d3] bg-[#f3f8f5] px-2 py-1.5"
                      style={{ width: 82 }} title="Auto-computed from status">
                      <div className="flex items-center gap-1.5 pr-1">
                        <div className="flex-1 bg-gray-200 rounded-full h-1.5 overflow-hidden">
                          <div className={`h-full rounded-full transition-all ${pct === 100 ? "bg-green-500" : "bg-[#1a4731]"}`}
                            style={{ width: `${pct}%` }} />
                        </div>
                        <span className="text-[10px] font-medium text-[#1a4731] w-7 text-right flex-shrink-0">{pct}%</span>
                      </div>
                    </td>

                    {/* Ageing */}
                    <td className="border-r border-b border-[#d3d3d3] bg-[#f8f8f8] px-2 py-1.5 text-[11px] text-muted-foreground whitespace-nowrap" style={{ width: 65 }}>
                      {comp.agingDays !== null ? `${comp.agingDays}d` : "—"}
                    </td>

                    {/* Days Left */}
                    <td className={`border-r border-b border-[#d3d3d3] bg-[#f8f8f8] px-2 py-1.5 text-[11px] whitespace-nowrap font-medium ${
                      comp.daysLeft === null ? "text-muted-foreground" :
                      comp.daysLeft < 0 ? "text-red-600" :
                      comp.daysLeft <= 7 ? "text-amber-600" : "text-green-600"
                    }`} style={{ width: 70 }}>
                      {comp.daysLeft !== null
                        ? comp.daysLeft < 0 ? `${Math.abs(comp.daysLeft)}d over` : `${comp.daysLeft}d`
                        : "—"}
                    </td>

                    {/* Priority */}
                    <td className={`border-r border-b border-[#d3d3d3] bg-[#f8f8f8] px-2 py-1.5 text-[11px] whitespace-nowrap font-medium ${
                      comp.priority === "Critical" ? "text-red-600" :
                      comp.priority === "High" ? "text-orange-500" :
                      comp.priority === "Medium" ? "text-amber-500" :
                      comp.priority === "Normal" ? "text-green-600" : "text-muted-foreground"
                    }`} style={{ width: 75 }}>
                      {comp.priority || "—"}
                    </td>

                    {/* Remarks */}
                    <td className="border-r border-b border-[#d3d3d3] bg-[#f8f8f8] px-2 py-1.5 text-[11px] whitespace-nowrap" style={{ width: 125 }}>
                      {comp.remarks ? <span className="text-red-500 font-medium">{comp.remarks}</span> : <span className="text-muted-foreground">—</span>}
                    </td>

                    {/* Created On */}
                    <td className="border-b border-[#d3d3d3] bg-[#f8f8f8] px-2 py-1.5 text-[11px] text-muted-foreground whitespace-nowrap font-mono" style={{ width: 140 }}>
                      {fmtDateTime(row.created_at)}
                    </td>

                    {/* Delete */}
                    <td className="border-b border-[#d3d3d3] text-center" style={{ width: 40, minWidth: 40 }}>
                      <button onClick={() => deleteRow(row.id)}
                        className="text-gray-300 hover:text-red-500 transition-colors p-1"
                        title="Delete row">
                        <X className="h-3.5 w-3.5" />
                      </button>
                    </td>
                  </tr>
                );
              })}

              {/* Add row */}
              <tr>
                <td colSpan={COLS.length + 9} className="border-b border-[#d3d3d3]">
                  <button onClick={addRow}
                    className="flex items-center gap-1.5 text-[12px] text-muted-foreground hover:text-[#1a4731] hover:bg-green-50 px-3 py-2 w-full transition-colors">
                    <Plus className="h-3.5 w-3.5" />Add Row
                  </button>
                </td>
              </tr>

              {rows.length === 0 && !loading && (
                <tr>
                  <td colSpan={COLS.length + 9} className="py-16 text-center text-muted-foreground text-sm">
                    No rows yet — click "Add Row" to get started.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        )}
      </div>

      {/* Members Modal */}
      {showMembers && (
        <div className="fixed inset-0 bg-black/40 z-50 flex items-center justify-center"
          onClick={(e) => { if (e.target === e.currentTarget) setShowMembers(false); }}>
          <div className="bg-background rounded-xl shadow-2xl w-96 max-h-[70vh] flex flex-col">
            <div className="flex items-center justify-between px-5 py-4 border-b border-border">
              <div>
                <h3 className="font-semibold text-base">Circle {activeCircle.toUpperCase()} — Members</h3>
                <p className="text-xs text-muted-foreground mt-0.5">
                  {currentCircle?.members?.length ?? 0} member{currentCircle?.members?.length !== 1 ? "s" : ""} assigned
                </p>
              </div>
              <button onClick={() => setShowMembers(false)} className="text-muted-foreground hover:text-foreground">
                <X className="h-4 w-4" />
              </button>
            </div>
            <div className="px-4 py-3 border-b border-border">
              <div className="relative">
                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                <input className="w-full pl-8 pr-3 py-1.5 text-sm border border-border rounded-md bg-background focus:outline-none focus:ring-2 focus:ring-[#1a4731]/40"
                  placeholder="Search team members..."
                  value={memberSearch} onChange={(e) => setMemberSearch(e.target.value)} />
              </div>
            </div>
            <div className="overflow-y-auto flex-1 py-2">
              {filteredUsers.map((u) => {
                const isMember = currentCircle?.members.some((m) => m.id === u.id) ?? false;
                const initials = u.name.split(" ").map((n) => n[0]).join("").substring(0, 2).toUpperCase();
                return (
                  <div key={u.id} className="flex items-center justify-between px-4 py-2.5 hover:bg-muted/30 transition-colors">
                    <div className="flex items-center gap-3">
                      <div className="h-8 w-8 rounded-full bg-[#1a4731] text-white flex items-center justify-center text-xs font-semibold flex-shrink-0">{initials}</div>
                      <div>
                        <p className="text-sm font-medium">{u.name}</p>
                        <p className="text-[10px] text-muted-foreground capitalize">{u.role}</p>
                      </div>
                    </div>
                    <button onClick={() => toggleMember(u.id)}
                      className={`text-xs font-medium px-3 py-1 rounded-md transition-colors ${
                        isMember ? "bg-red-50 text-red-600 hover:bg-red-100" : "bg-[#1a4731]/10 text-[#1a4731] hover:bg-[#1a4731]/20"
                      }`}>
                      {isMember ? "Remove" : "Add"}
                    </button>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
