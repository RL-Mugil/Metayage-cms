import { Head } from "@inertiajs/react";
import { useState } from "react";
import { Star, MessageSquare, Send, Filter } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { fmtDate } from "@/lib/date-utils";

const CLIENTS = [
  "Acme Corporation",
  "Tech Solutions Ltd",
  "InnovateTech Inc",
  "GlobalPatent Group",
  "BioMed Research",
  "StartupLabs",
  "Enterprise Corp",
  "FutureMark LLC",
];

const FEEDBACK_DATA: { id: number; client: string; rating: number; comment: string; date: string; category: string }[] = [];

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

export default function Feedback() {
  const [activeFilter, setActiveFilter] = useState("all");
  const [showRequestForm, setShowRequestForm] = useState(false);
  const [formData, setFormData] = useState({ client: "", subject: "" });
  const [formSent, setFormSent] = useState(false);

  const filters = ["all", "5★", "4★", "3★", "Below 3★"];

  const filtered = FEEDBACK_DATA.filter((f) => {
    if (activeFilter === "all") return true;
    if (activeFilter === "5★") return f.rating === 5;
    if (activeFilter === "4★") return f.rating === 4;
    if (activeFilter === "3★") return f.rating === 3;
    if (activeFilter === "Below 3★") return f.rating < 3;
    return true;
  });

  const total = FEEDBACK_DATA.length;
  const avgRating = (FEEDBACK_DATA.reduce((s, f) => s + f.rating, 0) / total).toFixed(1);

  const distribution = [5, 4, 3, 2, 1].map((star) => ({
    star,
    count: FEEDBACK_DATA.filter((f) => f.rating === star).length,
    pct: Math.round((FEEDBACK_DATA.filter((f) => f.rating === star).length / total) * 100),
  }));

  function handleSend() {
    if (!formData.client || !formData.subject) return;
    setFormSent(true);
    setTimeout(() => {
      setFormSent(false);
      setShowRequestForm(false);
      setFormData({ client: "", subject: "" });
    }, 2000);
  }

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
                  <select
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
                    value={formData.client}
                    onChange={(e) => setFormData({ ...formData, client: e.target.value })}
                  >
                    <option value="">-- Select a client --</option>
                    {CLIENTS.map((c) => (
                      <option key={c} value={c}>
                        {c}
                      </option>
                    ))}
                  </select>
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
                  disabled={formSent}
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
