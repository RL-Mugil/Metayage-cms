import { Head } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { CheckCircle, XCircle, Clock, DollarSign, Calendar, AlertTriangle, Filter, Loader2 } from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { api } from "@/lib/api-client";

type ApprovalType = "Leave" | "Expense";
type ApprovalStatus = "pending" | "approved" | "rejected";

interface Approval {
  id: number;
  type: ApprovalType;
  requester: string;
  description: string;
  amount?: string | null;
  from_date?: string | null;
  to_date?: string | null;
  submitted: string;
  urgency: "High" | "Normal";
  status: ApprovalStatus;
}

const typeColors: Record<ApprovalType, string> = {
  Leave: "bg-blue-500/10 text-blue-600 border-blue-200",
  Expense: "bg-amber-500/10 text-amber-600 border-amber-200",
};

const filterTypes: (ApprovalType | "All")[] = ["All", "Leave", "Expense"];

export default function Approvals() {
  const [items, setItems] = useState<Approval[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [activeTab, setActiveTab] = useState<"pending" | "approved" | "rejected">("pending");
  const [filterType, setFilterType] = useState<ApprovalType | "All">("All");

  useEffect(() => {
    api.getApprovals()
      .then((data) => setItems(data as unknown as Approval[]))
      .catch((e) => setError(e.message || "Failed to load approvals."))
      .finally(() => setLoading(false));
  }, []);

  const resolve = async (item: Approval, action: "Approved" | "Rejected") => {
    setError("");
    try {
      await api.resolveApproval(item.type, item.id, action);
      setItems((prev) =>
        prev.map((a) =>
          a.id === item.id && a.type === item.type
            ? { ...a, status: action.toLowerCase() as ApprovalStatus }
            : a
        )
      );
    } catch (e: any) {
      setError(e.message || "Failed to resolve approval.");
    }
  };

  const filtered = items.filter(
    (a) => a.status === activeTab && (filterType === "All" || a.type === filterType)
  );

  const pendingCount = items.filter((a) => a.status === "pending").length;
  const approvedWeek = items.filter((a) => a.status === "approved").length;
  const rejectedWeek = items.filter((a) => a.status === "rejected").length;

  return (
    <AppLayout>
      <Head title="Approvals" />
      <PageHeader
        eyebrow="Engagement"
        title="Approvals"
        description="Manage leave requests, expense claims, and project proposals"
      />
      <div className="px-8 py-6 space-y-6">
        {error && (
          <div className="rounded-md border border-red-500/40 bg-red-500/10 px-4 py-2 text-sm text-red-400">
            {error}
          </div>
        )}
        {/* Stats */}
        <div className="grid grid-cols-3 gap-4">
          <Card className="border-border">
            <CardContent className="p-4 flex items-center gap-3">
              <div className="relative">
                <Clock className="h-8 w-8 text-amber-500" />
                {pendingCount > 0 && (
                  <span className="absolute -top-1 -right-1 h-3 w-3 rounded-full bg-red-500 animate-pulse" />
                )}
              </div>
              <div>
                <div className="text-2xl font-bold">{pendingCount}</div>
                <div className="text-xs text-muted-foreground">Pending</div>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="p-4 flex items-center gap-3">
              <CheckCircle className="h-8 w-8 text-green-500" />
              <div>
                <div className="text-2xl font-bold">{approvedWeek}</div>
                <div className="text-xs text-muted-foreground">Approved</div>
              </div>
            </CardContent>
          </Card>
          <Card className="border-border">
            <CardContent className="p-4 flex items-center gap-3">
              <XCircle className="h-8 w-8 text-red-500" />
              <div>
                <div className="text-2xl font-bold">{rejectedWeek}</div>
                <div className="text-xs text-muted-foreground">Rejected</div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Tabs + Filter */}
        <div className="flex items-center justify-between">
          <div className="flex gap-1 border border-border rounded-lg p-1 bg-muted/30">
            {(["pending", "approved", "rejected"] as const).map((tab) => (
              <button
                key={tab}
                onClick={() => setActiveTab(tab)}
                className={`px-4 py-1.5 rounded text-sm font-medium capitalize transition-colors ${
                  activeTab === tab ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"
                }`}
              >
                {tab}
              </button>
            ))}
          </div>
          <div className="flex items-center gap-2">
            <Filter className="h-4 w-4 text-muted-foreground" />
            {filterTypes.map((t) => (
              <button
                key={t}
                onClick={() => setFilterType(t)}
                className={`px-3 py-1 rounded text-xs font-medium border transition-colors ${
                  filterType === t ? "bg-gold text-black border-gold" : "border-border text-muted-foreground hover:text-foreground"
                }`}
              >
                {t}
              </button>
            ))}
          </div>
        </div>

        {/* Table */}
        <Card className="border-border">
          <CardContent className="p-0">
            {loading ? (
              <div className="flex items-center justify-center py-16">
                <Loader2 className="h-8 w-8 animate-spin text-gold" />
              </div>
            ) : filtered.length === 0 ? (
              <div className="py-16 text-center text-muted-foreground text-sm">No {activeTab} approvals</div>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                  <tr>
                    <th className="px-4 py-3 text-left">Type</th>
                    <th className="px-4 py-3 text-left">Requester</th>
                    <th className="px-4 py-3 text-left">Details</th>
                    <th className="px-4 py-3 text-left">Submitted</th>
                    <th className="px-4 py-3 text-left">Urgency</th>
                    {activeTab === "pending" ? (
                      <th className="px-4 py-3 text-left">Actions</th>
                    ) : (
                      <th className="px-4 py-3 text-left">Resolved</th>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {filtered.map((a) => (
                    <tr key={a.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-3">
                        <span className={`px-2 py-0.5 rounded text-xs font-medium border ${typeColors[a.type]}`}>
                          {a.type}
                        </span>
                      </td>
                      <td className="px-4 py-3 font-medium">{a.requester}</td>
                      <td className="px-4 py-3">
                        <div>{a.description}</div>
                        {a.amount && (
                          <div className="text-xs text-muted-foreground flex items-center gap-1 mt-0.5">
                            <DollarSign className="h-3 w-3" />{a.amount}
                          </div>
                        )}
                        {a.from_date && (
                          <div className="text-xs text-muted-foreground flex items-center gap-1 mt-0.5">
                            <Calendar className="h-3 w-3" />{String(a.from_date).slice(0, 10)} → {String(a.to_date).slice(0, 10)}
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-muted-foreground text-xs">{a.submitted}</td>
                      <td className="px-4 py-3">
                        {a.urgency === "High" ? (
                          <div className="flex items-center gap-1 text-red-500 text-xs font-medium">
                            <AlertTriangle className="h-3 w-3" /> High
                          </div>
                        ) : (
                          <span className="text-xs text-muted-foreground">Normal</span>
                        )}
                      </td>
                      {activeTab === "pending" ? (
                        <td className="px-4 py-3">
                          <div className="flex gap-2">
                            <Button size="sm" className="h-7 px-3 bg-green-600 hover:bg-green-700 text-white text-xs" onClick={() => resolve(a, "Approved")}>
                              <CheckCircle className="h-3 w-3 mr-1" /> Approve
                            </Button>
                            <Button size="sm" variant="outline" className="h-7 px-3 text-xs border-red-200 text-red-600 hover:bg-red-50" onClick={() => resolve(a, "Rejected")}>
                              <XCircle className="h-3 w-3 mr-1" /> Reject
                            </Button>
                          </div>
                        </td>
                      ) : (
                        <td className="px-4 py-3 text-xs text-muted-foreground capitalize">
                          {a.status}
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  );
}
