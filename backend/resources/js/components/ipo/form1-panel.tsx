import { useState } from "react"
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query"
import { FileDown, Loader2, Save, TriangleAlert } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { api } from "@/lib/api-client"
import { InventorPicker } from "@/components/inventors/inventor-picker"
import { ApplicantSection, ApplicationTypeSection, Section } from "@/components/ipo/form1-sections"
import { Field, selectClass, TextField } from "@/components/ipo/party-fields"
import type { Form1Input, Form1Prefill, InventorRecord } from "@/types/ipo-forms"

/**
 * IPO Form 1 on the case's Forms tab. Applicant, inventor, agent and address-for-service
 * details come from the portal; the paralegal picks the application type, agents and
 * declarations, fixes any gaps here, and downloads an editable DOCX.
 */
export function Form1Panel({ projectId }: { projectId: number }) {
  const queryClient = useQueryClient()
  const key = ["ipo-form1", projectId]
  const { data, error, isLoading } = useQuery<Form1Prefill>({ queryKey: key, queryFn: () => api.getForm1(projectId), retry: false })
  // Local edits; null until something changes, so a refetch shows the saved input.
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
  const inventors = useMutation({
    mutationFn: (next: InventorRecord[]) => api.setProjectInventors(projectId, next.map((inventor) => inventor.id)),
    onMutate: (next) => queryClient.setQueryData<Form1Prefill>(key, (current) => (current ? { ...current, inventors: next } : current)),
    onError: (failure: Error) => { toast.error(failure.message); refresh() },
    onSuccess: () => { refresh(); void queryClient.invalidateQueries({ queryKey: ["project-inventors"] }) },
  })
  const busy = save.isPending || generate.isPending

  if (isLoading) return <div className="flex justify-center py-10"><Loader2 className="h-5 w-5 animate-spin text-gold" /></div>
  if (error || !data || !input) return <p className="text-sm text-destructive">{(error as Error | null)?.message ?? "Could not load Form 1."}</p>

  return (
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

      <Section title="Inventor(s)">
        <InventorPicker value={data.inventors} onChange={(next) => inventors.mutate(next)} clientId={data.client?.id} />
        <p className="text-xs text-muted-foreground">Changes to the inventor list are saved to the case straight away.</p>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={input.inventors_same_as_applicants} onChange={(event) => patch({ inventors_same_as_applicants: event.target.checked })} />
          All inventors are the same as the applicant(s)
        </label>
      </Section>

      <Section title="Authorised patent agent(s)">
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
      </Section>

      <Section title="Patent Office">
        <Field label={data.office_source === "state" ? `Worked out from the applicant's state: ${data.resolved_office}` : "Choose the office (the applicant has no Indian state on record)"}>
          <select className={selectClass} value={input.office ?? ""} onChange={(event) => patch({ office: event.target.value || null })}>
            <option value="">{data.office_source === "state" ? `Automatic (${data.resolved_office})` : "Select…"}</option>
            {data.offices.map((office) => <option key={office} value={office}>{office}</option>)}
          </select>
        </Field>
      </Section>

      <Section title="Declarations by the applicant(s): 12(iii)">
        {Object.entries(data.declarations).map(([keyName, text]) => (
          <label key={keyName} className="flex items-start gap-2 text-xs">
            <input type="checkbox" className="mt-0.5" checked={input.declarations.includes(keyName)} onChange={(event) => patch({
              declarations: event.target.checked ? [...input.declarations, keyName] : input.declarations.filter((item) => item !== keyName),
            })} />
            <span>{text}</span>
          </label>
        ))}
      </Section>

      <div className="sticky bottom-0 flex items-center justify-end gap-2 border-t border-border bg-background py-3">
        {draft && <span className="mr-auto text-xs text-muted-foreground">Unsaved changes</span>}
        <Button variant="outline" disabled={busy} onClick={() => save.mutate(input)}>
          {save.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}Save
        </Button>
        <Button disabled={busy} onClick={() => generate.mutate(input)}>
          {generate.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <FileDown className="mr-2 h-4 w-4" />}Generate DOCX
        </Button>
      </div>
    </div>
  )
}
