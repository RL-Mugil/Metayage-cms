import { useState } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { Loader2, Save } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import { api } from "@/lib/api-client"
import { Field, TextField } from "@/components/ipo/party-fields"
import type { IpoServiceAddress, IpoSettings, IpoStaffAgent } from "@/types/ipo-forms"

const KEY = ["ipo-settings"]

/** Settings → IPO Forms: address for service (Form 1 §7) and agents' IN/PA numbers (§6). */
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
          <p className="text-xs text-muted-foreground">Staff with an IN/PA number can be picked as the authorised agent on Form 1.</p>
        </div>
        <div className="divide-y divide-border rounded-md border border-border">
          {data.staff.map((agent) => <AgentRow key={agent.id} agent={agent} />)}
        </div>
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

function AgentRow({ agent }: { agent: IpoStaffAgent }) {
  const queryClient = useQueryClient()
  const [number, setNumber] = useState(agent.patent_agent_no ?? "")
  const [mobile, setMobile] = useState(agent.mobile ?? "")
  const dirty = number !== (agent.patent_agent_no ?? "") || mobile !== (agent.mobile ?? "")
  const save = useMutation({
    mutationFn: () => api.updatePatentAgent(agent.id, { patent_agent_no: number.trim() || null, mobile: mobile.trim() || null }),
    onSuccess: () => { void queryClient.invalidateQueries({ queryKey: KEY }); toast.success(`${agent.name} updated.`) },
    onError: (failure: Error) => toast.error(failure.message),
  })

  return (
    <div className="grid items-center gap-2 p-2 sm:grid-cols-[1fr_10rem_10rem_auto]">
      <div className="text-sm"><span className="font-medium">{agent.name}</span> <span className="text-xs text-muted-foreground">{agent.role}</span></div>
      <Input value={number} placeholder="IN/PA no." onChange={(event) => setNumber(event.target.value)} className="h-8 font-mono text-xs" />
      <Input value={mobile} placeholder="Mobile" onChange={(event) => setMobile(event.target.value)} className="h-8 text-xs" />
      <Button size="sm" variant="outline" className="h-8" disabled={!dirty || save.isPending} onClick={() => save.mutate()}>Save</Button>
    </div>
  )
}
