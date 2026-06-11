import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { useState, useEffect, useRef } from "react";
import { api } from "@/lib/api-client";
import { Sparkles, Send, Loader2, Bot, User, Database, ChevronDown, ChevronUp } from "lucide-react";

export const Route = createFileRoute("/ai")({
  head: () => ({ meta: [{ title: "AI Assistant — IPFlow" }] }),
  component: Page,
});

interface Message {
  id: string;
  sender: "user" | "ai";
  text: string;
  sql?: string;
  results?: any[];
}

function Page() {
  const [messages, setMessages] = useState<Message[]>([
    {
      id: "init",
      sender: "ai",
      text: "Hello! I am your IPFlow AI Assistant. I can help you query database tables, summarize matters, search clients, or draft communications. Ask me anything!",
    },
  ]);
  const [input, setInput] = useState("");
  const [loading, setLoading] = useState(false);
  const [showSqlId, setShowSqlId] = useState<string | null>(null);
  
  const chatEndRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    chatEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages]);

  const handleSend = async (queryText: string) => {
    if (!queryText.trim() || loading) return;

    const userMsgId = Date.now().toString();
    const userMsg: Message = {
      id: userMsgId,
      sender: "user",
      text: queryText,
    };

    setMessages((prev) => [...prev, userMsg]);
    setInput("");
    setLoading(true);

    try {
      const result = await api.queryAI(queryText);
      const aiMsg: Message = {
        id: (Date.now() + 1).toString(),
        sender: "ai",
        text: result.response,
        sql: result.sql_query || undefined,
        results: result.results || undefined,
      };
      setMessages((prev) => [...prev, aiMsg]);
    } catch (err: any) {
      const errMsg: Message = {
        id: (Date.now() + 1).toString(),
        sender: "ai",
        text: `Error connecting to AI service: ${err.message || "Unknown error"}. Make sure the FastAPI sidecar is running at port 8001.`,
      };
      setMessages((prev) => [...prev, errMsg]);
    } finally {
      setLoading(false);
    }
  };

  const handleSuggestion = (s: string) => {
    setInput(s);
    handleSend(s);
  };

  return (
    <div className="flex flex-col h-[calc(100vh-3.5rem)]">
      <PageHeader eyebrow="Module 16" title="AI Assistant" description="Smart natural language search & database queries via FastAPI sidecar. Not legal advice." />
      
      <div className="flex-1 px-8 py-6 grid gap-6 lg:grid-cols-4 min-h-0 overflow-hidden">
        {/* Chat Workspace */}
        <Card className="lg:col-span-3 flex flex-col h-full border-border bg-zinc-950/20 backdrop-blur">
          <CardHeader className="border-b border-border py-4">
            <CardTitle className="font-display flex items-center gap-2 text-lg">
              <Bot className="h-5 w-5 text-gold" /> Ask IPFlow Workspace
            </CardTitle>
          </CardHeader>
          
          <CardContent className="flex-1 overflow-y-auto p-6 space-y-4 min-h-0">
            {messages.map((m) => (
              <div key={m.id} className={`flex gap-3 max-w-3xl ${m.sender === "user" ? "ml-auto flex-row-reverse" : "mr-auto"}`}>
                <div className={`h-8 w-8 rounded-lg flex items-center justify-center text-xs font-semibold shrink-0 ${
                  m.sender === "user" ? "bg-primary text-primary-foreground" : "bg-zinc-800 text-zinc-300"
                }`}>
                  {m.sender === "user" ? <User className="h-4 w-4" /> : <Bot className="h-4 w-4" />}
                </div>

                <div className={`space-y-2 rounded-xl px-4 py-3 text-sm ${
                  m.sender === "user" ? "bg-primary/10 border border-primary/20 text-foreground" : "bg-zinc-900 border border-zinc-800 text-zinc-300"
                }`}>
                  <p className="whitespace-pre-wrap leading-relaxed">{m.text}</p>
                  
                  {/* SQL details dropdown */}
                  {m.sql && (
                    <div className="mt-2 border-t border-zinc-800 pt-2 space-y-1">
                      <button 
                        onClick={() => setShowSqlId(showSqlId === m.id ? null : m.id)}
                        className="flex items-center gap-1.5 text-xs font-mono text-zinc-500 hover:text-zinc-300"
                      >
                        <Database className="h-3 w-3" /> 
                        {showSqlId === m.id ? "Hide SQL" : "Show Generated SQL"}
                        {showSqlId === m.id ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
                      </button>
                      {showSqlId === m.id && (
                        <pre className="text-[11px] bg-zinc-950 p-2.5 rounded border border-zinc-800 overflow-x-auto text-amber-500/90 font-mono">
                          {m.sql}
                        </pre>
                      )}
                    </div>
                  )}

                  {/* SQL execution results */}
                  {m.results && m.results.length > 0 && (
                    <div className="mt-2 border-t border-zinc-800 pt-2">
                      <div className="text-xs font-semibold text-zinc-400 mb-1">Database Matches:</div>
                      <div className="overflow-x-auto border border-zinc-800 rounded">
                        <table className="w-full text-left text-[11px]">
                          <thead className="bg-zinc-950 text-zinc-400 uppercase font-mono">
                            <tr>
                              {Object.keys(m.results[0]).map((k) => (
                                <th key={k} className="px-2 py-1">{k}</th>
                              ))}
                            </tr>
                          </thead>
                          <tbody>
                            {m.results.map((row, idx) => (
                              <tr key={idx} className="border-t border-zinc-800 hover:bg-zinc-950/40">
                                {Object.values(row).map((val: any, vIdx) => (
                                  <td key={vIdx} className="px-2 py-1 truncate max-w-[120px]">
                                    {val === null ? "null" : val.toString()}
                                  </td>
                                ))}
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}
                </div>
              </div>
            ))}

            {loading && (
              <div className="flex gap-3 max-w-3xl mr-auto">
                <div className="h-8 w-8 rounded-lg bg-zinc-800 text-zinc-300 flex items-center justify-center text-xs shrink-0">
                  <Bot className="h-4 w-4" />
                </div>
                <div className="bg-zinc-900 border border-zinc-800 rounded-xl px-4 py-3 flex items-center gap-2 text-sm text-zinc-400">
                  <Loader2 className="h-4 w-4 animate-spin text-gold" />
                  Generating legal/analytical context...
                </div>
              </div>
            )}
            
            <div ref={chatEndRef} />
          </CardContent>

          {/* Quick suggestions footer */}
          <div className="px-6 py-2 border-t border-border bg-zinc-950/40">
            <div className="flex gap-2 overflow-x-auto py-1 scrollbar-none">
              {[
                "List all active matters",
                "Who is the manager for Helios?",
                "Show unpaid invoices",
                "Draft client status note",
              ].map((s) => (
                <button
                  key={s}
                  onClick={() => handleSuggestion(s)}
                  disabled={loading}
                  className="shrink-0 text-xs px-2.5 py-1.5 rounded-full border border-border bg-zinc-900 hover:border-gold/60 text-zinc-400 hover:text-white transition-colors"
                >
                  {s}
                </button>
              ))}
            </div>
          </div>

          {/* Text Input area */}
          <div className="p-4 border-t border-border bg-zinc-950/60">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                handleSend(input);
              }}
              className="flex gap-2"
            >
              <Input
                placeholder="Ask about clients, deadlines, invoices, or request patent drafting..."
                value={input}
                onChange={(e) => setInput(e.target.value)}
                disabled={loading}
                className="h-11 bg-zinc-900/60 border-zinc-800 text-white"
              />
              <Button type="submit" disabled={loading} className="bg-gradient-to-r from-primary to-gold text-primary-foreground h-11 px-5">
                <Send className="h-4 w-4" />
              </Button>
            </form>
          </div>
        </Card>

        {/* Sidebar Info Panel */}
        <div className="space-y-4">
          <Card className="border-border">
            <CardHeader>
              <CardTitle className="font-display text-base">Out of scope</CardTitle>
            </CardHeader>
            <CardContent className="text-xs text-muted-foreground space-y-2 leading-relaxed">
              <p>Will not perform legal analysis or make final validation determinations.</p>
              <p>All answers are derived strictly from your active client databases and legal documents.</p>
            </CardContent>
          </Card>

          <Card className="border-border bg-gradient-to-br from-gold/5 to-transparent">
            <CardHeader>
              <CardTitle className="font-display text-base flex items-center gap-1.5">
                <Sparkles className="h-4 w-4 text-gold" /> Supported Prompts
              </CardTitle>
            </CardHeader>
            <CardContent className="text-xs space-y-2 text-zinc-400">
              <p>• "Find all patent matters due in the next 30 days"</p>
              <p>• "Summarize invoices for Quantix Semiconductors"</p>
              <p>• "Who is the primary contact for Aurelia Foods?"</p>
              <p>• "Draft an office action reply about prior art search"</p>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
