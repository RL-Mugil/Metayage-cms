export interface WorkspacePerson {
  id: number
  name: string
  email?: string | null
}

export interface WorkspaceClient {
  id: number
  company_name?: string | null
  legal_name?: string | null
  client_code?: string | null
}

export interface WorkspaceProject {
  id: number
  project_code: string
  docket_number?: string | null
  invention_family_id?: number | null
  invention_number?: string | null
  project_name: string
  invention_title?: string | null
  project_type?: string | null
  case_type?: string | null
  patent_office_code?: string | null
  service_code?: string | null
  application_number?: string | null
  legal_status?: string | null
  status: string
  urgency?: string | null
  priority_date?: string | null
  filing_date?: string | null
  hard_deadline?: string | null
  notes?: string | null
  client?: WorkspaceClient | null
  partner?: WorkspacePerson | null
  manager?: WorkspacePerson | null
  secondary_manager?: WorkspacePerson | null
  patent_engineer?: WorkspacePerson | null
}

export interface WorkspaceFamily {
  id: number
  invention_number: string
  title: string
  earliest_priority_date?: string | null
  status: string
  client?: WorkspaceClient | null
}

export interface WorkspaceApplication {
  id: number
  application_number?: string | null
  title?: string | null
  priority_date?: string | null
  filing_date?: string | null
  publication_date?: string | null
  grant_number?: string | null
  grant_date?: string | null
  legal_status: string
  jurisdiction: string
}

export interface WorkspaceStage {
  id: number
  stage_name: string
  status: string
  sequence_order: number
  due_date?: string | null
  actual_start_at?: string | null
  actual_end_at?: string | null
  working_days?: number | null
  owner?: WorkspacePerson | null
}

export interface WorkspaceLifecycleTemplate {
  id: number
  jurisdiction: string
  service_code: string
  name: string
  version: string
  effective_from: string
}

export interface WorkspaceTransition {
  id: number
  to_service_code: string
  required_event_type?: string | null
  required_application_status?: string | null
  description: string
  eligible: boolean
  blocker?: string | null
}

export interface WorkspaceDeadline {
  id: number
  title: string
  legal_basis?: string | null
  due_date: string
  extended_due_date?: string | null
  status: string
  source_type: string
  rule_code?: string | null
  rule_version?: string | null
  review_status: string
  calculation_trace?: Record<string, string | null> | null
}

export interface WorkspaceTask {
  id: number
  title: string
  status: string
  priority: string
  due_date?: string | null
  estimated_hours?: number | null
  actual_hours?: number | null
  billable: boolean
  assignee?: WorkspacePerson | null
}

export interface WorkspaceDocument {
  id: number
  file_name: string
  file_type?: string | null
  file_size: number
  category: string
  storage_path?: string | null
  current_version: number
  status: string
  updated_at: string
  uploader?: WorkspacePerson | null
}

export interface RelatedMatter {
  id: number
  project_code: string
  docket_number?: string | null
  project_name: string
  service_code?: string | null
  patent_office_code?: string | null
  status: string
  priority_date?: string | null
  filing_date?: string | null
}

export interface WorkspaceInvoice {
  id: number
  invoice_code: string
  status: string
  currency: string
  total_amount: number | string
  balance_due: number | string
  created_at: string
  due_date?: string | null
}

export interface WorkspaceLedger {
  id: number
  document_type: string
  document_reference: string
  debit: number | string
  credit: number | string
  balance: number | string
  created_at: string
}

export interface WorkspaceAudit {
  id: number
  action: string
  metadata?: Record<string, unknown> | null
  created_at: string
  user?: WorkspacePerson | null
}

export interface WorkspaceTimelineItem {
  type: "stage" | "event" | "document" | "audit"
  title: string
  status?: string | null
  occurred_at: string
}

export interface MatterWorkspace {
  project: WorkspaceProject
  application?: WorkspaceApplication | null
  family?: WorkspaceFamily | null
  family_engagements: RelatedMatter[]
  lifecycle_template?: WorkspaceLifecycleTemplate | null
  docket_reviewer?: WorkspacePerson | null
  allowed_transitions: WorkspaceTransition[]
  stages: WorkspaceStage[]
  deadlines: WorkspaceDeadline[]
  deadline_summary: {
    overdue: number
    next_7_days: number
    next_30_days: number
    next_90_days: number
    unreviewed: number
    nearest_due_date?: string | null
  }
  events: Array<{ id: number; event_type: string; event_date: string; notes?: string | null; creator?: WorkspacePerson | null }>
  tasks: WorkspaceTask[]
  documents: WorkspaceDocument[]
  related_matters: RelatedMatter[]
  elevations: Array<{ id: number; from_docket?: string | null; to_docket?: string | null; from_service_code?: string | null; to_service_code?: string | null; elevated_at?: string | null; note?: string | null }>
  financials?: {
    invoices: WorkspaceInvoice[]
    ledger: WorkspaceLedger[]
    summary: { total_invoiced: number; total_received: number; total_pending: number }
  } | null
  audit: WorkspaceAudit[]
  timeline: WorkspaceTimelineItem[]
  capabilities: {
    can_update: boolean
    can_manage_docket: boolean
    can_view_financials: boolean
    can_view_audit: boolean
  }
}

export interface MatterWorkspaceResponse {
  data: MatterWorkspace
}
