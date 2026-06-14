import { Head } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { Star, MessageSquare, Send, Filter, Loader2, Search, X } from "lucide-react";
import { api } from "@/lib/api-client";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { fmtDate } from "@/lib/date-utils";

const CATEGORY_COLORS: Record<string, string> = {
  Service: "bg-blue-100 text-blue-700 border-blue-200",
  Communication: "bg-purple-100 text-purple-700 border-purple-200",
  Turnaround: "bg-amber-100 text-amber-700 border-amber-200",
  Overall: "bg-green-100 text-green-700 border-green-200",
};

function StarDisplay({ rating, size = "sm" }: { rating: number; size?: "sm" | "lg" }) {
  const starClass = size === "lg" ? "text-2xl" : "text-sm";
  return (
    <span className={starClass}>
      {[1, 2, 3, 4, 5].map((i) => (
        <span key={i} className={i <= rating ? "text-gold" : "text-muted-foreground/30"}>
          ★
        </span>
      ))}
    </span>
  );
}

interface ClientOption {
  id: number;
  label: string;
}

function ClientCombobox({
  value,
  onChange,
}: {
  value: string;
  onChange: (v: string) => void;
}) {
  const [options, setOptions] = useState<ClientOption[]>([]);
  const [query, setQuery] = useState(value);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    setLoading(true);
    const params = new URLSearchParams({ per_page: "200" });
    api.getClients(params)
      .then((res) => {
        const list = res.data ?? (Array.isArray(res) ? res : []);
        setOptions(
          (list as any[]).map((c) => ({
            id: c.id,
            label: c.company_name || c.legal_name || `Client #${c.id}`,
          }))
        );
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const filtered = query
    ? options.filter((o) => o.label.toLowerCase().includes(query.toLowerCase()))
    : options;

  function select(opt: ClientOption) {
    setQuery(opt.label);
    onChange(opt.label);
    setOpen(false);
  }

  function clear() {
    setQuery("");
    onChange("");
    setOpen(false);
  }

  return (
    <div ref={ref} className="relative">
      <div className="relative flex items-center">
        <Search className="absolute left-3 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
        <input
          className="w-full rounded-md border border-border bg-background pl-8 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
          placeholder="Search clients..."
          value={query}
          onChange={(e) => { setQuery(e.target.value); onChange(""); setOpen(true); }}
          onFocus={() => setOpen(true)}
        />
        {query && (
          <button
            className="absolute right-2 text-muted-foreground hover:text-foreground"
            onClick={clear}
            type="button"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        )}
      </div>
      {open && (
        <div className="absolute z-50 mt-1 w-full max-h-48 overflow-y-auto rounded-md border border-border bg-popover shadow-lg">
          {loading ? (
            <div className="flex items-center justify-center py-4">
              <Loader2 className="h-4 w-4 animate-spin text-gold" />
            </div>
          ) : filtered.length === 0 ? (
            <div className="px-3 py-2 text-sm text-muted-foreground">No clients found.</div>
          ) : (
            filtered.slice(0, 50).map((opt) => (
              <button
                key={opt.id}
                type="button"
                className="w-full text-left px-3 py-2 text-sm hover:bg-muted/50 transition-colors"
                onMouseDown={(e) => { e.preventDefault(); select(opt); }}
              >
                {opt.label}
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
}

export default function Feedback() {
  const [entries, setEntries] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [activeFilter, setActiveFilter] = useState("all");
  const [showRequestForm, setShowRequestForm] = useState(false);
  const [formData, setFormData] = useState({ client: "", subject: "" });
  const [formSent, setFormSent] = useState(false);

  useEffect(() => {
    api.getFeedback().then(setEntries).catch(() => {}).finally(() => setLoading(false));
  }, []);

  const filters = ["all", "5★", "4★", "3★", "Below 3★"];

  const filtered = entries.filter((f) => {
    if (activeFilter === "all") return true;
    if (activeFilter === "5★") return f.rating === 5;
    if (activeFilter === "4★") return f.rating === 4;
    if (activeFilter === "3★") return f.rating === 3;
    if (activeFilter === "Below 3★") return f.rating < 3;
    return true;
  });

  const total = entries.length;
  const avgRating = total ? (entries.reduce((s, f) => s + f.rating, 0) / total).toFixed(1) : "0.0";

  const distribution = [5, 4, 3, 2, 1].map((star) => ({
    star,
    count: entries.filter((f) => f.rating === star).length,
    pct: total ? Math.round((entries.filter((f) => f.rating === star).length / total) * 100) : 0,
  }));

  async function handleSend() {
    if (!formData.client || !formData.subject) return;
    try {
      await api.requestFeedback(formData);
      setFormSent(true);
      setTimeout(() => {
        setFormSent(false);
        setShowRequestForm(false);
        setFormData({ client: "", subject: "" });
      }, 2000);
    } catch { /* keep form open on failure */ }
  }

  if (loading) return (
    <AppLayout>
      <Head title="Feedback & CSAT" />
      <div className="flex h-screen items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-gold" />
      </div>
    </AppLayout>
  );

  return (
    <AppLayout>
      <Head title="Feedback & CSAT" />
      <PageHeader
        eyebrow="Engagement"
        title="Feedback & CSAT"
        description="Client satisfaction surveys and scores"
        actions={
          <Button
            onClick={() => setShowRequestForm(!showRequestForm)}
            className="bg-gold text-background hover:bg-gold/90"
          >
            <Send className="mr-2 h-4 w-4" />
            Request Feedback
          </Button>
        }
      />

      <div className="px-8 py-6 space-y-6">
        {/* Request Feedback Form */}
        {showRequestForm && (
          <Card className="border-border">
            <CardHeader>
              <CardTitle className="text-base">Request Client Feedback</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="space-y-1">
                  <label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                    Select Client
                  </label>
                  <ClientCombobox
                    value={formData.client}
                    onChange={(v) => setFormData({ ...formData, client: v })}
                  />
                </div>
                <div className="space-y-1">
                  <label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                    Subject
                  </label>
                  <input
                    type="text"
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
                    placeholder="e.g. Patent Filing Q1 2026"
                    value={formData.subject}
                    onChange={(e) => setFormData({ ...formData, subject: e.target.value })}
                  />
                </div>
              </div>
              <div className="flex items-center gap-3">
                <Button
                  onClick={handleSend}
                  className="bg-gold text-background hover:bg-gold/90"
                  disabled={formSent || !formData.client || !formData.subject}
                >
                  <Send className="mr-2 h-4 w-4" />
                  {formSent ? "Sent!" : "Send Request"}
                </Button>
                <Button variant="outline" onClick={() => setShowRequestForm(false)}>
                  Cancel
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Rating Summary */}
        <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
          <Card className="border-border col-span-1">
            <CardContent className="flex flex-col items-center justify-center py-8 gap-2">
              <div className="text-6xl font-bold text-gold">{avgRating}</div>
              <StarDisplay rating={Math.round(Number(avgRating))} size="lg" />
              <p className="text-sm text-muted-foreground">{total} total reviews</p>
            </CardContent>
          </Card>

          <Card className="border-border col-span-2">
            <CardHeader>
              <CardTitle className="text-sm font-medium text-muted-foreground uppercase tracking-wide">
                Rating Distribution
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-2">
              {distribution.map(({ star, count, pct }) => (
                <div key={star} className="flex items-center gap-3 text-sm">
                  <span className="w-6 text-right text-muted-foreground">{star}★</span>
                  <div className="flex-1 h-2 rounded-full bg-muted overflow-hidden">
                    <div
                      className="h-full rounded-full bg-gold"
                      style={{ width: `${pct}%` }}
                    />
                  </div>
                  <span className="w-8 text-right text-muted-foreground">{pct}%</span>
                  <span className="w-6 text-right text-foreground font-medium">{count}</span>
                </div>
              ))}
            </CardContent>
          </Card>
        </div>

        {/* Filter Bar */}
        <div className="flex items-center gap-2 flex-wrap">
          <Filter className="h-4 w-4 text-muted-foreground" />
          {filters.map((f) => (
            <Button
              key={f}
              variant={activeFilter === f ? "default" : "outline"}
              size="sm"
              onClick={() => setActiveFilter(f)}
              className={activeFilter === f ? "bg-gold text-background hover:bg-gold/90" : ""}
            >
              {f === "all" ? "All" : f}
            </Button>
          ))}
          <span className="ml-auto text-sm text-muted-foreground">
            {filtered.length} review{filtered.length !== 1 ? "s" : ""}
          </span>
        </div>

        {/* Feedback Cards Grid */}
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          {filtered.map((fb) => (
            <Card key={fb.id} className="border-border hover:border-gold/40 transition-colors">
              <CardContent className="p-5 space-y-3">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-semibold text-foreground text-sm">{fb.client}</p>
                    <StarDisplay rating={fb.rating} />
                  </div>
                  <span
                    className={`text-[10px] font-medium px-2 py-0.5 rounded border ${CATEGORY_COLORS[fb.category]}`}
                  >
                    {fb.category}
                  </span>
                </div>
                <p className="text-sm text-muted-foreground leading-relaxed">{fb.comment}</p>
                <div className="flex items-center gap-1 text-xs text-muted-foreground">
                  <MessageSquare className="h-3 w-3" />
                  <span>{fmtDate(fb.date)}</span>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>

        {filtered.length === 0 && (
          <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
            <Star className="h-10 w-10 opacity-30" />
            <p className="text-sm">No feedback entries match this filter.</p>
          </div>
        )}
      </div>
    </AppLayout>
  );
}
