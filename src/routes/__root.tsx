import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  Outlet, Link, createRootRouteWithContext, useRouter, HeadContent, Scripts,
} from "@tanstack/react-router";
import { useEffect, type ReactNode } from "react";
import { Search, Bell, HelpCircle } from "lucide-react";

import appCss from "../styles.css?url";
import { reportLovableError } from "../lib/lovable-error-reporting";
import { SidebarProvider, SidebarTrigger } from "@/components/ui/sidebar";
import { AppSidebar } from "@/components/app-sidebar";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";

function NotFoundComponent() {
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="max-w-md text-center">
        <h1 className="font-display text-7xl font-bold text-foreground">404</h1>
        <h2 className="mt-4 font-display text-xl font-semibold text-foreground">Page not found</h2>
        <p className="mt-2 text-sm text-muted-foreground">The page you're looking for doesn't exist.</p>
        <div className="mt-6">
          <Link to="/" className="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">
            Back to Dashboard
          </Link>
        </div>
      </div>
    </div>
  );
}

function ErrorComponent({ error, reset }: { error: Error; reset: () => void }) {
  console.error(error);
  const router = useRouter();
  useEffect(() => { reportLovableError(error, { boundary: "tanstack_root_error_component" }); }, [error]);
  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="max-w-md text-center">
        <h1 className="font-display text-xl font-semibold">Something went wrong</h1>
        <p className="mt-2 text-sm text-muted-foreground">Please try again or return to the dashboard.</p>
        <div className="mt-6 flex justify-center gap-2">
          <button onClick={() => { router.invalidate(); reset(); }}
            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">
            Try again
          </button>
          <a href="/" className="rounded-md border border-input bg-background px-4 py-2 text-sm font-medium hover:bg-accent">Home</a>
        </div>
      </div>
    </div>
  );
}

export const Route = createRootRouteWithContext<{ queryClient: QueryClient }>()({
  head: () => ({
    meta: [
      { charSet: "utf-8" },
      { name: "viewport", content: "width=device-width, initial-scale=1" },
      { title: "IPFlow — Enterprise IP Management" },
      { name: "description", content: "Metayage's internal platform for client, matter, financial, and HR operations." },
    ],
    links: [
      { rel: "stylesheet", href: appCss },
      { rel: "preconnect", href: "https://fonts.googleapis.com" },
      { rel: "preconnect", href: "https://fonts.gstatic.com", crossOrigin: "anonymous" },
      { rel: "stylesheet", href: "https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" },
    ],
  }),
  shellComponent: RootShell,
  component: RootComponent,
  notFoundComponent: NotFoundComponent,
  errorComponent: ErrorComponent,
});

function RootShell({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <head><HeadContent /></head>
      <body>{children}<Scripts /></body>
    </html>
  );
}

import { useState } from "react";
import { api } from "@/lib/api-client";
import { Lock, Mail, Loader2, Sparkles } from "lucide-react";

function RootComponent() {
  const { queryClient } = Route.useRouteContext();
  const [token, setTokenState] = useState<string | null>(() => api.getToken());
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    try {
      const data = await api.login(email, password);
      setTokenState(data.access_token);
    } catch (err: any) {
      setError(err.message || "Failed to log in. Please check your credentials.");
    } finally {
      setLoading(false);
    }
  };

  const handleQuickFill = (quickEmail: string) => {
    setEmail(quickEmail);
    setPassword("password123");
    if (quickEmail === "admin@ipflow.com") {
      setPassword("admin123");
    } else if (quickEmail === "priya@helios.com") {
      setPassword("client123");
    }
  };

  if (!token) {
    return (
      <QueryClientProvider client={queryClient}>
        <div className="flex min-h-screen w-full items-center justify-center bg-[#09090b] px-4 relative overflow-hidden">
          {/* Background Ambient Glows */}
          <div className="absolute top-[-10%] left-[-10%] h-[500px] w-[500px] rounded-full bg-primary/10 blur-[120px] pointer-events-none" />
          <div className="absolute bottom-[-10%] right-[-10%] h-[500px] w-[500px] rounded-full bg-gold/10 blur-[120px] pointer-events-none" />
          
          <div className="w-full max-w-md space-y-6 z-10">
            <div className="text-center space-y-2">
              <div className="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-tr from-primary to-gold text-primary-foreground font-display text-2xl font-bold shadow-lg shadow-primary/20">
                IP
              </div>
              <h1 className="text-3xl font-display font-semibold tracking-tight text-white mt-4">
                Welcome back to IPFlow
              </h1>
              <p className="text-sm text-zinc-400">
                Enterprise IP Practice & Operations Platform
              </p>
            </div>

            <div className="rounded-2xl border border-zinc-800 bg-zinc-950/80 backdrop-blur-xl p-8 shadow-2xl space-y-6">
              <form onSubmit={handleLogin} className="space-y-4">
                {error && (
                  <div className="rounded-lg bg-destructive/15 border border-destructive/30 p-3 text-xs text-destructive">
                    {error}
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
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      className="pl-10 bg-zinc-900 border-zinc-800 text-white focus-visible:ring-gold"
                    />
                  </div>
                </div>

                <div className="space-y-1.5">
                  <div className="flex items-center justify-between">
                    <label className="text-xs font-medium text-zinc-300">Password</label>
                    <a href="#" className="text-xs text-gold hover:underline">Forgot?</a>
                  </div>
                  <div className="relative">
                    <Lock className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-500" />
                    <Input
                      type="password"
                      required
                      placeholder="••••••••"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      className="pl-10 bg-zinc-900 border-zinc-800 text-white focus-visible:ring-gold"
                    />
                  </div>
                </div>

                <Button type="submit" disabled={loading} className="w-full bg-gradient-to-r from-primary to-gold text-primary-foreground font-medium py-6 rounded-lg transition-transform active:scale-[0.98]">
                  {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : "Sign In to Workspace"}
                </Button>
              </form>

              <div className="relative flex py-2 items-center">
                <div className="flex-grow border-t border-zinc-800"></div>
                <span className="flex-shrink mx-4 text-[10px] text-zinc-500 uppercase tracking-widest font-semibold">Quick Switch Roles</span>
                <div className="flex-grow border-t border-zinc-800"></div>
              </div>

              <div className="grid grid-cols-2 gap-2 text-xs">
                <button
                  onClick={() => handleQuickFill("suresh@metayage.com")}
                  className="p-2.5 rounded-lg border border-zinc-800 bg-zinc-900/40 hover:bg-zinc-900 text-zinc-300 hover:text-white transition-colors text-left flex flex-col justify-between"
                >
                  <span className="font-semibold text-gold">Partner</span>
                  <span className="text-[10px] text-zinc-500 truncate">suresh@metayage.com</span>
                </button>
                <button
                  onClick={() => handleQuickFill("anika@metayage.com")}
                  className="p-2.5 rounded-lg border border-zinc-800 bg-zinc-900/40 hover:bg-zinc-900 text-zinc-300 hover:text-white transition-colors text-left flex flex-col justify-between"
                >
                  <span className="font-semibold text-primary">Manager</span>
                  <span className="text-[10px] text-zinc-500 truncate">anika@metayage.com</span>
                </button>
                <button
                  onClick={() => handleQuickFill("admin@ipflow.com")}
                  className="p-2.5 rounded-lg border border-zinc-800 bg-zinc-900/40 hover:bg-zinc-900 text-zinc-300 hover:text-white transition-colors text-left flex flex-col justify-between"
                >
                  <span className="font-semibold text-emerald-400">Super Admin</span>
                  <span className="text-[10px] text-zinc-500 truncate">admin@ipflow.com</span>
                </button>
                <button
                  onClick={() => handleQuickFill("priya@helios.com")}
                  className="p-2.5 rounded-lg border border-zinc-800 bg-zinc-900/40 hover:bg-zinc-900 text-zinc-300 hover:text-white transition-colors text-left flex flex-col justify-between"
                >
                  <span className="font-semibold text-indigo-400">Client Contact</span>
                  <span className="text-[10px] text-zinc-500 truncate">priya@helios.com</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </QueryClientProvider>
    );
  }

  return (
    <QueryClientProvider client={queryClient}>
      <SidebarProvider>
        <div className="flex min-h-screen w-full bg-background">
          <AppSidebar />
          <div className="flex flex-1 flex-col min-w-0">
            <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-border bg-background/85 px-4 backdrop-blur">
              <SidebarTrigger />
              <div className="relative max-w-md flex-1">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input placeholder="Search matters, clients, documents…" className="h-9 pl-9 bg-secondary/60 border-transparent focus-visible:bg-background" />
              </div>
              <div className="ml-auto flex items-center gap-2">
                <Badge variant="outline" className="hidden md:inline-flex border-gold/40 text-gold">Production · v2.0</Badge>
                <Button variant="ghost" size="icon"><HelpCircle className="h-4 w-4" /></Button>
                <Button variant="ghost" size="icon" className="relative">
                  <Bell className="h-4 w-4" />
                  <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-gold" />
                </Button>
              </div>
            </header>
            <main className="flex-1 min-w-0">
              <Outlet />
            </main>
          </div>
        </div>
      </SidebarProvider>
    </QueryClientProvider>
  );
}
