import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import { MessageSquare, Plus, Send, Loader2, Pencil, Trash2, Check, X, Users, MessagesSquare } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Button } from "@/components/ui/button";
import { ChatRoom, ChatAvatar, type ChatEndpoints } from "@/components/chat-room";
import { api } from "@/lib/api-client";

type Tag = "General" | "Project" | "HR" | "Finance";
type Mode = "threads" | "direct";

interface Thread {
  id: number;
  title: string;
  author: string;
  last_reply: string;
  tag: Tag;
  client_id?: number | null;
  client_name?: string | null;
  messages: Array<{ id: number; author: string; text: string }>;
}

interface DmRow {
  thread_id: number;
  user: { id: number; name: string; role?: string | null; avatar_url?: string | null } | null;
  last: string | null;
  updated_at?: string | null;
  unread: number;
}
interface Contact { id: number; name: string; role?: string | null; avatar_url?: string | null }

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
  const role: string      = props.auth?.user?.role ?? "";
  const myName            = props.auth?.user?.name || "You";
  const isClientUser      = ["client", "client_admin"].includes(role);
  const isAdminRole       = ["super_admin", "partner"].includes(role);
  const tagOptions: Tag[] = isClientUser ? ["General", "Project"] : ["General", "Project", "HR", "Finance"];

  const [mode, setMode]                     = useState<Mode>("threads");
  const [clients, setClients]               = useState<any[]>([]);
  const [newClientId, setNewClientId]       = useState<string>("");
  const [threads, setThreads]               = useState<Thread[]>([]);
  const [loading, setLoading]               = useState(true);
  const [error, setError]                   = useState("");
  const [selectedThreadId, setSelectedThreadId] = useState<number | null>(null);
  const [tagFilter, setTagFilter]           = useState<"All" | Tag>("All");
  const [showNewForm, setShowNewForm]       = useState(false);
  const [newTitle, setNewTitle]             = useState("");
  const [newTag, setNewTag]                 = useState<Tag>("General");
  const [newMessage, setNewMessage]         = useState("");
  const [sending, setSending]               = useState(false);
  const [editingThreadId, setEditingThreadId] = useState<number | null>(null);
  const [editTitle, setEditTitle]           = useState("");
  const [editTag, setEditTag]               = useState<Tag>("General");
  const [editSaving, setEditSaving]         = useState(false);
  const [confirmDeleteId, setConfirmDeleteId] = useState<number | null>(null);
  const [deleting, setDeleting]             = useState(false);

  // Direct messages
  const [dms, setDms]                       = useState<DmRow[]>([]);
  const [contacts, setContacts]             = useState<Contact[]>([]);
  const [showContacts, setShowContacts]     = useState(false);
  const [contactQuery, setContactQuery]     = useState("");

  const loadThreads = () =>
    api.getDiscussions()
      .then((data) => { setThreads(data as unknown as Thread[]); })
      .catch((e) => setError(e.message || "Failed to load discussions."))
      .finally(() => setLoading(false));

  const loadDms = () =>
    api.getDirectMessages().then((res) => setDms(res.data as DmRow[])).catch(() => {});

  useEffect(() => {
    loadThreads();
    if (!isClientUser) {
      api.getClients(new URLSearchParams({ per_page: "2000" }))
        .then((res: any) => setClients(Array.isArray(res) ? res : res?.data ?? [])).catch(() => {});
      loadDms();
      api.getDmContacts().then((res) => setContacts(res.data as Contact[])).catch(() => {});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const filteredThreads = tagFilter === "All" ? threads : threads.filter((t) => t.tag === tagFilter);
  const selectedThread  = threads.find((t) => t.id === selectedThreadId) ?? null;
  const selectedDm      = dms.find((d) => d.thread_id === selectedThreadId) ?? null;

  const endpoints = useMemo<ChatEndpoints | null>(() => {
    if (!selectedThreadId) return null;
    const id = selectedThreadId;
    return {
      load: () => api.getThreadChat(id),
      send: (p) => api.sendThreadChat(id, p),
      edit: (mid, c) => api.editThreadChatMessage(id, mid, c),
      remove: (mid) => api.deleteThreadChatMessage(id, mid).then(() => undefined),
      markRead: (mid) => api.markThreadChatRead(id, mid).then(() => undefined),
      downloadAttachment: (path, name) => api.downloadThreadAttachment(id, path, name),
    };
  }, [selectedThreadId]);

  function openConversation(id: number) {
    setSelectedThreadId(id);
    setConfirmDeleteId(null);
    setEditingThreadId(null);
  }

  async function openDm(userId: number) {
    setShowContacts(false);
    try {
      const state = await api.openDirectMessage(userId);
      setMode("direct");
      await loadDms();
      openConversation(state.thread_id);
    } catch (e: any) { setError(e.message || "Could not open direct message."); }
  }

  async function handleCreateThread() {
    if (!newTitle.trim() || !newMessage.trim() || sending) return;
    setSending(true); setError("");
    try {
      const created = await api.createDiscussion({
        title: newTitle.trim(), tag: newTag, message: newMessage.trim(),
        client_id: !isClientUser && newClientId ? Number(newClientId) : undefined,
      }) as any;
      const thread: Thread = {
        id: created.id, title: created.title, tag: (created.tag || "General") as Tag,
        client_id: created.client_id ?? null,
        client_name: created.client_id ? (clients.find((c: any) => c.id === created.client_id)?.company_name ?? null) : null,
        author: myName, last_reply: "Just now", messages: [],
      };
      setThreads((prev) => [thread, ...prev]);
      openConversation(thread.id);
      setNewTitle(""); setNewTag("General"); setNewMessage(""); setNewClientId(""); setShowNewForm(false);
    } catch (e: any) { setError(e.message || "Failed to create discussion."); }
    finally { setSending(false); }
  }

  function startEdit(thread: Thread) { setEditingThreadId(thread.id); setEditTitle(thread.title); setEditTag(thread.tag); setConfirmDeleteId(null); }
  async function saveEdit(threadId: number) {
    if (!editTitle.trim()) return;
    setEditSaving(true);
    try {
      await api.updateDiscussion(threadId, { title: editTitle.trim(), tag: editTag });
      setThreads((prev) => prev.map((t) => t.id === threadId ? { ...t, title: editTitle.trim(), tag: editTag } : t));
      setEditingThreadId(null);
    } catch (e: any) { setError(e.message || "Failed to update discussion."); }
    finally { setEditSaving(false); }
  }
  async function handleDelete(threadId: number) {
    setDeleting(true);
    try {
      await api.deleteDiscussion(threadId);
      setThreads((prev) => prev.filter((t) => t.id !== threadId));
      if (selectedThreadId === threadId) setSelectedThreadId(null);
      setConfirmDeleteId(null);
    } catch (e: any) { setError(e.message || "Failed to delete discussion."); }
    finally { setDeleting(false); }
  }

  const filteredContacts = contactQuery
    ? contacts.filter((c) => c.name.toLowerCase().includes(contactQuery.toLowerCase()))
    : contacts;

  return (
    <AppLayout>
      <Head title="Discussions" />
      <PageHeader
        eyebrow="Engagement"
        title="Discussions"
        description="Team conversations and direct messages — real-time."
        actions={!isClientUser && mode === "threads" ? (
          <Button onClick={() => setShowNewForm((v) => !v)}><Plus className="h-4 w-4 mr-2" />New Discussion</Button>
        ) : !isClientUser ? (
          <Button onClick={() => setShowContacts(true)}><Plus className="h-4 w-4 mr-2" />New Message</Button>
        ) : undefined}
      />

      <div className="px-8 py-6">
        {error && <div className="mb-3 rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">{error}</div>}
        <div className="flex h-[calc(100vh-220px)] gap-0 rounded-lg border border-border overflow-hidden bg-card">

          {/* ── Left panel ── */}
          <div className="w-72 flex-shrink-0 flex flex-col border-r border-border">
            {/* Mode switch */}
            {!isClientUser && (
              <div className="flex gap-1 p-2 border-b border-border">
                <button onClick={() => { setMode("threads"); setSelectedThreadId(null); }}
                  className={`flex-1 flex items-center justify-center gap-1.5 py-1.5 rounded-md text-xs font-medium ${mode === "threads" ? "bg-gold text-background" : "text-muted-foreground hover:bg-muted/50"}`}>
                  <MessagesSquare className="h-3.5 w-3.5" />Threads
                </button>
                <button onClick={() => { setMode("direct"); setSelectedThreadId(null); }}
                  className={`flex-1 flex items-center justify-center gap-1.5 py-1.5 rounded-md text-xs font-medium ${mode === "direct" ? "bg-gold text-background" : "text-muted-foreground hover:bg-muted/50"}`}>
                  <Users className="h-3.5 w-3.5" />Direct
                </button>
              </div>
            )}

            {mode === "threads" && (
              <div className="flex flex-wrap gap-1 p-3 border-b border-border bg-muted/20">
                {(isClientUser ? CLIENT_TAGS : ALL_TAGS).map((tag) => (
                  <button key={tag} onClick={() => setTagFilter(tag)}
                    className={`px-2.5 py-1 rounded-full text-xs font-medium transition-colors ${tagFilter === tag ? "bg-gold text-background" : "bg-muted text-muted-foreground hover:bg-muted/70"}`}>
                    {tag}
                  </button>
                ))}
              </div>
            )}

            <div className="flex-1 overflow-y-auto">
              {loading ? (
                <div className="flex items-center justify-center py-10"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
              ) : mode === "threads" ? (
                <>
                  {filteredThreads.map((thread) => {
                    const lastMsg = thread.messages[thread.messages.length - 1];
                    return (
                      <div key={thread.id} className="relative group">
                        <button onClick={() => openConversation(thread.id)}
                          className={`w-full text-left px-4 py-3 border-b border-border hover:bg-muted/30 transition-colors ${selectedThreadId === thread.id ? "bg-muted/50" : ""}`}>
                          <div className="flex items-start justify-between gap-2 mb-1">
                            <span className="text-sm font-medium text-foreground leading-tight line-clamp-1 pr-6 flex-1">{thread.title}</span>
                          </div>
                          {lastMsg && <p className="text-xs text-muted-foreground line-clamp-1 mb-1.5"><span className="font-medium text-foreground/70">{lastMsg.author.split(" ")[0]}:</span> {lastMsg.text}</p>}
                          <div className="flex items-center justify-between gap-2">
                            <div className="flex items-center gap-1">
                              <span className={`inline-block px-1.5 py-0.5 rounded text-[10px] font-medium border ${TAG_COLORS[thread.tag] || TAG_COLORS.General}`}>{thread.tag}</span>
                              {thread.client_name && !isClientUser && <span className="inline-block px-1.5 py-0.5 rounded text-[10px] font-medium border bg-gold/10 text-gold border-gold/30 truncate max-w-[80px]">{thread.client_name}</span>}
                            </div>
                            <span className="text-[10px] text-muted-foreground whitespace-nowrap">{thread.last_reply}</span>
                          </div>
                        </button>
                        {(isAdminRole || thread.author === myName) && (
                          <div className="absolute top-2 right-2 hidden group-hover:flex items-center gap-0.5">
                            <button title="Edit" onClick={(e) => { e.stopPropagation(); startEdit(thread); openConversation(thread.id); }} className="p-1 rounded hover:bg-muted/60 text-muted-foreground hover:text-foreground"><Pencil className="h-3 w-3" /></button>
                            <button title="Delete" onClick={(e) => { e.stopPropagation(); setConfirmDeleteId(thread.id); openConversation(thread.id); }} className="p-1 rounded hover:bg-destructive/20 text-muted-foreground hover:text-destructive"><Trash2 className="h-3 w-3" /></button>
                          </div>
                        )}
                      </div>
                    );
                  })}
                  {filteredThreads.length === 0 && <div className="py-10 text-center text-xs text-muted-foreground">No discussions yet.</div>}
                </>
              ) : (
                <>
                  {dms.map((dm) => (
                    <button key={dm.thread_id} onClick={() => openConversation(dm.thread_id)}
                      className={`w-full text-left px-4 py-3 border-b border-border hover:bg-muted/30 transition-colors ${selectedThreadId === dm.thread_id ? "bg-muted/50" : ""}`}>
                      <div className="flex items-center gap-2.5">
                        {dm.user && <ChatAvatar name={dm.user.name} url={dm.user.avatar_url} id={dm.user.id} size={30} />}
                        <div className="min-w-0 flex-1">
                          <div className="flex items-center justify-between gap-2">
                            <span className="text-sm font-medium line-clamp-1">{dm.user?.name ?? "Unknown"}</span>
                            {dm.unread > 0 && <span className="rounded-full bg-gold px-1.5 text-[10px] font-semibold text-background">{dm.unread}</span>}
                          </div>
                          {dm.last && <p className="text-xs text-muted-foreground line-clamp-1">{dm.last}</p>}
                        </div>
                      </div>
                    </button>
                  ))}
                  {dms.length === 0 && <div className="py-10 text-center text-xs text-muted-foreground">No direct messages yet.<br />Start one with “New Message”.</div>}
                </>
              )}
            </div>
          </div>

          {/* ── Main panel ── */}
          <div className="flex-1 flex flex-col overflow-hidden">
            {showNewForm && mode === "threads" && (
              <div className="border-b border-border bg-muted/20 p-4 space-y-3">
                <div className="text-sm font-medium">Start a new discussion</div>
                <input type="text" placeholder="Thread title…" value={newTitle} onChange={(e) => setNewTitle(e.target.value)}
                  className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
                <div className="flex items-center gap-3 flex-wrap">
                  <select value={newTag} onChange={(e) => setNewTag(e.target.value as Tag)} className="rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                    {tagOptions.map((t) => <option key={t}>{t}</option>)}
                  </select>
                  {!isClientUser && (
                    <select value={newClientId} onChange={(e) => setNewClientId(e.target.value)} className="rounded-md border border-border bg-background px-3 py-2 text-sm text-muted-foreground focus:outline-none focus:ring-1 focus:ring-gold max-w-[220px]">
                      <option value="">Internal only</option>
                      {clients.map((c: any) => <option key={c.id} value={c.id}>Share with: {c.company_name ?? c.legal_name}</option>)}
                    </select>
                  )}
                </div>
                <textarea rows={3} placeholder="Write your first message…" value={newMessage} onChange={(e) => setNewMessage(e.target.value)}
                  onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); handleCreateThread(); } }}
                  className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none" />
                <div className="flex gap-2">
                  <Button size="sm" onClick={handleCreateThread} disabled={sending}>{sending ? <Loader2 className="h-3.5 w-3.5 mr-1.5 animate-spin" /> : <Send className="h-3.5 w-3.5 mr-1.5" />}Post Discussion</Button>
                  <Button size="sm" variant="outline" onClick={() => setShowNewForm(false)}>Cancel</Button>
                </div>
              </div>
            )}

            {confirmDeleteId && confirmDeleteId === selectedThreadId && (
              <div className="border-b border-destructive/30 bg-destructive/10 px-6 py-3 flex items-center justify-between gap-4">
                <p className="text-sm text-destructive font-medium">Delete this discussion and all messages? Cannot be undone.</p>
                <div className="flex items-center gap-2 flex-shrink-0">
                  <Button size="sm" variant="destructive" onClick={() => handleDelete(confirmDeleteId)} disabled={deleting}>{deleting ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : "Yes, Delete"}</Button>
                  <Button size="sm" variant="outline" onClick={() => setConfirmDeleteId(null)}>Cancel</Button>
                </div>
              </div>
            )}

            {selectedThreadId && endpoints ? (
              <div className="flex flex-1 flex-col overflow-hidden">
                {/* Header */}
                <div className="border-b border-border px-6 py-3 bg-card/50">
                  {mode === "threads" && selectedThread ? (
                    editingThreadId === selectedThread.id ? (
                      <div className="flex items-center gap-3 flex-wrap">
                        <input className="flex-1 min-w-0 rounded-md border border-border bg-background px-3 py-1.5 text-sm font-semibold focus:outline-none focus:ring-1 focus:ring-gold"
                          value={editTitle} onChange={(e) => setEditTitle(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") saveEdit(selectedThread.id); if (e.key === "Escape") setEditingThreadId(null); }} autoFocus />
                        <select value={editTag} onChange={(e) => setEditTag(e.target.value as Tag)} className="rounded-md border border-border bg-background px-2 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-gold">{tagOptions.map((t) => <option key={t}>{t}</option>)}</select>
                        <button onClick={() => saveEdit(selectedThread.id)} disabled={editSaving} className="p-1.5 rounded bg-gold/20 hover:bg-gold/30 text-gold">{editSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}</button>
                        <button onClick={() => setEditingThreadId(null)} className="p-1.5 rounded hover:bg-muted text-muted-foreground"><X className="h-4 w-4" /></button>
                      </div>
                    ) : (
                      <div className="flex items-center gap-3">
                        <MessageSquare className="h-4 w-4 text-gold flex-shrink-0" />
                        <div className="flex-1 min-w-0">
                          <div className="font-semibold text-sm text-foreground">{selectedThread.title}</div>
                          <span className={`inline-block px-1.5 py-0.5 rounded text-[10px] font-medium border ${TAG_COLORS[selectedThread.tag] || TAG_COLORS.General}`}>{selectedThread.tag}</span>
                        </div>
                        {(isAdminRole || selectedThread.author === myName) && (
                          <div className="flex items-center gap-1 flex-shrink-0">
                            <button title="Edit" onClick={() => startEdit(selectedThread)} className="p-1.5 rounded hover:bg-muted text-muted-foreground hover:text-foreground"><Pencil className="h-4 w-4" /></button>
                            <button title="Delete" onClick={() => setConfirmDeleteId(selectedThread.id)} className="p-1.5 rounded hover:bg-destructive/20 text-muted-foreground hover:text-destructive"><Trash2 className="h-4 w-4" /></button>
                          </div>
                        )}
                      </div>
                    )
                  ) : (
                    <div className="flex items-center gap-2.5">
                      {selectedDm?.user && <ChatAvatar name={selectedDm.user.name} url={selectedDm.user.avatar_url} id={selectedDm.user.id} size={28} />}
                      <span className="font-semibold text-sm">{selectedDm?.user?.name ?? "Direct message"}</span>
                    </div>
                  )}
                </div>

                <div className="flex-1 overflow-hidden p-3">
                  <ChatRoom
                    endpoints={endpoints}
                    roomKey={selectedThreadId}
                    title={mode === "direct" ? (selectedDm?.user?.name ?? "Direct message") : (selectedThread?.title ?? "Discussion")}
                    placeholder="Message…  (@ to mention)"
                    emptyText="No messages yet."
                    forbiddenText="You do not have access to this conversation."
                    heightClass="h-full"
                  />
                </div>
              </div>
            ) : (
              <div className="flex-1 flex flex-col items-center justify-center gap-3 text-muted-foreground">
                <MessageSquare className="h-12 w-12 opacity-20" />
                <p className="text-sm">{mode === "direct" ? "Select or start a direct message." : "Select a discussion to open the conversation."}</p>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* New DM contact picker */}
      {showContacts && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => setShowContacts(false)}>
          <div className="w-full max-w-sm rounded-lg border border-border bg-background p-4 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="mb-3 flex items-center justify-between">
              <h3 className="text-sm font-semibold">New message</h3>
              <button onClick={() => setShowContacts(false)} className="text-muted-foreground hover:text-foreground"><X className="h-5 w-5" /></button>
            </div>
            <input autoFocus placeholder="Search people…" value={contactQuery} onChange={(e) => setContactQuery(e.target.value)}
              className="mb-2 w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
            <div className="max-h-72 overflow-y-auto">
              {filteredContacts.map((c) => (
                <button key={c.id} onClick={() => openDm(c.id)} className="flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-left text-sm hover:bg-muted">
                  <ChatAvatar name={c.name} url={c.avatar_url} id={c.id} size={26} />
                  <span>{c.name}</span>{c.role && <span className="text-xs text-muted-foreground">· {c.role}</span>}
                </button>
              ))}
              {filteredContacts.length === 0 && <p className="py-6 text-center text-xs text-muted-foreground">No people found.</p>}
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  );
}
