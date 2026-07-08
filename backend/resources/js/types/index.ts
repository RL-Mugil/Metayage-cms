// Core entity interfaces for MYPL-CMS.
// These match the JSON shapes returned by the Laravel API.

export type UserRole =
  | 'super_admin' | 'partner' | 'manager' | 'hr'
  | 'finance' | 'associate' | 'paralegal' | 'client' | 'client_admin'

export type UserStatus = 'Active' | 'Inactive'

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
  status: UserStatus
  avatar_url?: string | null
  permissions?: Record<string, string>
}

export interface PaginatedResponse<T> {
  data: T[]
  total: number
  per_page: number
  current_page: number
  last_page: number
  has_more: boolean
}

// ── Clients ──────────────────────────────────────────────────────────────

export type GstType = 'B2B' | 'B2C' | 'Export' | 'Unregistered'
export type ClientStatus = 'Active' | 'Inactive' | 'Prospect' | 'On Hold'
export type ClientType = 'individual' | 'organization'

export interface ClientContact {
  id: number
  client_id: number
  name: string
  email?: string | null
  phone?: string | null
  role_type?: string | null
}

export interface Client {
  id: number
  client_code: string
  company_name: string
  legal_name?: string | null
  client_type: ClientType
  nationality: string
  gst_type: GstType
  has_gstin: boolean
  gstin?: string | null
  pan_number?: string | null
  cin_number?: string | null
  status: ClientStatus
  portal_enabled: boolean
  account_manager_id?: number | null
  contacts?: ClientContact[]
  projects_count?: number
  created_at: string
  updated_at: string
}

// ── Projects ─────────────────────────────────────────────────────────────

export type ProjectStatus = 'Open' | 'In Progress' | 'On Hold' | 'Closed' | 'Completed'
export type Urgency = 'Low' | 'Medium' | 'High' | 'Critical'
export type StageStatus = 'Pending' | 'In Progress' | 'Completed'

export interface ProjectStage {
  id: number
  project_id: number
  stage_name: string
  status: StageStatus
  sequence_order: number
  due_date?: string | null
}

export interface Project {
  id: number
  project_code: string
  docket_number: string
  project_name: string
  project_type: string
  case_type?: string | null
  patent_office_code?: string | null
  status: ProjectStatus
  urgency: Urgency
  hard_deadline?: string | null
  filing_date?: string | null
  client_id: number
  assigned_partner_id?: number | null
  assigned_manager_id?: number | null
  patent_engineer_id?: number | null
  client?: Pick<Client, 'id' | 'company_name' | 'client_code'>
  stages?: ProjectStage[]
  created_at: string
  updated_at: string
}

// ── Tasks ─────────────────────────────────────────────────────────────────

export type TaskStatus = 'Pending' | 'In Progress' | 'Review' | 'Completed' | 'Blocked'
export type TaskPriority = 'Low' | 'Medium' | 'High' | 'Urgent'

export interface Task {
  id: number
  title: string
  status: TaskStatus
  priority: TaskPriority
  due_date?: string | null
  project_id?: number | null
  assignee_id?: number | null
  reviewer_id?: number | null
  billable: boolean
  estimated_hours?: number | null
  actual_hours?: number | null
  project?: Pick<Project, 'id' | 'project_code' | 'project_name'>
  assignee?: Pick<User, 'id' | 'name'>
  created_at: string
}

// ── Invoices & Financial ──────────────────────────────────────────────────

export type InvoiceStatus =
  | 'Draft' | 'Sent' | 'Viewed' | 'Partially Paid' | 'Paid' | 'Overdue' | 'Cancelled'

export interface InvoiceItem {
  id?: number
  description: string
  quantity: number
  unit_rate: number
  amount: number
  tax_rate: number
}

export interface Invoice {
  id: number
  invoice_code: string
  client_id: number
  project_id?: number | null
  status: InvoiceStatus
  subtotal: number
  tax_amount: number
  total_amount: number
  balance_due: number
  issue_date: string
  due_date: string
  currency: string
  payment_terms?: string | null
  client?: Pick<Client, 'id' | 'company_name' | 'client_code'>
  items?: InvoiceItem[]
  created_at: string
}

// ── HRMS ─────────────────────────────────────────────────────────────────

export type EmploymentStatus = 'Active' | 'Inactive' | 'On Leave' | 'Terminated'
export type EmploymentType = 'Full-time' | 'Part-time' | 'Contract'

export interface Department {
  id: number
  name: string
}

export interface Designation {
  id: number
  title: string
  grade_band?: string | null
}

export interface Employee {
  id: number
  employee_code: string
  user_id: number
  full_name: string
  work_email: string
  phone?: string | null
  department_id?: number | null
  designation_id?: number | null
  employment_type: EmploymentType
  employment_status: EmploymentStatus
  work_location: string
  date_of_joining: string
  salary?: number | null
  department?: Department
  designation?: Designation
  user?: Pick<User, 'id' | 'name' | 'email' | 'role'>
}

export interface Attendance {
  id: number
  employee_id: number
  attendance_date: string
  check_in?: string | null
  check_out?: string | null
  status: string
  duration_minutes?: number | null
  capture_method?: string | null
}

export type LeaveStatus = 'Pending' | 'Approved' | 'Rejected' | 'Cancelled'
export type LeaveType = 'Earned Leave' | 'Casual Leave' | 'Sick Leave' | 'LOP'

export interface LeaveRequest {
  id: number
  employee_id: number
  employee_name?: string
  leave_type: LeaveType
  from_date: string
  to_date: string
  total_days: number
  reason?: string | null
  status: LeaveStatus
  comments?: string | null
  is_mine?: boolean
}

export interface LeaveBalance {
  id: number
  employee_id: number
  year: number
  earned_leave: number
  casual_leave: number
  sick_leave: number
  lop_days: number
}

// ── Payroll ───────────────────────────────────────────────────────────────

export type PayrollRunStatus = 'Processing' | 'Draft' | 'Finalized' | 'Paid' | 'Failed'

export interface Payslip {
  id: number
  payroll_run_id: number
  employee_id: number
  basic: number
  hra: number
  special_allowance: number
  gross_pay: number
  pf_employee: number
  esi_employee: number
  professional_tax: number
  tds: number
  total_deductions: number
  net_pay: number
  lop_days: number
  employee?: Pick<Employee, 'id' | 'full_name' | 'employee_code'>
}

export interface PayrollRun {
  id: number
  month: number
  year: number
  status: PayrollRunStatus
  run_by_id: number
  payslips?: Payslip[]
  created_at: string
}

// ── Notifications ─────────────────────────────────────────────────────────

export interface Notification {
  id: number
  user_id: number
  title: string
  message: string
  is_read: boolean
  action_url?: string | null
  created_at: string
}

// ── Reports ───────────────────────────────────────────────────────────────

export interface ReportResponse {
  type: string
  rows: Record<string, unknown>[]
  total: number
  per_page: number
  current_page: number
  last_page: number
  generated_at: string
}

// ── AI ────────────────────────────────────────────────────────────────────

export interface AIResponse {
  response: string
  sql_query: string | null
  results: Record<string, unknown>[]
}

// ── Dashboard ─────────────────────────────────────────────────────────────

export interface DashboardMetrics {
  metrics: Record<string, number>
  charts: {
    revenue_trend?: Array<{ month: string; revenue: number }>
    matter_types?: Array<{ type: string; count: number }>
    [key: string]: unknown
  }
}
