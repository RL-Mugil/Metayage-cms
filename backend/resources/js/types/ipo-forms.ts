export type IpoApplicationType = "ordinary" | "convention" | "pct_np" | "pph"
export type IpoSubType = "none" | "divisional" | "patent_of_addition"
export type IpoApplicantCategory = "natural_person" | "other_than_natural" | "educational" | "small_entity" | "startup" | "others"
export type IpoGender = "Male" | "Female" | "Others" | "Prefer not to disclose"

export interface IpoAddress {
  address_house?: string | null
  address_street?: string | null
  city?: string | null
  state?: string | null
  country?: string | null
  pincode?: string | null
}

export interface IpoCoApplicant extends IpoAddress {
  name: string
  natural_person?: boolean
  gender?: IpoGender | null
  age?: number | null
  nationality?: string | null
  country_of_residence?: string | null
  email?: string | null
  phone?: string | null
}

export interface IpoApplicationRef {
  application_number?: string | null
  filing_date?: string | null
}

export interface IpoConventionRow extends IpoApplicationRef {
  country?: string | null
  applicant_name?: string | null
  title?: string | null
}

export interface Form1Input {
  application_type: IpoApplicationType
  sub_type: IpoSubType
  title?: string | null
  applicant_category: IpoApplicantCategory
  co_applicants: IpoCoApplicant[]
  inventors_same_as_applicants: boolean
  agent_user_ids: number[]
  convention: IpoConventionRow[]
  pct: IpoApplicationRef
  original: IpoApplicationRef
  main: IpoApplicationRef
  declarations: string[]
  office?: string | null
}

/** Name and Form 1 particulars of an inventor, as sent when creating or editing one. */
export interface InventorInput extends IpoAddress {
  client_id?: number | null
  full_name: string
  gender?: IpoGender | null
  age?: number | string | null
  nationality?: string | null
  country_of_residence?: string | null
  email?: string | null
  phone?: string | null
}

/** A firm-wide inventor record, reusable across cases. */
export interface InventorRecord extends InventorInput {
  id: number
  client_code?: string | null
  client_name?: string | null
  case_count?: number
  has_portal_access: boolean
  complete: boolean
}

export interface Form1Client extends IpoAddress {
  id: number
  client_code?: string | null
  client_type?: string | null
  legal_name?: string | null
  company_name?: string | null
  nationality?: string | null
  address?: string | null
  contact_email?: string | null
  phone?: string | null
  applicant_category?: IpoApplicantCategory | null
}

export interface IpoAgentOption {
  id: number
  name: string
  patent_agent_no: string
  mobile?: string | null
}

export interface IpoServiceAddress {
  name: string | null
  address: string | null
  phone: string | null
  mobile: string | null
  fax: string | null
  email: string | null
}

export interface Form1Prefill {
  input: Form1Input
  client: Form1Client | null
  inventors: InventorRecord[]
  agent_options: IpoAgentOption[]
  service: IpoServiceAddress
  resolved_office: string | null
  office_source: "manual" | "state" | "none"
  offices: string[]
  declarations: Record<string, string>
  missing: string[]
}

/** An employee marked as Registered Patent Agent in HRMS (listed read-only in Settings). */
export interface IpoPatentAgent {
  id: number
  name: string
  patent_agent_no: string
  mobile: string | null
  employee_code: string | null
  designation: string | null
}

export interface IpoSettings {
  service: IpoServiceAddress
  agents: IpoPatentAgent[]
}
