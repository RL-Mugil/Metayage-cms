import { FileText } from "lucide-react"
import { Form1Panel } from "@/components/ipo/form1-panel"

/** The case's Forms tab: IPO filing forms prepared from the case data. Form 1 for now. */
export function FormsWorkspace({ projectId }: { projectId: number }) {
  return (
    <div className="mx-auto max-w-4xl space-y-4">
      <div className="flex items-start gap-3 rounded-md border border-border p-4">
        <FileText className="mt-0.5 h-5 w-5 shrink-0 text-gold" />
        <div>
          <h2 className="font-display text-base font-semibold">Form 1: application for grant of patent</h2>
          <p className="text-xs text-muted-foreground">
            The Patents Act, 1970, rule 20(1). Applicant, inventor, agent and address-for-service details come from the case.
            The date, place, fees and signatures stay blank for you to complete in Word.
          </p>
        </div>
      </div>
      <Form1Panel projectId={projectId} />
    </div>
  )
}
