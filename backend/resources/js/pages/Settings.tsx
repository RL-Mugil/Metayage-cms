import { Head, usePage } from "@inertiajs/react";
import { useState, useEffect } from "react";
import { User, Bell, Shield, Palette, Settings as SettingsIcon, Key, Building, Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";

function loadPrefs<T>(key: string, fallback: T): T {
  if (typeof window === "undefined") return fallback;
  try {
    const raw = localStorage.getItem(key);
    return raw ? { ...fallback, ...JSON.parse(raw) } : fallback;
  } catch {
    return fallback;
  }
}

type Tab = "profile" | "notifications" | "security" | "appearance" | "system";

const tabs: { id: Tab; label: string; icon: React.ElementType }[] = [
  { id: "profile", label: "Profile", icon: User },
  { id: "notifications", label: "Notifications", icon: Bell },
  { id: "security", label: "Security", icon: Shield },
  { id: "appearance", label: "Appearance", icon: Palette },
  { id: "system", label: "System", icon: Building },
];

function Toggle({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <button onClick={() => onChange(!checked)}
      className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${checked ? "bg-gold" : "bg-muted"}`}>
      <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform ${checked ? "translate-x-4.5" : "translate-x-0.5"}`} style={{ transform: checked ? "translateX(18px)" : "translateX(2px)" }} />
    </button>
  );
}

export default function Settings() {
  const { props } = usePage() as any;
  const user = props.auth?.user;
  const [tab, setTab] = useState<Tab>("profile");
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [profile, setProfile] = useState({ name: user?.name || "", email: user?.email || "", timezone: "Asia/Kolkata", language: "English" });
  const [notifs, setNotifs] = useState(() => loadPrefs("ipflow.notifs", { taskAssigned: true, deadlineEmail: true, paymentReceived: true, pushNotif: false, weeklyDigest: true, monthlyReport: true }));
  const [theme, setTheme] = useState<"light" | "dark" | "system">(() => loadPrefs("ipflow.appearance", { theme: "dark" as const }).theme);
  const [accentColor, setAccentColor] = useState(() => loadPrefs("ipflow.appearance", { accent: "gold" }).accent);
  const [pwForm, setPwForm] = useState({ current: "", next: "", confirm: "" });
  const [system, setSystem] = useState(() => loadPrefs("ipflow.system", { company: "My IP Law Firm", timezone: "Asia/Kolkata", currency: "INR", fiscalMonth: "April", maxUploadMB: "50" }));

  useEffect(() => {
    api.getSettings().then((data) => {
      if (data.profile) {
        setProfile((p) => ({ ...p, ...(data.profile as object) }));
      }
      if (data.notifications) {
        setNotifs((n) => ({ ...n, ...(data.notifications as object) }));
      }
      if (data.system) {
        setSystem((s) => ({ ...s, ...(data.system as object) }));
      }
    }).catch(() => {});
  }, []);

  const flashSaved = () => { setError(""); setSaved(true); setTimeout(() => setSaved(false), 2500); };

  const saveProfile = async () => {
    setSaving(true);
    setError("");
    try {
      await api.updateProfile({ name: profile.name, email: profile.email, timezone: profile.timezone, language: profile.language });
      flashSaved();
    } catch (e: any) {
      setError(e.message || "Failed to save profile.");
    } finally {
      setSaving(false);
    }
  };

  const savePassword = async () => {
    if (!pwForm.current || !pwForm.next) { setError("Fill in all password fields."); return; }
    if (pwForm.next !== pwForm.confirm) { setError("New passwords do not match."); return; }
    setSaving(true);
    setError("");
    try {
      await api.updatePassword({
        current_password: pwForm.current,
        password: pwForm.next,
        password_confirmation: pwForm.confirm,
      });
      setPwForm({ current: "", next: "", confirm: "" });
      flashSaved();
    } catch (e: any) {
      setError(e.message || "Failed to change password.");
    } finally {
      setSaving(false);
    }
  };

  const saveNotifs = async () => {
    setSaving(true);
    setError("");
    try {
      await api.updateNotifications(notifs);
      localStorage.setItem("ipflow.notifs", JSON.stringify(notifs));
      flashSaved();
    } catch (e: any) {
      setError(e.message || "Failed to save notification preferences.");
    } finally {
      setSaving(false);
    }
  };

  const saveAppearance = () => {
    localStorage.setItem("ipflow.appearance", JSON.stringify({ theme, accent: accentColor }));
    const root = document.documentElement;
    if (theme === "dark") {
      root.classList.add("dark");
    } else if (theme === "light") {
      root.classList.remove("dark");
    } else {
      // system: follow OS preference
      const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
      root.classList.toggle("dark", prefersDark);
    }
    flashSaved();
  };

  const saveSystem = async () => {
    setSaving(true);
    setError("");
    try {
      await api.updateSystemSettings(system);
      flashSaved();
    } catch (e: any) {
      setError(e.message || "Failed to save system settings.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <AppLayout>
      <Head title="Settings" />
      <PageHeader eyebrow="Operations" title="Settings" description="Manage your account, preferences, and system configuration" />
      <div className="flex min-h-[calc(100vh-180px)]">
        {/* Sidebar */}
        <div className="w-56 border-r border-border flex-shrink-0 py-4">
          {tabs.map(({ id, label, icon: Icon }) => (
            <button key={id} onClick={() => setTab(id)}
              className={`w-full flex items-center gap-3 px-6 py-2.5 text-sm transition-colors ${tab === id ? "text-gold font-semibold bg-gold/10 border-r-2 border-gold" : "text-muted-foreground hover:text-foreground hover:bg-muted/30"}`}>
              <Icon className="h-4 w-4" />{label}
            </button>
          ))}
        </div>

        {/* Content */}
        <div className="flex-1 p-8 space-y-6 max-w-2xl">
          {saved && (
            <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 font-medium">
              ✓ Settings saved successfully
            </div>
          )}
          {error && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 font-medium">
              {error}
            </div>
          )}

          {/* Profile */}
          {tab === "profile" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display">Profile Information</CardTitle></CardHeader>
              <CardContent className="space-y-4">
                <div className="flex items-center gap-4 mb-6">
                  <div className="h-16 w-16 rounded-full bg-gold/20 flex items-center justify-center text-gold text-xl font-bold">
                    {profile.name.charAt(0)}
                  </div>
                  <div>
                    <div className="font-semibold">{profile.name}</div>
                    <Badge variant="outline" className="text-xs mt-1">{user?.role || "Admin"}</Badge>
                  </div>
                </div>
                {[
                  { label: "Full Name", key: "name", type: "text" },
                  { label: "Email Address", key: "email", type: "email" },
                ].map(({ label, key, type }) => (
                  <div key={key}>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">{label}</label>
                    <input type={type} value={(profile as any)[key]}
                      onChange={(e) => setProfile((p) => ({ ...p, [key]: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                ))}
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">Timezone</label>
                    <select value={profile.timezone} onChange={(e) => setProfile((p) => ({ ...p, timezone: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                      {["Asia/Kolkata", "Asia/Dubai", "Europe/London", "America/New_York", "America/Los_Angeles"].map((tz) => <option key={tz}>{tz}</option>)}
                    </select>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">Language</label>
                    <select value={profile.language} onChange={(e) => setProfile((p) => ({ ...p, language: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                      {["English", "Hindi", "Tamil", "Telugu"].map((l) => <option key={l}>{l}</option>)}
                    </select>
                  </div>
                </div>
                <Button onClick={saveProfile} disabled={saving} className="bg-gold hover:bg-gold/90 text-black">
                  {saving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}Save Changes
                </Button>
              </CardContent>
            </Card>
          )}

          {/* Notifications */}
          {tab === "notifications" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display">Notification Preferences</CardTitle></CardHeader>
              <CardContent className="divide-y divide-border">
                {([
                  { key: "taskAssigned", label: "Task Assigned", desc: "Email when a task is assigned to you" },
                  { key: "deadlineEmail", label: "Deadline Alerts", desc: "Email 3 days before IP deadlines" },
                  { key: "paymentReceived", label: "Payment Events", desc: "Email on invoice paid or overdue" },
                  { key: "pushNotif", label: "Push Notifications", desc: "Browser push notifications" },
                  { key: "weeklyDigest", label: "Weekly Digest", desc: "Summary email every Monday" },
                  { key: "monthlyReport", label: "Monthly Report", desc: "Auto-generate and email monthly report" },
                ] as { key: keyof typeof notifs; label: string; desc: string }[]).map(({ key, label, desc }) => (
                  <div key={key} className="flex items-center justify-between py-4 first:pt-0 last:pb-0">
                    <div>
                      <div className="text-sm font-medium">{label}</div>
                      <div className="text-xs text-muted-foreground mt-0.5">{desc}</div>
                    </div>
                    <Toggle checked={notifs[key]} onChange={(v) => setNotifs((p) => ({ ...p, [key]: v }))} />
                  </div>
                ))}
                <div className="pt-4">
                  <Button onClick={saveNotifs} disabled={saving} className="bg-gold hover:bg-gold/90 text-black">
                    {saving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}Save Preferences
                  </Button>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Security */}
          {tab === "security" && (
            <div className="space-y-6">
              <Card className="border-border">
                <CardHeader><CardTitle className="font-display text-base">Change Password</CardTitle></CardHeader>
                <CardContent className="space-y-3">
                  {[
                    { label: "Current Password", key: "current" },
                    { label: "New Password", key: "next" },
                    { label: "Confirm New Password", key: "confirm" },
                  ].map(({ label, key }) => (
                    <div key={key}>
                      <label className="block text-xs font-medium text-muted-foreground mb-1">{label}</label>
                      <input type="password" value={(pwForm as any)[key]}
                        onChange={(e) => setPwForm((p) => ({ ...p, [key]: e.target.value }))}
                        className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                    </div>
                  ))}
                  <Button onClick={savePassword} disabled={saving} className="bg-gold hover:bg-gold/90 text-black mt-2">
                    {saving ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Key className="h-4 w-4 mr-2" />}Update Password
                  </Button>
                  <p className="text-xs text-muted-foreground pt-1">Minimum 8 characters. You stay signed in after changing your password.</p>
                </CardContent>
              </Card>
            </div>
          )}

          {/* Appearance */}
          {tab === "appearance" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display">Appearance</CardTitle></CardHeader>
              <CardContent className="space-y-6">
                <div>
                  <label className="block text-sm font-medium mb-3">Theme</label>
                  <div className="grid grid-cols-3 gap-3">
                    {(["light", "dark", "system"] as const).map((t) => (
                      <button key={t} onClick={() => setTheme(t)}
                        className={`p-4 rounded-xl border-2 capitalize text-sm font-medium transition-all ${theme === t ? "border-gold bg-gold/10 text-gold" : "border-border hover:border-gold/50"}`}>
                        {t === "light" ? "☀️" : t === "dark" ? "🌙" : "💻"} {t}
                      </button>
                    ))}
                  </div>
                </div>
                <div>
                  <label className="block text-sm font-medium mb-3">Accent Color</label>
                  <div className="flex gap-3">
                    {[
                      { name: "gold", color: "bg-[#C8971D]" },
                      { name: "blue", color: "bg-blue-500" },
                      { name: "green", color: "bg-green-500" },
                      { name: "purple", color: "bg-purple-500" },
                      { name: "rose", color: "bg-rose-500" },
                    ].map(({ name, color }) => (
                      <button key={name} onClick={() => setAccentColor(name)}
                        className={`h-8 w-8 rounded-full ${color} transition-all ${accentColor === name ? "ring-2 ring-offset-2 ring-foreground scale-110" : "hover:scale-105"}`} />
                    ))}
                  </div>
                </div>
                <Button onClick={saveAppearance} className="bg-gold hover:bg-gold/90 text-black">Save Appearance</Button>
              </CardContent>
            </Card>
          )}

          {/* System */}
          {tab === "system" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display">System Configuration</CardTitle></CardHeader>
              <CardContent className="space-y-4">
                {[
                  { label: "Company / Firm Name", key: "company" },
                  { label: "Default Timezone", key: "timezone" },
                  { label: "Currency", key: "currency" },
                  { label: "Fiscal Year Start Month", key: "fiscalMonth" },
                  { label: "Max File Upload (MB)", key: "maxUploadMB" },
                ].map(({ label, key }) => (
                  <div key={key}>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">{label}</label>
                    <input value={(system as any)[key]}
                      onChange={(e) => setSystem((p) => ({ ...p, [key]: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                  </div>
                ))}
                <Button onClick={saveSystem} disabled={saving} className="bg-gold hover:bg-gold/90 text-black mt-2">
                  {saving ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <SettingsIcon className="h-4 w-4 mr-2" />}Save System Settings
                </Button>
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </AppLayout>
  );
}
