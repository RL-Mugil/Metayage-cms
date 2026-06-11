import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/hrms/attendance")({
  head: () => ({ meta: [{ title: "Attendance — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="HRMS · 28.3" title="Attendance & Time" description="Web check-in, biometric, mobile geo-fence, IP-restricted." />
      <div className="px-8 py-6 space-y-6">
        <div className="grid gap-4 md:grid-cols-4">
          <StatCard label="Present" value="32" accent="primary" />
          <StatCard label="Late arrivals" value="3" accent="gold" />
          <StatCard label="WFH" value="6" accent="info" />
          <StatCard label="Overtime (week)" value="14h" accent="success" />
        </div>
        <Card><CardHeader><CardTitle className="font-display">Today's register</CardTitle></CardHeader>
          <CardContent className="p-0"><table className="w-full text-sm">
            <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground"><tr>
              <th className="px-4 py-2 text-left">Employee</th><th className="px-4 py-2 text-left">In</th><th className="px-4 py-2 text-left">Out</th>
              <th className="px-4 py-2 text-left">Hours</th><th className="px-4 py-2 text-left">Mode</th><th className="px-4 py-2 text-left">Status</th>
            </tr></thead><tbody>{[
              ["Anika Mehra", "09:02", "—", "—", "Web", "Present"],
              ["Ravi Nair", "09:14", "—", "—", "Mobile", "Present"],
              ["K. Suresh", "08:55", "—", "—", "Biometric", "Present"],
              ["Maya Bhat", "—", "—", "—", "—", "Leave"],
              ["Aarav Khanna", "09:46", "—", "—", "Web", "Late"],
            ].map((r, i) => (
              <tr key={i} className="border-t border-border hover:bg-muted/30">{r.map((c, j) => (<td key={j} className="px-4 py-3 font-mono text-xs">{c}</td>))}</tr>
            ))}</tbody></table></CardContent></Card>
      </div>
    </div>
  );
}
