import { Head, useForm, usePage, Link } from "@inertiajs/react";
import { Mail, Loader2, ArrowLeft } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";

export default function ForgotPassword() {
  const { props } = usePage() as any;
  const status = props.flash?.success;
  const { data, setData, post, processing, errors } = useForm({ email: "" });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post("/forgot-password");
  };

  return (
    <>
      <Head title="Forgot Password" />
      <div className="flex min-h-screen w-full items-center justify-center bg-[#09090b] px-4 relative overflow-hidden">
        <div className="absolute top-[-10%] left-[-10%] h-[500px] w-[500px] rounded-full bg-primary/10 blur-[120px] pointer-events-none" />
        <div className="absolute bottom-[-10%] right-[-10%] h-[500px] w-[500px] rounded-full bg-gold/10 blur-[120px] pointer-events-none" />

        <div className="w-full max-w-md space-y-6 z-10">
          <div className="text-center space-y-2">
            <div className="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-tr from-primary to-gold text-primary-foreground font-display text-2xl font-bold shadow-lg shadow-primary/20">
              IP
            </div>
            <h1 className="text-2xl font-display font-semibold tracking-tight text-white mt-4">
              Reset your password
            </h1>
            <p className="text-sm text-zinc-400">We'll email you a secure reset link.</p>
          </div>

          <div className="rounded-2xl border border-zinc-800 bg-zinc-950/80 backdrop-blur-xl p-8 shadow-2xl space-y-6">
            {status && (
              <div className="rounded-lg bg-emerald-500/15 border border-emerald-500/30 p-3 text-xs text-emerald-400">
                {status}
              </div>
            )}
            <form onSubmit={handleSubmit} className="space-y-4">
              {errors.email && (
                <div className="rounded-lg bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive">
                  {errors.email}
                </div>
              )}
              <div className="space-y-1.5">
                <label className="text-xs font-medium text-zinc-300">Email Address</label>
                <div className="relative">
                  <Mail className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                  <Input
                    type="email"
                    required
                    placeholder="name@metayage.com"
                    value={data.email}
                    onChange={(e) => setData("email", e.target.value)}
                    className="pl-10 bg-zinc-900 border-zinc-800 text-white focus-visible:ring-gold"
                  />
                </div>
              </div>
              <Button
                type="submit"
                disabled={processing}
                className="w-full bg-gradient-to-r from-primary to-gold text-primary-foreground font-medium py-6 rounded-lg transition-transform active:scale-[0.98]"
              >
                {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : "Send Reset Link"}
              </Button>
            </form>

            <Link href="/login" className="flex items-center justify-center gap-1.5 text-xs text-zinc-400 hover:text-white transition-colors">
              <ArrowLeft className="h-3 w-3" /> Back to sign in
            </Link>
          </div>
        </div>
      </div>
    </>
  );
}
