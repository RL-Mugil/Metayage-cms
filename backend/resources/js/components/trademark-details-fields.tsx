import { useEffect, useMemo, useRef, useState } from "react"
import { AlertTriangle, ImagePlus, Loader2, Upload, X } from "lucide-react"
import { api } from "@/lib/api-client"
import {
  MARK_IMAGE_ACCEPT, MARK_IMAGE_MAX_BYTES, MARK_TYPES, NICE_CLASSES,
  isTrademarkFilingCode, markTypeNeedsImage,
  type MarkType, type TrademarkDetails, type TrademarkInput,
} from "@/types/trademark"

const inputCls = "w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"

function Label({ children, req }: { children: React.ReactNode; req?: boolean }) {
  return (
    <div className="text-[10px] text-muted-foreground mb-0.5">
      {children}{req && <span className="text-destructive ml-0.5">*</span>}
    </div>
  )
}

/** Renders an access-checked document image via an object URL. */
export function TrademarkMarkImage({ path, alt, className }: { path: string; alt: string; className?: string }) {
  const [src, setSrc] = useState<string | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let url: string | null = null
    let cancelled = false
    setFailed(false)
    api.documentObjectUrl(path)
      .then((objectUrl) => {
        url = objectUrl
        if (cancelled) URL.revokeObjectURL(objectUrl)
        else setSrc(objectUrl)
      })
      .catch(() => { if (!cancelled) setFailed(true) })
    return () => {
      cancelled = true
      if (url) URL.revokeObjectURL(url)
    }
  }, [path])

  if (failed) return <div className={`${className ?? ""} flex items-center justify-center text-[10px] text-muted-foreground`}>Image unavailable</div>
  if (!src) return <div className={`${className ?? ""} flex items-center justify-center`}><Loader2 className="h-4 w-4 animate-spin text-muted-foreground" /></div>
  return <img src={src} alt={alt} className={`${className ?? ""} object-contain`} />
}

function useFilePreview(file: File | null): string | null {
  const url = useMemo(() => (file ? URL.createObjectURL(file) : null), [file])
  useEffect(() => () => { if (url) URL.revokeObjectURL(url) }, [url])
  return url
}

/** Returns an error message for an unacceptable logo file, or null. */
export function markImageError(file: File): string | null {
  if (!MARK_IMAGE_ACCEPT.split(",").includes(file.type)) return "Logo must be a JPG or PNG image."
  if (file.size > MARK_IMAGE_MAX_BYTES) return "Logo must be 5 MB or smaller."
  return null
}

interface FieldsProps {
  serviceCode: string
  value: TrademarkInput
  onChange: (value: TrademarkInput) => void
  file: File | null
  onFileChange: (file: File | null) => void
  existingImagePath?: string | null
}

/** Mark name, mark type, Nice classes and logo upload for the New / Edit Case form. */
export function TrademarkDetailsFields({ serviceCode, value, onChange, file, onFileChange, existingImagePath }: FieldsProps) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [fileError, setFileError] = useState<string | null>(null)
  const [classQuery, setClassQuery] = useState("")
  const preview = useFilePreview(file)
  const required = isTrademarkFilingCode(serviceCode)
  const needsImage = markTypeNeedsImage(value.mark_type)
  const svc = serviceCode.toUpperCase()
  const singleClass = svc === "TMF"

  const visibleClasses = useMemo(() => {
    const q = classQuery.trim().toLowerCase()
    if (!q) return NICE_CLASSES
    return NICE_CLASSES.filter((c) => String(c.id) === q || c.label.toLowerCase().includes(q))
  }, [classQuery])

  function toggleClass(id: number) {
    const selected = value.nice_classes.includes(id)
    const next = selected
      ? value.nice_classes.filter((c) => c !== id)
      : singleClass ? [id] : [...value.nice_classes, id].sort((a, b) => a - b)
    onChange({ ...value, nice_classes: next })
  }

  function pickFile(next: File | null) {
    if (next) {
      const err = markImageError(next)
      setFileError(err)
      if (err) return
    } else {
      setFileError(null)
    }
    onFileChange(next)
  }

  return (
    <div className="grid grid-cols-[1fr_140px] gap-3">
      <div className="space-y-2">
        <div className="grid grid-cols-2 gap-2">
          <div>
            <Label req={required}>Mark name / text</Label>
            <input value={value.mark_text} maxLength={255}
              onChange={(e) => onChange({ ...value, mark_text: e.target.value })}
              placeholder="e.g. MYPL" className={inputCls} />
          </div>
          <div>
            <Label req={required}>Mark type</Label>
            <select value={value.mark_type}
              onChange={(e) => onChange({ ...value, mark_type: e.target.value as MarkType | "" })}
              className={inputCls}>
              <option value="">Select…</option>
              {MARK_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </div>
        </div>

        <div>
          <div className="flex items-center justify-between">
            <Label req={required}>
              Nice class{singleClass ? "" : "es"}
              {svc === "TMF" && <span className="ml-1 opacity-70">(one class — use TMC for several)</span>}
              {svc === "TMC" && <span className="ml-1 opacity-70">(two or more)</span>}
            </Label>
            <input value={classQuery} onChange={(e) => setClassQuery(e.target.value)}
              placeholder="Filter classes…"
              className="h-6 w-32 rounded border border-border bg-background px-1.5 text-[10px] focus:outline-none focus:ring-1 focus:ring-gold mb-0.5" />
          </div>
          {value.nice_classes.length > 0 && (
            <div className="flex flex-wrap gap-1 mb-1">
              {value.nice_classes.map((id) => (
                <button key={id} type="button" onClick={() => toggleClass(id)}
                  className="inline-flex items-center gap-1 rounded bg-gold/15 text-gold px-1.5 py-0.5 text-[10px] font-mono">
                  Class {id}<X className="h-2.5 w-2.5" />
                </button>
              ))}
            </div>
          )}
          <div className="max-h-28 overflow-y-auto rounded border border-border divide-y divide-border/50">
            {visibleClasses.map((c) => {
              const selected = value.nice_classes.includes(c.id)
              return (
                <label key={c.id} className={`flex items-center gap-2 px-2 py-1 text-[11px] cursor-pointer hover:bg-muted/50 ${selected ? "bg-gold/10" : ""}`}>
                  <input type={singleClass ? "radio" : "checkbox"} name="tm-nice-class" checked={selected}
                    onChange={() => toggleClass(c.id)} className="h-3 w-3 accent-[hsl(45_93%_47%)]" />
                  <span className="font-mono w-5 text-right text-muted-foreground">{c.id}</span>
                  <span>{c.label}</span>
                </label>
              )
            })}
            {visibleClasses.length === 0 && <div className="px-2 py-1.5 text-[11px] text-muted-foreground">No class matches.</div>}
          </div>
        </div>
      </div>

      <div>
        <Label req={needsImage}>Logo / mark image</Label>
        <button type="button" onClick={() => inputRef.current?.click()}
          onDragOver={(e) => e.preventDefault()}
          onDrop={(e) => { e.preventDefault(); pickFile(e.dataTransfer.files?.[0] ?? null) }}
          className={`relative flex h-[132px] w-full flex-col items-center justify-center gap-1 rounded border border-dashed text-[10px] text-muted-foreground hover:bg-muted/40 ${needsImage && !file && !existingImagePath ? "border-destructive/60" : "border-border"}`}>
          {preview ? (
            <img src={preview} alt="Selected mark" className="h-full w-full object-contain p-1" />
          ) : existingImagePath ? (
            <TrademarkMarkImage path={existingImagePath} alt="Current mark" className="h-full w-full p-1" />
          ) : (
            <>
              <ImagePlus className="h-5 w-5" />
              <span>Drop or click to upload</span>
              <span className="opacity-70">JPG / PNG · max 5 MB</span>
            </>
          )}
        </button>
        <input ref={inputRef} type="file" accept={MARK_IMAGE_ACCEPT} className="hidden"
          onChange={(e) => { pickFile(e.target.files?.[0] ?? null); e.target.value = "" }} />
        {file && (
          <button type="button" onClick={() => pickFile(null)} className="mt-1 text-[10px] text-muted-foreground hover:text-destructive">
            Remove {existingImagePath ? "replacement" : "image"}
          </button>
        )}
        {!file && existingImagePath && <div className="mt-1 text-[10px] text-muted-foreground">Click to replace</div>}
        {fileError && <div className="mt-1 text-[10px] text-destructive">{fileError}</div>}
        {!needsImage && value.mark_type && <div className="mt-1 text-[10px] text-muted-foreground">Optional for a {value.mark_type.toLowerCase()} mark.</div>}
      </div>
    </div>
  )
}

/** Compact read view + "Replace logo" for case detail views. Renders nothing for non-TM cases. */
export function TrademarkMarkPanel({ projectId, serviceCode, canEdit = true }: { projectId: number; serviceCode?: string | null; canEdit?: boolean }) {
  const [details, setDetails] = useState<TrademarkDetails | null>(null)
  const [loaded, setLoaded] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const inputRef = useRef<HTMLInputElement>(null)
  const isTrademark = (serviceCode ?? "").toUpperCase().startsWith("TM")

  useEffect(() => {
    if (!isTrademark) return
    let cancelled = false
    setLoaded(false)
    api.getProjectTrademark(projectId)
      .then((d) => { if (!cancelled) setDetails(d) })
      .catch(() => { if (!cancelled) setDetails(null) })
      .finally(() => { if (!cancelled) setLoaded(true) })
    return () => { cancelled = true }
  }, [projectId, isTrademark])

  async function upload(file: File | null) {
    if (!file) return
    const err = markImageError(file)
    if (err) { setError(err); return }
    setUploading(true); setError(null)
    try {
      setDetails(await api.uploadTrademarkMark(projectId, file))
    } catch (e) {
      setError(e instanceof Error ? e.message : "Logo upload failed")
    } finally {
      setUploading(false)
    }
  }

  if (!isTrademark || !loaded) return null
  const missingImage = !!details?.requires_image && !details.image_path

  return (
    <div className="flex gap-3 rounded-lg border border-border bg-card/50 p-3">
      <div className="h-20 w-20 shrink-0 rounded border border-border bg-background">
        {details?.image_path
          ? <TrademarkMarkImage path={details.image_path} alt={details.mark_text ?? "Mark"} className="h-full w-full p-1" />
          : <div className="flex h-full items-center justify-center text-[10px] text-muted-foreground">No logo</div>}
      </div>
      <div className="min-w-0 flex-1 space-y-1">
        <div className="text-[10px] uppercase tracking-widest text-muted-foreground">Trademark</div>
        <div className="truncate font-display text-sm font-semibold">{details?.mark_text || "Mark not recorded"}</div>
        <div className="text-[11px] text-muted-foreground">
          {details?.mark_type ?? "Type not set"}
          {details && details.nice_classes.length > 0 && <> · Class {details.nice_classes.join(", ")}</>}
        </div>
        {missingImage && (
          <div className="flex items-center gap-1 text-[11px] text-destructive">
            <AlertTriangle className="h-3 w-3" /> Logo missing — required before TM-A filing
          </div>
        )}
        {error && <div className="text-[11px] text-destructive">{error}</div>}
        {canEdit && (
          <>
            <button type="button" disabled={uploading} onClick={() => inputRef.current?.click()}
              className="inline-flex items-center gap-1 text-[11px] text-gold hover:underline disabled:opacity-50">
              {uploading ? <Loader2 className="h-3 w-3 animate-spin" /> : <Upload className="h-3 w-3" />}
              {details?.image_path ? "Replace logo" : "Upload logo"}
            </button>
            <input ref={inputRef} type="file" accept={MARK_IMAGE_ACCEPT} className="hidden"
              onChange={(e) => { void upload(e.target.files?.[0] ?? null); e.target.value = "" }} />
          </>
        )}
      </div>
    </div>
  )
}
