import * as React from "react";

import { cn } from "@/lib/utils";

const Textarea = React.forwardRef<HTMLTextAreaElement, React.ComponentProps<"textarea">>(
  ({ className, ...props }, ref) => {
    return (
      <textarea
        className={cn(
          "flex min-h-[60px] w-full rounded-md border border-input bg-background/70 px-3 py-2 text-base shadow-[inset_0_1px_2px_rgb(15_23_42/0.07)] transition-[border-color,box-shadow] placeholder:text-muted-foreground focus-visible:outline-none focus-visible:border-gold/60 focus-visible:ring-2 focus-visible:ring-gold/25 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
          className,
        )}
        ref={ref}
        {...props}
      />
    );
  },
);
Textarea.displayName = "Textarea";

export { Textarea };
