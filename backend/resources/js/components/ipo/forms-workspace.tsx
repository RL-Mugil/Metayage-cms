import { useState } from "react"
import { useMutation, useQuery } from "@tanstack/react-query"
import { CheckCircle2, FileDown, FileText, Loader2, TriangleAlert } from "lucide-react"
import { toast } from "sonner"
import { Button } from "@/components/ui/button"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog"
import { api } from "@/lib/api-client"
import { Form1Panel } from "@/components/ipo/form1-panel"
import type { Form1Prefill } from "@/types/ipo-forms"

type Specification = "provisional" | "complete"

/** The case's Forms tab: one card per IPO form, each with a short description and Generate. */
export function FormsWorkspace({ projectId, serviceCode }: { projectId: number; serviceCode?: string | null }) {
  return (
    <div className="mx-auto max-w-4xl space-y-3">
      <p className="text-xs text-muted-foreground">
        Forms are filled from the case, client, inventors and Settings → IPO Forms. Dates, fees and signatures stay blank to complete in Word.
      </p>
      <Form1Card projectId={projectId} />
      <Form2Card projectId={projectId} serviceCode={serviceCode} />
    </div>
  )
}

function FormCard({ name, description, status, children }: {
  name: string
  description: string
  status?: React.ReactNode
  children: React.ReactNode
}) {
  return (
    <section className="flex flex-col gap-3 rounded-md border border-border p-4 sm:flex-row sm:items-center">
      <FileText className="hidden h-5 w-5 shrink-0 text-gold sm:block" />
      <div className="min-w-0 flex-1">
        <h3 className="font-display text-sm font-semibold">{name}</h3>
        <p className="text-xs text-muted-foreground">{description}</p>
        {status && <div className="mt-1 text-xs">{status}</div>}
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">{children}</div>
    </section>
  )
}

function Form1Card({ projectId }: { projectId: number }) {
  const [open, setOpen] = useState(false)
  // Same query key as Form1Panel, so the pop-up opens on cached data.
  const { data, isLoading } = useQuery<Form1Prefill>({ queryKey: ["ipo-form1", projectId], queryFn: () => api.getForm1(projectId), retry: false })
  const missing = data?.missing.length ?? 0

  return (
    <>
      <FormCard
        name="Form 1: application for grant of patent"
        description="Applicant, inventors, application type, agents, address for service and declarations."
        status={isLoading ? <span className="text-muted-foreground">Checking…</span>
          : !data ? null
          : missing === 0
            ? <span className="flex items-center gap-1 text-emerald-600"><CheckCircle2 className="h-3.5 w-3.5" />Ready</span>
            : <span className="flex items-center gap-1 text-amber-600"><TriangleAlert className="h-3.5 w-3.5" />{missing} item{missing === 1 ? "" : "s"} missing; boxes are left blank</span>}
      >
        <Button size="sm" onClick={() => setOpen(true)}><FileDown className="mr-2 h-4 w-4" />Generate</Button>
      </FormCard>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Form 1: application for grant of patent</DialogTitle>
            <DialogDescription>Check the details, then Generate DOCX. Saved details are reused for the other forms.</DialogDescription>
          </DialogHeader>
          <Form1Panel projectId={projectId} />
        </DialogContent>
      </Dialog>
    </>
  )
}

function Form2Card({ projectId, serviceCode }: { projectId: number; serviceCode?: string | null }) {
  const [specification, setSpecification] = useState<Specification>((serviceCode ?? "").toUpperCase() === "PRV" ? "provisional" : "complete")
  const generate = useMutation({
    mutationFn: () => api.generateForm2(projectId, specification),
    onSuccess: () => toast.success("Form 2 downloaded. Paste the drafted specification under each heading."),
    onError: (failure: Error) => toast.error(failure.message),
  })

  return (
    <FormCard
      name="Form 2: provisional / complete specification"
      description="Filled cover page (title, applicants, preamble) plus section headings to paste the drafted specification into. Title and applicants come from Form 1."
    >
      <div className="flex overflow-hidden rounded-md border border-border text-xs" role="radiogroup" aria-label="Specification type">
        {(["provisional", "complete"] as const).map((value) => (
          <button key={value} type="button" role="radio" aria-checked={specification === value} onClick={() => setSpecification(value)}
            className={`px-3 py-1.5 capitalize ${specification === value ? "bg-gold font-medium text-black" : "text-muted-foreground hover:bg-muted"}`}>
            {value}
          </button>
        ))}
      </div>
      <Button size="sm" disabled={generate.isPending} onClick={() => generate.mutate()}>
        {generate.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <FileDown className="mr-2 h-4 w-4" />}Generate
      </Button>
    </FormCard>
  )
}
