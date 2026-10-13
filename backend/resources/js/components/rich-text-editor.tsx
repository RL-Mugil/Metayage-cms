import { useEffect, useRef, useState } from "react";
import {
  Bold, Italic, Underline, AlignLeft, AlignCenter, AlignRight, AlignJustify,
  List, ListOrdered, Link2, Undo2, Redo2, Eraser,
} from "lucide-react";

interface Props {
  /** Initial HTML. Changing `resetKey` reloads the editor from `value`. */
  value: string;
  resetKey: number;
  onChange: (html: string) => void;
}

const SWATCHES = ["#eef2f8", "#b4c0d4", "#f5b83d", "#7fa6d9", "#8fe3b0", "#ff9a9a"];

/**
 * Word-style editor for the public legal pages: block styles, bold / italic / underline,
 * text colour, alignment, lists and links. The canvas mimics the dark marketing site so the
 * colours an admin picks look the way visitors will see them. Output is sanitized again on the
 * server against an allow-list, so this component never needs to be trusted.
 */
export function RichTextEditor({ value, resetKey, onChange }: Props) {
  const ref = useRef<HTMLDivElement>(null);
  const savedRange = useRef<Range | null>(null);
  const [linkOpen, setLinkOpen] = useState(false);
  const [linkUrl, setLinkUrl] = useState("https://");
  const [color, setColor] = useState("#f5b83d");

  useEffect(() => {
    if (ref.current) ref.current.innerHTML = value;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resetKey]);

  const emit = () => { if (ref.current) onChange(ref.current.innerHTML); };

  const focusEditor = () => ref.current?.focus();

  const run = (command: string, arg?: string, css = false) => {
    focusEditor();
    document.execCommand("styleWithCSS", false, css ? "true" : "false");
    document.execCommand(command, false, arg);
    emit();
  };

  const rememberSelection = () => {
    const sel = window.getSelection();
    if (sel && sel.rangeCount > 0 && ref.current?.contains(sel.anchorNode)) {
      savedRange.current = sel.getRangeAt(0).cloneRange();
    }
  };

  const restoreSelection = () => {
    focusEditor();
    const sel = window.getSelection();
    if (sel && savedRange.current) { sel.removeAllRanges(); sel.addRange(savedRange.current); }
  };

  const applyLink = () => {
    restoreSelection();
    if (/^(https?:\/\/|mailto:|\/|#)/i.test(linkUrl.trim()) && linkUrl.trim() !== "https://") {
      run("createLink", linkUrl.trim());
    }
    setLinkOpen(false);
  };

  // Buttons must not steal focus from the editor, or the selection is lost.
  const keep = (e: React.MouseEvent) => e.preventDefault();

  const btn = "inline-flex h-8 w-8 items-center justify-center rounded-md text-foreground/80 hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold";
  const Tool = ({ label, onClick, children }: { label: string; onClick: () => void; children: React.ReactNode }) => (
    <button type="button" className={btn} aria-label={label} title={label} onMouseDown={keep} onClick={onClick}>{children}</button>
  );
  const Sep = () => <span className="mx-1 h-5 w-px bg-border" aria-hidden="true" />;

  return (
    <div className="rounded-lg border border-border overflow-hidden">
      <div className="flex flex-wrap items-center gap-0.5 border-b border-border bg-muted/40 p-1.5" role="toolbar" aria-label="Text formatting">
        <select
          aria-label="Paragraph style"
          className="h-8 rounded-md border border-border bg-background px-2 text-xs"
          defaultValue="p"
          onMouseDown={(e) => e.stopPropagation()}
          onChange={(e) => { run("formatBlock", e.target.value); e.target.value = "p"; }}
        >
          <option value="p">Paragraph</option>
          <option value="h1">Heading 1</option>
          <option value="h2">Heading 2</option>
          <option value="h3">Heading 3</option>
          <option value="blockquote">Quote</option>
        </select>
        <Sep />
        <Tool label="Bold" onClick={() => run("bold")}><Bold className="h-4 w-4" /></Tool>
        <Tool label="Italic" onClick={() => run("italic")}><Italic className="h-4 w-4" /></Tool>
        <Tool label="Underline" onClick={() => run("underline")}><Underline className="h-4 w-4" /></Tool>
        <Sep />
        <label className="flex h-8 items-center gap-1.5 rounded-md px-1.5 hover:bg-muted" title="Text colour" onMouseDown={rememberSelection}>
          <span className="text-xs font-semibold" style={{ color }}>A</span>
          <input
            type="color"
            value={color}
            aria-label="Text colour"
            className="h-5 w-6 cursor-pointer border-0 bg-transparent p-0"
            onChange={(e) => { setColor(e.target.value); restoreSelection(); run("foreColor", e.target.value, true); }}
          />
        </label>
        {SWATCHES.map((c) => (
          <button
            key={c} type="button" aria-label={`Colour ${c}`} title={c}
            className="h-5 w-5 rounded-full border border-border focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold"
            style={{ backgroundColor: c }}
            onMouseDown={keep}
            onClick={() => { setColor(c); run("foreColor", c, true); }}
          />
        ))}
        <Sep />
        <Tool label="Align left" onClick={() => run("justifyLeft", undefined, true)}><AlignLeft className="h-4 w-4" /></Tool>
        <Tool label="Align centre" onClick={() => run("justifyCenter", undefined, true)}><AlignCenter className="h-4 w-4" /></Tool>
        <Tool label="Align right" onClick={() => run("justifyRight", undefined, true)}><AlignRight className="h-4 w-4" /></Tool>
        <Tool label="Justify" onClick={() => run("justifyFull", undefined, true)}><AlignJustify className="h-4 w-4" /></Tool>
        <Sep />
        <Tool label="Bulleted list" onClick={() => run("insertUnorderedList")}><List className="h-4 w-4" /></Tool>
        <Tool label="Numbered list" onClick={() => run("insertOrderedList")}><ListOrdered className="h-4 w-4" /></Tool>
        <Tool label="Insert link" onClick={() => { rememberSelection(); setLinkOpen((o) => !o); }}><Link2 className="h-4 w-4" /></Tool>
        <Sep />
        <Tool label="Undo" onClick={() => run("undo")}><Undo2 className="h-4 w-4" /></Tool>
        <Tool label="Redo" onClick={() => run("redo")}><Redo2 className="h-4 w-4" /></Tool>
        <Tool label="Clear formatting" onClick={() => run("removeFormat")}><Eraser className="h-4 w-4" /></Tool>
      </div>

      {linkOpen && (
        <div className="flex items-center gap-2 border-b border-border bg-muted/20 p-2">
          <input
            value={linkUrl}
            onChange={(e) => setLinkUrl(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); applyLink(); } }}
            aria-label="Link address"
            placeholder="https://example.com"
            className="h-8 flex-1 rounded-md border border-border bg-background px-2 text-sm"
            autoFocus
          />
          <button type="button" className="h-8 rounded-md bg-gold px-3 text-xs font-semibold text-black" onClick={applyLink}>Apply</button>
          <button type="button" className="h-8 rounded-md px-3 text-xs hover:bg-muted" onClick={() => setLinkOpen(false)}>Cancel</button>
        </div>
      )}

      <div
        ref={ref}
        contentEditable
        suppressContentEditableWarning
        role="textbox"
        aria-multiline="true"
        aria-label="Page content"
        onInput={emit}
        onBlur={emit}
        className={
          "min-h-[420px] max-h-[640px] overflow-y-auto bg-[#0b1220] p-6 text-[15px] leading-relaxed text-[#b4c0d4] focus:outline-none " +
          "[&_h1]:mb-3 [&_h1]:text-3xl [&_h1]:font-bold [&_h1]:text-[#eef2f8] " +
          "[&_h2]:mb-2 [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-bold [&_h2]:text-[#eef2f8] " +
          "[&_h3]:mb-2 [&_h3]:mt-4 [&_h3]:text-lg [&_h3]:font-semibold [&_h3]:text-[#eef2f8] " +
          "[&_p]:mb-3 [&_ul]:mb-3 [&_ul]:list-disc [&_ul]:pl-6 [&_ol]:mb-3 [&_ol]:list-decimal [&_ol]:pl-6 [&_li]:mb-1 " +
          "[&_strong]:text-[#eef2f8] [&_a]:text-[#f5b83d] [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-[#f5b83d] [&_blockquote]:pl-4"
        }
      />
    </div>
  );
}
