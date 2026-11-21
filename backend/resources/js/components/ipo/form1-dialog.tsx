import { useState } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { FileDown, FileText, Loader2, Save, TriangleAlert } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { api } from "@/lib/api-client"
import { ApplicantSection, ApplicationTypeSection, InventorSection } from "@/components/ipo/form1-sections"
import { Field, selectClass, TextField } from "@/components/ipo/party-fields"
import type { Form1Input, Form1Prefill } from "@/types/ipo-forms"

/**
 * "Form 1" button + dialog on a case. Applicant, inventor, agent and service details
 * are pulled from the database; the paralegal picks the application type, agents and
 * declarations, fixes any gaps inline, and downloads an editable DOCX.
 */
export function Form1Button({ projectId }: { projectId: number }) {
  const [open, setOpen] = useState(false)
  return (
    <>
      <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
        <FileText className="mr-2 h-4 w-4" />Form 1
      </Button>
      {open && <Form1Dialog projectId={projectId} onClose={() => setOpen(false)} />}
    </>
  )
}

function Form1Dialog({ projectId, onClose }: { projectId: number; onClose: () => void }) {
  const queryClient = useQueryClient()
  const key = ["ipo-form1", projectId]
  const { data, error, isLoading } = useQuery<Form1Prefill>({ queryKey: key, queryFn: () => api.getForm1(projectId), retry: false })
  // Local edits; null until the paralegal changes something, so refetches show fresh saved input.
  const [draft, setDraft] = useState<Form1Input | null>(null)
  const input = draft ?? data?.input ?? null
  const patch = (next: Partial<Form1Input>) => setDraft((current) => ({ ...(current ?? data!.input), ...next }))
  const refresh = () => { void queryClient.invalidateQueries({ queryKey: key }) }

  const save = useMutation({
    mutationFn: (value: Form1Input) => api.saveForm1(projectId, value),
    onSuccess: (fresh) => { queryClient.setQueryData(key, fresh); setDraft(null); toast.success("Form 1 details saved.") },
    onError: (failure: Error) => toast.error(failure.message),
  })
  const generate = useMutation({
    mutationFn: (value: Form1Input) => api.generateForm1(projectId, value),
    onSuccess: () => { setDraft(null); refresh(); toast.success("Form 1 downloaded. Fill in the date, place and fees in Word.") },
    onError: (failure: Error) => toast.error(failure.message),
  })
  const busy = save.isPending || generate.isPending

  return (
    <Dialog open onOpenChange={(value) => { if (!value && !busy) onClose() }}>
      <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Form 1: application for grant of patent</DialogTitle>
          <DialogDescription>
            Applicant, inventor, agent and address-for-service details come from the portal. The date, place, fees and signatures stay blank for you to complete in Word.
          </DialogDescription>
        </DialogHeader>

        {isLoading && <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>}
        {error && <p className="text-sm text-destructive">{(error as Error).message}</p>}

        {data && input && (
          <div className="space-y-4">
            {data.missing.length > 0 && (
              <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                <p className="mb-1 flex items-center gap-1.5 font-medium"><TriangleAlert className="h-4 w-4 text-amber-500" />Missing on the form ({data.missing.length})</p>
                <ul className="list-disc space-y-0.5 pl-5 text-xs text-muted-foreground">
                  {data.missing.map((item) => <li key={item}>{item}</li>)}
                </ul>
                <p className="mt-1 text-xs text-muted-foreground">You can still generate; these boxes will be left blank.</p>
              </div>
            )}

            <ApplicationTypeSection input={input} patch={patch} />
            <TextField label="Title of the invention (optional until finalised)" value={input.title} onChange={(title) => patch({ title })} />
            <ApplicantSection projectId={projectId} client={data.client} input={input} patch={patch} onSaved={refresh} />
            <InventorSection projectId={projectId} inventors={data.inventors} input={input} patch={patch} onSaved={refresh} />

            <section className="space-y-2 rounded-md border border-border p-3">
              <h3 className="text-sm font-semibold">Authorised patent agent(s)</h3>
              {data.agent_options.length === 0 ? (
                <p className="text-xs text-muted-foreground">No agents have an IN/PA number yet. An admin can add them in Settings → IPO Forms.</p>
              ) : data.agent_options.map((agent) => (
                <label key={agent.id} className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={input.agent_user_ids.includes(agent.id)} onChange={(event) => patch({
                    agent_user_ids: event.target.checked ? [...input.agent_user_ids, agent.id] : input.agent_user_ids.filter((id) => id !== agent.id),
                  })} />
                  <span className="font-medium">{agent.name}</span>
                  <span className="font-mono text-xs text-muted-foreground">{agent.patent_agent_no}</span>
                  {agent.mobile && <span className="text-xs text-muted-foreground">{agent.mobile}</span>}
                </label>
              ))}
              <p className="text-xs text-muted-foreground">
                Address for service: {[data.service.name, data.service.address].filter(Boolean).join(", ") || "not set (Settings → IPO Forms)"}
              </p>
            </section>

            <section className="space-y-2 rounded-md border border-border p-3">
              <h3 className="text-sm font-semibold">Patent Office</h3>
              <Field label={data.office_source === "state" ? `Worked out from the applicant's state: ${data.resolved_office}` : "Choose the office (the applicant has no Indian state on record)"}>
                <select className={selectClass} value={input.office ?? ""} onChange={(event) => patch({ office: event.target.value || null })}>
                  <option value="">{data.office_source === "state" ? `Automatic (${data.resolved_office})` : "Select…"}</option>
                  {data.offices.map((office) => <option key={office} value={office}>{office}</option>)}
                </select>
              </Field>
            </section>

            <section className="space-y-2 rounded-md border border-border p-3">
              <h3 className="text-sm font-semibold">Declarations by the applicant(s): 12(iii)</h3>
              {Object.entries(data.declarations).map(([keyName, text]) => (
                <label key={keyName} className="flex items-start gap-2 text-xs">
                  <input type="checkbox" className="mt-0.5" checked={input.declarations.includes(keyName)} onChange={(event) => patch({
                    declarations: event.target.checked ? [...input.declarations, keyName] : input.declarations.filter((item) => item !== keyName),
                  })} />
                  <span>{text}</span>
                </label>
              ))}
            </section>

            <div className="sticky bottom-0 flex justify-end gap-2 border-t border-border bg-background pt-3">
              <Button variant="outline" disabled={busy} onClick={() => save.mutate(input)}>
                {save.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}Save
              </Button>
              <Button disabled={busy} onClick={() => generate.mutate(input)}>
                {generate.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <FileDown className="mr-2 h-4 w-4" />}Generate DOCX
              </Button>
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}
