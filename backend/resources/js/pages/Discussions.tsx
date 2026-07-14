import { Head, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { MessageSquare, Plus, Send, Loader2, Pencil, Trash2, Check, X } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

type Tag = "General" | "Project" | "HR" | "Finance";

interface Message {
  id: number;
  author: string;
  author_avatar?: string | null;
  time: string;
  text: string;
}

interface Thread {
  id: number;
  title: string;
  author: string;
  last_reply: string;
  tag: Tag;
  client_id?: number | null;
  client_name?: string | null;
  messages: Message[];
}

function getInitials(name: string) {
  return name.split(" ").map((n) => n[0]).join("").toUpperCase().slice(0, 2);
}

function Avatar({ name, src, size = "md" }: { name: string; src?: string | null; size?: "sm" | "md" }) {
  const dim = size === "sm" ? "h-6 w-6 text-[9px]" : "h-8 w-8 text-xs";
  if (src) return <img src={src} alt={name} className={`${dim} rounded-full object-cover ring-1 ring-gold/30 flex-shrink-0 mt-0.5`} />;
  return (
    <div className={`${dim} rounded-full bg-gold flex items-center justify-center text-background font-semibold flex-shrink-0 mt-0.5`}>
      {getInitials(name || "—")}
    </div>
  );
}

const TAG_COLORS: Record<Tag, string> = {
  General: "bg-blue-500/10 text-blue-600 border-blue-500/30",
  Project: "bg-purple-500/10 text-purple-600 border-purple-500/30",
  HR:      "bg-green-500/10 text-green-600 border-green-500/30",
  Finance: "bg-amber-500/10 text-amber-600 border-amber-500/30",
};

const ALL_TAGS: Array<"All" | Tag>    = ["All", "General", "Project", "HR", "Finance"];
const CLIENT_TAGS: Array<"All" | Tag> = ["All", "General", "Project"];

export default function Discussions() {
  const { props } = usePage() as any;
  const myName    = props.auth?.user?.name || "You";
  const myAvatar  = props.auth?.user?.avatar_url ?? null;
  const role: string      = props.auth?.user?.role ?? "";
  const isClientUser      = ["client", "client_admin"].includes(role);
  const isAdminRole       = ["super_admin", "partner"].includes(role);
  const tagOptions: Tag[] = isClientUser ? ["General", "Project"] : ["General", "Project", "HR", "Finance"];

  const [clients, setClients]               = useState<any[]>([]);
  const [newClientId, setNewClientId]       = useState<string>("");
  const [threads, setThreads]               = useState<Thread[]>([]);
  const [loading, setLoading]               = useState(true);
  const [error, setError]                   = useState("");
  const [selectedThreadId, setSelectedThreadId] = useState<number | null>(null);
  const [tagFilter, setTagFilter]           = useState<"All" | Tag>("All");
  const [replyText, setReplyText]           = useState("");
  const [sending, setSending]               = useState(false);
  const [showNewForm, setShowNewForm]       = useState(false);
  const [newTitle, setNewTitle]             = useState("");
  const [newTag, setNewTag]                 = useState<Tag>("General");
  const [newMessage, setNewMessage]         = useState("");
  const [editingThreadId, setEditingThreadId] = useState<number | null>(null);
  const [editTitle, setEditTitle]           = useState("");
  const [editTag, setEditTag]               = useState<Tag>("General");
  const [editSaving, setEditSaving]         = useState(false);
  const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null);
  const [deleting, setDeleting]             = useState(false);
  // Tracks which threads have been opened this session (for unread dot)
  const [seenIds, setSeenIds]               = useState<Set<number>>(new Set());
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const pollRef        = useRef<ReturnType<typeof setInterval> | null>(null);

  // ── Load threads once ──────────────────────────────────────────────────────
  const loadThreads = () =>
    api.getDiscussions()
      .then((data) => {
        setThreads(data as unknown as Thread[]);
        setLoading(false);
      })
      .catch((e) => { setError(e.message || "Failed to load discussions."); setLoading(false); });

  useEffect(() => {
    loadThreads();
    if (!isClientUser) {
      api.getClients(new URLSearchParams({ per_page: "2000" }))
        .then((res: any) => setClients(Array.isArray(res) ? res : res?.data ?? []))
        .catch(() => {});
    }
  }, []);

  // ── Poll messages for selected thread every 8s ─────────────────────────────
  useEffect(() => {
    if (pollRef.current) clearInterval(pollRef.current);
    if (!selectedThreadId) return;
    pollRef.current = setInterval(() => {
      api.getDiscussions()
        .then((data) => {
          const fresh = (data as unknown as Thread[]).find((t) => t.id === selectedThreadId);
          if (fresh) {
            setThreads((prev) =>
              prev.map((t) => t.id === selectedThreadId ? { ...t, messages: fresh.messages, last_reply: fresh.last_reply } : t)
            );
          }
        })
        .catch(() => {});
    }, 8000);
    return () => { if (pollRef.current) clearInterval(pollRef.current); };
  }, [selectedThreadId]);

  // Auto-scroll to bottom when messages change
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [threads.find((t) => t.id === selectedThreadId)?.messages.length]);

  const filteredThreads = tagFilter === "All" ? threads : threads.filter((t) => t.tag === tagFilter);
  const selectedThread  = threads.find((t) => t.id === selectedThreadId) ?? null;

  function openThread(id: number) {
    setSelectedThreadId(id);
    setSeenIds((s) => new Set([...s, id]));
    setConfirmDeleteId(null);
    setEditingThreadId(null);
  }

  async function handleSendReply() {
    if (!replyText.trim() || !selectedThreadId || sending) return;
    setSending(true);
    setError("");
    try {
      const msg = await api.replyDiscussion(selectedThreadId, replyText.trim()) as unknown as Message;
      setThreads((prev) =>
        prev.map((t) =>
          t.id !== selectedThreadId ? t
            : { ...t, last_reply: "Just now", messages: [...t.messages, msg] }
        )
      );
      setReplyText("");
    } catch (e: any) {
      setError(e.message || "Failed to send reply.");
    } finally {
      setSending(false);
    }
  }

  async function handleCreateThread() {
    if (!newTitle.trim() || !newMessage.trim() || sending) return;
    setSending(true);
    setError("");
    try {
      const created = await api.createDiscussion({
        title:     newTitle.trim(),
        tag:       newTag,
        message:   newMessage.trim(),
        client_id: !isClientUser && newClientId ? Number(newClientId) : undefined,
      }) as any;
      const thread: Thread = {
        id:          created.id,
        title:       created.title,
        tag:         (created.tag || "General") as Tag,
        client_id:   created.client_id ?? null,
        client_name: created.client_id ? (clients.find((c: any) => c.id === created.client_id)?.company_name ?? null) : null,
        author:      myName,
        last_reply:  "Just now",
        messages:    (created.messages || []).map((m: any) => ({
          id:     m.id,
          author: m.author?.name || myName,
          time:   "Just now",
          text:   m.content,
        })),
      };
      setThreads((prev) => [thread, ...prev]);
      openThread(thread.id);
      setNewTitle(""); setNewTag("General"); setNewMessage(""); setNewClientId(""); setShowNewForm(false);
    } catch (e: any) {
      setError(e.message || "Failed to create discussion.");
    } finally {
      setSending(false);
    }
  }

  function startEdit(thread: Thread) { setEditingThreadId(thread.id); setEditTitle(thread.title); setEditTag(thread.tag); setConfirmDeleteId(null); }
  function cancelEdit() { setEditingThreadId(null); }

  async function saveEdit(threadId: number) {
    if (!editTitle.trim()) return;
    setEditSaving(true);
    try {
      await api.updateDiscussion(threadId, { title: editTitle.trim(), tag: editTag });
      setThreads((prev) => prev.map((t) => t.id === threadId ? { ...t, title: editTitle.trim(), tag: editTag } : t));
      setEditingThreadId(null);
    } catch (e: any) {
      setError(e.message || "Failed to update discussion.");
    } finally {
      setEditSaving(false); setError("");
    }
  }

  async function handleDelete(threadId: number) {
    setDeleting(true);
    try {
      await api.deleteDiscussion(threadId);
      setThreads((prev) => prev.filter((t) => t.id !== threadId));
      if (selectedThreadId === threadId) setSelectedThreadId(null);
      setConfirmDeleteId(null);
    } catch (e: any) {
      setError(e.message || "Failed to delete discussion.");
    } finally {
      setDeleting(false); setError("");
    }
  }

  return (
    <AppLayout>
      <Head title="Discussions" />
      <PageHeader
        eyebrow="Engagement"
        title="Discussions"
        description="Team conversations — like a chat room, per topic."
        actions={
          !isClientUser ? (
            <Button onClick={() => setShowNewForm((v) => !v)}>
              <Plus className="h-4 w-4 mr-2" />New Discussion
            </Button>
          ) : undefined
        }
      />

      <div className="px-8 py-6">
        {error && (
          <div className="mb-3 rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>
        )}
        <div className="flex h-[calc(100vh-220px)] gap-0 rounded-lg border border-border overflow-hidden bg-card">

          {/* ── Left panel — thread list ──────────────────────────────────── */}
          <div className="w-72 flex-shrink-0 flex flex-col border-r border-border">
            {/* Tag filters */}
            <div className="flex flex-wrap gap-1 p-3 border-b border-border bg-muted/20">
              {(isClientUser ? CLIENT_TAGS : ALL_TAGS).map((tag) => (
                <button key={tag} onClick={() => setTagFilter(tag)}
                  className={`px-2.5 py-1 rounded-full text-xs font-medium transition-colors ${tagFilter === tag ? "bg-gold text-background" : "bg-muted text-muted-foreground hover:bg-muted/70"}`}>
                  {tag}
                </button>
              ))}
            </div>

            <div className="flex-1 overflow-y-auto">
              {loading ? (
                <div className="flex items-center justify-center py-10">
                  <Loader2 className="h-6 w-6 animate-spin text-gold" />
                </div>
              ) : (
                <>
                  {filteredThreads.map((thread) => {
                    const isUnread = !seenIds.has(thread.id);
                    const lastMsg  = thread.messages[thread.messages.length - 1];
                    return (
                      <div key={thread.id} className="relative group">
                        <button
                          onClick={() => openThread(thread.id)}
                          className={`w-full text-left px-4 py-3 border-b border-border hover:bg-muted/30 transition-colors ${selectedThreadId === thread.id ? "bg-muted/50" : ""}`}
                        >
                          <div className="flex items-start justify-between gap-2 mb-1">
                            <span className="text-sm font-medium text-foreground leading-tight line-clamp-1 pr-6 flex-1">
                              {thread.title}
                            </span>
                            {isUnread && (
                              <span className="h-2 w-2 rounded-full bg-gold flex-shrink-0 mt-1.5" title="New activity" />
                            )}
                          </div>
                          {/* Last message preview */}
                          {lastMsg && (
                            <p className="text-xs text-muted-foreground line-clamp-1 mb-1.5">
                              <span className="font-medium text-foreground/70">{lastMsg.author.split(" ")[0]}:</span> {lastMsg.text}
                            </p>
                          )}
                          <div className="flex items-center justify-between gap-2">
                            <div className="flex items-center gap-1">
                              <span className={`inline-block px-1.5 py-0.5 rounded text-[10px] font-medium border ${TAG_COLORS[thread.tag] || TAG_COLORS.General}`}>
                                {thread.tag}
                              </span>
                              {thread.client_name && !isClientUser && (
                                <span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium border bg-gold/10 text-gold border-gold/30 truncate max-w-[80px]">
                                  {thread.client_name}
                                </span>
                              )}
                            </div>
                            <span className="text-[10px] text-muted-foreground whitespace-nowrap">{thread.last_reply}</span>
                          </div>
                        </button>
                        {(isAdminRole || thread.author === myName) && (
                          <div className="absolute top-2 right-2 hidden group-hover:flex items-center gap-0.5">
                            <button title="Edit" onClick={(e) => { e.stopPropagation(); startEdit(thread); openThread(thread.id); }}
                              className="p-1 rounded hover:bg-muted/60 text-muted-foreground hover:text-foreground transition-colors">
                              <Pencil className="h-3 w-3" />
                            </button>
                            <button title="Delete" onClick={(e) => { e.stopPropagation(); setConfirmDeleteId(thread.id); setEditingThreadId(null); openThread(thread.id); }}
                              className="p-1 rounded hover:bg-destructive/20 text-muted-foreground hover:text-destructive transition-colors">
                              <Trash2 className="h-3 w-3" />
                            </button>
                          </div>
                        )}
                      </div>
                    );
                  })}
                  {filteredThreads.length === 0 && (
                    <div className="py-10 text-center text-xs text-muted-foreground">No discussions yet. Start one above.</div>
                  )}
                </>
              )}
            </div>
          </div>

          {/* ── Main panel ───────────────────────────────────────────────── */}
          <div className="flex-1 flex flex-col overflow-hidden">
            {/* New discussion form */}
            {showNewForm && (
              <div className="border-b border-border bg-muted/20 p-4 space-y-3">
                <div className="text-sm font-medium">Start a new discussion</div>
                <input type="text" placeholder="Thread title…" value={newTitle}
                  onChange={(e) => setNewTitle(e.target.value)}
                  className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                <div className="flex items-center gap-3 flex-wrap">
                  <select value={newTag} onChange={(e) => setNewTag(e.target.value as Tag)}
                    className="rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                    {tagOptions.map((t) => <option key={t}>{t}</option>)}
                  </select>
                  {!isClientUser && (
                    <select value={newClientId} onChange={(e) => setNewClientId(e.target.value)}
                      className="rounded-md border border-border bg-background px-3 py-2 text-sm text-muted-foreground focus:outline-none focus:ring-1 focus:ring-gold max-w-[220px]">
                      <option value="">Internal only</option>
                      {clients.map((c: any) => <option key={c.id} value={c.id}>Share with: {c.company_name ?? c.legal_name}</option>)}
                    </select>
                  )}
                </div>
                <textarea rows={3} placeholder="Write your first message…" value={newMessage}
                  onChange={(e) => setNewMessage(e.target.value)}
                  onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); handleCreateThread(); } }}
                  className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                <div className="flex gap-2">
                  <Button size="sm" onClick={handleCreateThread} disabled={sending}>
                    {sending ? <Loader2 className="h-3.5 w-3.5 mr-1.5 animate-spin" /> : <Send className="h-3.5 w-3.5 mr-1.5" />}Post Discussion
                  </Button>
                  <Button size="sm" variant="outline" onClick={() => setShowNewForm(false)}>Cancel</Button>
                </div>
              </div>
            )}

            {/* Delete confirmation */}
            {confirmDeleteId && confirmDeleteId === selectedThreadId && (
              <div className="border-b border-destructive/30 bg-destructive/10 px-6 py-3 flex items-center justify-between gap-4">
                <p className="text-sm text-destructive font-medium">Delete this discussion and all messages? Cannot be undone.</p>
                <div className="flex items-center gap-2 flex-shrink-0">
                  <Button size="sm" variant="destructive" onClick={() => handleDelete(confirmDeleteId)} disabled={deleting}>
                    {deleting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : "Yes, Delete"}
                  </Button>
                  <Button size="sm" variant="outline" onClick={() => setConfirmDeleteId(null)}>Cancel</Button>
                </div>
              </div>
            )}

            {selectedThread ? (
              <>
                {/* Thread header */}
                <div className="border-b border-border px-6 py-3 bg-card/50">
                  {editingThreadId === selectedThread.id ? (
                    <div className="flex items-center gap-3 flex-wrap">
                      <input className="flex-1 min-w-0 rounded-md border border-border bg-background px-3 py-1.5 text-sm font-semibold focus:outline-none focus:ring-1 focus:ring-gold"
                        value={editTitle} onChange={(e) => setEditTitle(e.target.value)}
                        onKeyDown={(e) => { if (e.key === "Enter") saveEdit(selectedThread.id); if (e.key === "Escape") cancelEdit(); }}
                        autoFocus />
                      <select value={editTag} onChange={(e) => setEditTag(e.target.value as Tag)}
                        className="rounded-md border border-border bg-background px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                        {tagOptions.map((t) => <option key={t}>{t}</option>)}
                      </select>
                      <button onClick={() => saveEdit(selectedThread.id)} disabled={editSaving}
                        className="p-1.5 rounded bg-gold/20 hover:bg-gold/30 text-gold transition-colors">
                        {editSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
                      </button>
                      <button onClick={cancelEdit} className="p-1.5 rounded hover:bg-muted text-muted-foreground transition-colors">
                        <X className="h-4 w-4" />
                      </button>
                    </div>
                  ) : (
                    <div className="flex items-center gap-3">
                      <MessageSquare className="h-4 w-4 text-gold flex-shrink-0" />
                      <div className="flex-1 min-w-0">
                        <div className="font-semibold text-sm text-foreground">{selectedThread.title}</div>
                        <div className="flex items-center gap-2 mt-0.5">
                          <span className={`inline-block px-1.5 py-0.5 rounded text-[10px] font-medium border ${TAG_COLORS[selectedThread.tag] || TAG_COLORS.General}`}>
                            {selectedThread.tag}
                          </span>
                          <span className="text-xs text-muted-foreground">{selectedThread.messages.length} messages</span>
                        </div>
                      </div>
                      {(isAdminRole || selectedThread.author === myName) && (
                        <div className="flex items-center gap-1 flex-shrink-0">
                          <button title="Edit" onClick={() => startEdit(selectedThread)}
                            className="p-1.5 rounded hover:bg-muted text-muted-foreground hover:text-foreground transition-colors">
                            <Pencil className="h-4 w-4" />
                          </button>
                          <button title="Delete" onClick={() => setConfirmDeleteId(selectedThread.id)}
                            className="p-1.5 rounded hover:bg-destructive/20 text-muted-foreground hover:text-destructive transition-colors">
                            <Trash2 className="h-4 w-4" />
                          </button>
                        </div>
                      )}
                    </div>
                  )}
                </div>

                {/* Messages */}
                <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
                  {selectedThread.messages.map((msg) => {
                    const isMe = msg.author === myName;
                    return (
                      <div key={msg.id} className={`flex gap-3 ${isMe ? "flex-row-reverse" : ""}`}>
                        <Avatar name={msg.author} src={isMe ? myAvatar : msg.author_avatar} />
                        <div className={`max-w-[70%] ${isMe ? "items-end" : "items-start"} flex flex-col`}>
                          <div className={`flex items-baseline gap-2 mb-1 ${isMe ? "flex-row-reverse" : ""}`}>
                            <span className="text-xs font-semibold text-foreground">{isMe ? "You" : msg.author}</span>
                            <span className="text-[10px] text-muted-foreground">{msg.time}</span>
                          </div>
                          <div className={`rounded-2xl px-3.5 py-2 text-sm leading-relaxed whitespace-pre-wrap ${
                            isMe
                              ? "bg-gold text-background rounded-tr-sm"
                              : "bg-muted/60 text-foreground rounded-tl-sm border border-border"
                          }`}>
                            {msg.text}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                  <div ref={messagesEndRef} />
                </div>

                {/* Reply box */}
                <div className="border-t border-border px-4 py-3 bg-card/50">
                  <div className="flex gap-3 items-end">
                    <Avatar name={myName} src={myAvatar} />
                    <div className="flex-1 relative">
                      <textarea rows={1}
                        placeholder="Message… (Enter to send, Shift+Enter for new line)"
                        value={replyText}
                        onChange={(e) => {
                          setReplyText(e.target.value);
                          // Auto-grow
                          e.target.style.height = "auto";
                          e.target.style.height = Math.min(e.target.scrollHeight, 120) + "px";
                        }}
                        onKeyDown={(e) => {
                          if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); handleSendReply(); }
                        }}
                        className="w-full rounded-xl border border-border bg-background px-4 py-2.5 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none overflow-hidden min-h-[40px]"
                        style={{ height: "40px" }}
                      />
                    </div>
                    <Button
                      size="icon"
                      onClick={handleSendReply}
                      disabled={!replyText.trim() || sending}
                      className="bg-gold hover:bg-gold/90 text-background h-10 w-10 rounded-xl flex-shrink-0"
                    >
                      {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                    </Button>
                  </div>
                </div>
              </>
            ) : (
              <div className="flex-1 flex flex-col items-center justify-center gap-3 text-muted-foreground">
                <MessageSquare className="h-12 w-12 opacity-20" />
                <p className="text-sm">Select a discussion to open the conversation.</p>
              </div>
            )}
          </div>
        </div>
      </div>
    </AppLayout>
  );
}
