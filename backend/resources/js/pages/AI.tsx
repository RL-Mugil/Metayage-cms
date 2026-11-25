import { Head, Link } from "@inertiajs/react";
import { Fragment, useEffect, useRef, useState, type ReactNode } from "react";
import { Bot, Send, Sparkles, Loader2, Plug, Copy, Check, ShieldCheck, Wrench, RotateCcw } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api-client";
import type { AIProviders } from "@/types";

type Provider = "claude" | "groq";
type Tab = Provider | "connect";

interface Message {
  role: "user" | "assistant";
  content: string;
  tools?: string[];
  provider?: Provider;
  error?: boolean;
}

const QUICK_PROMPTS = [
  "Plan my day",
  "Payment Collection report for this month as Excel",
  "Which of my deadlines fall in the next 30 days?",
  "Show overdue cases",
  "Summarize my client portfolio",
  "List high priority tasks",
  "Outstanding invoices, oldest first",
  "Team workload summary",
  "Cases by current stage",
  "Compliance items due this month",
];

const TOOL_LABEL: Record<string, string> = {
  search_clients: "clients", get_client: "client details", search_cases: "cases", get_case: "case details",
  upcoming_deadlines: "deadlines", compliance_items: "compliance", list_tasks: "tasks", case_analytics: "case analytics",
  team_workload: "workload", finance_summary: "finance summary", outstanding_invoices: "outstanding invoices",
  list_reports: "report list", generate_report: "report file", sign_report: "signature", report_history: "saved reports",
  download_saved_report: "saved report", deadline_calendar: "calendar file", pending_payments: "pending payments", collections: "collections",
  client_statement: "client statement", unbilled_and_quoted: "unbilled work", revenue_forecast: "forecast", revenue_breakdown: "revenue breakdown",
  period_comparison: "period comparison", case_timeline: "case timeline", search_documents: "documents", portfolio_summary: "portfolio",
  dashboard_kpis: "KPIs", my_day: "my day", search_tracker: "tracker", staff_directory: "staff directory", case_discussions: "discussions", my_hr: "my HR",
};

const WELCOME: Message = {
  role: "assistant",
  content: "Hello! Ask me about your clients, cases, deadlines, compliance, tasks, billing or pending payments, or ask for any report as PDF / Excel. I can't change portal records, and I see exactly what you can see.",
};

/** Only links to this portal become clickable (e.g. report download links). */
const safeHref = (url: string) => { try { const u = new URL(url); return u.protocol === "https:" && u.host === window.location.host ? u.href : null; } catch { return null; } };

/** Inline **bold**, `code` and [links](https://…), as React nodes (no HTML injection). */
function inline(text: string): ReactNode[] {
  return text.split(/(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\(https?:\/\/[^)\s]+\)|https?:\/\/[^\s)]+)/g).filter(Boolean).map((part, i) => {
    if (part.startsWith("**") && part.endsWith("**")) return <strong key={i}>{part.slice(2, -2)}</strong>;
    if (part.startsWith("`") && part.endsWith("`")) return <code key={i} className="rounded bg-background/60 px-1 font-mono text-[12px]">{part.slice(1, -1)}</code>;
    const md = /^\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)$/.exec(part);
    const href = md ? safeHref(md[2]) : /^https?:\/\//.test(part) ? safeHref(part) : null;
    if (href) return <a key={i} href={href} className="font-medium text-gold underline underline-offset-2" rel="noopener noreferrer">{md ? md[1] : "Download"}</a>;
    return <Fragment key={i}>{md ? md[1] : part}</Fragment>;
  });
}

/** A small, safe renderer for the assistant's markdown: headings, bullets, numbered lists, pipe tables, paragraphs. */
function RichText({ text }: { text: string }) {
  const lines = text.replace(/\r/g, "").split("\n");
  const out: ReactNode[] = [];
  let i = 0;
  while (i < lines.length) {
    const line = lines[i];
    if (/^\s*\|.*\|\s*$/.test(line)) {
      const rows: string[][] = [];
      while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) {
        if (!/^\s*\|[\s:|-]+\|\s*$/.test(lines[i])) rows.push(lines[i].trim().slice(1, -1).split("|").map((c) => c.trim()));
        i++;
      }
      const [head, ...body] = rows;
      out.push(
        <div key={`t${i}`} className="my-2 overflow-x-auto rounded-lg border border-border">
          <table className="w-full text-xs">
            {head && <thead className="bg-background/50"><tr>{head.map((c, k) => <th key={k} className="px-2.5 py-1.5 text-left font-semibold whitespace-nowrap">{inline(c)}</th>)}</tr></thead>}
            <tbody>{body.map((r, ri) => <tr key={ri} className="border-t border-border">{r.map((c, k) => <td key={k} className="px-2.5 py-1.5 align-top">{inline(c)}</td>)}</tr>)}</tbody>
          </table>
        </div>,
      );
      continue;
    }
    if (/^\s*([-*•]|\d+[.)])\s+/.test(line)) {
      const ordered = /^\s*\d+[.)]/.test(line);
      const items: string[] = [];
      while (i < lines.length && /^\s*([-*•]|\d+[.)])\s+/.test(lines[i])) {
        items.push(lines[i].replace(/^\s*([-*•]|\d+[.)])\s+/, ""));
        i++;
      }
      const List = ordered ? "ol" : "ul";
      out.push(<List key={`l${i}`} className={`my-1.5 space-y-0.5 pl-5 ${ordered ? "list-decimal" : "list-disc"}`}>{items.map((it, k) => <li key={k}>{inline(it)}</li>)}</List>);
      continue;
    }
    const h = /^\s*#{1,4}\s+(.*)$/.exec(line);
    if (h) {
      out.push(<div key={`h${i}`} className="mt-2 mb-1 font-semibold">{inline(h[1])}</div>);
    } else if (line.trim() !== "") {
      out.push(<p key={`p${i}`} className="my-1">{inline(line)}</p>);
    }
    i++;
  }
  return <>{out}</>;
}

function ConnectClaude({ providers }: { providers: AIProviders | null }) {
  const [copied, setCopied] = useState(false);
  const url = providers?.mcp_url ?? `${window.location.origin}/mcp`;
  const copy = () => {
    navigator.clipboard.writeText(url).then(() => { setCopied(true); setTimeout(() => setCopied(false), 2000); }).catch(() => {});
  };
  return (
    <Card className="border-border">
      <CardContent className="space-y-5 p-6">
        <div className="flex items-start gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-gold/30 bg-gold/10"><Plug className="h-5 w-5 text-gold" /></div>
          <div>
            <h2 className="font-display text-lg font-semibold">Connect Claude to the portal</h2>
            <p className="text-sm text-muted-foreground">Use Claude (claude.ai, Claude Desktop or mobile) to read and analyse your portal data and make report files. It sees only what you can see and cannot create, edit or delete portal records.</p>
          </div>
        </div>
        {providers && !providers.mcp_enabled && (
          <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-300">
            The Claude connector is turned off. A System Admin can turn it on in Integrations → Claude connector (MCP).
          </div>
        )}
        <div>
          <div className="mb-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Connector URL</div>
          <div className="flex items-center gap-2">
            <code className="flex-1 truncate rounded-lg border border-border bg-muted/40 px-3 py-2 font-mono text-sm">{url}</code>
            <Button variant="outline" size="sm" onClick={copy}>{copied ? <Check className="h-4 w-4 text-green-600" /> : <Copy className="h-4 w-4" />}</Button>
          </div>
        </div>
        <ol className="list-decimal space-y-2 pl-5 text-sm">
          <li>In claude.ai (or Claude Desktop), open <strong>Settings → Connectors</strong> and choose <strong>Add custom connector</strong>.</li>
          <li>Name it <strong>MyIPStrategy</strong> and paste the URL above. Leave the advanced settings empty.</li>
          <li>Click <strong>Connect</strong>. A portal page opens — sign in if asked, check it says <em>read-only</em>, and press <strong>Allow</strong>.</li>
          <li>In a chat, turn the connector on from the tools menu and ask, for example, “What deadlines do I have in the next two weeks?”.</li>
        </ol>
        <div className="flex items-start gap-2 rounded-lg border border-border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
          <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-gold" />
          <span>Your access follows your role: the same clients, cases and finance you see in the portal. Every question Claude asks the portal is recorded in the audit log. You can disconnect any time in <Link href="/settings" className="underline">Settings → Profile → Connected apps</Link>.</span>
        </div>
      </CardContent>
    </Card>
  );
}

export default function AI() {
  const [providers, setProviders] = useState<AIProviders | null>(null);
  const [tab, setTab] = useState<Tab>("claude");
  const [messages, setMessages] = useState<Message[]>([WELCOME]);
  const [inputValue, setInputValue] = useState("");
  const [loading, setLoading] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    api.getAIProviders().then((p) => {
      setProviders(p);
      setTab(p.claude ? "claude" : p.groq ? "groq" : "connect");
    }).catch(() => setTab("connect"));
  }, []);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, loading]);

  const provider: Provider | null = tab === "connect" ? null : tab;
  const ready = provider !== null && !!providers?.[provider];

  async function sendQuery(query: string) {
    if (!query.trim() || loading || !provider) return;
    const history = messages.filter((m) => m !== WELCOME && !m.error).map((m) => ({ role: m.role, content: m.content })).slice(-10);
    setMessages((prev) => [...prev, { role: "user", content: query }]);
    setInputValue("");
    setLoading(true);
    try {
      const res = await api.queryAI(query, provider, history);
      setMessages((prev) => [...prev, { role: "assistant", content: res.response, tools: res.tools_used, provider: res.provider }]);
    } catch (error) {
      const message = error instanceof Error && error.message ? error.message : "Sorry, something went wrong. Please try again.";
      setMessages((prev) => [...prev, { role: "assistant", content: message, error: true }]);
    } finally {
      setLoading(false);
    }
  }

  const TABS: { id: Tab; label: string; hint?: string }[] = [
    { id: "claude", label: "Claude", hint: providers && !providers.claude ? "Add key in Integrations" : undefined },
    { id: "groq", label: "Groq (Llama)", hint: providers && !providers.groq ? "Add key in Integrations" : undefined },
    { id: "connect", label: "Connect Claude" },
  ];

  return (
    <AppLayout>
      <Head title="AI Assistant" />
      <PageHeader
        eyebrow="Knowledge"
        title="AI Assistant"
        description="Ask questions about your clients, cases, deadlines and billing — read-only, limited to what you can see"
        actions={<Badge variant="outline" className="gap-1"><ShieldCheck className="h-3 w-3 text-gold" /> Read-only</Badge>}
      />

      <div className="px-4 pt-4 md:px-8">
        <div className="flex flex-wrap gap-2">
          {TABS.map((t) => (
            <button key={t.id} type="button" onClick={() => setTab(t.id)}
              className={`rounded-full border px-3.5 py-1.5 text-sm transition-colors ${tab === t.id ? "border-gold bg-gold/15 text-foreground" : "border-border text-muted-foreground hover:border-gold/40"}`}>
              {t.id === "connect" ? <Plug className="mr-1.5 inline h-3.5 w-3.5" /> : <Sparkles className="mr-1.5 inline h-3.5 w-3.5 text-gold" />}
              {t.label}
              {t.hint && <span className="ml-1.5 text-[11px] text-muted-foreground">· {t.hint}</span>}
            </button>
          ))}
        </div>
      </div>

      {tab === "connect" ? (
        <div className="px-4 py-6 md:px-8"><ConnectClaude providers={providers} /></div>
      ) : (
        <div className="flex h-[calc(100vh-14rem)] flex-col gap-6 px-4 py-6 md:flex-row md:px-8">
          <aside className="hidden w-72 flex-shrink-0 flex-col gap-3 md:flex">
            <div className="mb-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Try asking</div>
            <div className="flex flex-col gap-2">
              {QUICK_PROMPTS.map((prompt) => (
                <button key={prompt} onClick={() => sendQuery(prompt)} disabled={loading || !ready}
                  className="rounded-lg border border-border px-3 py-2.5 text-left text-sm text-foreground transition-colors hover:border-gold/40 hover:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-50">
                  <Sparkles className="mr-2 inline h-3.5 w-3.5 text-gold" />{prompt}
                </button>
              ))}
            </div>
            {messages.length > 1 && (
              <Button variant="ghost" size="sm" className="justify-start text-muted-foreground" onClick={() => setMessages([WELCOME])}>
                <RotateCcw className="mr-2 h-3.5 w-3.5" /> New conversation
              </Button>
            )}
          </aside>

          <div className="flex min-w-0 flex-1 flex-col gap-3">
            {providers && !ready && (
              <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-300">
                {tab === "claude" ? "Claude" : "Groq"} isn't set up yet. A System Admin can add the API key on the <Link href="/integrations" className="underline">Integrations</Link> page.
              </div>
            )}
            <Card className="flex flex-1 flex-col overflow-hidden border-border">
              <CardContent className="flex-1 space-y-4 overflow-y-auto p-4">
                {messages.map((msg, idx) => (
                  <div key={idx} className={`flex ${msg.role === "user" ? "justify-end" : "justify-start"}`}>
                    {msg.role === "assistant" && (
                      <div className="mr-2 mt-1 flex-shrink-0">
                        <div className="flex h-7 w-7 items-center justify-center rounded-full border border-gold/30 bg-gold/10"><Bot className="h-4 w-4 text-gold" /></div>
                      </div>
                    )}
                    <div className={`flex max-w-[85%] flex-col gap-1 ${msg.role === "user" ? "items-end" : "items-start"}`}>
                      <div className={`rounded-2xl px-4 py-2.5 text-sm leading-relaxed ${msg.role === "user"
                        ? "rounded-br-sm bg-gold font-medium text-black"
                        : msg.error ? "rounded-bl-sm border border-red-500/30 bg-red-500/10 text-foreground" : "rounded-bl-sm bg-muted text-foreground"}`}>
                        {msg.role === "assistant" ? <RichText text={msg.content} /> : msg.content}
                      </div>
                      {msg.tools && msg.tools.length > 0 && (
                        <div className="flex items-center gap-1 text-[11px] text-muted-foreground">
                          <Wrench className="h-3 w-3" /> Looked up: {msg.tools.map((t) => TOOL_LABEL[t] ?? t).join(", ")}
                          {msg.provider && <span>· {msg.provider === "claude" ? "Claude" : "Groq"}</span>}
                        </div>
                      )}
                    </div>
                  </div>
                ))}
                {loading && (
                  <div className="flex justify-start">
                    <div className="mr-2 mt-1 flex-shrink-0">
                      <div className="flex h-7 w-7 items-center justify-center rounded-full border border-gold/30 bg-gold/10"><Bot className="h-4 w-4 text-gold" /></div>
                    </div>
                    <div className="rounded-2xl rounded-bl-sm bg-muted px-4 py-2.5"><Loader2 className="h-4 w-4 animate-spin text-gold" /></div>
                  </div>
                )}
                <div ref={bottomRef} />
              </CardContent>
            </Card>

            <div className="flex gap-2">
              <Input
                placeholder="Ask about clients, cases, deadlines, billing…"
                value={inputValue}
                onChange={(e) => setInputValue(e.target.value)}
                onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); sendQuery(inputValue); } }}
                className="flex-1"
                disabled={loading || !ready}
                maxLength={2000}
              />
              <Button onClick={() => sendQuery(inputValue)} disabled={loading || !ready || !inputValue.trim()} className="px-4">
                <Send className="h-4 w-4" />
              </Button>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
