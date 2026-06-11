import { Head, useForm } from "@inertiajs/react";
import { Lock, Mail, Loader2 } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";

interface Props {
  token: string;
  email: string;
}

export default function ResetPassword({ token, email }: Props) {
  const { data, setData, post, processing, errors } = useForm({
    token,
    email,
    password: "",
    password_confirmation: "",
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post("/reset-password");
  };

  return (
    <>
      <Head title="Set New Password" />
      <div className="flex min-h-screen w-full items-center justify-center bg-[#09090b] px-4 relative overflow-hidden">
        <div className="absolute top-[-10%] left-[-10%] h-[500px] w-[500px] rounded-full bg-primary/10 blur-[120px] pointer-events-none" />
        <div className="absolute bottom-[-10%] right-[-10%] h-[500px] w-[500px] rounded-full bg-gold/10 blur-[120px] pointer-events-none" />

        <div className="w-full max-w-md space-y-6 z-10">
          <div className="text-center space-y-2">
            <div className="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-tr from-primary to-gold text-primary-foreground font-display text-2xl font-bold shadow-lg shadow-primary/20">
              IP
            </div>
            <h1 className="text-2xl font-display font-semibold tracking-tight text-white mt-4">
              Set a new password
            </h1>
            <p className="text-sm text-zinc-400">Choose a strong password of at least 8 characters.</p>
          </div>

          <div className="rounded-2xl border border-zinc-800 bg-zinc-950/80 backdrop-blur-xl p-8 shadow-2xl space-y-6">
            <form onSubmit={handleSubmit} className="space-y-4">
              {(errors.email || errors.token) && (
                <div className="rounded-lg bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive">
                  {errors.email || errors.token}
                </div>
              )}

              <div className="space-y-1.5">
                <label className="text-xs font-medium text-zinc-300">Email Address</label>
                <div className="relative">
                  <Mail className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                  <Input
                    type="email"
                    required
                    value={data.email}
                    onChange={(e) => setData("email", e.target.value)}
                    className="pl-10 bg-zinc-900 border-zinc-800 text-white focus-visible:ring-gold"
                  />
                </div>
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-medium text-zinc-300">New Password</label>
                <div className="relative">
                  <Lock className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                  <Input
                    type="password"
                    required
                    placeholder="••••••••"
                    value={data.password}
                    onChange={(e) => setData("password", e.target.value)}
                    className="pl-10 bg-zinc-900 border-zinc-800 text-white focus-visible:ring-gold"
                  />
                </div>
                {errors.password && <p className="text-xs text-destructive">{errors.password}</p>}
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-medium text-zinc-300">Confirm New Password</label>
                <div className="relative">
                  <Lock className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                  <Input
                    type="password"
                    required
                    placeholder="••••••••"
                    value={data.password_confirmation}
                    onChange={(e) => setData("password_confirmation", e.target.value)}
                    className="pl-10 bg-zinc-900 border-zinc-800 text-white focus-visible:ring-gold"
                  />
                </div>
              </div>

              <Button
                type="submit"
                disabled={processing}
                className="w-full bg-gradient-to-r from-primary to-gold text-primary-foreground font-medium py-6 rounded-lg transition-transform active:scale-[0.98]"
              >
                {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : "Reset Password"}
              </Button>
            </form>
          </div>
        </div>
      </div>
    </>
  );
}
