import { Head, router, usePage } from "@inertiajs/react";
import { useState } from "react";
import { Check, Eye, Loader2, Plug, ShieldCheck, X } from "lucide-react";
import { Button } from "@/components/ui/button";

interface Props {
  error: string | null;
  client: { name: string; redirect_host: string | null } | null;
  user: { name: string; email: string };
  params: Record<string, string | undefined>;
  errors?: { oauth?: string };
  [key: string]: unknown;
}

const CAN = [
  "Read the clients, cases, deadlines, compliance items, tasks and documents you can see in the portal",
  "Read billing, invoices and pending payments only where your role allows",
  "Make Reports-tab reports (PDF / Excel / CSV) as drafts in your Reports history, with 30-minute download links",
  "Sign a report as the firm signatory — only when you give the signatory PIN",
];
const CANNOT = [
  "Create, edit or delete clients, cases, invoices or any other portal record",
  "See data outside your own access",
  "See payslips, salaries, bank details or passwords",
];

/** Claude connector sign-in: the user approves read-only access for Claude (OAuthController). */
export default function OAuthConsent() {
  const { props } = usePage<Props>();
  const [busy, setBusy] = useState<"approve" | "deny" | null>(null);
  const error = props.error ?? props.errors?.oauth ?? null;

  const decide = (decision: "approve" | "deny") => {
    setBusy(decision);
    router.post("/oauth/authorize", { ...props.params, decision }, { onFinish: () => setBusy(null) });
  };

  return (
    <>
      <Head title="Connect Claude" />
      <div className="flex min-h-screen w-full items-center justify-center bg-background px-4 py-10">
        <div className="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-xl">
          <div className="mb-5 flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-xl border border-gold/30 bg-gold/10"><Plug className="h-5 w-5 text-gold" /></div>
            <div>
              <h1 className="font-display text-lg font-semibold">Connect {props.client?.name ?? "Claude"}</h1>
              <p className="text-xs text-muted-foreground">to the MyIPStrategy portal as {props.user.name}</p>
            </div>
          </div>

          {error ? (
            <div className="space-y-4">
              <div className="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm">{error}</div>
              <Button variant="outline" className="w-full" onClick={() => router.visit("/")}>Back to the portal</Button>
            </div>
          ) : (
            <div className="space-y-5">
              <div className="flex items-start gap-2 rounded-lg border border-gold/30 bg-gold/5 px-3 py-2 text-sm">
                <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-gold" />
                <span><strong>Read and report access.</strong> Claude will see exactly what you can see in the portal, and nothing more.</span>
              </div>
              <div>
                <div className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground"><Eye className="h-3.5 w-3.5" /> Claude can</div>
                <ul className="space-y-1 text-sm">{CAN.map((t) => <li key={t} className="flex gap-2"><Check className="mt-0.5 h-4 w-4 shrink-0 text-green-600" />{t}</li>)}</ul>
              </div>
              <div>
                <div className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Claude cannot</div>
                <ul className="space-y-1 text-sm">{CANNOT.map((t) => <li key={t} className="flex gap-2"><X className="mt-0.5 h-4 w-4 shrink-0 text-red-500" />{t}</li>)}</ul>
              </div>
              <p className="text-xs text-muted-foreground">
                You'll return to {props.client?.redirect_host ?? "Claude"}. Every lookup is recorded in the audit log, and you can disconnect any time in Settings → Profile → Connected apps.
              </p>
              <div className="flex gap-2">
                <Button variant="outline" className="flex-1" disabled={busy !== null} onClick={() => decide("deny")}>
                  {busy === "deny" ? <Loader2 className="h-4 w-4 animate-spin" /> : "Deny"}
                </Button>
                <Button className="flex-1 bg-gold text-black hover:bg-gold/90" disabled={busy !== null} onClick={() => decide("approve")}>
                  {busy === "approve" ? <Loader2 className="h-4 w-4 animate-spin" /> : "Allow access"}
                </Button>
              </div>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
