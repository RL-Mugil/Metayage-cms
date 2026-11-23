import { useState } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { Link } from "@inertiajs/react"
import { Loader2, Save } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { api } from "@/lib/api-client"
import { Field, TextField } from "@/components/ipo/party-fields"
import type { IpoServiceAddress, IpoSettings } from "@/types/ipo-forms"

const KEY = ["ipo-settings"]

/** Settings → IPO Forms: address for service (Form 1 §7) and the registered patent agents (§6). */
export function IpoSettingsPanel() {
  const { data, isLoading, error } = useQuery<IpoSettings>({ queryKey: KEY, queryFn: () => api.getIpoSettings(), retry: false })

  if (isLoading) return <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
  if (error || !data) return <p className="text-sm text-destructive">{(error as Error | null)?.message ?? "Could not load IPO settings."}</p>

  return (
    <div className="space-y-6">
      <ServiceForm initial={data.service} />
      <section className="space-y-3">
        <div>
          <h3 className="text-sm font-semibold">Registered patent agents</h3>
          <p className="text-xs text-muted-foreground">
            These can be picked as the authorised agent on Form 1 and sign Form 2. Mark an employee as a Registered Patent Agent
            (with their IN/PA No.) in{" "}
            <Link href="/hrms/employees" className="font-medium text-gold hover:underline">HRMS → Employees → Edit</Link>.
            Their mobile comes from the employee record.
          </p>
        </div>
        {data.agents.length === 0 ? (
          <p className="rounded-md border border-dashed border-border p-4 text-center text-xs text-muted-foreground">No employee is marked as a Registered Patent Agent yet.</p>
        ) : (
          <div className="overflow-hidden rounded-md border border-border">
            <table className="w-full text-xs">
              <thead className="bg-muted/30 text-left text-muted-foreground">
                <tr>
                  <th className="px-3 py-2 font-medium">Name</th>
                  <th className="px-3 py-2 font-medium">Designation</th>
                  <th className="px-3 py-2 font-medium">IN/PA No.</th>
                  <th className="px-3 py-2 font-medium">Mobile</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {data.agents.map((agent) => (
                  <tr key={agent.id}>
                    <td className="px-3 py-2">
                      <span className="font-medium">{agent.name}</span>
                      {agent.employee_code && <span className="ml-1.5 font-mono text-muted-foreground">{agent.employee_code}</span>}
                    </td>
                    <td className="px-3 py-2 text-muted-foreground">{agent.designation || "—"}</td>
                    <td className="px-3 py-2 font-mono">{agent.patent_agent_no}</td>
                    <td className="px-3 py-2">{agent.mobile || <span className="text-amber-600">Add in HRMS</span>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}

function ServiceForm({ initial }: { initial: IpoServiceAddress }) {
  const queryClient = useQueryClient()
  const [service, setService] = useState<IpoServiceAddress>(initial)
  const set = (key: keyof IpoServiceAddress) => (value: string) => setService({ ...service, [key]: value })
  const save = useMutation({
    mutationFn: () => api.updateIpoService(service),
    onSuccess: (fresh) => { queryClient.setQueryData(KEY, fresh); toast.success("Address for service saved.") },
    onError: (failure: Error) => toast.error(failure.message),
  })

  return (
    <section className="space-y-3">
      <div>
        <h3 className="text-sm font-semibold">Address for service of applicant in India</h3>
        <p className="text-xs text-muted-foreground">Printed in section 7 of every Form 1.</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label="Name" value={service.name} onChange={set("name")} />
        <TextField label="E-mail ID" type="email" value={service.email} onChange={set("email")} />
        <Field label="Postal address" className="sm:col-span-2">
          <Textarea rows={2} value={service.address ?? ""} onChange={(event) => set("address")(event.target.value)} className="text-sm text-foreground" />
        </Field>
        <TextField label="Telephone no." value={service.phone} onChange={set("phone")} />
        <TextField label="Mobile no." value={service.mobile} onChange={set("mobile")} />
        <TextField label="Fax no." value={service.fax} onChange={set("fax")} />
      </div>
      <Button size="sm" disabled={save.isPending} onClick={() => save.mutate()}>
        {save.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}Save
      </Button>
    </section>
  )
}
