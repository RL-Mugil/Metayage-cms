import { Head } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { Bot, Send, Sparkles, Code2, Table2, Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api-client";

interface Message {
  role: "user" | "assistant";
  content: string;
  sql_query?: string;
  results?: any[];
}

const QUICK_PROMPTS = [
  "Show overdue matters",
  "Summarize client portfolio",
  "List high priority tasks",
  "Revenue this month",
  "Upcoming IP deadlines",
  "Team workload summary",
  "Clients with active patents",
  "Projects ending this quarter",
];

const WELCOME: Message = {
  role: "assistant",
  content:
    "Hello! I'm your IP Assistant. Ask me anything about your matters, clients, tasks, or financials.",
};

export default function AI() {
  const [messages, setMessages] = useState<Message[]>([WELCOME]);
  const [inputValue, setInputValue] = useState("");
  const [loading, setLoading] = useState(false);
  const bottomRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages, loading]);

  async function sendQuery(query: string) {
    if (!query.trim() || loading) return;
    const userMessage: Message = { role: "user", content: query };
    setMessages((prev) => [...prev, userMessage]);
    setInputValue("");
    setLoading(true);
    try {
      const res = await api.queryAI(query);
      const assistantMessage: Message = {
        role: "assistant",
        content: res.response,
        sql_query: res.sql_query ?? undefined,
        results: res.results,
      };
      setMessages((prev) => [...prev, assistantMessage]);
    } catch (error) {
      const message = error instanceof Error && error.message
        ? error.message
        : "Sorry, I encountered an error processing your request. Please try again.";
      setMessages((prev) => [
        ...prev,
        { role: "assistant", content: message },
      ]);
    } finally {
      setLoading(false);
    }
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      sendQuery(inputValue);
    }
  }

  return (
    <AppLayout>
      <Head title="AI Assistant" />
      <PageHeader
        eyebrow="Knowledge"
        title="AI Assistant"
        description="Natural language queries over your matter data — powered by your live database"
        actions={
          <Badge variant="outline" className="gap-1">
            <Sparkles className="h-3 w-3 text-gold" /> IP Intelligence
          </Badge>
        }
      />

      <div className="px-8 py-6 flex gap-6 h-[calc(100vh-11rem)]">
        {/* Left sidebar — Quick Prompts */}
        <aside className="w-72 flex-shrink-0 flex flex-col gap-3">
          <div className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1">
            Quick Prompts
          </div>
          <div className="flex flex-col gap-2">
            {QUICK_PROMPTS.map((prompt) => (
              <button
                key={prompt}
                onClick={() => sendQuery(prompt)}
                disabled={loading}
                className="text-left rounded-lg border border-border px-3 py-2.5 text-sm text-foreground hover:bg-muted/50 hover:border-gold/40 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <Sparkles className="inline h-3.5 w-3.5 text-gold mr-2" />
                {prompt}
              </button>
            ))}
          </div>
        </aside>

        {/* Main chat panel */}
        <div className="flex-1 min-w-0 flex flex-col gap-3">
          {/* Messages area */}
          <Card className="flex-1 border-border overflow-hidden flex flex-col">
            <CardContent className="flex-1 overflow-y-auto p-4 space-y-4">
              {messages.map((msg, idx) => (
                <div
                  key={idx}
                  className={`flex ${msg.role === "user" ? "justify-end" : "justify-start"}`}
                >
                  {msg.role === "assistant" && (
                    <div className="flex-shrink-0 mr-2 mt-1">
                      <div className="h-7 w-7 rounded-full bg-gold/10 border border-gold/30 flex items-center justify-center">
                        <Bot className="h-4 w-4 text-gold" />
                      </div>
                    </div>
                  )}
                  <div className={`flex flex-col gap-2 max-w-[75%] ${msg.role === "user" ? "items-end" : "items-start"}`}>
                    <div
                      className={`rounded-2xl px-4 py-2.5 text-sm leading-relaxed ${
                        msg.role === "user"
                          ? "bg-gold text-black font-medium rounded-br-sm"
                          : "bg-muted text-foreground rounded-bl-sm"
                      }`}
                    >
                      {msg.content}
                    </div>

                    {/* SQL query block */}
                    {msg.sql_query && (
                      <div className="w-full rounded-lg border border-border bg-[#1a1a1a] overflow-hidden">
                        <div className="flex items-center gap-2 px-3 py-1.5 border-b border-border/60">
                          <Code2 className="h-3.5 w-3.5 text-muted-foreground" />
                          <span className="text-xs text-muted-foreground font-mono">SQL Query</span>
                        </div>
                        <pre className="p-3 text-xs font-mono text-green-400 overflow-x-auto whitespace-pre-wrap">
                          {msg.sql_query}
                        </pre>
                      </div>
                    )}

                    {/* Results mini-table */}
                    {msg.results && msg.results.length > 0 && (
                      <div className="w-full rounded-lg border border-border overflow-hidden">
                        <div className="flex items-center gap-2 px-3 py-1.5 border-b border-border bg-muted/40">
                          <Table2 className="h-3.5 w-3.5 text-muted-foreground" />
                          <span className="text-xs text-muted-foreground">
                            {msg.results.length} row{msg.results.length !== 1 ? "s" : ""}
                          </span>
                        </div>
                        <div className="overflow-x-auto">
                          <table className="w-full text-xs">
                            <thead className="bg-muted/40">
                              <tr>
                                {Object.keys(msg.results[0]).map((col) => (
                                  <th key={col} className="px-3 py-1.5 text-left font-semibold text-muted-foreground uppercase tracking-wider whitespace-nowrap">
                                    {col}
                                  </th>
                                ))}
                              </tr>
                            </thead>
                            <tbody>
                              {msg.results.slice(0, 10).map((row, ri) => (
                                <tr key={ri} className="border-t border-border hover:bg-muted/30">
                                  {Object.values(row).map((val: any, ci) => (
                                    <td key={ci} className="px-3 py-1.5 text-foreground whitespace-nowrap">
                                      {val === null || val === undefined ? (
                                        <span className="text-muted-foreground">—</span>
                                      ) : String(val)}
                                    </td>
                                  ))}
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                        {msg.results.length > 10 && (
                          <div className="px-3 py-1.5 border-t border-border text-xs text-muted-foreground">
                            Showing 10 of {msg.results.length} rows
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                </div>
              ))}

              {loading && (
                <div className="flex justify-start">
                  <div className="flex-shrink-0 mr-2 mt-1">
                    <div className="h-7 w-7 rounded-full bg-gold/10 border border-gold/30 flex items-center justify-center">
                      <Bot className="h-4 w-4 text-gold" />
                    </div>
                  </div>
                  <div className="rounded-2xl rounded-bl-sm bg-muted px-4 py-2.5">
                    <Loader2 className="h-4 w-4 animate-spin text-gold" />
                  </div>
                </div>
              )}

              <div ref={bottomRef} />
            </CardContent>
          </Card>

          {/* Input area */}
          <div className="flex gap-2">
            <Input
              placeholder="Ask about matters, clients, deadlines, financials…"
              value={inputValue}
              onChange={(e) => setInputValue(e.target.value)}
              onKeyDown={handleKeyDown}
              className="flex-1"
              disabled={loading}
            />
            <Button
              onClick={() => sendQuery(inputValue)}
              disabled={loading || !inputValue.trim()}
              className="px-4"
            >
              <Send className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
