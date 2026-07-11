import { Head, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { Star, MessageSquare, Send, Filter, Loader2, Search, X, FileText, CheckCircle, Clock } from "lucide-react";
import { api } from "@/lib/api-client";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
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

function StarInput({ value, onChange }: { value: number; onChange: (v: number) => void }) {
  const [hover, setHover] = useState(0);
  return (
    <div className="flex gap-1">
      {[1, 2, 3, 4, 5].map((i) => (
        <button
          key={i}
          type="button"
          onClick={() => onChange(i)}
          onMouseEnter={() => setHover(i)}
          onMouseLeave={() => setHover(0)}
          className={`text-3xl transition-colors ${i <= (hover || value) ? "text-gold" : "text-muted-foreground/30"}`}
        >
          ★
        </button>
      ))}
    </div>
  );
}

interface CaseOption {
  id: number;
  docket: string;
  name: string;
  client: string;
}

/** Search cases by UIN/docket, code, or name. */
function CaseCombobox({ onSelect, selected }: { onSelect: (c: CaseOption | null) => void; selected: CaseOption | null }) {
  const [query, setQuery] = useState("");
  const [options, setOptions] = useState<CaseOption[]>([]);
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handleClickOutside(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  useEffect(() => {
    const t = setTimeout(() => {
      setLoading(true);
      const params = new URLSearchParams({ per_page: "20" });
      if (query) params.set("search", query);
      api.getProjectsPaged(params)
        .then((res: any) => {
          const list: any[] = Array.isArray(res) ? res : res?.data ?? [];
          setOptions(list.map((p) => ({
            id: p.id,
            docket: p.docket_number ?? p.project_code ?? `#${p.id}`,
            name: p.project_name ?? "",
            client: p.client?.company_name ?? p.client?.legal_name ?? "—",
          })));
        })
        .catch(() => {})
        .finally(() => setLoading(false));
    }, 300);
    return () => clearTimeout(t);
  }, [query]);

  return (
    <div ref={ref} className="relative">
      <div className="relative flex items-center">
        <Search className="absolute left-3 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
        <input
          className="w-full rounded-md border border-border bg-background pl-8 pr-8 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gold/40"
          placeholder="Search case by UIN / docket number…"
          value={selected ? `${selected.docket} — ${selected.client}` : query}
          onChange={(e) => { setQuery(e.target.value); onSelect(null); setOpen(true); }}
          onFocus={() => { if (selected) { onSelect(null); setQuery(""); } setOpen(true); }}
        />
        {(selected || query) && (
          <button
            className="absolute right-2 text-muted-foreground hover:text-foreground"
            onClick={() => { onSelect(null); setQuery(""); }}
            type="button"
          >
            <X className="h-3.5 w-3.5" />
          </button>
        )}
      </div>
      {open && !selected && (
        <div className="absolute z-50 mt-1 w-full max-h-56 overflow-y-auto rounded-md border border-border bg-popover shadow-lg">
          {loading ? (
            <div className="flex items-center justify-center py-4">
              <Loader2 className="h-4 w-4 animate-spin text-gold" />
            </div>
          ) : options.length === 0 ? (
            <div className="px-3 py-2 text-sm text-muted-foreground">No cases found.</div>
          ) : (
            options.map((opt) => (
              <button
                key={opt.id}
                type="button"
                className="w-full text-left px-3 py-2 text-sm hover:bg-muted/50 transition-colors"
                onMouseDown={(e) => { e.preventDefault(); onSelect(opt); setOpen(false); }}
              >
                <span className="font-mono font-medium">{opt.docket}</span>
                <span className="text-muted-foreground"> · {opt.client}</span>
                {opt.name && <div className="text-xs text-muted-foreground truncate">{opt.name}</div>}
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
}

interface FeedbackRequestRow {
  id: number;
  docket_number: string | null;
  subject: string | null;
  client: string | null;
  requester: string | null;
  status: "Pending" | "Completed";
  rating: number | null;
  comment: string | null;
  requested_at: string | null;
  completed_at: string | null;
  can_rate: boolean;
}

export default function Feedback() {
  const { props: pageProps } = usePage() as any;
  const role: string = pageProps.auth?.user?.role ?? "";
  const isClientUser = ["client", "client_admin"].includes(role);
  const isClientAdmin = role === "client_admin";
  // Any internal staff member can request feedback on a case they work on.
  const canRequest = !isClientUser;

  const [entries, setEntries] = useState<any[]>([]);
  const [requests, setRequests] = useState<FeedbackRequestRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [activeFilter, setActiveFilter] = useState("all");

  // Request form (firm side)
  const [showRequestForm, setShowRequestForm] = useState(false);
  const [selectedCase, setSelectedCase] = useState<CaseOption | null>(null);
  const [formSent, setFormSent] = useState(false);
  const [sendError, setSendError] = useState("");
  const [sending, setSending] = useState(false);

  // Rate modal (client_admin)
  const [rateTarget, setRateTarget] = useState<FeedbackRequestRow | null>(null);
  const [rateValue, setRateValue] = useState(0);
  const [rateComment, setRateComment] = useState("");
  const [rating, setRating] = useState(false);
  const [rateError, setRateError] = useState("");

  const loadAll = () => {
    const jobs: Promise<any>[] = [api.getFeedbackRequests().then(setRequests).catch(() => {})];
    if (!isClientUser) {
      jobs.push(api.getFeedback().then(setEntries).catch(() => {}));
    }
    Promise.all(jobs).finally(() => setLoading(false));
  };

  useEffect(loadAll, []);

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
    if (!selectedCase) return;
    setSending(true);
    setSendError("");
    try {
      await api.requestFeedback({ project_id: selectedCase.id });
      setFormSent(true);
      loadAll();
      setTimeout(() => {
        setFormSent(false);
        setShowRequestForm(false);
        setSelectedCase(null);
      }, 2000);
    } catch (e: any) {
      setSendError(e?.message || "Failed to send request.");
    } finally {
      setSending(false);
    }
  }

  async function submitRating() {
    if (!rateTarget || rateValue < 1) return;
    setRating(true);
    setRateError("");
    try {
      await api.rateFeedbackRequest(rateTarget.id, { rating: rateValue, comment: rateComment.trim() || undefined });
      setRateTarget(null);
      setRateValue(0);
      setRateComment("");
      loadAll();
    } catch (e: any) {
      setRateError(e?.message || "Failed to submit rating.");
    } finally {
      setRating(false);
    }
  }

  const pendingRequests = requests.filter((r) => r.status === "Pending");
  const completedRequests = requests.filter((r) => r.status === "Completed");

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
        description={isClientUser
          ? "Rate your case experience when your legal team requests feedback"
          : "Case feedback requests and client satisfaction scores"}
        actions={canRequest ? (
          <Button
            onClick={() => { setShowRequestForm(!showRequestForm); setSendError(""); }}
            className="bg-gold text-background hover:bg-gold/90"
          >
            <Send className="mr-2 h-4 w-4" />
            Request Feedback
          </Button>
        ) : undefined}
      />

      {/* Rate modal (client_admin) */}
      {rateTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
            <div className="flex items-center justify-between mb-1">
              <h2 className="font-display text-lg font-semibold">Rate Case Experience</h2>
              <button onClick={() => setRateTarget(null)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>
            <p className="text-sm text-muted-foreground mb-4">
              Case <span className="font-mono font-medium text-foreground">{rateTarget.docket_number}</span>
              {rateTarget.requester && <> · requested by {rateTarget.requester}</>}
            </p>
            {rateError && <p className="text-xs text-red-500 mb-2">{rateError}</p>}
            <div className="flex justify-center mb-4">
              <StarInput value={rateValue} onChange={setRateValue} />
            </div>
            <textarea
              rows={3}
              placeholder="Tell us about your experience (optional)…"
              value={rateComment}
              onChange={(e) => setRateComment(e.target.value)}
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none mb-4"
            />
            <div className="flex gap-2">
              <Button className="bg-gold hover:bg-gold/90 text-black flex-1" disabled={rateValue < 1 || rating} onClick={submitRating}>
                {rating ? <Loader2 className="h-4 w-4 animate-spin" /> : "Submit Rating"}
              </Button>
              <Button variant="outline" onClick={() => setRateTarget(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      <div className="px-8 py-6 space-y-6">
        {error && (
          <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>
        )}

        {/* Request Feedback form (firm) */}
        {showRequestForm && canRequest && (
          <Card className="border-border">
            <CardHeader>
              <CardTitle className="text-base">Request Case Feedback</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {sendError && <p className="text-xs text-red-500">{sendError}</p>}
              <div className="space-y-1 max-w-lg">
                <label className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
                  Case (UIN / Docket)
                </label>
                <CaseCombobox selected={selectedCase} onSelect={setSelectedCase} />
                {selectedCase && (
                  <p className="text-xs text-muted-foreground mt-1">
                    Request will be sent to <span className="text-foreground font-medium">{selectedCase.client}</span>'s portal — their admin rates the experience.
                  </p>
                )}
              </div>
              <div className="flex items-center gap-3">
                <Button
                  onClick={handleSend}
                  className="bg-gold text-background hover:bg-gold/90"
                  disabled={formSent || !selectedCase || sending}
                >
                  {sending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
                  {formSent ? "Sent!" : "Send Request"}
                </Button>
                <Button variant="outline" onClick={() => setShowRequestForm(false)}>Cancel</Button>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Pending requests — client side gets rate buttons */}
        {pendingRequests.length > 0 && (
          <Card className="border-border">
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <Clock className="h-4 w-4 text-amber-500" />
                {isClientUser ? "Awaiting Your Feedback" : "Pending with Clients"} ({pendingRequests.length})
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-2.5 text-left">Case UIN</th>
                    {!isClientUser && <th className="px-4 py-2.5 text-left">Client</th>}
                    <th className="px-4 py-2.5 text-left">Requested By</th>
                    <th className="px-4 py-2.5 text-left">Date</th>
                    <th className="px-4 py-2.5 text-left">{isClientUser ? "Action" : "Status"}</th>
                  </tr>
                </thead>
                <tbody>
                  {pendingRequests.map((r) => (
                    <tr key={r.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-4 py-3">
                        <span className="flex items-center gap-1.5 font-mono font-medium">
                          <FileText className="h-3.5 w-3.5 text-muted-foreground" />
                          {r.docket_number ?? "—"}
                        </span>
                      </td>
                      {!isClientUser && <td className="px-4 py-3">{r.client ?? "—"}</td>}
                      <td className="px-4 py-3 text-muted-foreground">{r.requester ?? "—"}</td>
                      <td className="px-4 py-3 text-muted-foreground text-xs">{r.requested_at ?? "—"}</td>
                      <td className="px-4 py-3">
                        {r.can_rate ? (
                          <Button size="sm" className="h-7 text-xs bg-gold hover:bg-gold/90 text-black"
                            onClick={() => { setRateTarget(r); setRateValue(0); setRateComment(""); setRateError(""); }}>
                            <Star className="h-3 w-3 mr-1" /> Rate Now
                          </Button>
                        ) : (
                          <span className="text-xs text-muted-foreground">
                            {isClientUser && !isClientAdmin ? "Your portal admin will rate" : "Awaiting client"}
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}

        {/* Completed requests */}
        {completedRequests.length > 0 && (
          <Card className="border-border">
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-medium flex items-center gap-2">
                <CheckCircle className="h-4 w-4 text-green-500" /> Rated Cases ({completedRequests.length})
              </CardTitle>
            </CardHeader>
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-2.5 text-left">Case UIN</th>
                    {!isClientUser && <th className="px-4 py-2.5 text-left">Client</th>}
                    <th className="px-4 py-2.5 text-left">Rating</th>
                    <th className="px-4 py-2.5 text-left">Comment</th>
                    <th className="px-4 py-2.5 text-left">Rated On</th>
                  </tr>
                </thead>
                <tbody>
                  {completedRequests.map((r) => (
                    <tr key={r.id} className="border-t border-border hover:bg-muted/20">
                      <td className="px-4 py-3 font-mono font-medium">{r.docket_number ?? "—"}</td>
                      {!isClientUser && <td className="px-4 py-3">{r.client ?? "—"}</td>}
                      <td className="px-4 py-3"><StarDisplay rating={r.rating ?? 0} /></td>
                      <td className="px-4 py-3 text-muted-foreground text-xs max-w-[280px] truncate" title={r.comment ?? ""}>
                        {r.comment || "—"}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground text-xs">{r.completed_at ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}

        {isClientUser && requests.length === 0 && (
          <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
            <Star className="h-10 w-10 opacity-30" />
            <p className="text-sm">No feedback requests yet. Your legal team will send one after a case concludes.</p>
          </div>
        )}

        {/* CSAT aggregates — firm side only */}
        {!isClientUser && (
          <>
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
                        <div className="h-full rounded-full bg-gold" style={{ width: `${pct}%` }} />
                      </div>
                      <span className="w-8 text-right text-muted-foreground">{pct}%</span>
                      <span className="w-6 text-right text-foreground font-medium">{count}</span>
                    </div>
                  ))}
                </CardContent>
              </Card>
            </div>

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

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {filtered.map((fb) => (
                <Card key={fb.id} className="border-border hover:border-gold/40 transition-colors">
                  <CardContent className="p-5 space-y-3">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <p className="font-semibold text-foreground text-sm">{fb.client}</p>
                        <StarDisplay rating={fb.rating} />
                      </div>
                      <span className={`text-[10px] font-medium px-2 py-0.5 rounded border ${CATEGORY_COLORS[fb.category]}`}>
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
          </>
        )}
      </div>
    </AppLayout>
  );
}
