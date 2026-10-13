// Mirrors backend config/trademark_lifecycle.php — keep the two in sync.

export const MARK_TYPES = [
  { value: "Word", label: "Word mark", needsImage: false },
  { value: "Device", label: "Device / logo", needsImage: true },
  { value: "Composite", label: "Composite (word + logo)", needsImage: true },
  { value: "Shape", label: "Shape / 3D", needsImage: true },
  { value: "Colour", label: "Colour combination", needsImage: true },
  { value: "Sound", label: "Sound mark", needsImage: false },
] as const

export type MarkType = (typeof MARK_TYPES)[number]["value"]

export const TRADEMARK_FILING_CODES = ["TMF", "TMC", "TMMD"]
export const MARK_IMAGE_MAX_BYTES = 5 * 1024 * 1024
export const MARK_IMAGE_ACCEPT = "image/jpeg,image/png"

export function isTrademarkCode(serviceCode: string | null | undefined): boolean {
  return (serviceCode ?? "").toUpperCase().startsWith("TM")
}

export function isTrademarkFilingCode(serviceCode: string | null | undefined): boolean {
  return TRADEMARK_FILING_CODES.includes((serviceCode ?? "").toUpperCase())
}

export function markTypeNeedsImage(markType: string | null | undefined): boolean {
  return MARK_TYPES.some((type) => type.value === markType && type.needsImage)
}

export interface TrademarkInput {
  mark_text: string
  mark_type: MarkType | ""
  nice_classes: number[]
}

export interface TrademarkDetails {
  id: number
  ip_record_id: number
  mark_text: string | null
  mark_type: MarkType | null
  nice_classes: number[]
  requires_image: boolean
  image_path: string | null
  image_name: string | null
  image_version: number | null
}

/** Returns an error message, or null when the input is valid for this service code. */
export function validateTrademarkInput(serviceCode: string, input: TrademarkInput, hasImage: boolean): string | null {
  if (!isTrademarkFilingCode(serviceCode)) return null
  if (!input.mark_text.trim()) return "Enter the mark name / text."
  if (!input.mark_type) return "Select the mark type."
  const svc = serviceCode.toUpperCase()
  if (input.nice_classes.length === 0) return "Select at least one Nice class."
  if (svc === "TMF" && input.nice_classes.length !== 1) return "A single-class filing (TMF) takes exactly one Nice class — use TMC for several."
  if (svc === "TMC" && input.nice_classes.length < 2) return "A multi-class filing (TMC) needs at least two Nice classes."
  if (markTypeNeedsImage(input.mark_type) && !hasImage) return `Upload the logo / mark image for a ${input.mark_type} mark.`
  return null
}

// Nice Classification (12th ed.) — short headings.
export const NICE_CLASSES: { id: number; label: string }[] = [
  { id: 1, label: "Chemicals" },
  { id: 2, label: "Paints, coatings" },
  { id: 3, label: "Cosmetics, cleaning preparations" },
  { id: 4, label: "Industrial oils, fuels" },
  { id: 5, label: "Pharmaceuticals" },
  { id: 6, label: "Common metals" },
  { id: 7, label: "Machines, machine tools" },
  { id: 8, label: "Hand tools" },
  { id: 9, label: "Computers, software, electronics" },
  { id: 10, label: "Medical apparatus" },
  { id: 11, label: "Lighting, heating, cooling" },
  { id: 12, label: "Vehicles" },
  { id: 13, label: "Firearms, fireworks" },
  { id: 14, label: "Jewellery, watches" },
  { id: 15, label: "Musical instruments" },
  { id: 16, label: "Paper, printed matter" },
  { id: 17, label: "Rubber, plastics, insulation" },
  { id: 18, label: "Leather goods, bags" },
  { id: 19, label: "Non-metallic building materials" },
  { id: 20, label: "Furniture" },
  { id: 21, label: "Household utensils" },
  { id: 22, label: "Ropes, sacks, raw fibres" },
  { id: 23, label: "Yarns, threads" },
  { id: 24, label: "Textiles" },
  { id: 25, label: "Clothing, footwear" },
  { id: 26, label: "Lace, buttons, haberdashery" },
  { id: 27, label: "Carpets, floor coverings" },
  { id: 28, label: "Games, toys, sporting goods" },
  { id: 29, label: "Meat, dairy, processed foods" },
  { id: 30, label: "Coffee, tea, bakery, staples" },
  { id: 31, label: "Agricultural produce" },
  { id: 32, label: "Beers, soft drinks" },
  { id: 33, label: "Alcoholic beverages" },
  { id: 34, label: "Tobacco, smokers' articles" },
  { id: 35, label: "Advertising, business services" },
  { id: 36, label: "Financial, insurance, real estate" },
  { id: 37, label: "Construction, repair" },
  { id: 38, label: "Telecommunications" },
  { id: 39, label: "Transport, travel" },
  { id: 40, label: "Treatment of materials" },
  { id: 41, label: "Education, entertainment" },
  { id: 42, label: "Scientific, IT & software services" },
  { id: 43, label: "Food & drink services, lodging" },
  { id: 44, label: "Medical, beauty, agricultural services" },
  { id: 45, label: "Legal, security, personal services" },
]
