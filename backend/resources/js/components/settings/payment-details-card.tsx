import { useEffect, useState } from "react"
import { toast } from "sonner"
import { Loader2 } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { api, type PaymentDetails } from "@/lib/api-client"

const FIELDS: { key: keyof PaymentDetails; label: string; placeholder?: string }[] = [
  { key: "account_name", label: "Account name" },
  { key: "bank_name", label: "Bank" },
  { key: "account_number", label: "Account number" },
  { key: "ifsc", label: "IFSC" },
  { key: "swift", label: "SWIFT (foreign clients)" },
  { key: "branch", label: "Branch" },
  { key: "upi_id", label: "UPI ID", placeholder: "firm@bank" },
  { key: "note", label: "Note shown to clients", placeholder: "e.g. Add the invoice number as the payment reference" },
]

/** The bank / UPI details clients see when they press Pay on an invoice. */
export function PaymentDetailsCard() {
  const [values, setValues] = useState<PaymentDetails | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => { api.getPaymentDetails().then(setValues).catch(() => setValues(null)) }, [])

  const save = async () => {
    if (!values) return
    setSaving(true)
    try { setValues(await api.updatePaymentDetails(values)); toast.success("Payment details saved") }
    catch (e) { toast.error((e as Error).message) } finally { setSaving(false) }
  }

  return (
    <Card className="border-border">
      <CardHeader>
        <CardTitle className="font-display text-base">Client payment details</CardTitle>
        <p className="text-xs text-muted-foreground">Shown to clients in the portal when they pay an invoice, with copy buttons and a UPI link.</p>
      </CardHeader>
      <CardContent>
        {!values ? <Loader2 className="h-4 w-4 animate-spin text-gold" /> : (
          <div className="grid gap-3 sm:grid-cols-2">
            {FIELDS.map((f) => (
              <label key={f.key} className={`text-xs ${f.key === "note" ? "sm:col-span-2" : ""}`}>
                <span className="text-muted-foreground">{f.label}</span>
                <input value={values[f.key] ?? ""} placeholder={f.placeholder} onChange={(e) => setValues({ ...values, [f.key]: e.target.value || null })}
                  className="mt-1 h-8 w-full rounded-md border border-border bg-background px-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold" />
              </label>
            ))}
            <div className="sm:col-span-2">
              <Button onClick={save} disabled={saving} className="bg-gold text-black hover:bg-gold/90">{saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}Save payment details</Button>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
