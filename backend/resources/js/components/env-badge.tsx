import { useState } from "react";
import { usePage, router } from "@inertiajs/react";
import { Pencil, Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { api } from "@/lib/api-client";

const DEFAULT_BADGE = "Production · v2.0";

/**
 * The environment badge in the top bar. Everyone sees the text a system admin saved; only a
 * system admin gets the pencil and can change it.
 */
export function EnvBadge() {
  const { props } = usePage() as any;
  const text: string = props.systemSettings?.env_badge || DEFAULT_BADGE;
  const isSuperAdmin = props.auth?.user?.role === "super_admin";

  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState(text);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const badge = (
    <Badge variant="outline" className="hidden md:inline-flex border-gold/40 text-gold gap-1.5">
      {text}
      {isSuperAdmin && <Pencil className="h-3 w-3 opacity-70" aria-hidden="true" />}
    </Badge>
  );

  if (!isSuperAdmin) return badge;

  const save = async () => {
    const next = draft.trim();
    if (!next) { setError("Badge text cannot be empty."); return; }
    setSaving(true);
    setError("");
    try {
      await api.updateBadge(next);
      router.reload({ only: ["systemSettings"] });
      setOpen(false);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not save the badge.");
    } finally {
      setSaving(false);
    }
  };

  return (
    <Popover open={open} onOpenChange={(o) => { setOpen(o); if (o) { setDraft(text); setError(""); } }}>
      <PopoverTrigger asChild>
        <button type="button" className="hidden md:inline-flex rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold" aria-label={`Edit badge: ${text}`}>
          {badge}
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-72 space-y-3">
        <div>
          <p className="text-sm font-medium">Top-bar badge</p>
          <p className="text-xs text-muted-foreground">Shown to every user. Up to 40 characters.</p>
        </div>
        <Input
          value={draft}
          maxLength={40}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") save(); }}
          aria-label="Badge text"
          autoFocus
        />
        {error && <p className="text-xs text-destructive">{error}</p>}
        <div className="flex justify-between gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={() => setDraft(DEFAULT_BADGE)}>Reset</Button>
          <Button type="button" size="sm" className="bg-gold hover:bg-gold/90 text-black" onClick={save} disabled={saving}>
            {saving && <Loader2 className="h-3.5 w-3.5 mr-1.5 animate-spin" />}Save
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  );
}
