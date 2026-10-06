import { useCallback, useEffect, useRef, useState } from "react";

declare global {
  interface Window {
    turnstile?: {
      render: (container: HTMLElement, options: Record<string, unknown>) => string;
      reset: (widgetId: string) => void;
      remove: (widgetId: string) => void;
    };
  }
}

const TURNSTILE_SCRIPT_SRC = "https://challenges.cloudflare.com/turnstile/v0/api.js";

/**
 * Renders a Cloudflare Turnstile widget into `containerRef` when `siteKey` is set (blank means
 * Turnstile isn't configured — matches the backend's own graceful-degrade in
 * TurnstileVerificationService, so the widget just doesn't render and nothing is required).
 * Shared by Login/ForgotPassword/ResetPassword — mount `<div ref={containerRef} />` where the
 * widget should appear, read `token` into the form's `cf-turnstile-response` field, gate submit
 * on `solved`, and call `reset()` after a failed submit (each token is single-use).
 */
export function useTurnstile(siteKey: string | null) {
  const containerRef = useRef<HTMLDivElement>(null);
  const widgetId = useRef<string | null>(null);
  const [token, setToken] = useState("");
  const [solved, setSolved] = useState(false);

  useEffect(() => {
    if (!siteKey) return;

    function renderWidget() {
      if (!containerRef.current || !window.turnstile || widgetId.current) return;
      widgetId.current = window.turnstile.render(containerRef.current, {
        sitekey: siteKey,
        callback: (t: string) => { setToken(t); setSolved(true); },
        "expired-callback": () => { setToken(""); setSolved(false); },
        "error-callback": () => { setToken(""); setSolved(false); },
      });
    }

    if (window.turnstile) {
      renderWidget();
      return;
    }

    const existing = document.querySelector<HTMLScriptElement>(`script[src="${TURNSTILE_SCRIPT_SRC}"]`);
    if (existing) {
      existing.addEventListener("load", renderWidget);
      return () => existing.removeEventListener("load", renderWidget);
    }
    const script = document.createElement("script");
    script.src = TURNSTILE_SCRIPT_SRC;
    script.async = true;
    script.defer = true;
    script.addEventListener("load", renderWidget);
    document.head.appendChild(script);
  }, [siteKey]);

  const reset = useCallback(() => {
    if (widgetId.current && window.turnstile) {
      window.turnstile.reset(widgetId.current);
    }
    setToken("");
    setSolved(false);
  }, []);

  return { containerRef, token, solved: !siteKey || solved, reset };
}
