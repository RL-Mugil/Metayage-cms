/**
 * One-line explanations of patent terms for clients. Keys are matched case-insensitively
 * as whole words; longer terms are tried first so "Form 18" wins over "Form".
 */
export const GLOSSARY: Record<string, string> = {
  "FER": "First Examination Report: the patent office's first list of objections to the application.",
  "Office Action": "A letter from the patent office listing objections that must be answered by a deadline.",
  "RFE": "Request for Examination: asks the office to examine the application (Form 18 in India).",
  "Form 18": "Request for Examination in India; without it the application is treated as withdrawn.",
  "Form 26": "Power of attorney letting the firm act for the applicant before the Indian Patent Office.",
  "Form 1": "The Indian patent application form, signed by the applicant.",
  "Form 3": "Statement of foreign filings for the same invention (India).",
  "Form 5": "Declaration of who the inventors are (India).",
  "PCT": "International application under the Patent Cooperation Treaty; keeps options open in many countries for up to 30/31 months.",
  "National Phase": "Entering individual countries from a PCT application, by 30 or 31 months from priority.",
  "Provisional": "A first, informal filing that secures the date; the complete specification must follow within 12 months.",
  "Complete Specification": "The full patent document with claims, filed after (or instead of) a provisional.",
  "Priority": "The date of the first filing; later filings abroad within 12 months keep this date.",
  "Publication": "The application becomes public, normally 18 months from the priority date.",
  "Hearing": "A meeting with the examiner when objections remain after the written reply.",
  "Annuity": "A yearly fee that keeps a patent or application alive.",
  "Renewal": "A fee paid at set intervals to keep the patent in force.",
  "Maintenance Fee": "US fees due 3.5, 7.5 and 11.5 years after grant to keep the patent in force.",
  "Grant": "The office has accepted the application; the patent is now in force.",
  "Opposition": "A third party challenging the application or patent at the office.",
  "Foreign Filing Permission": "Permission from the inventor's home country before filing abroad first.",
  "IDS": "Information Disclosure Statement: prior art the applicant must tell the USPTO about.",
  "ADS": "Application Data Sheet: the US form listing applicant, inventors and priority claims.",
  "Claims": "The numbered sentences that define exactly what the patent protects.",
  "Prior Art": "Earlier publications or products that the invention is compared against.",
  "IDF": "Invention Disclosure Form: the inventor's description of the invention for drafting.",
}

const TERMS = Object.keys(GLOSSARY).sort((a, b) => b.length - a.length)
const PATTERN = new RegExp(`\\b(${TERMS.map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")).join("|")})\\b`, "gi")

/** Split text into plain and glossary parts. */
export function glossarySplit(text: string): Array<{ text: string; tip?: string }> {
  const parts: Array<{ text: string; tip?: string }> = []
  let last = 0
  for (const match of text.matchAll(PATTERN)) {
    const at = match.index ?? 0
    if (at > last) parts.push({ text: text.slice(last, at) })
    const key = TERMS.find((t) => t.toLowerCase() === match[0].toLowerCase())
    parts.push({ text: match[0], tip: key ? GLOSSARY[key] : undefined })
    last = at + match[0].length
  }
  if (last < text.length) parts.push({ text: text.slice(last) })
  return parts
}
