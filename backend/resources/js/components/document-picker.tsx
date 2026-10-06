import { useEffect, useState } from "react";
import { Paperclip, Upload, X, FileText, Loader2, Search } from "lucide-react";
import { api } from "@/lib/api-client";

export interface PickedDocument {
  id: number;
  name: string;
}

/**
 * Shared "attach documents" control for Tasks and Approvals — upload a new
 * file, or pick from documents already on the tagged case. Both actions feed
 * one selected list (chips), so it doesn't matter which tab a document came
 * from. "Select Existing" is scoped to `projectId` and shows a prompt state
 * until a case is tagged, rather than an unfiltered firm-wide list.
 */
export function DocumentPicker({
  projectId, clientId = null, folder, selected, onChange,
}: {
  projectId: number | null;
  clientId?: number | null;
  folder?: string;
  selected: PickedDocument[];
  onChange: (docs: PickedDocument[]) => void;
}) {
  const [tab, setTab] = useState<"upload" | "existing">("upload");
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState("");
  const [existing, setExisting] = useState<any[]>([]);
  const [loadingExisting, setLoadingExisting] = useState(false);
  const [search, setSearch] = useState("");

  useEffect(() => {
    if (tab !== "existing" || !projectId) return;
    setLoadingExisting(true);
    api.getDocuments({ project_id: projectId })
      .then((res: any) => setExisting(Array.isArray(res) ? res : []))
      .catch(() => setExisting([]))
      .finally(() => setLoadingExisting(false));
  }, [tab, projectId]);

  async function handleUpload(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = ""; // allow re-selecting the same file again later
    if (!file) return;
    setUploading(true);
    setUploadError("");
    try {
      const doc: any = await api.uploadDocument(file, folder, clientId, projectId ?? undefined);
      onChange([...selected, { id: doc.id, name: doc.name ?? file.name }]);
    } catch (err: any) {
      setUploadError(err.message || "Upload failed.");
    } finally {
      setUploading(false);
    }
  }

  function toggleExisting(doc: any) {
    const isSelected = selected.some((d) => d.id === doc.id);
    onChange(isSelected ? selected.filter((d) => d.id !== doc.id) : [...selected, { id: doc.id, name: doc.name }]);
  }

  function removeSelected(id: number) {
    onChange(selected.filter((d) => d.id !== id));
  }

  const filteredExisting = existing.filter((d: any) =>
    !search || (d.name ?? "").toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div>
      <label className="block text-xs text-muted-foreground mb-1">Documents</label>
      <div className="flex gap-1 border border-border rounded-lg p-1 bg-muted/30 mb-2">
        <button type="button" onClick={() => setTab("upload")}
          className={`flex-1 px-3 py-1.5 rounded text-xs font-medium transition-colors flex items-center justify-center gap-1 ${
            tab === "upload" ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"
          }`}>
          <Upload className="h-3 w-3" /> Upload New
        </button>
        <button type="button" onClick={() => setTab("existing")}
          className={`flex-1 px-3 py-1.5 rounded text-xs font-medium transition-colors flex items-center justify-center gap-1 ${
            tab === "existing" ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"
          }`}>
          <FileText className="h-3 w-3" /> Select Existing
        </button>
      </div>

      {tab === "upload" ? (
        <div>
          <label className="flex items-center justify-center gap-2 h-9 rounded-md border border-dashed border-border text-xs text-muted-foreground hover:border-gold/50 hover:text-foreground cursor-pointer transition-colors">
            {uploading ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Upload className="h-3.5 w-3.5" />}
            {uploading ? "Uploading…" : "Choose a file to upload"}
            <input type="file" className="hidden" onChange={handleUpload} disabled={uploading} />
          </label>
          {uploadError && <p className="text-[11px] text-destructive mt-1">{uploadError}</p>}
        </div>
      ) : !projectId ? (
        <p className="text-xs text-muted-foreground text-center py-3 border border-dashed border-border rounded-md">
          Tag a case above to browse its documents.
        </p>
      ) : (
        <div className="border border-border rounded-md">
          <div className="relative p-1.5 border-b border-border">
            <Search className="absolute left-4 top-1/2 -translate-y-1/2 h-3 w-3 text-muted-foreground" />
            <input type="text" placeholder="Filter documents…" value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full h-7 pl-7 pr-2 rounded border border-border bg-background text-xs focus:outline-none focus:ring-1 focus:ring-gold" />
          </div>
          <div className="max-h-40 overflow-y-auto">
            {loadingExisting ? (
              <div className="flex items-center justify-center py-4"><Loader2 className="h-4 w-4 animate-spin text-muted-foreground" /></div>
            ) : filteredExisting.length === 0 ? (
              <p className="text-xs text-muted-foreground text-center py-3">No documents on this case yet.</p>
            ) : filteredExisting.map((d: any) => {
              const isSelected = selected.some((s) => s.id === d.id);
              return (
                <button key={d.id} type="button" onClick={() => toggleExisting(d)}
                  className={`w-full text-left px-3 py-1.5 text-xs flex items-center gap-2 hover:bg-muted/40 ${isSelected ? "bg-gold/10 text-gold" : ""}`}>
                  <FileText className="h-3 w-3 flex-shrink-0" />
                  <span className="truncate flex-1">{d.name}</span>
                  {isSelected && <span className="text-[10px] flex-shrink-0">Selected</span>}
                </button>
              );
            })}
          </div>
        </div>
      )}

      {selected.length > 0 && (
        <div className="flex flex-wrap gap-1.5 mt-2">
          {selected.map((d) => (
            <span key={d.id} className="flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px]">
              <Paperclip className="h-2.5 w-2.5" />
              <span className="max-w-[140px] truncate">{d.name}</span>
              <button type="button" onClick={() => removeSelected(d.id)}><X className="h-2.5 w-2.5" /></button>
            </span>
          ))}
        </div>
      )}
    </div>
  );
}
