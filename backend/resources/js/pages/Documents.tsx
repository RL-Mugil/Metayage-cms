import { Head, usePage } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import {
  FolderOpen,
  File,
  FileText,
  FileImage,
  Upload,
  Search,
  Download,
  Trash2,
  Loader2,
  X,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api } from "@/lib/api-client";
import { SearchCombobox } from "@/components/search-combobox";

interface DocFile {
  name: string;
  path: string;
  folder: string;
  size: number;
  modified: string;
  uploader?: string;
}

interface ClientOption {
  id: number;
  company_name?: string;
  legal_name?: string;
  client_code?: string;
  entity_type?: string;
  primary_jurisdiction?: string;
  contact_name?: string;
  contact_email?: string;
  status?: string;
}

const FOLDERS = ["All Documents", "General", "Patents", "Trademarks", "Contracts", "Correspondence", "Invoices"];
const UPLOAD_FOLDERS = ["General", "Patents", "Trademarks", "Contracts", "Correspondence", "Invoices", "Payment Proof", "Grant Order", "Refusal Order", "Draft Document", "Forms"];

function fmtSize(bytes: number): string {
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`;
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${bytes} B`;
}

function fmtModified(ts: string | number): string {
  const d = typeof ts === "number" ? new Date(ts * 1000) : new Date(ts);
  return d.toISOString().slice(0, 10);
}

function ext(name: string): string {
  return name.split(".").pop()?.toLowerCase() || "";
}

function FileIcon({ name }: { name: string }) {
  const e = ext(name);
  if (["jpg", "png", "jpeg", "gif", "svg"].includes(e))
    return <FileImage className="h-4 w-4 text-purple-400" />;
  if (e === "pdf")
    return <FileText className="h-4 w-4 text-red-400" />;
  if (e === "docx" || e === "doc")
    return <FileText className="h-4 w-4 text-blue-400" />;
  if (e === "xlsx" || e === "xls")
    return <FileText className="h-4 w-4 text-green-400" />;
  return <File className="h-4 w-4 text-muted-foreground" />;
}

export default function Documents() {
  const { props: pageProps } = usePage() as any;
  const role = pageProps.auth?.user?.role;
  const isClientUser = ["client", "client_admin"].includes(role);
  const canDelete = ["super_admin", "partner", "manager", "galvanizer"].includes(role);

  const [activeFolder, setActiveFolder] = useState("All Documents");
  const [search, setSearch] = useState("");
  const [files, setFiles] = useState<DocFile[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [banner, setBanner] = useState<{ kind: "ok" | "err"; text: string } | null>(null);

  // Upload modal — internal users can tag an upload to a client via a searchable,
  // scrollable combobox showing full client details; clients always upload to
  // their own account (server enforces this regardless of what's sent).
  const [clients, setClients] = useState<ClientOption[]>([]);
  const [showUploadModal, setShowUploadModal] = useState(false);
  const [uploadClientId, setUploadClientId] = useState("");
  const [uploadFolder, setUploadFolder] = useState("General");
  const [pendingFile, setPendingFile] = useState<File | null>(null);
  const [uploadError, setUploadError] = useState("");
  const modalFileInputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!isClientUser) {
      api.getClients(new URLSearchParams({ per_page: "2000" }))
        .then((res: any) => setClients(Array.isArray(res) ? res : res?.data ?? []))
        .catch(() => {});
    }
  }, [isClientUser]);

  function clientDisplayName(c: ClientOption) {
    return `${c.client_code ?? ""} — ${c.company_name ?? c.legal_name ?? ""}`.trim().replace(/^— /, "");
  }

  function openUploadModal() {
    setPendingFile(null);
    setUploadClientId("");
    setUploadFolder(activeFolder === "All Documents" ? "General" : activeFolder);
    setUploadError("");
    setShowUploadModal(true);
  }

  function showBanner(kind: "ok" | "err", text: string) {
    setBanner({ kind, text });
    setTimeout(() => setBanner(null), 3500);
  }

  function refresh() {
    api.getDocuments()
      .then((data) => setFiles(data as unknown as DocFile[]))
      .catch((e) => showBanner("err", e.message || "Failed to load documents"))
      .finally(() => setLoading(false));
  }

  useEffect(refresh, []);

  const filtered = files.filter((f) => {
    const matchFolder = activeFolder === "All Documents" || f.folder === activeFolder;
    const matchSearch = f.name.toLowerCase().includes(search.toLowerCase());
    return matchFolder && matchSearch;
  });

  function folderCount(folder: string): number {
    if (folder === "All Documents") return files.length;
    return files.filter((f) => f.folder === folder).length;
  }

  const ALLOWED_TYPES = new Set(["pdf","docx","doc","xlsx","xls","pptx","ppt","txt","csv","png","jpg","jpeg","gif","zip"]);
  const MAX_BYTES = 50 * 1024 * 1024; // 50 MB

  function pickFile(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    const fileExt = file.name.split(".").pop()?.toLowerCase() ?? "";
    if (!ALLOWED_TYPES.has(fileExt)) {
      setUploadError(`File type .${fileExt} is not allowed. Supported: PDF, Word, Excel, PowerPoint, TXT, CSV, images, ZIP.`);
      return;
    }
    if (file.size > MAX_BYTES) {
      setUploadError(`File too large (${(file.size / 1024 / 1024).toFixed(1)} MB). Maximum allowed size is 50 MB.`);
      return;
    }
    setUploadError("");
    setPendingFile(file);
  }

  async function submitUpload() {
    if (!pendingFile) { setUploadError("Choose a file first."); return; }
    setUploading(true);
    setUploadError("");
    try {
      const clientId = !isClientUser && uploadClientId ? Number(uploadClientId) : null;
      const uploaded = await api.uploadDocument(pendingFile, uploadFolder, clientId);
      showBanner("ok", `Uploaded: ${uploaded.name}${clientId ? " (shared with client)" : ""}`);
      setShowUploadModal(false);
      refresh();
    } catch (err: any) {
      setUploadError(err.message || "Upload failed");
    } finally {
      setUploading(false);
    }
  }

  async function handleDownload(file: DocFile) {
    try {
      await api.downloadDocument(file.path, file.name);
    } catch {
      showBanner("err", "Download failed");
    }
  }

  async function handleDelete(file: DocFile) {
    if (!confirm(`Delete "${file.name}"? This cannot be undone.`)) return;
    try {
      await api.deleteDocument(file.path);
      setFiles((prev) => prev.filter((f) => f.path !== file.path));
      showBanner("ok", `Deleted: ${file.name}`);
    } catch (err: any) {
      showBanner("err", err.message || "Delete failed");
    }
  }

  return (
    <AppLayout>
      <Head title="Documents" />
      <PageHeader
        eyebrow="Knowledge"
        title="Document Management"
        description="IP specifications, filings, contracts, and correspondence"
        actions={
          <>
            {!isClientUser && (
              <Button onClick={openUploadModal}>
                <Upload className="h-4 w-4 mr-2" /> Upload File
              </Button>
            )}
          </>
        }
      />

      {showUploadModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Upload File</h2>
              <button onClick={() => setShowUploadModal(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>

            {uploadError && (
              <div className="rounded-md bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive mb-3">{uploadError}</div>
            )}

            <div className="grid grid-cols-2 gap-3">
              <div className="col-span-2">
                <label className="block text-xs text-muted-foreground mb-1">File *</label>
                <input
                  ref={modalFileInputRef}
                  type="file"
                  className="hidden"
                  onChange={pickFile}
                  accept=".pdf,.docx,.doc,.xlsx,.xls,.pptx,.ppt,.txt,.csv,.png,.jpg,.jpeg,.gif,.zip"
                />
                <button
                  type="button"
                  onClick={() => modalFileInputRef.current?.click()}
                  className="w-full flex items-center gap-2 h-10 rounded-md border border-dashed border-border bg-background px-3 text-sm text-left hover:border-gold/50 hover:bg-muted/30 transition-colors"
                >
                  {pendingFile ? <FileIcon name={pendingFile.name} /> : <Upload className="h-4 w-4 text-muted-foreground flex-shrink-0" />}
                  <span className={`truncate ${pendingFile ? "text-foreground" : "text-muted-foreground"}`}>
                    {pendingFile ? pendingFile.name : "Choose a file…"}
                  </span>
                  {pendingFile && <span className="ml-auto text-xs text-muted-foreground flex-shrink-0">{fmtSize(pendingFile.size)}</span>}
                </button>
              </div>

              <div className={isClientUser ? "col-span-2" : ""}>
                <label className="block text-xs text-muted-foreground mb-1">Folder</label>
                <select
                  value={uploadFolder}
                  onChange={(e) => setUploadFolder(e.target.value)}
                  className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                >
                  {UPLOAD_FOLDERS.map((f) => <option key={f} value={f}>{f}</option>)}
                </select>
              </div>

              {isClientUser ? (
                <p className="col-span-2 text-xs text-muted-foreground">This will be uploaded to your own account.</p>
              ) : (
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Share with client (optional)</label>
                  <SearchCombobox<ClientOption>
                    value={uploadClientId}
                    items={clients}
                    onSelect={setUploadClientId}
                    getId={(c) => c.id}
                    filterItem={(c, q) =>
                      (c.company_name ?? "").toLowerCase().includes(q) ||
                      (c.legal_name ?? "").toLowerCase().includes(q) ||
                      (c.client_code ?? "").toLowerCase().includes(q) ||
                      (c.contact_name ?? "").toLowerCase().includes(q)
                    }
                    renderSelected={clientDisplayName}
                    placeholder="Search client name or code… (leave blank for internal only)"
                    renderOption={(c) => (
                      <span className="flex flex-col gap-0.5 min-w-0">
                        <span className="flex items-baseline gap-2">
                          <span className="font-mono text-[10px] text-gold flex-shrink-0">{c.client_code}</span>
                          <span className="truncate font-medium">{c.company_name ?? c.legal_name}</span>
                          {c.status && c.status !== "Active" && (
                            <Badge variant="outline" className="text-[9px] flex-shrink-0">{c.status}</Badge>
                          )}
                        </span>
                        <span className="text-[11px] text-muted-foreground truncate">
                          {[c.entity_type, c.primary_jurisdiction, c.contact_name].filter(Boolean).join(" · ") || "—"}
                        </span>
                      </span>
                    )}
                  />
                  <p className="text-[11px] text-muted-foreground mt-1">Leave blank to keep this file internal-only.</p>
                </div>
              )}
            </div>

            <div className="flex gap-2 mt-5">
              <Button className="flex-1" onClick={submitUpload} disabled={uploading || !pendingFile}>
                {uploading ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : <Upload className="h-4 w-4 mr-2" />}
                {uploading ? "Uploading…" : "Upload"}
              </Button>
              <Button variant="outline" onClick={() => setShowUploadModal(false)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {banner && (
        <div className={`mx-8 mb-2 rounded-md border px-4 py-2 text-sm ${
          banner.kind === "ok"
            ? "border-green-500/40 bg-green-500/10 text-green-400"
            : "border-red-500/40 bg-red-500/10 text-red-400"
        }`}>
          {banner.text}
        </div>
      )}

      <div className="px-8 py-6 flex gap-6">
        {/* Sidebar */}
        <aside className="w-56 flex-shrink-0">
          <Card className="border-border">
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">
                Folders
              </CardTitle>
            </CardHeader>
            <CardContent className="p-2 pt-0">
              {FOLDERS.map((folder) => (
                <button
                  key={folder}
                  onClick={() => setActiveFolder(folder)}
                  className={`w-full flex items-center justify-between rounded-md px-3 py-2 text-sm transition-colors ${
                    activeFolder === folder
                      ? "bg-gold/10 text-gold font-medium"
                      : "text-foreground hover:bg-muted/50"
                  }`}
                >
                  <span className="flex items-center gap-2">
                    <FolderOpen className="h-4 w-4 flex-shrink-0" />
                    {folder}
                  </span>
                  <span className="text-xs text-muted-foreground font-mono">
                    {folderCount(folder)}
                  </span>
                </button>
              ))}
            </CardContent>
          </Card>
        </aside>

        {/* Main area */}
        <div className="flex-1 min-w-0 space-y-4">
          <div className="flex items-center gap-3">
            <div className="relative flex-1 max-w-sm">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search files…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-9"
              />
            </div>
            <span className="text-sm text-muted-foreground">
              {filtered.length} file{filtered.length !== 1 ? "s" : ""}
            </span>
          </div>

          <Card className="border-border">
            <CardContent className="p-0">
              {loading ? (
                <div className="flex items-center justify-center py-20">
                  <Loader2 className="h-8 w-8 animate-spin text-gold" />
                </div>
              ) : filtered.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-20 text-center">
                  <FolderOpen className="h-12 w-12 text-muted-foreground mb-4" />
                  <p className="text-muted-foreground text-sm">No documents found in this folder</p>
                  <p className="text-xs text-muted-foreground mt-1">Upload a file or choose another folder</p>
                </div>
              ) : (
                <table className="w-full text-sm">
                  <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                      <th className="px-4 py-3 text-left">Name</th>
                      <th className="px-4 py-3 text-left">Folder</th>
                      <th className="px-4 py-3 text-left">Modified</th>
                      <th className="px-4 py-3 text-left">Size</th>
                      <th className="px-4 py-3 text-right">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((file) => (
                      <tr key={file.path} className="border-t border-border hover:bg-muted/30">
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <FileIcon name={file.name} />
                            <span className="font-medium truncate max-w-[280px]" title={file.name}>
                              {file.name}
                            </span>
                            <Badge variant="outline" className="text-[10px] font-mono ml-1 flex-shrink-0">
                              {ext(file.name).toUpperCase()}
                            </Badge>
                          </div>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground">{file.folder}</td>
                        <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{fmtModified(file.modified)}</td>
                        <td className="px-4 py-3 text-muted-foreground font-mono text-xs">{fmtSize(file.size)}</td>
                        <td className="px-4 py-3">
                          <div className="flex items-center justify-end gap-1">
                            <Button
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 p-0"
                              title="Download"
                              onClick={() => handleDownload(file)}
                            >
                              <Download className="h-4 w-4 text-muted-foreground" />
                            </Button>
                            {canDelete && (
                              <Button
                                variant="ghost"
                                size="sm"
                                className="h-8 w-8 p-0"
                                title="Delete"
                                onClick={() => handleDelete(file)}
                              >
                                <Trash2 className="h-4 w-4 text-red-400" />
                              </Button>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </AppLayout>
  );
}
