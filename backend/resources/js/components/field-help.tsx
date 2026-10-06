import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/** Small "?" affordance placed next to a field label — hover to see what the field means. */
export function FieldHelp({ text }: { text: string }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <button
          type="button"
          tabIndex={-1}
          className="inline-flex items-center justify-center w-3.5 h-3.5 rounded-full border border-muted-foreground/40 text-muted-foreground/70 text-[9px] ml-1 align-middle hover:border-gold hover:text-gold cursor-help"
        >
          ?
        </button>
      </TooltipTrigger>
      <TooltipContent className="max-w-64 text-xs leading-relaxed">{text}</TooltipContent>
    </Tooltip>
  );
}
