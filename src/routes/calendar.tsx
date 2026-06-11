import { createFileRoute } from "@tanstack/react-router";
import { PageHeader } from "@/components/page-header";
import { StatCard } from "@/components/stat-card";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";

export const Route = createFileRoute("/calendar")({
  head: () => ({ meta: [{ title: "Calendar — IPFlow" }] }),
  component: Page,
});

function Page() {
  return (
    <div>
      <PageHeader eyebrow="Module 20" title="Calendar & Scheduling" description="Day / Week / Month / Agenda views with timezone handling." />
      <div className="px-8 py-6">
        <Card><CardContent className="p-4">
          <div className="grid grid-cols-7 gap-px bg-border rounded-md overflow-hidden">{
            ["Mon","Tue","Wed","Thu","Fri","Sat","Sun"].map((d) => (
              <div key={d} className="bg-muted/50 px-3 py-2 text-xs uppercase tracking-wider text-muted-foreground">{d}</div>
            ))
          }{
            Array.from({length: 35}).map((_, i) => {
              const day = i - 2;
              const inMonth = day > 0 && day <= 30;
              const events = [3, 9, 12, 15, 22, 28].includes(day);
              return (<div key={i} className="bg-card min-h-[88px] p-2">
                <div className={"text-xs " + (inMonth ? "text-foreground" : "text-muted-foreground/40")}>{inMonth ? day : ""}</div>
                {events && <div className="mt-1 rounded bg-gold/15 text-gold text-[10px] px-1.5 py-0.5 truncate">Deadline · M-205{day % 9}</div>}
              </div>);
            })
          }</div>
        </CardContent></Card>
      </div>
    </div>
  );
}
