import { Head, usePage, router } from "@inertiajs/react";
import { useState, useEffect, useRef, useCallback } from "react";
import { User, Bell, Shield, Palette, Settings as SettingsIcon, Key, Building, Loader2, Camera, Trash2, ZoomIn, ZoomOut, RotateCcw, Plus, X, Landmark, Check } from "lucide-react";
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

const TIMEZONES = [
  "Asia/Kolkata", "Asia/Dubai", "Asia/Singapore", "Asia/Tokyo",
  "Europe/London", "Europe/Paris", "America/New_York", "America/Chicago",
  "America/Los_Angeles", "Australia/Sydney", "UTC",
];

const LANGUAGES = ["English", "Hindi", "Tamil", "Telugu"];

type Tab = "profile" | "notifications" | "security" | "appearance" | "system" | "finance";

const tabs: { id: Tab; label: string; icon: React.ElementType }[] = [
  { id: "profile",       label: "Profile",       icon: User },
  { id: "notifications", label: "Notifications", icon: Bell },
  { id: "security",      label: "Security",      icon: Shield },
  { id: "appearance",    label: "Appearance",    icon: Palette },
  { id: "system",        label: "System",        icon: Building },
  { id: "finance",       label: "Finance",       icon: Landmark },
];

const FEE_JURISDICTIONS = ["IN", "US", "EP", "WO"] as const;
const BLANK_FEE_ROW = {
  jurisdiction: "IN", service_code: "", entity_tier: "", year_from: "", year_to: "",
  validation_country: "", govt_fee_amount: "", govt_fee_currency: "INR",
  professional_fee_amount: "", professional_fee_currency: "INR", professional_fee_max_amount: "",
  professional_fee_charge_basis: "per_unit", notes: "", is_active: true,
};

function Toggle({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <button onClick={() => onChange(!checked)}
      className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors ${checked ? "bg-gold" : "bg-muted"}`}>
      <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform`}
        style={{ transform: checked ? "translateX(18px)" : "translateX(2px)" }} />
    </button>
  );
}

function AvatarDisplay({ src, name, size = "lg" }: { src?: string | null; name: string; size?: "sm" | "lg" }) {
  const dim = size === "lg" ? "h-16 w-16 text-xl" : "h-8 w-8 text-xs";
  if (src) return <img src={src} alt={name} className={`${dim} rounded-full object-cover ring-2 ring-gold/30`} />;
  return (
    <div className={`${dim} rounded-full bg-gold/20 flex items-center justify-center text-gold font-bold`}>
      {name.charAt(0).toUpperCase()}
    </div>
  );
}

export default function Settings() {
  const { props } = usePage() as any;
  const user = props.auth?.user;
  const canManageSystem = ["super_admin", "partner"].includes(user?.role ?? "");
  const [tab, setTab] = useState<Tab>("profile");
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [avatarUploading, setAvatarUploading] = useState(false);
  const [avatarRemoving, setAvatarRemoving] = useState(false);
  const [localAvatar, setLocalAvatar] = useState<string | null>(user?.avatar_url ?? null);
  const avatarInputRef = useRef<HTMLInputElement>(null);

  // ── Crop modal state ──
  const [cropSrc, setCropSrc] = useState<string | null>(null);
  const cropCanvasRef = useRef<HTMLCanvasElement>(null);
  const cropImgRef = useRef<HTMLImageElement | null>(null);
  const [cropScale, setCropScale] = useState(1);
  const [cropOffset, setCropOffset] = useState({ x: 0, y: 0 });
  const dragState = useRef<{ active: boolean; startX: number; startY: number; startOX: number; startOY: number }>({ active: false, startX: 0, startY: 0, startOX: 0, startOY: 0 });
  const CANVAS_SIZE = 300;
  const EXPORT_SIZE = 400;
  const [profile, setProfile] = useState({
    name: user?.name || "",
    email: user?.email || "",
    timezone: user?.timezone || "Asia/Kolkata",
    language: user?.language || "English",
  });
  const [notifs, setNotifs] = useState({ taskAssigned: true, deadlineEmail: true, paymentReceived: true, pushNotif: false, weeklyDigest: true, monthlyReport: true });
  const [theme, setTheme] = useState<"light" | "dark" | "system">(() => loadPrefs("myipstrategy.appearance", { theme: "dark" as const }).theme);
  const [accentColor, setAccentColor] = useState(() => loadPrefs("myipstrategy.appearance", { accent: "gold" }).accent);
  const [pwForm, setPwForm] = useState({ current: "", next: "", confirm: "" });
  const [system, setSystem] = useState({ company: "My IP Law Firm", currency: "INR", fiscalMonth: "April", maxUploadMB: "50" });

  const isSuperAdmin = user?.role === "super_admin";
  const sysSettings = (props.systemSettings ?? {}) as any;

  const [flags, setFlags] = useState({
    feature_link_predecessor:    sysSettings.feature_link_predecessor    ?? true,
    feature_legacy_case:         sysSettings.feature_legacy_case         ?? true,
    feature_existing_client:     sysSettings.feature_existing_client     ?? true,
    feature_lock_code_dropdowns: sysSettings.feature_lock_code_dropdowns ?? true,
  });
  const [savingFlags, setSavingFlags] = useState(false);

  const [renewalFees, setRenewalFees] = useState({
    government_fee: String(sysSettings.renewal_fee_rates?.government_fee ?? 0),
    professional_fee: String(sysSettings.renewal_fee_rates?.professional_fee ?? 0),
    currency: sysSettings.renewal_fee_rates?.currency ?? "INR",
  });
  const [savingRenewalFees, setSavingRenewalFees] = useState(false);

  const [svcCodes, setSvcCodes]   = useState<{code:string;label:string}[]>(sysSettings.dropdown_service_codes ?? []);
  const [ctyCodes, setCtyCodes]   = useState<{code:string;label:string}[]>(sysSettings.dropdown_country_codes ?? []);
  const [newSvcCode,  setNewSvcCode]  = useState("");
  const [newSvcLabel, setNewSvcLabel] = useState("");
  const [newCtyCode,  setNewCtyCode]  = useState("");
  const [newCtyLabel, setNewCtyLabel] = useState("");
  const [svcSearch, setSvcSearch] = useState("");
  const [ctySearch, setCtySearch] = useState("");
  const [svcAddErr, setSvcAddErr] = useState("");
  const [ctyAddErr, setCtyAddErr] = useState("");
  const [savingDropdown, setSavingDropdown] = useState<"service"|"country"|null>(null);

  const visibleTabs = canManageSystem ? tabs : tabs.filter((item) => item.id !== "system" && item.id !== "finance");

  useEffect(() => {
    api.getSettings().then((data) => {
      if (data.profile) setProfile((p) => ({ ...p, ...(data.profile as object) }));
      if (data.notifications) setNotifs((n) => ({ ...n, ...(data.notifications as object) }));
      if (data.system) setSystem((s) => ({ ...s, ...(data.system as object) }));
    }).catch(() => {});
  }, []);

  useEffect(() => {
    if (!canManageSystem && (tab === "system" || tab === "finance")) setTab("profile");
  }, [canManageSystem, tab]);

  const [feeRows, setFeeRows] = useState<any[]>([]);
  const [feeLoading, setFeeLoading] = useState(false);
  const [feeJurisdiction, setFeeJurisdiction] = useState<typeof FEE_JURISDICTIONS[number]>("IN");
  const [feeEdits, setFeeEdits] = useState<Record<number, any>>({});
  const [feeSavingId, setFeeSavingId] = useState<number | null>(null);
  const [newFeeRow, setNewFeeRow] = useState({ ...BLANK_FEE_ROW });
  const [feeAddErr, setFeeAddErr] = useState("");

  const loadFeeRows = useCallback(() => {
    setFeeLoading(true);
    api.getFeeRateCards({ jurisdiction: feeJurisdiction, includeInactive: true })
      .then((rows) => { setFeeRows(rows); setFeeEdits({}); })
      .catch(() => setFeeRows([]))
      .finally(() => setFeeLoading(false));
  }, [feeJurisdiction]);

  useEffect(() => {
    if (tab === "finance" && isSuperAdmin) loadFeeRows();
  }, [tab, isSuperAdmin, loadFeeRows]);

  function feeField(row: any, key: string) {
    return feeEdits[row.id]?.[key] ?? row[key] ?? "";
  }
  function setFeeField(row: any, key: string, value: any) {
    setFeeEdits((p) => ({ ...p, [row.id]: { ...(p[row.id] ?? {}), [key]: value } }));
  }
  async function saveFeeRow(row: any) {
    const edits = feeEdits[row.id];
    if (!edits) return;
    setFeeSavingId(row.id);
    try {
      const updated = await api.updateFeeRateCard(row.id, edits);
      setFeeRows((rs) => rs.map((r) => r.id === row.id ? updated : r));
      setFeeEdits((p) => { const n = { ...p }; delete n[row.id]; return n; });
      flashSaved();
    } catch { setError("Failed to save fee rate row."); }
    finally { setFeeSavingId(null); }
  }
  async function deleteFeeRow(id: number) {
    if (!confirm("Delete this fee rate row?")) return;
    try { await api.deleteFeeRateCard(id); setFeeRows((rs) => rs.filter((r) => r.id !== id)); } catch { setError("Failed to delete."); }
  }
  async function addFeeRow() {
    if (!newFeeRow.service_code.trim()) { setFeeAddErr("Service code is required."); return; }
    setFeeAddErr("");
    try {
      const payload: any = { ...newFeeRow, jurisdiction: feeJurisdiction };
      ["entity_tier", "year_from", "year_to", "validation_country", "govt_fee_amount", "govt_fee_currency",
        "professional_fee_amount", "professional_fee_currency", "professional_fee_max_amount", "notes"]
        .forEach((k) => { if (payload[k] === "") payload[k] = null; });
      const created = await api.createFeeRateCard(payload);
      setFeeRows((rs) => [...rs, created]);
      setNewFeeRow({ ...BLANK_FEE_ROW });
    } catch (e: any) { setFeeAddErr(e?.message || "Failed to add row."); }
  }

  const flashSaved = () => { setError(""); setSaved(true); setTimeout(() => setSaved(false), 2500); };

  // Compress image to under 5 MB using canvas if needed
  async function compressIfNeeded(file: File): Promise<File> {
    if (file.size <= 5 * 1024 * 1024) return file;
    return new Promise((resolve) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => {
        URL.revokeObjectURL(url);
        const MAX_DIM = 2400;
        const ratio = Math.min(1, MAX_DIM / Math.max(img.naturalWidth, img.naturalHeight));
        const canvas = document.createElement("canvas");
        canvas.width = Math.round(img.naturalWidth * ratio);
        canvas.height = Math.round(img.naturalHeight * ratio);
        canvas.getContext("2d")!.drawImage(img, 0, 0, canvas.width, canvas.height);
        const tryQ = (q: number) => {
          canvas.toBlob((blob) => {
            if (!blob || blob.size <= 5 * 1024 * 1024 || q <= 0.3) {
              resolve(new File([blob ?? file], "avatar.jpg", { type: "image/jpeg" }));
            } else {
              tryQ(q - 0.1);
            }
          }, "image/jpeg", q);
        };
        tryQ(0.85);
      };
      img.src = url;
    });
  }

  async function handleAvatarChange(e: React.ChangeEvent<HTMLInputElement>) {
    const raw = e.target.files?.[0];
    e.target.value = "";
    if (!raw) return;
    setError("");
    const file = await compressIfNeeded(raw);
    const url = URL.createObjectURL(file);
    // Store file ref for later upload after crop
    (avatarInputRef as any)._pendingFile = file;
    setCropSrc(url);
    setCropScale(1);
    setCropOffset({ x: 0, y: 0 });
  }

  // Draw crop preview on canvas
  const drawCrop = useCallback(() => {
    const canvas = cropCanvasRef.current;
    const img = cropImgRef.current;
    if (!canvas || !img || !img.complete) return;
    const ctx = canvas.getContext("2d")!;
    ctx.clearRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);

    // Fit image to fill the circle (cover), then apply user scale + offset
    const fitScale = Math.max(CANVAS_SIZE / img.naturalWidth, CANVAS_SIZE / img.naturalHeight);
    const drawW = img.naturalWidth * fitScale * cropScale;
    const drawH = img.naturalHeight * fitScale * cropScale;
    const drawX = (CANVAS_SIZE - drawW) / 2 + cropOffset.x;
    const drawY = (CANVAS_SIZE - drawH) / 2 + cropOffset.y;
    ctx.drawImage(img, drawX, drawY, drawW, drawH);

    // Dark overlay outside circle
    ctx.save();
    ctx.fillStyle = "rgba(0,0,0,0.55)";
    ctx.fillRect(0, 0, CANVAS_SIZE, CANVAS_SIZE);
    ctx.globalCompositeOperation = "destination-out";
    ctx.beginPath();
    ctx.arc(CANVAS_SIZE / 2, CANVAS_SIZE / 2, CANVAS_SIZE / 2 - 2, 0, Math.PI * 2);
    ctx.fill();
    ctx.restore();

    // Gold circle border
    ctx.strokeStyle = "#C8971D";
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.arc(CANVAS_SIZE / 2, CANVAS_SIZE / 2, CANVAS_SIZE / 2 - 2, 0, Math.PI * 2);
    ctx.stroke();
  }, [cropScale, cropOffset]);

  useEffect(() => { drawCrop(); }, [drawCrop]);

  function onCropMouseDown(e: React.MouseEvent) {
    dragState.current = { active: true, startX: e.clientX, startY: e.clientY, startOX: cropOffset.x, startOY: cropOffset.y };
  }
  function onCropMouseMove(e: React.MouseEvent) {
    if (!dragState.current.active) return;
    setCropOffset({ x: dragState.current.startOX + e.clientX - dragState.current.startX, y: dragState.current.startOY + e.clientY - dragState.current.startY });
  }
  function onCropMouseUp() { dragState.current.active = false; }

  function onCropWheel(e: React.WheelEvent) {
    e.preventDefault();
    setCropScale(s => Math.min(4, Math.max(0.5, s - e.deltaY * 0.001)));
  }

  async function applyCrop() {
    const img = cropImgRef.current;
    if (!img) return;
    const offscreen = document.createElement("canvas");
    offscreen.width = EXPORT_SIZE;
    offscreen.height = EXPORT_SIZE;
    const ctx = offscreen.getContext("2d")!;

    const fitScale = Math.max(CANVAS_SIZE / img.naturalWidth, CANVAS_SIZE / img.naturalHeight);
    const drawW = img.naturalWidth * fitScale * cropScale;
    const drawH = img.naturalHeight * fitScale * cropScale;
    const drawX = (CANVAS_SIZE - drawW) / 2 + cropOffset.x;
    const drawY = (CANVAS_SIZE - drawH) / 2 + cropOffset.y;
    const ex = EXPORT_SIZE / CANVAS_SIZE;

    ctx.beginPath();
    ctx.arc(EXPORT_SIZE / 2, EXPORT_SIZE / 2, EXPORT_SIZE / 2, 0, Math.PI * 2);
    ctx.clip();
    ctx.drawImage(img, drawX * ex, drawY * ex, drawW * ex, drawH * ex);

    offscreen.toBlob(async (blob) => {
      if (!blob) return;
      const file = new File([blob], "avatar.jpg", { type: "image/jpeg" });
      setCropSrc(null);
      if (cropSrc) URL.revokeObjectURL(cropSrc);
      setAvatarUploading(true);
      setError("");
      try {
        const res = await api.uploadAvatar(file);
        setLocalAvatar(res.avatar_url);
        flashSaved();
        router.reload({ only: [] });
      } catch (e: any) {
        setError(e.message || "Avatar upload failed.");
      } finally {
        setAvatarUploading(false);
      }
    }, "image/jpeg", 0.92);
  }

  async function handleRemoveAvatar() {
    if (!confirm("Remove your profile photo?")) return;
    setAvatarRemoving(true);
    setError("");
    try {
      await api.removeAvatar();
      setLocalAvatar(null);
      flashSaved();
      router.reload({ only: [] });
    } catch (e: any) {
      setError(e.message || "Failed to remove photo.");
    } finally {
      setAvatarRemoving(false);
    }
  }

  const saveProfile = async () => {
    setSaving(true);
    setError("");
    try {
      await api.updateProfile({ name: profile.name, email: profile.email, timezone: profile.timezone, language: profile.language });
      flashSaved();
      // Reload to propagate language/timezone to Inertia shared props
      router.reload({ only: [] });
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
      await api.updatePassword({ current_password: pwForm.current, password: pwForm.next, password_confirmation: pwForm.confirm });
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
      flashSaved();
    } catch (e: any) {
      setError(e.message || "Failed to save notification preferences.");
    } finally {
      setSaving(false);
    }
  };

  const saveAppearance = () => {
    localStorage.setItem("myipstrategy.appearance", JSON.stringify({ theme, accent: accentColor }));
    const root = document.documentElement;
    if (theme === "dark") root.classList.add("dark");
    else if (theme === "light") root.classList.remove("dark");
    else root.classList.toggle("dark", window.matchMedia("(prefers-color-scheme: dark)").matches);
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
          {visibleTabs.map(({ id, label, icon: Icon }) => (
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

          {/* ── Profile ── */}
          {tab === "profile" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display">Profile Information</CardTitle></CardHeader>
              <CardContent className="space-y-4">
                {/* Avatar upload */}
                <div className="flex items-center gap-4 mb-6">
                  <div className="relative group">
                    <AvatarDisplay src={localAvatar} name={profile.name} size="lg" />
                    <button
                      onClick={() => avatarInputRef.current?.click()}
                      disabled={avatarUploading || avatarRemoving}
                      className="absolute inset-0 rounded-full bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"
                      title="Change photo"
                    >
                      {avatarUploading
                        ? <Loader2 className="h-5 w-5 text-white animate-spin" />
                        : <Camera className="h-5 w-5 text-white" />}
                    </button>
                    <input
                      ref={avatarInputRef}
                      type="file"
                      accept="image/jpeg,image/png,image/gif,image/webp"
                      className="hidden"
                      onChange={handleAvatarChange}
                    />
                  </div>
                  <div className="space-y-1.5">
                    <div className="font-semibold">{profile.name}</div>
                    <Badge variant="outline" className="text-xs">{user?.role || "Admin"}</Badge>
                    <div className="flex items-center gap-2 pt-0.5">
                      <button
                        onClick={() => avatarInputRef.current?.click()}
                        disabled={avatarUploading || avatarRemoving}
                        className="text-xs text-gold hover:text-gold/80 underline underline-offset-2 disabled:opacity-50"
                      >
                        {avatarUploading ? "Uploading…" : localAvatar ? "Change photo" : "Upload photo"}
                      </button>
                      {localAvatar && (
                        <>
                          <span className="text-muted-foreground text-xs">·</span>
                          <button
                            onClick={handleRemoveAvatar}
                            disabled={avatarRemoving || avatarUploading}
                            className="text-xs text-destructive hover:text-destructive/80 flex items-center gap-1 disabled:opacity-50"
                          >
                            {avatarRemoving ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />}
                            Remove
                          </button>
                        </>
                      )}
                    </div>
                    <p className="text-xs text-muted-foreground">Any size — large images are auto-compressed</p>
                  </div>
                </div>

                {/* Name + Email */}
                {(["name", "email"] as const).map((key) => (
                  <div key={key}>
                    <label className="block text-xs font-medium text-muted-foreground mb-1 capitalize">
                      {key === "name" ? "Full Name" : "Email Address"}
                    </label>
                    <input
                      type={key === "email" ? "email" : "text"}
                      value={profile[key]}
                      onChange={(e) => setProfile((p) => ({ ...p, [key]: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    />
                  </div>
                ))}

                {/* Timezone + Language */}
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">Timezone</label>
                    <select
                      value={profile.timezone}
                      onChange={(e) => setProfile((p) => ({ ...p, timezone: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    >
                      {TIMEZONES.map((tz) => <option key={tz}>{tz}</option>)}
                    </select>
                    <p className="text-xs text-muted-foreground mt-1">Applies to date/time display throughout the portal.</p>
                  </div>
                  <div>
                    <label className="block text-xs font-medium text-muted-foreground mb-1">Language</label>
                    <select
                      value={profile.language}
                      onChange={(e) => setProfile((p) => ({ ...p, language: e.target.value }))}
                      className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                    >
                      {LANGUAGES.map((l) => <option key={l}>{l}</option>)}
                    </select>
                    <p className="text-xs text-muted-foreground mt-1">Portal UI language. Takes effect after save.</p>
                  </div>
                </div>

                <Button onClick={saveProfile} disabled={saving} className="bg-gold hover:bg-gold/90 text-black">
                  {saving && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}Save Changes
                </Button>
              </CardContent>
            </Card>
          )}

          {/* ── Notifications ── */}
          {tab === "notifications" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display">Notification Preferences</CardTitle></CardHeader>
              <CardContent className="divide-y divide-border">
                {([
                  { key: "taskAssigned",    label: "Task Assigned",   desc: "In-app alert when a task is assigned to you" },
                  { key: "deadlineEmail",   label: "Deadline Alerts", desc: "In-app alert 3 days before IP deadlines" },
                  { key: "paymentReceived", label: "Payment Events",  desc: "In-app alert on invoice paid or overdue" },
                  { key: "pushNotif",       label: "Push Notifications", desc: "Browser push notifications" },
                  { key: "weeklyDigest",    label: "Weekly Digest",   desc: "Summary every Monday" },
                  { key: "monthlyReport",   label: "Monthly Report",  desc: "Auto-generate monthly report" },
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

          {/* ── Security ── */}
          {tab === "security" && (
            <Card className="border-border">
              <CardHeader><CardTitle className="font-display text-base">Change Password</CardTitle></CardHeader>
              <CardContent className="space-y-3">
                {[
                  { label: "Current Password", key: "current" },
                  { label: "New Password",     key: "next" },
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
          )}

          {/* ── Appearance ── */}
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
                      { name: "gold",   color: "bg-[#C8971D]" },
                      { name: "blue",   color: "bg-blue-500" },
                      { name: "green",  color: "bg-green-500" },
                      { name: "purple", color: "bg-purple-500" },
                      { name: "rose",   color: "bg-rose-500" },
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

          {/* ── System ── */}
          {tab === "system" && (
            <div className="space-y-6">
              {/* General config */}
              <Card className="border-border">
                <CardHeader><CardTitle className="font-display">System Configuration</CardTitle></CardHeader>
                <CardContent className="space-y-4">
                  {[
                    { label: "Company / Firm Name",      key: "company" },
                    { label: "Currency",                 key: "currency" },
                    { label: "Fiscal Year Start Month",  key: "fiscalMonth" },
                    { label: "Max File Upload (MB)",     key: "maxUploadMB" },
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

              {/* Feature Flags — super_admin only */}
              {isSuperAdmin && (
                <Card className="border-border">
                  <CardHeader>
                    <CardTitle className="font-display">Feature Visibility</CardTitle>
                    <p className="text-xs text-muted-foreground mt-1">Toggle off to hide these options for all users portal-wide.</p>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {([
                      { key: "feature_link_predecessor",    label: "Link Predecessor",             desc: "Show 'Link Predecessor' button on cases" },
                      { key: "feature_legacy_case",         label: "Existing / Legacy Case",       desc: "Show the 'Existing / Legacy' tab when creating a new case" },
                      { key: "feature_existing_client",     label: "Existing Client",              desc: "Show the 'Existing' tab when creating a new client" },
                      { key: "feature_lock_code_dropdowns", label: "Lock Code Dropdowns",          desc: "Prevent users from adding custom country/service codes from the project form (manage codes only via Settings)" },
                    ] as const).map(({ key, label, desc }) => (
                      <div key={key} className="flex items-center justify-between gap-4 py-1">
                        <div>
                          <p className="text-sm font-medium">{label}</p>
                          <p className="text-xs text-muted-foreground">{desc}</p>
                        </div>
                        <Toggle checked={flags[key]} onChange={(v) => setFlags((f) => ({ ...f, [key]: v }))} />
                      </div>
                    ))}
                    <Button
                      onClick={async () => {
                        setSavingFlags(true);
                        try { await api.updateFeatureFlags(flags); flashSaved(); } catch { setError("Failed to save flags."); }
                        finally { setSavingFlags(false); }
                      }}
                      disabled={savingFlags}
                      className="bg-gold hover:bg-gold/90 text-black mt-2"
                    >
                      {savingFlags ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Shield className="h-4 w-4 mr-2" />}Save Feature Flags
                    </Button>
                  </CardContent>
                </Card>
              )}

              {/* Renewal fee rates — super_admin only. A plain lookup the client
                  portal's "approve renewal" flow multiplies by years selected —
                  update these here whenever government/professional fees change,
                  no code deploy needed. */}
              {isSuperAdmin && (
                <Card className="border-border">
                  <CardHeader>
                    <CardTitle className="font-display">Renewal Fee Rates</CardTitle>
                    <p className="text-xs text-muted-foreground mt-1">
                      Used by the client portal's renewal approval flow: total payable = years selected × (government fee + professional fee).
                    </p>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">Government Fee (per year)</label>
                        <input type="number" min={0} value={renewalFees.government_fee}
                          onChange={(e) => setRenewalFees((p) => ({ ...p, government_fee: e.target.value }))}
                          className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">Professional Fee (per year)</label>
                        <input type="number" min={0} value={renewalFees.professional_fee}
                          onChange={(e) => setRenewalFees((p) => ({ ...p, professional_fee: e.target.value }))}
                          className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                      <div>
                        <label className="block text-xs font-medium text-muted-foreground mb-1">Currency</label>
                        <input value={renewalFees.currency}
                          onChange={(e) => setRenewalFees((p) => ({ ...p, currency: e.target.value.toUpperCase() }))}
                          maxLength={10}
                          className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                      </div>
                    </div>
                    <Button
                      onClick={async () => {
                        setSavingRenewalFees(true);
                        try {
                          await api.updateRenewalFeeRates({
                            government_fee: parseFloat(renewalFees.government_fee) || 0,
                            professional_fee: parseFloat(renewalFees.professional_fee) || 0,
                            currency: renewalFees.currency,
                          });
                          flashSaved();
                        } catch { setError("Failed to save renewal fee rates."); }
                        finally { setSavingRenewalFees(false); }
                      }}
                      disabled={savingRenewalFees}
                      className="bg-gold hover:bg-gold/90 text-black"
                    >
                      {savingRenewalFees ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <SettingsIcon className="h-4 w-4 mr-2" />}Save Renewal Fee Rates
                    </Button>
                  </CardContent>
                </Card>
              )}

              {/* Dropdown Managers — super_admin only */}
              {isSuperAdmin && (
                <>
                  {/* Service Codes */}
                  <Card className="border-border">
                    <CardHeader>
                      <CardTitle className="font-display">Service Codes</CardTitle>
                      <p className="text-xs text-muted-foreground mt-1">All codes listed here appear in the Service Code dropdown across the portal. {svcCodes.length} total.</p>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      {/* Search */}
                      <div className="relative">
                        <input
                          value={svcSearch}
                          onChange={(e) => setSvcSearch(e.target.value)}
                          placeholder="Search codes or descriptions…"
                          className="w-full h-8 rounded border border-border bg-background pl-3 pr-8 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
                        />
                        {svcSearch && (
                          <button onClick={() => setSvcSearch("")} className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                            <X className="h-3 w-3" />
                          </button>
                        )}
                      </div>

                      {/* Chip list filtered by search */}
                      {(() => {
                        const q = svcSearch.trim().toLowerCase();
                        const filtered = q
                          ? svcCodes.filter((x) => x.code.toLowerCase().includes(q) || x.label.toLowerCase().includes(q))
                          : svcCodes;
                        return (
                          <div className="flex flex-wrap gap-2 min-h-[40px] max-h-60 overflow-y-auto pr-1">
                            {filtered.length === 0 && (
                              <p className="text-xs text-muted-foreground italic py-2">
                                {q ? `No codes match "${svcSearch}" — you can add it below.` : "No service codes yet."}
                              </p>
                            )}
                            {filtered.map((item) => {
                              const i = svcCodes.findIndex((x) => x.code === item.code);
                              return (
                                <span key={item.code} className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-muted text-xs font-mono">
                                  <span className="font-semibold text-gold">{item.code}</span>
                                  <span className="text-muted-foreground">– {item.label.replace(/^[A-Z\-]+ [–-] /, "")}</span>
                                  <button onClick={() => { setSvcCodes(svcCodes.filter((_, j) => j !== i)); setSvcAddErr(""); }}
                                    className="ml-1 text-muted-foreground hover:text-destructive"><X className="h-3 w-3" /></button>
                                </span>
                              );
                            })}
                          </div>
                        );
                      })()}

                      {/* Add new */}
                      <div className="border-t border-border pt-3 space-y-2">
                        <p className="text-xs font-medium text-muted-foreground">Add new code</p>
                        <div className="flex gap-2">
                          <input value={newSvcCode}
                            onChange={(e) => { setNewSvcCode(e.target.value.toUpperCase()); setSvcAddErr(""); }}
                            placeholder="CODE" maxLength={10}
                            className="w-24 h-8 rounded border border-border bg-background px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-gold" />
                          <input value={newSvcLabel} onChange={(e) => setNewSvcLabel(e.target.value)}
                            placeholder="Description (e.g. Complete Patent Draft)"
                            className="flex-1 h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                          <Button size="sm" variant="outline" className="h-8 px-3 shrink-0"
                            onClick={() => {
                              const code = newSvcCode.trim().toUpperCase();
                              const lbl  = newSvcLabel.trim();
                              if (!code) { setSvcAddErr("Enter a code."); return; }
                              if (!lbl)  { setSvcAddErr("Enter a description."); return; }
                              const dup = svcCodes.find((x) => x.code.toUpperCase() === code);
                              if (dup) { setSvcAddErr(`"${code}" already exists: "${dup.label}"`); return; }
                              setSvcCodes([...svcCodes, { code, label: `${code} – ${lbl}` }]);
                              setNewSvcCode(""); setNewSvcLabel(""); setSvcAddErr("");
                              setSvcSearch(code); // jump to the newly added chip
                            }}>
                            <Plus className="h-3 w-3" />
                          </Button>
                        </div>
                        {svcAddErr && <p className="text-xs text-destructive">{svcAddErr}</p>}
                      </div>

                      <Button size="sm" onClick={async () => {
                        setSavingDropdown("service");
                        try { await api.updateDropdown("dropdown_service_codes", svcCodes); flashSaved(); } catch { setError("Failed to save."); }
                        finally { setSavingDropdown(null); }
                      }} disabled={savingDropdown === "service"} className="bg-gold hover:bg-gold/90 text-black">
                        {savingDropdown === "service" ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : null}Save Service Codes
                      </Button>
                    </CardContent>
                  </Card>

                  {/* Country / Patent Office Codes */}
                  <Card className="border-border">
                    <CardHeader>
                      <CardTitle className="font-display">Country / Patent Office Codes</CardTitle>
                      <p className="text-xs text-muted-foreground mt-1">Codes listed here appear in the Patent Office dropdown when creating a case. {ctyCodes.length} total.</p>
                    </CardHeader>
                    <CardContent className="space-y-3">
                      {/* Search */}
                      <div className="relative">
                        <input
                          value={ctySearch}
                          onChange={(e) => setCtySearch(e.target.value)}
                          placeholder="Search country codes…"
                          className="w-full h-8 rounded border border-border bg-background pl-3 pr-8 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
                        />
                        {ctySearch && (
                          <button onClick={() => setCtySearch("")} className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                            <X className="h-3 w-3" />
                          </button>
                        )}
                      </div>

                      {/* Chip list filtered by search */}
                      {(() => {
                        const q = ctySearch.trim().toLowerCase();
                        const filtered = q
                          ? ctyCodes.filter((x) => x.code.toLowerCase().includes(q) || x.label.toLowerCase().includes(q))
                          : ctyCodes;
                        return (
                          <div className="flex flex-wrap gap-2 min-h-[40px] max-h-60 overflow-y-auto pr-1">
                            {filtered.length === 0 && (
                              <p className="text-xs text-muted-foreground italic py-2">
                                {q ? `No codes match "${ctySearch}" — you can add it below.` : "No country codes yet."}
                              </p>
                            )}
                            {filtered.map((item) => {
                              const i = ctyCodes.findIndex((x) => x.code === item.code);
                              return (
                                <span key={item.code} className="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-muted text-xs font-mono">
                                  <span className="font-semibold text-gold">{item.code}</span>
                                  <span className="text-muted-foreground">– {item.label.replace(/^[A-Z]+ [–-] /, "")}</span>
                                  <button onClick={() => { setCtyCodes(ctyCodes.filter((_, j) => j !== i)); setCtyAddErr(""); }}
                                    className="ml-1 text-muted-foreground hover:text-destructive"><X className="h-3 w-3" /></button>
                                </span>
                              );
                            })}
                          </div>
                        );
                      })()}

                      {/* Add new */}
                      <div className="border-t border-border pt-3 space-y-2">
                        <p className="text-xs font-medium text-muted-foreground">Add new code</p>
                        <div className="flex gap-2">
                          <input value={newCtyCode}
                            onChange={(e) => { setNewCtyCode(e.target.value.toUpperCase()); setCtyAddErr(""); }}
                            placeholder="XX" maxLength={4}
                            className="w-16 h-8 rounded border border-border bg-background px-2 text-xs font-mono focus:outline-none focus:ring-1 focus:ring-gold" />
                          <input value={newCtyLabel} onChange={(e) => setNewCtyLabel(e.target.value)}
                            placeholder="Country / Office name"
                            className="flex-1 h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
                          <Button size="sm" variant="outline" className="h-8 px-3 shrink-0"
                            onClick={() => {
                              const code = newCtyCode.trim().toUpperCase();
                              const lbl  = newCtyLabel.trim();
                              if (!code) { setCtyAddErr("Enter a code."); return; }
                              if (!lbl)  { setCtyAddErr("Enter a description."); return; }
                              const dup = ctyCodes.find((x) => x.code.toUpperCase() === code);
                              if (dup) { setCtyAddErr(`"${code}" already exists: "${dup.label}"`); return; }
                              setCtyCodes([...ctyCodes, { code, label: `${code} – ${lbl}` }]);
                              setNewCtyCode(""); setNewCtyLabel(""); setCtyAddErr("");
                              setCtySearch(code);
                            }}>
                            <Plus className="h-3 w-3" />
                          </Button>
                        </div>
                        {ctyAddErr && <p className="text-xs text-destructive">{ctyAddErr}</p>}
                      </div>

                      <Button size="sm" onClick={async () => {
                        setSavingDropdown("country");
                        try { await api.updateDropdown("dropdown_country_codes", ctyCodes); flashSaved(); } catch { setError("Failed to save."); }
                        finally { setSavingDropdown(null); }
                      }} disabled={savingDropdown === "country"} className="bg-gold hover:bg-gold/90 text-black">
                        {savingDropdown === "country" ? <Loader2 className="h-3 w-3 mr-1 animate-spin" /> : null}Save Country Codes
                      </Button>
                    </CardContent>
                  </Card>
                </>
              )}
            </div>
          )}

          {tab === "finance" && isSuperAdmin && (
            <div className="space-y-6">
              <Card className="border-border">
                <CardHeader>
                  <CardTitle className="font-display">Fee Rate Card</CardTitle>
                  <p className="text-xs text-muted-foreground mt-1">
                    Government + professional fees by jurisdiction, service, and client entity tier — drives
                    auto-populated amounts on quotes/invoices and the renewal-approval flow. Editable after auto-fill either way.
                  </p>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="flex gap-1.5">
                    {FEE_JURISDICTIONS.map((j) => (
                      <button key={j} onClick={() => setFeeJurisdiction(j)}
                        className={`h-8 rounded-md border px-3 text-xs font-medium ${feeJurisdiction === j ? "border-gold bg-gold/10 text-foreground" : "border-border text-muted-foreground hover:border-gold/40"}`}>
                        {j}
                      </button>
                    ))}
                  </div>

                  {feeLoading ? (
                    <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
                  ) : (
                    <div className="overflow-x-auto rounded-md border border-border">
                      <table className="w-full min-w-[1100px] text-xs">
                        <thead className="bg-muted/40 text-left text-muted-foreground">
                          <tr>
                            <th className="p-2">Service</th><th className="p-2">Tier</th>
                            <th className="p-2">Yr from</th><th className="p-2">Yr to</th>
                            <th className="p-2">Validation Country</th>
                            <th className="p-2">Govt Fee</th><th className="p-2">Govt Ccy</th>
                            <th className="p-2">Prof Fee</th><th className="p-2">Prof Ccy</th><th className="p-2">Prof Max</th>
                            <th className="p-2">Charge Basis</th><th className="p-2">Notes</th>
                            <th className="p-2">Active</th><th className="p-2"></th>
                          </tr>
                        </thead>
                        <tbody>
                          {feeRows.map((row) => {
                            const dirty = !!feeEdits[row.id];
                            return (
                              <tr key={row.id} className={`border-t border-border ${dirty ? "bg-gold/5" : ""}`}>
                                <td className="p-1"><input value={feeField(row, "service_code")} onChange={(e) => setFeeField(row, "service_code", e.target.value.toUpperCase())} className="w-16 h-7 rounded border border-border bg-background px-1 font-mono" /></td>
                                <td className="p-1">
                                  <select value={feeField(row, "entity_tier") ?? ""} onChange={(e) => setFeeField(row, "entity_tier", e.target.value || null)} className="h-7 rounded border border-border bg-background px-1">
                                    <option value="">Universal</option>
                                    <option value="discounted">Discounted</option>
                                    <option value="standard">Standard</option>
                                  </select>
                                </td>
                                <td className="p-1"><input value={feeField(row, "year_from") ?? ""} onChange={(e) => setFeeField(row, "year_from", e.target.value)} className="w-14 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "year_to") ?? ""} onChange={(e) => setFeeField(row, "year_to", e.target.value)} className="w-14 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "validation_country") ?? ""} onChange={(e) => setFeeField(row, "validation_country", e.target.value)} className="w-28 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "govt_fee_amount") ?? ""} onChange={(e) => setFeeField(row, "govt_fee_amount", e.target.value)} className="w-20 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "govt_fee_currency") ?? ""} onChange={(e) => setFeeField(row, "govt_fee_currency", e.target.value.toUpperCase())} className="w-14 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "professional_fee_amount") ?? ""} onChange={(e) => setFeeField(row, "professional_fee_amount", e.target.value)} className="w-20 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "professional_fee_currency") ?? ""} onChange={(e) => setFeeField(row, "professional_fee_currency", e.target.value.toUpperCase())} className="w-14 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1"><input value={feeField(row, "professional_fee_max_amount") ?? ""} onChange={(e) => setFeeField(row, "professional_fee_max_amount", e.target.value)} className="w-20 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1">
                                  <select value={feeField(row, "professional_fee_charge_basis")} onChange={(e) => setFeeField(row, "professional_fee_charge_basis", e.target.value)} className="h-7 rounded border border-border bg-background px-1">
                                    <option value="per_unit">Per year</option>
                                    <option value="flat_per_transaction">Flat/txn</option>
                                  </select>
                                </td>
                                <td className="p-1"><input value={feeField(row, "notes") ?? ""} onChange={(e) => setFeeField(row, "notes", e.target.value)} className="w-40 h-7 rounded border border-border bg-background px-1" /></td>
                                <td className="p-1 text-center"><Toggle checked={!!feeField(row, "is_active")} onChange={(v) => setFeeField(row, "is_active", v)} /></td>
                                <td className="p-1 whitespace-nowrap">
                                  {dirty && (
                                    <button onClick={() => saveFeeRow(row)} disabled={feeSavingId === row.id} className="mr-1 rounded p-1 text-green-600 hover:bg-green-500/10">
                                      {feeSavingId === row.id ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Check className="h-3.5 w-3.5" />}
                                    </button>
                                  )}
                                  <button onClick={() => deleteFeeRow(row.id)} className="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"><Trash2 className="h-3.5 w-3.5" /></button>
                                </td>
                              </tr>
                            );
                          })}
                          {feeRows.length === 0 && (
                            <tr><td colSpan={14} className="p-4 text-center text-muted-foreground">No rate rows for {feeJurisdiction} yet.</td></tr>
                          )}
                        </tbody>
                      </table>
                    </div>
                  )}

                  <div className="rounded-md border border-border bg-muted/20 p-3 space-y-2">
                    <p className="text-xs font-medium text-muted-foreground">Add a new {feeJurisdiction} row</p>
                    <div className="grid grid-cols-6 gap-2">
                      <input value={newFeeRow.service_code} onChange={(e) => setNewFeeRow((p) => ({ ...p, service_code: e.target.value.toUpperCase() }))} placeholder="Service code" className="h-8 rounded border border-border bg-background px-2 text-xs font-mono" />
                      <select value={newFeeRow.entity_tier} onChange={(e) => setNewFeeRow((p) => ({ ...p, entity_tier: e.target.value }))} className="h-8 rounded border border-border bg-background px-2 text-xs">
                        <option value="">Universal</option>
                        <option value="discounted">Discounted</option>
                        <option value="standard">Standard</option>
                      </select>
                      <input value={newFeeRow.year_from} onChange={(e) => setNewFeeRow((p) => ({ ...p, year_from: e.target.value }))} placeholder="Yr from" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                      <input value={newFeeRow.year_to} onChange={(e) => setNewFeeRow((p) => ({ ...p, year_to: e.target.value }))} placeholder="Yr to" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                      <input value={newFeeRow.govt_fee_amount} onChange={(e) => setNewFeeRow((p) => ({ ...p, govt_fee_amount: e.target.value }))} placeholder="Govt fee" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                      <input value={newFeeRow.professional_fee_amount} onChange={(e) => setNewFeeRow((p) => ({ ...p, professional_fee_amount: e.target.value }))} placeholder="Professional fee" className="h-8 rounded border border-border bg-background px-2 text-xs" />
                    </div>
                    {feeAddErr && <p className="text-xs text-destructive">{feeAddErr}</p>}
                    <Button size="sm" onClick={addFeeRow}><Plus className="h-3 w-3 mr-1" />Add Row</Button>
                  </div>
                </CardContent>
              </Card>
            </div>
          )}
        </div>
      </div>
      {/* ── Crop / Zoom Modal ── */}
      {cropSrc && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-2xl shadow-2xl w-full max-w-sm p-6 flex flex-col items-center gap-5">
            <div className="text-center">
              <h2 className="font-display text-base font-semibold">Adjust Photo</h2>
              <p className="text-xs text-muted-foreground mt-0.5">Drag to reposition · Scroll or use buttons to zoom</p>
            </div>

            {/* Canvas preview */}
            <div className="relative select-none" style={{ width: CANVAS_SIZE, height: CANVAS_SIZE }}>
              <canvas
                ref={cropCanvasRef}
                width={CANVAS_SIZE}
                height={CANVAS_SIZE}
                className="rounded-full cursor-grab active:cursor-grabbing"
                style={{ width: CANVAS_SIZE, height: CANVAS_SIZE }}
                onMouseDown={onCropMouseDown}
                onMouseMove={onCropMouseMove}
                onMouseUp={onCropMouseUp}
                onMouseLeave={onCropMouseUp}
                onWheel={onCropWheel}
              />
              {/* Hidden img element to hold the decoded image */}
              <img
                src={cropSrc}
                className="hidden"
                ref={(el) => {
                  cropImgRef.current = el;
                  if (el) el.onload = () => drawCrop();
                }}
                alt=""
              />
            </div>

            {/* Zoom controls */}
            <div className="flex items-center gap-3 w-full">
              <button
                onClick={() => setCropScale(s => Math.max(0.5, s - 0.1))}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-border hover:bg-muted transition-colors"
              >
                <ZoomOut className="h-4 w-4" />
              </button>
              <input
                type="range"
                min={50} max={400} step={1}
                value={Math.round(cropScale * 100)}
                onChange={e => setCropScale(Number(e.target.value) / 100)}
                className="flex-1 accent-[#C8971D] h-1.5 rounded-full cursor-pointer"
              />
              <button
                onClick={() => setCropScale(s => Math.min(4, s + 0.1))}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-border hover:bg-muted transition-colors"
              >
                <ZoomIn className="h-4 w-4" />
              </button>
              <button
                onClick={() => { setCropScale(1); setCropOffset({ x: 0, y: 0 }); }}
                className="flex h-8 w-8 items-center justify-center rounded-full border border-border hover:bg-muted transition-colors"
                title="Reset"
              >
                <RotateCcw className="h-3.5 w-3.5" />
              </button>
            </div>

            <div className="text-xs text-muted-foreground">{Math.round(cropScale * 100)}%</div>

            {/* Action buttons */}
            <div className="flex gap-3 w-full">
              <Button
                variant="outline"
                className="flex-1"
                onClick={() => { setCropSrc(null); if (cropSrc) URL.revokeObjectURL(cropSrc); }}
              >
                Cancel
              </Button>
              <Button
                className="flex-1 bg-gold hover:bg-gold/90 text-black font-semibold"
                onClick={applyCrop}
                disabled={avatarUploading}
              >
                {avatarUploading ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                Apply
              </Button>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
