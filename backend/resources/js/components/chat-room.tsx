import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePage } from "@inertiajs/react";
import { Loader2, Paperclip, Send, X, Pencil, Trash2, Check, Download, FileText, Users } from "lucide-react";
import echo from "@/lib/echo";

/* ─────────────────────────── Types ─────────────────────────── */

export interface ChatAttachment { name: string; path: string; size: number; type: string }
export interface ChatMessage {
  id: number;
  thread_id: number;
  author_id: number;
  author: string;
  avatar_url?: string | null;
  role?: string | null;
  content: string;
  attachments: ChatAttachment[];
  mentions: number[];
  edited_at?: string | null;
  created_at: string;
}
export interface ChatParticipant { id: number; name: string; role?: string | null; avatar_url?: string | null }
export interface ChatState {
  thread_id: number;
  channel: string;
  current_user: { id: number; name: string };
  can_moderate: boolean;
  participants: ChatParticipant[];
  messages: ChatMessage[];
  reads: Record<string, number>;
}

/** The data plumbing a room needs — bound to project / DM / group endpoints. */
export interface ChatEndpoints {
  load: () => Promise<ChatState>;
  send: (payload: { content?: string; mentions?: number[]; attachments?: File[] }) => Promise<ChatMessage>;
  edit: (messageId: number, content: string) => Promise<ChatMessage>;
  remove: (messageId: number) => Promise<void>;
  markRead: (lastReadMessageId: number) => Promise<void>;
  downloadAttachment: (path: string, name: string) => Promise<void>;
}

interface ChatRoomProps {
  endpoints: ChatEndpoints;
  /** Changing this re-initialises the room (switch between DMs/threads). */
  roomKey: string | number;
  title?: string;
  placeholder?: string;
  emptyText?: string;
  forbiddenText?: string;
  heightClass?: string;
}

/* ─────────────────────────── Helpers ─────────────────────────── */

function initials(name: string): string {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]?.toUpperCase()).join("") || "?";
}
function colorFor(id: number): string {
  const palette = ["bg-blue-500", "bg-emerald-500", "bg-violet-500", "bg-amber-500", "bg-rose-500", "bg-cyan-600", "bg-indigo-500", "bg-teal-500"];
  return palette[id % palette.length];
}
export function ChatAvatar({ name, url, id, size = 32 }: { name: string; url?: string | null; id: number; size?: number }) {
  const px = { width: size, height: size };
  if (url) return <img src={url} alt={name} style={px} className="rounded-full object-cover" />;
  return (
    <span style={px} className={`flex shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white ${colorFor(id)}`}>
      {initials(name)}
    </span>
  );
}
const Avatar = ChatAvatar;
function fmtTime(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "" : new Intl.DateTimeFormat("en-GB", { hour: "2-digit", minute: "2-digit" }).format(d);
}
function fmtDay(iso: string): string {
  const d = new Date(iso);
  const today = new Date();
  const yest = new Date(); yest.setDate(today.getDate() - 1);
  if (d.toDateString() === today.toDateString()) return "Today";
  if (d.toDateString() === yest.toDateString()) return "Yesterday";
  return new Intl.DateTimeFormat("en-GB", { day: "2-digit", month: "short", year: "numeric" }).format(d);
}
function fmtSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / 1048576).toFixed(1)} MB`;
}
/** Highlight @mentions of known participants — full name, not just first word. */
function renderContent(text: string, names: string[]): React.ReactNode {
  if (!names.length) return text;
  const escaped = names.slice().sort((a, b) => b.length - a.length)
    .map((n) => n.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"));
  const re = new RegExp(`(@(?:${escaped.join("|")}))`, "g");
  return text.split(re).map((part, i) =>
    part.startsWith("@") && names.some((n) => part === "@" + n)
      ? <span key={i} className="rounded bg-gold/15 px-1 font-medium text-gold">{part}</span>
      : <span key={i}>{part}</span>,
  );
}

/* ─────────────────────────── Component ─────────────────────────── */

export function ChatRoom({ endpoints, roomKey, title = "Discussion", placeholder = "Message…  (@ to mention)", emptyText = "No messages yet.", forbiddenText = "You do not have access to this conversation.", heightClass = "h-[calc(100vh-260px)]" }: ChatRoomProps) {
  const page = usePage<{ auth?: { user?: { id?: number; role?: string } } }>();
  const meId = page.props.auth?.user?.id ?? 0;

  const [state, setState] = useState<ChatState | null>(null);
  const [loading, setLoading] = useState(true);
  const [forbidden, setForbidden] = useState(false);
  const [draft, setDraft] = useState("");
  const [files, setFiles] = useState<File[]>([]);
  const [sending, setSending] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editText, setEditText] = useState("");
  const [typingUsers, setTypingUsers] = useState<Record<number, string>>({});
  const [mentionOpen, setMentionOpen] = useState(false);
  const [mentionQuery, setMentionQuery] = useState("");
  const [showRoster, setShowRoster] = useState(false);

  const scrollRef = useRef<HTMLDivElement | null>(null);
  const fileInputRef = useRef<HTMLInputElement | null>(null);
  const typingTimers = useRef<Record<number, ReturnType<typeof setTimeout>>>({});
  const lastWhisper = useRef(0);
  const channelRef = useRef<ReturnType<typeof echo.private> | null>(null);
  // Keep the latest endpoints without re-subscribing the socket each render.
  const epRef = useRef(endpoints);
  epRef.current = endpoints;

  const messages = state?.messages ?? [];
  const participants = state?.participants ?? [];
  const mentionNames = useMemo(
    () => [...participants.map((p) => p.name), state?.current_user.name].filter(Boolean) as string[],
    [participants, state?.current_user.name],
  );

  const isNearBottom = () => {
    const el = scrollRef.current;
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
  };
  const scrollToBottom = useCallback((smooth = true) => {
    const el = scrollRef.current;
    if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? "smooth" : "auto" });
  }, []);

  /* ── Load ── */
  useEffect(() => {
    let active = true;
    setLoading(true); setForbidden(false); setState(null);
    epRef.current.load()
      .then((data) => {
        if (!active) return;
        setState(data);
        setTimeout(() => scrollToBottom(false), 50);
        const lastId = data.messages.length ? data.messages[data.messages.length - 1].id : 0;
        if (lastId) epRef.current.markRead(lastId).catch(() => {});
      })
      .catch((e: unknown) => {
        if (!active) return;
        if (e instanceof Error && /restricted|Forbidden|403/i.test(e.message)) setForbidden(true);
        setState(null);
      })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [roomKey, scrollToBottom]);

  /* ── Subscribe ── */
  useEffect(() => {
    if (!state?.channel) return;
    let channel: ReturnType<typeof echo.private>;
    try {
      channel = echo.private(state.channel);
      channelRef.current = channel;
    } catch { return; }

    const merge = (updater: (s: ChatState) => ChatState) => setState((s) => (s ? updater(s) : s));

    channel.listen(".chat", (e: { action: string; data: any }) => {
      if (e.action === "message.sent") {
        const near = isNearBottom();
        merge((s) => s.messages.some((m) => m.id === e.data.id) ? s : { ...s, messages: [...s.messages, e.data] });
        setTypingUsers((t) => { const n = { ...t }; delete n[e.data.author_id]; return n; });
        if (near) setTimeout(() => scrollToBottom(), 60);
        epRef.current.markRead(e.data.id).catch(() => {});
      } else if (e.action === "message.updated") {
        merge((s) => ({ ...s, messages: s.messages.map((m) => (m.id === e.data.id ? { ...m, ...e.data } : m)) }));
      } else if (e.action === "message.deleted") {
        merge((s) => ({ ...s, messages: s.messages.filter((m) => m.id !== e.data.id) }));
      } else if (e.action === "read") {
        merge((s) => ({ ...s, reads: { ...s.reads, [e.data.user_id]: e.data.last_read_message_id } }));
      }
    });

    channel.listenForWhisper("typing", (e: { id: number; name: string }) => {
      if (e.id === meId) return;
      setTypingUsers((t) => ({ ...t, [e.id]: e.name }));
      clearTimeout(typingTimers.current[e.id]);
      typingTimers.current[e.id] = setTimeout(() => {
        setTypingUsers((t) => { const n = { ...t }; delete n[e.id]; return n; });
      }, 3500);
    });

    const chan = state.channel;
    return () => { try { echo.leave(chan); } catch { /* noop */ } channelRef.current = null; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state?.channel, meId]);

  /* ── Typing whisper ── */
  const emitTyping = () => {
    const now = Date.now();
    if (now - lastWhisper.current < 1500) return;
    lastWhisper.current = now;
    try { channelRef.current?.whisper("typing", { id: meId, name: state?.current_user.name ?? "" }); } catch { /* noop */ }
  };

  /* ── @mention autocomplete ── */
  const mentionMatches = useMemo(() => {
    if (!mentionOpen) return [];
    const q = mentionQuery.toLowerCase();
    return participants.filter((p) => p.id !== meId && p.name.toLowerCase().includes(q)).slice(0, 6);
  }, [mentionOpen, mentionQuery, participants, meId]);

  const onDraftChange = (value: string) => {
    setDraft(value);
    emitTyping();
    const m = value.match(/@([\w.\- ]*)$/);
    if (m) { setMentionOpen(true); setMentionQuery(m[1]); }
    else setMentionOpen(false);
  };
  const pickMention = (p: ChatParticipant) => {
    setDraft((d) => d.replace(/@([\w.\- ]*)$/, `@${p.name} `));
    setMentionOpen(false);
  };

  /* ── Send ── */
  const resolveMentions = (text: string): number[] =>
    participants.filter((p) => text.includes(`@${p.name}`)).map((p) => p.id);

  const send = async () => {
    const content = draft.trim();
    if ((!content && files.length === 0) || sending) return;
    setSending(true);
    const mentions = resolveMentions(content);
    const attached = files;
    setDraft(""); setFiles([]); setMentionOpen(false);
    try {
      const msg = await epRef.current.send({ content, mentions, attachments: attached });
      setState((s) => (s && !s.messages.some((m) => m.id === msg.id) ? { ...s, messages: [...s.messages, msg] } : s));
      setTimeout(() => scrollToBottom(), 60);
    } catch {
      setDraft(content); setFiles(attached);
    } finally { setSending(false); }
  };

  const saveEdit = async (id: number) => {
    const text = editText.trim();
    if (!text) return;
    try {
      const updated = await epRef.current.edit(id, text);
      setState((s) => (s ? { ...s, messages: s.messages.map((m) => (m.id === id ? { ...m, ...updated } : m)) } : s));
    } catch { /* noop */ }
    setEditingId(null); setEditText("");
  };
  const remove = async (id: number) => {
    if (!confirm("Delete this message?")) return;
    setState((s) => (s ? { ...s, messages: s.messages.filter((m) => m.id !== id) } : s));
    try { await epRef.current.remove(id); } catch { /* noop */ }
  };

  /* ── Read receipts ── */
  const lastMessageId = messages.length ? messages[messages.length - 1].id : 0;
  const seenBy = useMemo(() => {
    if (!state || !lastMessageId) return [];
    return participants.filter((p) => p.id !== meId && (state.reads[String(p.id)] ?? 0) >= lastMessageId);
  }, [state, participants, meId, lastMessageId]);

  /* ─────────── Render ─────────── */

  if (loading) return <div className={`flex ${heightClass} items-center justify-center`}><Loader2 className="h-6 w-6 animate-spin text-muted-foreground" /></div>;
  if (forbidden) return (
    <div className={`flex ${heightClass} flex-col items-center justify-center text-center`}>
      <Users className="h-8 w-8 text-muted-foreground" />
      <p className="mt-3 text-sm font-semibold">Restricted conversation</p>
      <p className="mt-1 max-w-md text-sm text-muted-foreground">{forbiddenText}</p>
    </div>
  );
  if (!state) return <p className="py-10 text-center text-sm text-muted-foreground">This conversation is unavailable.</p>;

  return (
    <div className={`flex ${heightClass} min-h-[420px] flex-col rounded-md border border-border bg-background`}>
      {/* Header */}
      <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
        <div className="flex items-center gap-2">
          <div className="flex -space-x-2">
            {participants.slice(0, 5).map((p) => <Avatar key={p.id} name={p.name} url={p.avatar_url} id={p.id} size={26} />)}
          </div>
          <button onClick={() => setShowRoster((v) => !v)} className="text-xs text-muted-foreground hover:text-foreground">
            {participants.length} {participants.length === 1 ? "person" : "people"}
          </button>
        </div>
        <span className="text-[11px] font-medium text-muted-foreground">{title}</span>
      </div>

      {showRoster && (
        <div className="border-b border-border bg-muted/20 px-4 py-2">
          <div className="flex flex-wrap gap-2">
            {participants.map((p) => (
              <span key={p.id} className="flex items-center gap-1.5 rounded-full border border-border bg-background px-2 py-0.5 text-xs">
                <Avatar name={p.name} url={p.avatar_url} id={p.id} size={18} />
                {p.name}{p.role ? <span className="text-muted-foreground">· {p.role}</span> : null}
              </span>
            ))}
          </div>
        </div>
      )}

      {/* Messages */}
      <div ref={scrollRef} className="flex-1 space-y-1 overflow-y-auto px-4 py-3">
        {messages.length === 0 && <p className="py-10 text-center text-sm text-muted-foreground">{emptyText}</p>}
        {messages.map((m, i) => {
          const prev = messages[i - 1];
          const showDay = !prev || fmtDay(prev.created_at) !== fmtDay(m.created_at);
          const grouped = prev && !showDay && prev.author_id === m.author_id
            && new Date(m.created_at).getTime() - new Date(prev.created_at).getTime() < 5 * 60 * 1000;
          const mine = m.author_id === meId;
          return (
            <div key={m.id}>
              {showDay && (
                <div className="my-3 flex items-center gap-3">
                  <div className="h-px flex-1 bg-border" />
                  <span className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{fmtDay(m.created_at)}</span>
                  <div className="h-px flex-1 bg-border" />
                </div>
              )}
              <div className={`group flex gap-2.5 ${grouped ? "mt-0.5" : "mt-2"}`}>
                <div className="w-8 shrink-0">
                  {!grouped && <Avatar name={m.author} url={m.avatar_url} id={m.author_id} />}
                </div>
                <div className="min-w-0 flex-1">
                  {!grouped && (
                    <div className="flex items-baseline gap-2">
                      <span className="text-xs font-semibold">{mine ? "You" : m.author}</span>
                      {m.role && <span className="text-[10px] text-muted-foreground">{m.role}</span>}
                      <span className="text-[10px] text-muted-foreground">{fmtTime(m.created_at)}</span>
                    </div>
                  )}
                  {editingId === m.id ? (
                    <div className="mt-1 flex items-center gap-2">
                      <input autoFocus value={editText} onChange={(e) => setEditText(e.target.value)}
                        onKeyDown={(e) => { if (e.key === "Enter") saveEdit(m.id); if (e.key === "Escape") setEditingId(null); }}
                        className="flex-1 rounded-md border border-border bg-background px-2 py-1 text-sm" />
                      <button onClick={() => saveEdit(m.id)} className="text-emerald-600"><Check className="h-4 w-4" /></button>
                      <button onClick={() => setEditingId(null)} className="text-muted-foreground"><X className="h-4 w-4" /></button>
                    </div>
                  ) : (
                    <div className="relative">
                      {m.content && <p className="whitespace-pre-wrap break-words text-sm leading-relaxed">{renderContent(m.content, mentionNames)}</p>}
                      {m.attachments?.length > 0 && (
                        <div className="mt-1 flex flex-wrap gap-2">
                          {m.attachments.map((a) => (
                            <button key={a.path} onClick={() => epRef.current.downloadAttachment(a.path, a.name)}
                              className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-2 py-1.5 text-left text-xs hover:bg-muted">
                              <FileText className="h-4 w-4 shrink-0 text-muted-foreground" />
                              <span className="min-w-0"><span className="block max-w-[180px] truncate font-medium">{a.name}</span>
                                <span className="text-[10px] text-muted-foreground">{fmtSize(a.size)}</span></span>
                              <Download className="h-3.5 w-3.5 text-muted-foreground" />
                            </button>
                          ))}
                        </div>
                      )}
                      {m.edited_at && <span className="ml-1 text-[10px] text-muted-foreground">(edited)</span>}
                      {mine && (
                        <div className="absolute -top-1 right-0 hidden gap-1 group-hover:flex">
                          <button onClick={() => { setEditingId(m.id); setEditText(m.content); }} title="Edit"
                            className="rounded bg-background p-1 text-muted-foreground shadow-sm hover:text-foreground"><Pencil className="h-3 w-3" /></button>
                          <button onClick={() => remove(m.id)} title="Delete"
                            className="rounded bg-background p-1 text-muted-foreground shadow-sm hover:text-destructive"><Trash2 className="h-3 w-3" /></button>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
            </div>
          );
        })}

        {seenBy.length > 0 && (
          <div className="flex items-center justify-end gap-1 pr-1 pt-1">
            <span className="text-[10px] text-muted-foreground">Seen</span>
            <div className="flex -space-x-1.5">
              {seenBy.slice(0, 6).map((p) => <Avatar key={p.id} name={p.name} url={p.avatar_url} id={p.id} size={16} />)}
            </div>
          </div>
        )}
      </div>

      {/* Typing */}
      <div className="h-5 px-4 text-[11px] italic text-muted-foreground">
        {Object.values(typingUsers).length > 0 && (
          <span>{Object.values(typingUsers).slice(0, 2).join(", ")} {Object.values(typingUsers).length === 1 ? "is" : "are"} typing…</span>
        )}
      </div>

      {/* Composer */}
      <div className="relative border-t border-border p-3">
        {mentionOpen && mentionMatches.length > 0 && (
          <div className="absolute bottom-full left-3 mb-1 w-64 overflow-hidden rounded-md border border-border bg-popover shadow-lg">
            {mentionMatches.map((p) => (
              <button key={p.id} onClick={() => pickMention(p)}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-muted">
                <Avatar name={p.name} url={p.avatar_url} id={p.id} size={22} />
                <span>{p.name}</span>{p.role && <span className="text-xs text-muted-foreground">· {p.role}</span>}
              </button>
            ))}
          </div>
        )}
        {files.length > 0 && (
          <div className="mb-2 flex flex-wrap gap-2">
            {files.map((f, i) => (
              <span key={i} className="flex items-center gap-1.5 rounded-md border border-border bg-muted/30 px-2 py-1 text-xs">
                <FileText className="h-3.5 w-3.5 text-muted-foreground" /><span className="max-w-[140px] truncate">{f.name}</span>
                <button onClick={() => setFiles((fs) => fs.filter((_, j) => j !== i))}><X className="h-3.5 w-3.5 text-muted-foreground hover:text-destructive" /></button>
              </span>
            ))}
          </div>
        )}
        <div className="flex items-end gap-2">
          <button onClick={() => fileInputRef.current?.click()} title="Attach files"
            className="mb-0.5 rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground"><Paperclip className="h-4 w-4" /></button>
          <input ref={fileInputRef} type="file" multiple hidden
            onChange={(e) => { setFiles((fs) => [...fs, ...Array.from(e.target.files ?? [])]); e.target.value = ""; }} />
          <textarea value={draft} onChange={(e) => onDraftChange(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey && !mentionOpen) { e.preventDefault(); send(); } }}
            rows={1} placeholder={placeholder}
            className="max-h-32 min-h-[38px] flex-1 resize-none rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
          <button onClick={send} disabled={sending || (!draft.trim() && files.length === 0)}
            className="mb-0.5 rounded-md bg-gold p-2 text-white disabled:opacity-40">
            {sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
          </button>
        </div>
      </div>
    </div>
  );
}
