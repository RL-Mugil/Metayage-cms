import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Bell, BellOff, CheckCircle2, Clock, Calendar, Plus, AlertCircle, Loader2, Trash2 } from "lucide-react";
import { api } from "@/lib/api-client";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { fmtDate } from "@/lib/date-utils";

type Category = "Deadline" | "Meeting" | "Follow-up" | "Renewal";

interface Reminder {
  id: number;
  title: string;
  description: string;
  category: Category;
  dueDate: string;
  dueTime?: string;
  assignedTo: string;
  completed: boolean;
  section: "today" | "week" | "upcoming";
}

interface EmployeeOption {
  id: number;
  name: string;
}

const CATEGORY_COLORS: Record<Category, string> = {
  Deadline: "bg-red-100 text-red-700 border-red-200",
  Meeting: "bg-blue-100 text-blue-700 border-blue-200",
  "Follow-up": "bg-purple-100 text-purple-700 border-purple-200",
  Renewal: "bg-amber-100 text-amber-700 border-amber-200",
};

const CATEGORIES: Category[] = ["Deadline", "Meeting", "Follow-up", "Renewal"];

export default function Reminders() {
  const [reminders, setReminders] = useState<Reminder[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [showForm, setShowForm] = useState(false);
  const [employees, setEmployees] = useState<EmployeeOption[]>([]);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [newReminder, setNewReminder] = useState({
    title: "",
    description: "",
    dueDate: "",
    dueTime: "",
    category: "Deadline" as Category,
    remindTo: "self",
  });

  const load = () => api.getReminders().then((d) => setReminders(d as unknown as Reminder[])).catch(() => {}).finally(() => setLoading(false));

  useEffect(() => {
    load();
    api.getEmployees()
      .then((list) => {
        setEmployees(
          list.map((e: any) => ({
            id: e.user_id ?? e.id,
            name: e.user?.name ?? e.full_name ?? `Employee #${e.id}`,
          }))
        );
      })
      .catch(() => {});
  }, []);

  function toggleComplete(id: number) {
    const target = reminders.find((r) => r.id === id);
    if (!target) return;
    const next = !target.completed;
    setReminders((prev) => prev.map((r) => (r.id === id ? { ...r, completed: next } : r)));
    api.updateReminder(id, { completed: next }).catch(() => load());
  }

  async function deleteReminder(id: number) {
    setDeletingId(id);
    try {
      await api.deleteReminder(id);
      setReminders((prev) => prev.filter((r) => r.id !== id));
    } catch {
      load();
    } finally {
      setDeletingId(null);
    }
  }

  async function saveReminder() {
    if (!newReminder.title || !newReminder.dueDate) return;
    setSaving(true);
    try {
      const scope = newReminder.remindTo === "self" ? "self" : "team";
      await api.createReminder({
        title: newReminder.title,
        description: newReminder.description || null,
        category: newReminder.category,
        due_date: newReminder.dueDate,
        due_time: newReminder.dueTime || null,
        scope,
      });
      setNewReminder({ title: "", description: "", dueDate: "", dueTime: "", category: "Deadline", remindTo: "self" });
      setShowForm(false);
      load();
    } catch { /* validation errors keep the form open */ }
    finally { setSaving(false); }
  }

  const active = reminders.filter((r) => !r.completed);
  const dueToday = reminders.filter(
    (r) => !r.completed && r.section === "today"
  ).length;

  const overdueCount = reminders.filter((r) => {
    if (r.completed) return false;
    const due = new Date(r.dueDate + "T00:00:00");
    return due < new Date(new Date().toDateString());
  }).length;

  const bySection = (section: "today" | "week" | "upcoming") =>
    reminders.filter((r) => r.section === section);

  const sectionLabel: Record<string, string> = {
    today: "Today",
    week: "This Week",
    upcoming: "Upcoming",
  };

  if (loading) return (
    <AppLayout>
      <Head title="Reminders" />
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-gold" />
      </div>
    </AppLayout>
  );

  return (
    <AppLayout>
      <Head title="Reminders" />
      <PageHeader
        eyebrow="Engagement"
        title="Reminders"
        description="Automated deadline and follow-up reminders"
        actions={
          <Button
            onClick={() => setShowForm(!showForm)}
            className="bg-gold text-background hover:bg-gold/90"
          >
            <Plus className="mr-2 h-4 w-4" />
            New Reminder
          </Button>
        }
      />

      <div className="px-8 py-6 space-y-6">
        {/* Stats */}
        <div className="grid grid-cols-3 gap-4">
          <Card className="border-border">
            <CardContent className="flex items-center gap-3 py-4">
              <Bell className="h-5 w-5 text-gold" />
              <div>
                <p className="text-xs text-muted-foreground">Total Active</p>
                <p className="text-2xl font-bold text-foreground">{active.length}</p>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="flex items-center gap-3 py-4">
              <Clock className={`h-5 w-5 ${dueToday > 0 ? "text-red-500" : "text-muted-foreground"}`} />
              <div>
                <p className="text-xs text-muted-foreground">Due Today</p>
                <p className={`text-2xl font-bold ${dueToday > 0 ? "text-red-500" : "text-foreground"}`}>
                  {dueToday}
                </p>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="flex items-center gap-3 py-4">
              <AlertCircle className={`h-5 w-5 ${overdueCount > 0 ? "text-red-500" : "text-muted-foreground"}`} />
              <div>
                <p className="text-xs text-muted-foreground">Overdue</p>
                <p className={`text-2xl font-bold ${overdueCount > 0 ? "text-red-500" : "text-foreground"}`}>
                  {overdueCount}
                </p>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* New Reminder Form */}
        {showForm && (
          <Card className="border-border border-gold/40">
            <CardHeader>
              <CardTitle className="text-base">New Reminder</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="space-y-1 md:col-span-2">
                  <label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Title</label>
                  <input
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
                    placeholder="Reminder title..."
                    value={newReminder.title}
                    onChange={(e) => setNewReminder({ ...newReminder, title: e.target.value })}
                  />
                </div>
                <div className="space-y-1 md:col-span-2">
                  <label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Description</label>
                  <input
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
                    placeholder="Optional description..."
                    value={newReminder.description}
                    onChange={(e) => setNewReminder({ ...newReminder, description: e.target.value })}
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Date</label>
                  <input
                    type="date"
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
                    value={newReminder.dueDate}
                    onChange={(e) => setNewReminder({ ...newReminder, dueDate: e.target.value })}
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Time (optional)</label>
                  <input
                    type="time"
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
                    value={newReminder.dueTime}
                    onChange={(e) => setNewReminder({ ...newReminder, dueTime: e.target.value })}
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Category</label>
                  <select
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
                    value={newReminder.category}
                    onChange={(e) => setNewReminder({ ...newReminder, category: e.target.value as Category })}
                  >
                    {CATEGORIES.map((c) => (
                      <option key={c} value={c}>{c}</option>
                    ))}
                  </select>
                </div>
                <div className="space-y-1">
                  <label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">Remind To</label>
                  <select
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
                    value={newReminder.remindTo}
                    onChange={(e) => setNewReminder({ ...newReminder, remindTo: e.target.value })}
                  >
                    <option value="self">Myself</option>
                    {employees.map((emp) => (
                      <option key={emp.id} value={String(emp.id)}>
                        {emp.name}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
              <div className="flex gap-3">
                <Button onClick={saveReminder} disabled={saving} className="bg-gold text-background hover:bg-gold/90">
                  {saving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Saving…</> : "Save Reminder"}
                </Button>
                <Button variant="outline" onClick={() => setShowForm(false)}>Cancel</Button>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Sections */}
        {(["today", "week", "upcoming"] as const).map((section) => {
          const items = bySection(section);
          if (items.length === 0) return null;
          return (
            <div key={section} className="space-y-3">
              <h2 className="text-xs font-semibold uppercase tracking-widest text-gold">
                {sectionLabel[section]}
              </h2>
              <div className="space-y-2">
                {items.map((reminder) => (
                  <Card
                    key={reminder.id}
                    className={`border-border transition-opacity ${reminder.completed ? "opacity-50" : ""}`}
                  >
                    <CardContent className="flex items-start gap-4 p-4">
                      <button
                        onClick={() => toggleComplete(reminder.id)}
                        className="mt-0.5 flex-shrink-0"
                      >
                        {reminder.completed ? (
                          <CheckCircle2 className="h-5 w-5 text-green-500" />
                        ) : (
                          <div className="h-5 w-5 rounded-full border-2 border-border hover:border-gold transition-colors" />
                        )}
                      </button>
                      <div className="flex-1 min-w-0 space-y-1">
                        <div className="flex items-start justify-between gap-2 flex-wrap">
                          <p
                            className={`text-sm font-medium ${
                              reminder.completed ? "line-through text-muted-foreground" : "text-foreground"
                            }`}
                          >
                            {reminder.title}
                          </p>
                          <span
                            className={`text-[10px] font-medium px-2 py-0.5 rounded border flex-shrink-0 ${CATEGORY_COLORS[reminder.category]}`}
                          >
                            {reminder.category}
                          </span>
                        </div>
                        {reminder.description && (
                          <p className="text-xs text-muted-foreground">{reminder.description}</p>
                        )}
                        <div className="flex items-center gap-4 text-xs text-muted-foreground">
                          <span className="flex items-center gap-1">
                            <Calendar className="h-3 w-3" />
                            {fmtDate(reminder.dueDate)}
                            {reminder.dueTime && ` at ${reminder.dueTime}`}
                          </span>
                          <span className="flex items-center gap-1">
                            <BellOff className="h-3 w-3" />
                            {reminder.assignedTo}
                          </span>
                        </div>
                      </div>
                      {/* Delete button */}
                      <button
                        onClick={() => deleteReminder(reminder.id)}
                        disabled={deletingId === reminder.id}
                        className="flex-shrink-0 p-1.5 rounded hover:bg-destructive/20 text-muted-foreground hover:text-destructive transition-colors"
                        title="Delete reminder"
                      >
                        {deletingId === reminder.id
                          ? <Loader2 className="h-4 w-4 animate-spin" />
                          : <Trash2 className="h-4 w-4" />}
                      </button>
                    </CardContent>
                  </Card>
                ))}
              </div>
            </div>
          );
        })}

        {reminders.length === 0 && (
          <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
            <Bell className="h-10 w-10 opacity-30" />
            <p className="text-sm">No reminders yet. Create one above.</p>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
