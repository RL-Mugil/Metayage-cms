import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { Loader2, Clock } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { api } from "@/lib/api-client";
import { fmtDate, fmtTime } from "@/lib/date-utils";

export default function HRMSAttendance() {
  const [logs, setLogs] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [clocking, setClocking] = useState(false);

  useEffect(() => {
    api.getAttendance().then((data) => {
      setLogs(data);
      setLoading(false);
    }).catch(() => setLoading(false));
  }, []);

  const handleClockIn = async () => {
    setClocking(true);
    try {
      await api.clockIn();
      const data = await api.getAttendance();
      setLogs(data);
    } finally {
      setClocking(false);
    }
  };

  const handleClockOut = async () => {
    setClocking(true);
    try {
      await api.clockOut();
      const data = await api.getAttendance();
      setLogs(data);
    } finally {
      setClocking(false);
    }
  };

  const todayLog = logs.find((l) => l.attendance_date === new Date().toLocaleDateString('en-CA'));

  return (
    <AppLayout>
      <Head title="Attendance" />
      <PageHeader
        eyebrow="HRMS"
        title="Attendance"
        description="Your check-in/out history"
        actions={
          <div className="flex gap-2">
            {!todayLog?.check_in && (
              <Button onClick={handleClockIn} disabled={clocking}>
                <Clock className="h-4 w-4 mr-2" /> Clock In
              </Button>
            )}
            {todayLog?.check_in && !todayLog?.check_out && (
              <Button variant="outline" onClick={handleClockOut} disabled={clocking}>
                Clock Out
              </Button>
            )}
          </div>
        }
      />
      <div className="px-8 py-6">
        {loading ? (
          <div className="flex items-center justify-center py-20"><Loader2 className="h-8 w-8 animate-spin text-gold" /></div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Date</th>
                    <th className="px-4 py-3 text-left">Check In</th>
                    <th className="px-4 py-3 text-left">Check Out</th>
                    <th className="px-4 py-3 text-left">Duration</th>
                    <th className="px-4 py-3 text-left">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {logs.map((l) => (
                    <tr key={l.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3 font-mono text-xs">{fmtDate(l.attendance_date)}</td>
                      <td className="px-4 py-3 font-mono text-xs">{fmtTime(l.check_in)}</td>
                      <td className="px-4 py-3 font-mono text-xs">{fmtTime(l.check_out)}</td>
                      <td className="px-4 py-3">{l.duration_minutes ? `${Math.floor(l.duration_minutes / 60)}h ${l.duration_minutes % 60}m` : "—"}</td>
                      <td className="px-4 py-3"><Badge variant="secondary">{l.status}</Badge></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}
      </div>
    </AppLayout>
  );
}
