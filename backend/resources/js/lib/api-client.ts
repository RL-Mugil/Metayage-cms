import type {
  User, Client, ClientContact, Project, Task, Invoice, InvoiceItem,
  Employee, Attendance, LeaveRequest, LeaveBalance, PayrollRun, Payslip, LopAdjustment,
  SalaryComponent, ReimbursementType, ExpenseClaim,
  Notification, ReportResponse, AIResponse, DashboardMetrics,
  PaginatedResponse, SearchResult, Quotation, AuditLog, PatentInvoiceIn, ProjectTab,
  CodeRow, CodeRenameImpact, CodeRenameResult, CodeMergeAvailable, CodeMergeImpact, CodeMergeResult,
} from '@/types'
import type { MatterWorkspace, MatterWorkspaceResponse } from '@/types/matter-workspace'
import type { ZohoQuotePreview } from '@/types/zoho'

function getCsrfToken(): string {
  if (typeof document === 'undefined') return ''
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

export type { User, Client, Project, Task, Invoice, Employee, PaginatedResponse, SearchResult, Quotation, AuditLog, PatentInvoiceIn, ProjectTab, CodeRow, CodeRenameImpact, CodeRenameResult, CodeMergeAvailable, CodeMergeImpact, CodeMergeResult }

export interface ReminderProfile {
  id: number
  name: string
  frequency: 'daily' | 'weekly' | 'monthly'
  timezone: string
  send_time: string
  horizon_days: number
  recipients: string[]
  filters: Record<string, string | number | boolean | null>
  columns: string[]
  color_bands: { red: number; amber: number }
  send_empty: boolean
  email_enabled: boolean
  in_app_enabled: boolean
  critical_alerts_enabled: boolean
  active: boolean
}

export interface DocketWorklistItem {
  id: number
  title: string
  statutory_due_date: string
  operational_due_date: string
  days_remaining: number
  band: 'overdue' | 'red' | 'amber' | 'green'
  risk_level: string
  review_status: string
  rule_code: string | null
  record: { id: number; code: string; type: string; title: string } | null
  project: { id: number; docket_number: string; name: string } | null
  client: { id: number; code: string; name: string } | null
}

export interface IpRecord {
  id: number
  record_code: string
  record_type: 'Patent' | 'Trademark'
  jurisdiction: string
  title: string
  client_reference: string | null
  legal_status: string
  status_date: string | null
  data_quality_status: string
  tags: string[]
  uins: string[]
  client?: { id: number; client_code: string; name: string }
  responsible_user?: { id: number; name: string } | null
  backup_user?: { id: number; name: string } | null
}

export interface ZohoBooksCase {
  project_id: number
  docket_number: string
  source: 'uin' | 'docket'
}

export interface ZohoBooksRecord {
  number: string | null
  date: string | null
  status: string | null
  total: number
  balance: number | null
  url: string | null
  application_no: string | null
  patent_office: string | null
  case: ZohoBooksCase | null
}

export interface ZohoClientSummary {
  outstanding_balance: number
  opening_balance: number
  total_receivables: number
  opening_balances: Array<{ id: number; customer_name: string | null; balance_date: string | null; amount: number; balance: number; currency: string; source: string }>
  invoices: ZohoBooksRecord[]
  estimates: ZohoBooksRecord[]
}

export interface ZohoOpeningBalanceRow {
  id: number; zoho_contact_id: string; client_id: number | null; customer_name: string | null; client_code: string | null;
  balance_date: string | null; amount: number; balance: number; currency: string; source: string; synced_at: string | null;
}

export interface ZohoAllRow {
  can_refresh: boolean
  duplicate_number: boolean
  id: number
  type: 'invoice' | 'quote'
  number: string | null
  client: string | null
  client_code: string | null
  project_id: number | null
  docket_number: string | null
  project_name: string | null
  date: string | null
  due_date: string | null
  status: string | null
  total: number
  balance: number | null
  currency: string
  url: string | null
  match_source: 'uin' | 'docket' | 'manual' | 'export' | null
  synced_at: string | null
}

export interface FinancialAnalyticsSource {
  invoice_count: number; quote_count: number; billed: number; collected: number; outstanding: number;
  opening_balance: number; total_receivables: number;
  collection_rate: number; overdue_count: number; converted_quote_count: number; conversion_rate: number;
  monthly: Array<{ month: string; billed: number; collected: number }>;
}
export interface FinancialAnalytics {
  currency: 'INR'; generated_at: string;
  portal: FinancialAnalyticsSource; zoho: FinancialAnalyticsSource;
  comparison: { portal_invoice_count: number; zoho_invoice_count: number; linked_zoho_invoice_count: number; unlinked_zoho_invoice_count: number; portal_billed: number; zoho_billed: number; billed_difference: number; portal_outstanding: number; zoho_outstanding: number; zoho_opening_balance: number; zoho_total_receivables: number; outstanding_difference: number };
}

export const api = {
  async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const method = (options.method || 'GET').toUpperCase()
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(options.headers as Record<string, string> || {}),
    }
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      headers['X-XSRF-TOKEN'] = getCsrfToken()
    }
    const response = await fetch(`/api${endpoint}`, {
      ...options, headers, credentials: 'same-origin',
    })
    if (!response.ok) {
      if (response.status === 401) { window.location.href = '/login'; return null as unknown as T }
      const errData = await response.json().catch(() => ({}))
      // Laravel validation errors carry a generic "message" ("The given data was
      // invalid.") plus a field-keyed "errors" map with the actual specific text —
      // prefer the specific message when present so e.g. business-rule conflicts
      // (duplicate tab codes, wrong current password, ...) are visible to the user.
      const fieldErrors = (errData as { errors?: Record<string, string[]> }).errors
      const firstFieldError = fieldErrors ? Object.values(fieldErrors)[0]?.[0] : undefined
      throw new Error(firstFieldError || (errData as { message?: string }).message || `Request failed: ${response.status}`)
    }
    return response.json()
  },

  // ── Global search ──
  async globalSearch(q: string): Promise<{ results: SearchResult[]; total: number }> {
    return this.request(`/search?q=${encodeURIComponent(q)}`)
  },

  // ── Users ──
  async getUsers(): Promise<User[]> { return this.request('/users') },

  // ── Dashboard ──
  async getDashboardMetrics(roleFilter?: string): Promise<DashboardMetrics> {
    const q = roleFilter && roleFilter !== 'all' ? `?role_filter=${roleFilter}` : ''
    return this.request(`/dashboard/metrics${q}`)
  },

  // ── Clients ──
  async getClientStats(): Promise<{ total: number; active: number; inactive: number; prospect: number; b2b: number; b2c: number; export: number; unregistered: number }> {
    return this.request('/clients/stats')
  },
  async getProjectStats(roleFilter?: string, tab?: string): Promise<{ total: number; open: number; in_progress: number; on_hold: number; overdue: number }> {
    const params = new URLSearchParams()
    if (roleFilter && roleFilter !== 'all') params.set('role_filter', roleFilter)
    if (tab) params.set('tab', tab)
    const q = params.toString()
    return this.request(`/projects/stats${q ? `?${q}` : ''}`)
  },
  async getMatterWorkspace(projectId: number | string): Promise<MatterWorkspace> {
    const response = await this.request<MatterWorkspaceResponse>(`/projects/${projectId}/workspace`)
    return response.data
  },
  async verifyClientEntity(id: number | string): Promise<{
    gstin?: { status: string; message: string; matches?: { name: string; address: string }; fetched?: { name: string | null; address: string | null } }
    cin?: { status: string; message: string; matches?: { name: string; address: string }; fetched?: { name: string | null; address: string | null } }
  }> {
    return this.request(`/clients/${id}/verify-entity`, { method: 'POST' })
  },
  async getClients(params?: string | URLSearchParams): Promise<PaginatedResponse<Client>> {
    let query = '';
    if (params instanceof URLSearchParams) {
      query = '?' + params.toString();
    } else if (typeof params === 'string') {
      query = `?search=${encodeURIComponent(params)}`;
    }
    return this.request(`/clients${query}`);
  },
  async getAllClients(search?: string): Promise<Client[]> {
    const perPage = 2000
    let page = 1
    let allClients: Client[] = []

    while (true) {
      const params = new URLSearchParams({
        per_page: String(perPage),
        page: String(page),
      })
      if (search) params.set('search', search)

      const response = await this.getClients(params)
      allClients = allClients.concat(response.data ?? [])

      if (!response.has_more || page >= response.last_page) break
      page += 1
    }

    return allClients
  },
  async getClient(id: number | string): Promise<Client> { return this.request(`/clients/${id}`) },
  async createClient(data: Partial<Client>): Promise<Client> {
    return this.request('/clients', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateClient(id: number | string, data: Partial<Client>): Promise<Client> {
    return this.request(`/clients/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteClient(id: number | string): Promise<{ message: string }> {
    return this.request(`/clients/${id}`, { method: 'DELETE' })
  },
  async importClients(formData: FormData): Promise<{ imported: number; skipped: number; errors: string[] } | { requires_confirmation: true; duplicates: { line: number; name: string; reason: string }[] }> {
    const response = await fetch('/api/clients/import', {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrfToken() },
      body: formData,
      credentials: 'same-origin',
    })
    if (!response.ok) {
      if (response.status === 401) { window.location.href = '/login'; return null as any }
      const errData = await response.json().catch(() => ({}))
      throw new Error((errData as { message?: string }).message || `Import failed: ${response.status}`)
    }
    return response.json()
  },
  async addClientContact(id: number | string, data: Partial<ClientContact>): Promise<ClientContact> {
    return this.request(`/clients/${id}/contacts`, { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Cases / Projects ──
  async getProjects(search?: string, roleFilter?: string): Promise<Project[]> {
    const params = new URLSearchParams({ per_page: '500' })
    if (search) params.set('search', search)
    if (roleFilter && roleFilter !== 'all') params.set('role_filter', roleFilter)
    const res = await this.request<PaginatedResponse<Project> | Project[]>(`/projects?${params.toString()}`)
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Project>).data ?? [])
  },
  async getProjectsPaged(params?: URLSearchParams): Promise<PaginatedResponse<Project>> {
    const query = params ? '?' + params.toString() : ''
    return this.request(`/projects${query}`)
  },
  // Unpaginated (capped at 20000), same filters as getProjectsPaged — for "export everything
  // matching the current view" rather than just the visible page.
  async getProjectsExport(params?: URLSearchParams): Promise<Project[]> {
    const p = new URLSearchParams(params)
    p.set('export', '1')
    const res = await this.request<{ data: Project[] }>(`/projects?${p.toString()}`)
    return res.data ?? []
  },
  async getProject(id: number | string): Promise<Project> { return this.request(`/projects/${id}`) },
  async createProject(data: Partial<Project>): Promise<Project> {
    return this.request('/projects', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateProject(id: number | string, data: Partial<Project>): Promise<Project> {
    return this.request(`/projects/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteProject(id: number | string): Promise<{ message: string }> {
    return this.request(`/projects/${id}`, { method: 'DELETE' })
  },
  async updateProjectStage(id: number | string, stageName: string): Promise<Project> {
    return this.request(`/projects/${id}/stage`, { method: 'POST', body: JSON.stringify({ stage_name: stageName }) })
  },
  async createFamilyEngagement(familyId: number | string, data: {
    source_project_id: number
    patent_office_code: string
    service_code: string
    application_number?: string
    complete_source?: boolean
    filing_date?: string
    note?: string
  }): Promise<{ message: string; project: Project }> {
    return this.request(`/invention-families/${familyId}/engagements`, { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Docketing engine ──
  async getProjectDocket(projectId: number | string): Promise<any> {
    return this.request(`/projects/${projectId}/docket`)
  },
  async recordDocketEvent(projectId: number | string, data: { event_type: string; event_date: string; notes?: string }): Promise<any> {
    return this.request(`/projects/${projectId}/docket/events`, { method: 'POST', body: JSON.stringify(data) })
  },
  // Dedicated endpoint (not a plain docket event) — marks the renewal schedule Paid through
  // the given year, anchors later years to the payment date, advances the renewal pipeline
  // stages, and may auto-create the REN/RNF successor engagement on first use.
  async recordRenewalPaid(projectId: number | string, data: { paid_through_year: number; paid_date: string; notes?: string }): Promise<any> {
    return this.request(`/projects/${projectId}/docket/renewal-paid`, { method: 'POST', body: JSON.stringify(data) })
  },
  // A Grant/Refusal-order or stage-gated document upload prompt was skipped — tracks it as
  // a Task + daily reminder + in-app notification until a matching Document is uploaded.
  async requireDocument(projectId: number | string, data: { category: string; context_label: string }): Promise<{ ok: boolean }> {
    return this.request(`/projects/${projectId}/require-document`, { method: 'POST', body: JSON.stringify(data) })
  },
  async updateDocketEvent(eventId: number | string, data: { event_type: string; event_date: string; notes?: string | null }): Promise<any> {
    return this.request(`/docket/events/${eventId}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteDocketEvent(eventId: number | string): Promise<{ message: string }> {
    return this.request(`/docket/events/${eventId}`, { method: 'DELETE' })
  },
  async createDocketDeadline(projectId: number | string, data: { title: string; due_date: string; extended_due_date?: string | null; legal_basis?: string | null; notes?: string | null; risk_level: 'Low' | 'Medium' | 'High' | 'Critical' }): Promise<any> {
    return this.request(`/projects/${projectId}/docket/deadlines`, { method: 'POST', body: JSON.stringify(data) })
  },
  async updateDocketDeadline(deadlineId: number | string, data: { title?: string; due_date?: string; extended_due_date?: string | null; legal_basis?: string | null; notes?: string | null; risk_level?: 'Low' | 'Medium' | 'High' | 'Critical'; status?: string }): Promise<any> {
    return this.request(`/docket/deadlines/${deadlineId}`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  async deleteDocketDeadline(deadlineId: number | string): Promise<{ message: string }> {
    return this.request(`/docket/deadlines/${deadlineId}`, { method: 'DELETE' })
  },
  async getDeadlineRules(): Promise<any[]> {
    return this.request('/docket/rules')
  },
  async updateDeadlineRule(ruleId: number | string, status: 'Approved' | 'Retired'): Promise<any> {
    return this.request(`/docket/rules/${ruleId}`, { method: 'PATCH', body: JSON.stringify({ status }) })
  },
  async updateRenewal(renewalId: number | string, status: string): Promise<any> {
    return this.request(`/docket/renewals/${renewalId}`, { method: 'PATCH', body: JSON.stringify({ status }) })
  },
  // Manual renewal-year entry for cases outside the IN S.53 auto-generation path (e.g. non-IN jurisdictions).
  async addRenewal(projectId: number | string, data: { renewal_year: number; due_date: string }): Promise<any> {
    return this.request(`/projects/${projectId}/docket/renewals`, { method: 'POST', body: JSON.stringify(data) })
  },
  // Fields the IPO-style status view needs that nothing else auto-generates.
  async updateApplicationStatusFields(projectId: number | string, data: { application_type?: string | null; fer_reply_date?: string | null; certificate_issue_date?: string | null; post_grant_journal_date?: string | null; grant_number?: string | null }): Promise<any> {
    return this.request(`/projects/${projectId}/docket/application`, { method: 'PATCH', body: JSON.stringify(data) })
  },
  // ── Renewal approve → invoice → proof → confirm loop (PatentInvoiceIn-backed) ──
  async getPendingPayments(params?: URLSearchParams): Promise<any> {
    const q = params ? `?${params.toString()}` : ''
    return this.request(`/pending-payments${q}`)
  },
  async approveRenewal(projectId: number | string, years: number): Promise<any> {
    return this.request(`/projects/${projectId}/renewals/approve`, { method: 'POST', body: JSON.stringify({ years }) })
  },
  async submitRenewalProof(invoiceId: number | string, documentId: number): Promise<any> {
    return this.request(`/pending-payments/${invoiceId}/proof`, { method: 'POST', body: JSON.stringify({ document_id: documentId }) })
  },
  async confirmRenewalReceipt(invoiceId: number | string): Promise<any> {
    return this.request(`/pending-payments/${invoiceId}/confirm`, { method: 'POST' })
  },
  async postRenewalStatusNote(invoiceId: number | string, note: string): Promise<any> {
    return this.request(`/pending-payments/${invoiceId}/status-note`, { method: 'POST', body: JSON.stringify({ status_note: note }) })
  },
  // Approves an auto-generated renewal quote (90-day-prior estimate) — marks it Accepted,
  // does NOT create an invoice; staff raise the real invoice separately.
  async approveRenewalQuote(invoiceId: number | string): Promise<any> {
    return this.request(`/pending-payments/${invoiceId}/approve-quote`, { method: 'POST' })
  },
  // ── Inventor role (Phase 3) ──
  async getProjectInventors(projectId: number | string): Promise<any[]> {
    return this.request(`/projects/${projectId}/inventors`)
  },
  async addProjectInventor(projectId: number | string, data: { name: string; email: string; password?: string }): Promise<any> {
    return this.request(`/projects/${projectId}/inventors`, { method: 'POST', body: JSON.stringify(data) })
  },
  async removeProjectInventor(projectId: number | string, userId: number): Promise<any> {
    return this.request(`/projects/${projectId}/inventors/${userId}`, { method: 'DELETE' })
  },
  async getUpcomingDeadlines(days = 90): Promise<any[]> {
    return this.request(`/docket/upcoming?days=${days}`)
  },

  // ── Tasks ──
  async getTasks(status?: string): Promise<Task[]> {
    const res = await this.request<PaginatedResponse<Task> | Task[]>(`/tasks${status ? `?status=${encodeURIComponent(status)}` : ''}`)
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Task>).data ?? [])
  },
  async createTask(data: Partial<Task>): Promise<Task> {
    return this.request('/tasks', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateTask(id: number | string, data: Partial<Task>): Promise<Task> {
    return this.request(`/tasks/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteTask(id: number | string): Promise<{ message: string }> {
    return this.request(`/tasks/${id}`, { method: 'DELETE' })
  },
  async logTimeEntry(data: {
    project_id: number; task_id?: number | null
    duration_hours: number; entry_date: string; description: string; billable?: boolean
  }): Promise<{ id: number }> {
    return this.request('/tasks/time-entries', { method: 'POST', body: JSON.stringify(data) })
  },

  // ── HRMS ──
  async getHRMSStats(): Promise<{ total: number; active: number; on_leave: number; departments: number }> {
    return this.request('/hrms/stats')
  },
  async getEmployees(params?: Record<string, string | number | boolean>): Promise<Employee[]> {
    const query = params ? '?' + new URLSearchParams(params as Record<string, string>).toString() : ''
    const res = await this.request<PaginatedResponse<Employee> | Employee[]>(`/hrms/employees${query}`)
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Employee>).data ?? [])
  },
  async getEmployeesPaged(params?: URLSearchParams): Promise<PaginatedResponse<Employee>> {
    const query = params ? '?' + params.toString() : ''
    return this.request(`/hrms/employees${query}`)
  },
  async createEmployee(data: Partial<Employee>): Promise<Employee> {
    return this.request('/hrms/employees', { method: 'POST', body: JSON.stringify(data) })
  },
  async inviteTeamMember(data: { name: string; email: string }): Promise<{ ok: boolean; message: string; user: User }> {
    return this.request('/hrms/invitations', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateEmployee(id: number | string, data: Partial<Employee>): Promise<Employee> {
    return this.request(`/hrms/employees/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async verifyEmployeePan(id: number | string): Promise<{
    status: string
    name_on_record: string | null
    dob_on_record?: string | null
    message: string
    matches?: { name: string; dob: string }
    fetched?: { name: string | null; dob: string | null }
  }> {
    return this.request(`/employees/${id}/verify-pan`, { method: 'POST' })
  },
  async startDigiLockerConsent(id: number | string, documentType: 'AADHAAR' | 'PAN'): Promise<{ consent_url: string }> {
    return this.request(`/employees/${id}/digilocker/consent`, { method: 'POST', body: JSON.stringify({ document_type: documentType }) })
  },
  async startMyDigiLockerConsent(): Promise<{ consent_url: string }> {
    return this.request('/me/digilocker/consent', { method: 'POST', body: JSON.stringify({ document_type: 'AADHAAR' }) })
  },
  async getMyDigiLockerStatus(): Promise<{
    has_employee_record: boolean
    has_aadhaar?: boolean
    aadhaar_verification_status?: string
    fetched?: { name: string | null; dob: string | null; address: string | null }
  }> {
    return this.request('/me/digilocker/status')
  },
  async deleteEmployee(id: number | string): Promise<{ message: string }> {
    return this.request(`/hrms/employees/${id}`, { method: 'DELETE' })
  },
  async updatePortalAccess(id: number | string, enabled: boolean): Promise<{ enabled: boolean; status: string }> {
    return this.request(`/hrms/employees/${id}/portal-access`, { method: 'PUT', body: JSON.stringify({ enabled }) })
  },
  async resetEmployeeToday(id: number | string): Promise<{ message: string }> {
    return this.request(`/hrms/employees/${id}/reset-today`, { method: 'POST' })
  },
  async getAttendance(): Promise<Attendance[]> { return this.request('/hrms/attendance') },
  async clockIn(locationGps?: string): Promise<Attendance> {
    return this.request('/hrms/clock-in', { method: 'POST', body: JSON.stringify({ location_gps: locationGps }) })
  },
  async clockOut(): Promise<Attendance> {
    return this.request('/hrms/clock-out', { method: 'POST' })
  },
  async getAttendanceSettings(): Promise<{ max_sessions_per_day: number; work_start_time: string; work_end_time: string; lunch_start: string; lunch_end: string; standard_hours_minutes: number }> {
    return this.request('/hrms/attendance/settings')
  },
  async updateAttendanceSettings(data: { max_sessions_per_day?: number; work_start_time?: string; work_end_time?: string; lunch_start?: string; lunch_end?: string; standard_hours_minutes?: number }): Promise<{ message: string; settings: object }> {
    return this.request('/hrms/attendance/settings', { method: 'PUT', body: JSON.stringify(data) })
  },
  async getAdminAttendance(params?: URLSearchParams): Promise<{ data: object[]; total: number }> {
    const query = params ? '?' + params.toString() : ''
    return this.request(`/hrms/admin-attendance${query}`)
  },
  async createAdminAttendance(data: object): Promise<object> {
    return this.request('/hrms/admin-attendance', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateAdminAttendance(id: number, data: object): Promise<object> {
    return this.request(`/hrms/admin-attendance/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteAdminAttendance(id: number): Promise<{ message: string }> {
    return this.request(`/hrms/admin-attendance/${id}`, { method: 'DELETE' })
  },
  async getAttendanceReport(month: number, year: number): Promise<object> {
    return this.request(`/hrms/attendance/report?month=${month}&year=${year}`)
  },
  async getLeaves(): Promise<{ requests: LeaveRequest[]; balances: LeaveBalance | null; is_approver: boolean }> {
    return this.request('/leaves')
  },
  async applyLeave(data: { leave_type: string; from_date: string; to_date: string; reason?: string }): Promise<LeaveRequest> {
    return this.request('/leaves', { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Financial ──
  async getFinancialStats(): Promise<{ total_billed: number; total_received: number; total_outstanding: number; overdue_count: number; draft_count: number; paid_count: number }> {
    return this.request('/financial/stats')
  },
  async getFinancialAnalytics(): Promise<FinancialAnalytics> { return this.request('/financial/analytics') },
  async getInvoices(params?: URLSearchParams): Promise<Invoice[]> {
    const query = params ? '?' + params.toString() : ''
    const res = await this.request<PaginatedResponse<Invoice> | Invoice[]>(`/financial/invoices${query}`)
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Invoice>).data ?? [])
  },
  async getInvoicesPaged(params?: URLSearchParams): Promise<PaginatedResponse<Invoice>> {
    const query = params ? '?' + params.toString() : ''
    return this.request(`/financial/invoices${query}`)
  },
  async createInvoice(data: Partial<Invoice> & { items?: Partial<InvoiceItem>[] }): Promise<Invoice> {
    return this.request('/financial/invoices', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateInvoice(id: number | string, data: Partial<Invoice>): Promise<Invoice> {
    return this.request(`/financial/invoices/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteInvoice(id: number | string): Promise<{ message: string }> {
    return this.request(`/financial/invoices/${id}`, { method: 'DELETE' })
  },
  async getQuotations(params?: URLSearchParams): Promise<PaginatedResponse<Quotation>> {
    const query = params ? '?' + params.toString() : ''
    return this.request(`/financial/quotations${query}`)
  },
  async createQuotation(data: Partial<Quotation>): Promise<Quotation> {
    return this.request('/financial/quotations', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateQuotation(id: number | string, data: Partial<Quotation>): Promise<Quotation> {
    return this.request(`/financial/quotations/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteQuotation(id: number | string): Promise<{ message: string }> {
    return this.request(`/financial/quotations/${id}`, { method: 'DELETE' })
  },
  async convertQuotationToInvoice(id: number | string): Promise<Invoice> {
    return this.request(`/financial/quotations/${id}/convert`, { method: 'POST' })
  },
  async batchUpdateInvoices(ids: number[], action: string, extra?: Record<string, unknown>): Promise<{ updated: number; skipped: number; errors: string[] }> {
    return this.request('/financial/invoices/batch', { method: 'POST', body: JSON.stringify({ ids, action, ...extra }) })
  },
  async getAuditLogs(params?: URLSearchParams): Promise<PaginatedResponse<AuditLog>> {
    const query = params ? '?' + params.toString() : ''
    return this.request(`/audit-logs${query}`)
  },

  // ── Patent Invoices — Indian clients ──
  async getPatentInvoicesIn(params?: URLSearchParams): Promise<PaginatedResponse<PatentInvoiceIn>> {
    const query = params ? '?' + params.toString() : ''
    return this.request(`/patent-invoices/in${query}`)
  },
  async createPatentInvoiceIn(data: Partial<PatentInvoiceIn>): Promise<PatentInvoiceIn> {
    return this.request('/patent-invoices/in', { method: 'POST', body: JSON.stringify(data) })
  },
  async updatePatentInvoiceIn(id: number, data: Partial<PatentInvoiceIn>): Promise<PatentInvoiceIn> {
    return this.request(`/patent-invoices/in/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deletePatentInvoiceIn(id: number): Promise<{ message: string }> {
    return this.request(`/patent-invoices/in/${id}`, { method: 'DELETE' })
  },
  async batchUpdatePatentInvoicesIn(ids: number[], action: string): Promise<{ updated: number }> {
    return this.request('/patent-invoices/in/batch', { method: 'POST', body: JSON.stringify({ ids, action }) })
  },
  async convertPatentQuoteToInvoice(id: number): Promise<PatentInvoiceIn> {
    return this.request(`/patent-invoices/in/${id}/convert`, { method: 'POST' })
  },
  async recordPayment(data: {
    invoice_id: number; amount: number; payment_method: string
    transaction_reference?: string; notes?: string
  }): Promise<any> {
    return this.request('/financial/payments', { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Reports ──
  async getReportData(type: string, params?: URLSearchParams): Promise<ReportResponse> {
    const extra = params ? '&' + params.toString() : ''
    return this.request(`/reports/data?type=${encodeURIComponent(type)}${extra}`)
  },
  async generateReport(data: { type: string; format: "PDF" | "Excel" | "CSV"; fromDate?: string; toDate?: string; clientCode?: string }): Promise<ReportResponse & { export_id: number }> {
    return this.request('/reports/generate', { method: 'POST', body: JSON.stringify({
      type: data.type,
      format: data.format,
      from_date: data.fromDate || null,
      to_date: data.toDate || null,
      client_code: data.clientCode?.trim() || null,
    }) })
  },
  async getReportHistory(): Promise<Array<{ id: number; name: string; type: string; generated_by: string; generated_at: string; format: string; row_count: number; filters: Record<string, string | null> }>> {
    return this.request('/reports/history')
  },
  async getReportHistoryItem(id: number): Promise<{ id: number; name: string; type: string; format: string; generated_at: string; rows: Record<string, unknown>[]; filters: Record<string, string | null> }> {
    return this.request(`/reports/history/${id}`)
  },

  // ── AI ──
  async queryAI(query: string): Promise<AIResponse> {
    return this.request('/ai/query', { method: 'POST', body: JSON.stringify({ query }) })
  },

  // ── Calendar ──
  async getCalendarEvents(): Promise<Record<string, unknown>[]> { return this.request('/calendar/events') },

  // ── Analytics ──
  async getTrackerAnalytics(): Promise<Record<string, unknown>> { return this.request('/analytics/tracker') },

  // ── Notifications ──
  async getNotifications(): Promise<Notification[]> {
    const res = await this.request<PaginatedResponse<Notification> | Notification[]>('/notifications')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Notification>).data ?? [])
  },
  async getUnreadNotificationCount(): Promise<number> {
    const res = await this.request<{ count: number }>('/notifications/unread-count')
    return res?.count ?? 0
  },
  async markAllNotificationsRead(): Promise<{ message: string }> { return this.request('/notifications/mark-all-read', { method: 'POST' }) },
  async markNotificationRead(id: number | string): Promise<{ message: string }> { return this.request(`/notifications/${id}/read`, { method: 'POST' }) },
  async dismissNotification(id: number | string): Promise<{ message: string }> { return this.request(`/notifications/${id}`, { method: 'DELETE' }) },

  // ── Project Tracker ──
  async getTrackerProjects(circle?: string, q?: string): Promise<Project[]> {
    const params = new URLSearchParams();
    if (circle) params.set('circle', circle);
    if (q) params.set('q', q);
    const qs = params.toString();
    return this.request(`/tracker/projects${qs ? `?${qs}` : ''}`)
  },
  async getTrackerCircles(): Promise<Record<string, unknown>[]> { return this.request('/tracker/circles') },
  async getTrackerRows(circle: string): Promise<Record<string, unknown>[]> {
    return this.request(`/tracker/rows?circle=${circle}`)
  },
  async createTrackerRow(data: { circle_slug: string }): Promise<Record<string, unknown>> {
    return this.request('/tracker/rows', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateTrackerRow(id: number | string, data: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.request(`/tracker/rows/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteTrackerRow(id: number | string): Promise<{ message: string }> {
    return this.request(`/tracker/rows/${id}`, { method: 'DELETE' })
  },
  async addCircleMember(circleId: number | string, userId: number | string): Promise<{ message: string }> {
    return this.request(`/tracker/circles/${circleId}/members`, { method: 'POST', body: JSON.stringify({ user_id: userId }) })
  },
  async removeCircleMember(circleId: number | string, userId: number | string): Promise<{ message: string }> {
    return this.request(`/tracker/circles/${circleId}/members/${userId}`, { method: 'DELETE' })
  },

  // ── Documents ──
  async getDocuments(params?: Record<string, string | number>): Promise<Record<string, unknown>[]> {
    const entries = params ? Object.entries(params).map(([k, v]) => [k, String(v)] as [string, string]) : []
    const query = entries.length ? '?' + new URLSearchParams(entries).toString() : ''
    const res = await this.request<PaginatedResponse<Record<string, unknown>> | Record<string, unknown>[]>(`/documents${query}`)
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Record<string, unknown>>).data ?? [])
  },
  async uploadDocument(file: File, folder?: string, clientId?: number | null, projectId?: number | null): Promise<Record<string, unknown>> {
    const formData = new FormData()
    formData.append('file', file)
    if (folder) formData.append('folder', folder)
    if (clientId) formData.append('client_id', String(clientId))
    if (projectId) formData.append('project_id', String(projectId))
    const response = await fetch('/api/documents', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body: formData,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new Error((err as { message?: string }).message || 'Upload failed')
  }
    return response.json()
  },
  async deleteDocument(path: string): Promise<{ message: string }> {
    return this.request('/documents', { method: 'DELETE', body: JSON.stringify({ path }) })
  },
  async downloadDocument(path: string, name: string): Promise<void> {
    const response = await fetch(`/api/documents/download?path=${encodeURIComponent(path)}`, {
      headers: { Accept: 'application/octet-stream' }, credentials: 'same-origin',
    })
    if (!response.ok) throw new Error('Download failed')
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = name
    link.click()
    URL.revokeObjectURL(url)
  },

  // ── Approvals ──
  async getApprovals(): Promise<Record<string, unknown>[]> {
    const res = await this.request<PaginatedResponse<Record<string, unknown>> | Record<string, unknown>[]>('/approvals')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Record<string, unknown>>).data ?? [])
  },
  async resolveApproval(type: string, id: number, action: 'Approved' | 'Rejected', comment?: string): Promise<{ message: string }> {
    return this.request('/approvals/resolve', { method: 'POST', body: JSON.stringify({ type, id, action, comment }) })
  },

  // ── Discussions ──
  async getDiscussions(): Promise<Record<string, unknown>[]> {
    const res = await this.request<PaginatedResponse<Record<string, unknown>> | Record<string, unknown>[]>('/discussions')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Record<string, unknown>>).data ?? [])
  },
  async createDiscussion(data: { title: string; tag: string; message: string; client_id?: number | null }): Promise<Record<string, unknown>> {
    return this.request('/discussions', { method: 'POST', body: JSON.stringify(data) })
  },
  async replyDiscussion(threadId: number, message: string): Promise<Record<string, unknown>> {
    return this.request(`/discussions/${threadId}/reply`, { method: 'POST', body: JSON.stringify({ message }) })
  },
  async updateDiscussion(threadId: number, data: { title?: string; tag?: string; status?: string }): Promise<any> {
    return this.request(`/discussions/${threadId}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteDiscussion(threadId: number): Promise<any> {
    return this.request(`/discussions/${threadId}`, { method: 'DELETE' })
  },

  // ── Direct messages + thread chat ──
  async getDirectMessages(): Promise<{ data: any[] }> {
    return this.request('/dm')
  },
  async getDmContacts(): Promise<{ data: any[] }> {
    return this.request('/dm/contacts')
  },
  async openDirectMessage(userId: number): Promise<any> {
    return this.request(`/dm/open/${userId}`, { method: 'POST' })
  },
  async getThreadChat(threadId: number | string): Promise<any> {
    return this.request(`/threads/${threadId}/chat`)
  },
  async getThreadChatHistory(threadId: number | string, before: number): Promise<any> {
    return this.request(`/threads/${threadId}/chat/history?before=${before}`)
  },
  async sendThreadChat(
    threadId: number | string,
    payload: { content?: string; mentions?: number[]; attachments?: File[] },
  ): Promise<any> {
    const form = new FormData()
    if (payload.content) form.append('content', payload.content)
    ;(payload.mentions ?? []).forEach((id) => form.append('mentions[]', String(id)))
    ;(payload.attachments ?? []).forEach((f) => form.append('attachments[]', f))
    const response = await fetch(`/api/threads/${threadId}/chat`, {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body: form,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new Error((err as { message?: string }).message || 'Could not send message')
    }
    return response.json()
  },
  async editThreadChatMessage(threadId: number | string, messageId: number, content: string): Promise<any> {
    return this.request(`/threads/${threadId}/chat/messages/${messageId}`, { method: 'PUT', body: JSON.stringify({ content }) })
  },
  async deleteThreadChatMessage(threadId: number | string, messageId: number): Promise<any> {
    return this.request(`/threads/${threadId}/chat/messages/${messageId}`, { method: 'DELETE' })
  },
  async markThreadChatRead(threadId: number | string, lastReadMessageId: number): Promise<any> {
    return this.request(`/threads/${threadId}/chat/read`, { method: 'POST', body: JSON.stringify({ last_read_message_id: lastReadMessageId }) })
  },
  async downloadThreadAttachment(threadId: number | string, path: string, name: string): Promise<void> {
    const response = await fetch(`/api/threads/${threadId}/chat/attachment?path=${encodeURIComponent(path)}`, {
      headers: { Accept: 'application/octet-stream' }, credentials: 'same-origin',
    })
    if (!response.ok) throw new Error('Download failed')
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = name
    link.click()
    URL.revokeObjectURL(url)
  },

  // ── Per-case chat (Google-Chat-style room) ──
  async getProjectChat(projectId: number | string): Promise<any> {
    return this.request(`/projects/${projectId}/chat`)
  },
  async getProjectChatHistory(projectId: number | string, before: number): Promise<any> {
    return this.request(`/projects/${projectId}/chat/history?before=${before}`)
  },
  async getChatUnreadCount(): Promise<{ count: number }> {
    return this.request('/chat/unread-count')
  },
  async sendProjectChat(
    projectId: number | string,
    payload: { content?: string; mentions?: number[]; attachments?: File[] },
  ): Promise<any> {
    const form = new FormData()
    if (payload.content) form.append('content', payload.content)
    ;(payload.mentions ?? []).forEach((id) => form.append('mentions[]', String(id)))
    ;(payload.attachments ?? []).forEach((f) => form.append('attachments[]', f))
    const response = await fetch(`/api/projects/${projectId}/chat`, {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body: form,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new Error((err as { message?: string }).message || 'Could not send message')
    }
    return response.json()
  },
  async editProjectChatMessage(projectId: number | string, messageId: number, content: string): Promise<any> {
    return this.request(`/projects/${projectId}/chat/messages/${messageId}`, {
      method: 'PUT', body: JSON.stringify({ content }),
    })
  },
  async deleteProjectChatMessage(projectId: number | string, messageId: number): Promise<any> {
    return this.request(`/projects/${projectId}/chat/messages/${messageId}`, { method: 'DELETE' })
  },
  async markProjectChatRead(projectId: number | string, lastReadMessageId: number): Promise<any> {
    return this.request(`/projects/${projectId}/chat/read`, {
      method: 'POST', body: JSON.stringify({ last_read_message_id: lastReadMessageId }),
    })
  },
  async downloadChatAttachment(projectId: number | string, path: string, name: string): Promise<void> {
    const response = await fetch(`/api/projects/${projectId}/chat/attachment?path=${encodeURIComponent(path)}`, {
      headers: { Accept: 'application/octet-stream' }, credentials: 'same-origin',
    })
    if (!response.ok) throw new Error('Download failed')
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = name
    link.click()
    URL.revokeObjectURL(url)
  },

  // ── Settings ──
  async getSettings(): Promise<Record<string, unknown>> {
    return this.request('/settings')
  },
  async updateProfile(data: { name: string; email: string; timezone?: string; language?: string }): Promise<any> {
    return this.request('/settings/profile', { method: 'PUT', body: JSON.stringify(data) })
  },
  async updatePassword(data: { current_password: string; password: string; password_confirmation: string }): Promise<any> {
    return this.request('/settings/password', { method: 'PUT', body: JSON.stringify(data) })
  },
  async resetInternalSignaturePin(pin: string): Promise<any> {
    return this.request('/settings/internal-signature-pin', { method: 'PUT', body: JSON.stringify({ pin }) })
  },
  async updateNotifications(data: Record<string, boolean>): Promise<any> {
    return this.request('/settings/notifications', { method: 'PUT', body: JSON.stringify(data) })
  },
  async updateSystemSettings(data: { company: string; currency: string; fiscalMonth: string; maxUploadMB: string }): Promise<any> {
    return this.request('/settings/system', { method: 'PUT', body: JSON.stringify(data) })
  },
  async updateFeatureFlags(data: { feature_link_predecessor: boolean; feature_legacy_case: boolean; feature_existing_client: boolean }): Promise<any> {
    return this.request('/settings/feature-flags', { method: 'PUT', body: JSON.stringify(data) })
  },
  async updateDropdown(key: 'dropdown_service_codes' | 'dropdown_country_codes', items: { code: string; label: string }[]): Promise<any> {
    return this.request('/settings/dropdown', { method: 'PUT', body: JSON.stringify({ key, items }) })
  },
  async getCodes(): Promise<{ service: CodeRow[]; country: CodeRow[] }> {
    return this.request('/settings/codes')
  },
  async previewCodeRename(type: 'service' | 'country', oldCode: string, newCode: string): Promise<CodeRenameImpact | CodeMergeAvailable> {
    return this.request('/settings/codes/rename-preview', {
      method: 'POST', body: JSON.stringify({ type, old_code: oldCode, new_code: newCode }),
    })
  },
  async executeCodeRename(type: 'service' | 'country', oldCode: string, newCode: string): Promise<CodeRenameResult> {
    return this.request('/settings/codes/rename', {
      method: 'PUT', body: JSON.stringify({ type, old_code: oldCode, new_code: newCode }),
    })
  },
  async previewCodeMerge(type: 'service' | 'country', oldCode: string, newCode: string): Promise<CodeMergeImpact> {
    return this.request('/settings/codes/merge-preview', {
      method: 'POST', body: JSON.stringify({ type, old_code: oldCode, new_code: newCode }),
    })
  },
  async executeCodeMerge(type: 'service' | 'country', oldCode: string, newCode: string): Promise<CodeMergeResult> {
    return this.request('/settings/codes/merge', {
      method: 'PUT', body: JSON.stringify({ type, old_code: oldCode, new_code: newCode }),
    })
  },
  async updateProjectTabs(tabs: ProjectTab[]): Promise<{ ok: boolean; tabs: ProjectTab[] }> {
    return this.request('/settings/project-tabs', { method: 'PUT', body: JSON.stringify({ tabs }) })
  },
  async updateRenewalFeeRates(data: { government_fee: number; professional_fee: number; currency?: string }): Promise<any> {
    return this.request('/settings/renewal-fee-rates', { method: 'PUT', body: JSON.stringify(data) })
  },
  // ── Fee rate card ── (default: active rows only, for auto-fill lookups; pass includeInactive for the admin grid)
  async getFeeRateCards(opts?: { jurisdiction?: string; includeInactive?: boolean }): Promise<any[]> {
    const params = new URLSearchParams()
    if (opts?.jurisdiction) params.set('jurisdiction', opts.jurisdiction)
    if (opts?.includeInactive) params.set('include_inactive', '1')
    const q = params.toString()
    return this.request(`/fee-rate-cards${q ? `?${q}` : ''}`)
  },
  async createFeeRateCard(data: Record<string, unknown>): Promise<any> {
    return this.request('/fee-rate-cards', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateFeeRateCard(id: number | string, data: Record<string, unknown>): Promise<any> {
    return this.request(`/fee-rate-cards/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteFeeRateCard(id: number | string): Promise<any> {
    return this.request(`/fee-rate-cards/${id}`, { method: 'DELETE' })
  },
  async uploadAvatar(file: File): Promise<{ ok: boolean; avatar_url: string }> {
    const form = new FormData()
    form.append('avatar', file)
    const token = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''
    const res = await fetch('/api/settings/avatar', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' },
      body: form,
    })
    if (!res.ok) { const e = await res.json().catch(() => ({})); throw new Error((e as any).message || 'Upload failed') }
    return res.json()
  },
  async removeAvatar(): Promise<{ ok: boolean }> {
    return this.request('/settings/avatar', { method: 'DELETE' })
  },
  async requestReminderHelp(id: number, targetUserId: number, note?: string): Promise<{ ok: boolean }> {
    return this.request(`/reminders/${id}/help-request`, { method: 'POST', body: JSON.stringify({ target_user_id: targetUserId, note: note ?? null }) })
  },

  // ── Compliance ──
  async getComplianceStats(): Promise<{ critical: number; at_risk: number; on_track: number; compliant: number }> {
    return this.request('/compliance/stats')
  },
  async getCompliance(): Promise<Record<string, unknown>[]> {
    const res = await this.request<PaginatedResponse<Record<string, unknown>> | Record<string, unknown>[]>('/compliance')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Record<string, unknown>>).data ?? [])
  },
  async getCompliancePaged(params?: URLSearchParams): Promise<PaginatedResponse<Record<string, unknown>>> {
    const query = params ? '?' + params.toString() : ''
    return this.request(`/compliance${query}`)
  },
  async createCompliance(data: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.request('/compliance', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateCompliance(id: number | string, data: { assignee_id?: number | null; note?: string; resolved?: boolean }): Promise<Record<string, unknown>> {
    return this.request(`/compliance/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async remindCompliance(id: number | string): Promise<{ message: string }> {
    return this.request(`/compliance/${id}/remind`, { method: 'POST' })
  },

  // ── Reminders ──
  async getReminders(): Promise<Record<string, unknown>[]> {
    const res = await this.request<PaginatedResponse<Record<string, unknown>> | Record<string, unknown>[]>('/reminders')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Record<string, unknown>>).data ?? [])
  },
  async createReminder(data: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.request('/reminders', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateReminder(id: number | string, data: { completed: boolean }): Promise<Record<string, unknown>> {
    return this.request(`/reminders/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteReminder(id: number | string): Promise<{ message: string }> {
    return this.request(`/reminders/${id}`, { method: 'DELETE' })
  },
  async getReminderProfile(): Promise<ReminderProfile> {
    return this.request('/reminder-profile')
  },
  async updateReminderProfile(data: ReminderProfile): Promise<ReminderProfile> {
    return this.request('/reminder-profile', { method: 'PUT', body: JSON.stringify(data) })
  },
  async previewReminderProfile(): Promise<{ counts: Record<'overdue' | 'red' | 'amber' | 'green', number>; data: DocketWorklistItem[] }> {
    return this.request('/reminder-profile/preview', { method: 'POST' })
  },
  async getDocketWorklist(params?: URLSearchParams): Promise<PaginatedResponse<DocketWorklistItem>> {
    return this.request(`/docket/worklist${params ? `?${params.toString()}` : ''}`)
  },
  async getIpRecords(params?: URLSearchParams): Promise<PaginatedResponse<IpRecord>> {
    return this.request(`/ip-records${params ? `?${params.toString()}` : ''}`)
  },
  async getEmployeeWorkload(): Promise<{ user_id: number; project_count: number; tracker_count: number; total: number }[]> {
    return this.request('/hrms/employees/workload')
  },

  // ── Feedback / CSAT ──
  async getFeedback(): Promise<Record<string, unknown>[]> {
    const res = await this.request<PaginatedResponse<Record<string, unknown>> | Record<string, unknown>[]>('/feedback')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Record<string, unknown>>).data ?? [])
  },
  async requestFeedback(data: { project_id: number; subject?: string }): Promise<{ message: string }> {
    return this.request('/feedback/request', { method: 'POST', body: JSON.stringify(data) })
  },
  async getFeedbackRequests(): Promise<any[]> {
    return this.request('/feedback/requests')
  },
  async rateFeedbackRequest(id: number, data: { rating: number; comment?: string }): Promise<{ ok: boolean }> {
    return this.request(`/feedback/requests/${id}/rate`, { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Performance ──
  async getPerformance(): Promise<{ reviews: Record<string, unknown>[]; goals: Record<string, unknown>[]; feedback360: Record<string, unknown>[] }> {
    return this.request('/performance')
  },
  async submitPerformanceReview(id: number | string, data: { scores: Record<string, number>; comments?: string }): Promise<{ message: string }> {
    return this.request(`/performance/reviews/${id}/submit`, { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Recruitment ──
  async getRecruitment(): Promise<{ jobs: Record<string, unknown>[]; pipeline: Record<string, unknown>[] }> { return this.request('/recruitment') },
  async createJob(data: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.request('/recruitment/jobs', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateJob(id: number | string, data: { status: string }): Promise<Record<string, unknown>> {
    return this.request(`/recruitment/jobs/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },

  // ── Offboarding ──
  async getOffboarding(): Promise<{ cases: Record<string, unknown>[]; completed: Record<string, unknown>[] }> { return this.request('/offboarding') },
  async createOffboarding(data: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.request('/offboarding', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateOffboardingChecklist(id: number | string, checklist: boolean[]): Promise<Record<string, unknown>> {
    return this.request(`/offboarding/${id}/checklist`, { method: 'PUT', body: JSON.stringify({ checklist }) })
  },

  // ── Integrations ──
  async getIntegrations(): Promise<Record<string, unknown>[]> { return this.request('/integrations') },
  async toggleIntegration(slug: string): Promise<{ ok: boolean; connected: boolean; message: string }> {
    return this.request(`/integrations/${slug}/toggle`, { method: 'POST' })
  },
  async saveIntegrationConfig(slug: string, apiKey: string): Promise<{ ok: boolean; message: string }> {
    return this.request(`/integrations/${slug}/config`, { method: 'POST', body: JSON.stringify({ api_key: apiKey }) })
  },
  async saveZohoConfig(data: { client_id: string; client_secret: string; refresh_token: string; organization_id: string; region?: string }): Promise<{ ok: boolean; message: string }> {
    return this.request('/integrations/zoho/config', { method: 'POST', body: JSON.stringify(data) })
  },
  async saveRestVerifierConfig(slug: 'api_setu' | 'entitylocker', data: { client_id: string; client_secret: string; base_url: string; verify_path: string }): Promise<{ ok: boolean; message: string }> {
    return this.request(`/integrations/${slug}/config`, { method: 'POST', body: JSON.stringify(data) })
  },
  async saveDigiLockerConfig(data: { client_id: string; client_secret: string; authorize_url: string; token_url: string; document_pull_url: string; redirect_uri: string }): Promise<{ ok: boolean; message: string }> {
    return this.request('/integrations/digilocker/config', { method: 'POST', body: JSON.stringify(data) })
  },
  async testIntegration(slug: string): Promise<{ ok: boolean; message: string }> {
    return this.request(`/integrations/${slug}/test`, { method: 'POST' })
  },
  async getZohoClientSummary(clientId: number | string): Promise<ZohoClientSummary> {
    return this.request(`/integrations/zoho/clients/${clientId}/summary`)
  },
  async getZohoMySummary(): Promise<ZohoClientSummary> {
    return this.request('/integrations/zoho/me/summary')
  },
  async getZohoProjectSummary(projectId: number | string): Promise<ZohoClientSummary> {
    return this.request(`/integrations/zoho/projects/${projectId}/summary`)
  },
  async zohoMatchBatch(uins: string[]): Promise<Record<string, { status: string | null; balance: number | null; total: number; url: string | null }>> {
    return this.request('/integrations/zoho/match', { method: 'POST', body: JSON.stringify({ uins }) })
  },
  async zohoSyncNow(): Promise<{ ok: boolean; message: string }> {
    return this.request('/integrations/zoho/sync', { method: 'POST' })
  },
  async zohoEmergencySync(): Promise<{ ok: boolean; message: string }> {
    return this.request('/integrations/zoho/emergency-sync', { method: 'POST' })
  },
  async zohoConfigureAddon(limit: number): Promise<{ ok: boolean; limit: number }> {
    return this.request('/integrations/zoho/addon-limit', { method: 'POST', body: JSON.stringify({ limit }) })
  },
  async zohoAddonSync(): Promise<{ ok: boolean; message: string }> {
    return this.request('/integrations/zoho/addon-sync', { method: 'POST' })
  },
  async zohoRefreshRecord(id: number): Promise<{ ok: boolean; message: string }> {
    return this.request(`/integrations/zoho/records/${id}/sync`, { method: 'POST' })
  },
  async zohoQuotePreview(kind: 'standard' | 'in', id: number, search: string, customerId: string): Promise<ZohoQuotePreview> {
    const query = new URLSearchParams({ search })
    if (customerId) query.set('customer_id', customerId)
    return this.request(`/integrations/zoho/quotes/${kind}/${id}?${query}`)
  },
  async zohoSendQuote(kind: 'standard' | 'in', id: number, customerId: string, version: string): Promise<{ state: string; estimate_id: string; message: string }> {
    return this.request(`/integrations/zoho/quotes/${kind}/${id}`, { method: 'POST', body: JSON.stringify({ customer_id: customerId, version, review_confirmed: true }) })
  },
  async zohoDeleteQuoteInZoho(kind: 'standard' | 'in', id: number): Promise<{ state: string; estimate_id: string; message: string }> {
    return this.request(`/integrations/zoho/quotes/${kind}/${id}/delete-in-zoho`, { method: 'POST' })
  },
  async zohoSyncStatus(): Promise<{ budget: { used: number; limit: number; remaining: number; nightly_limit: number; evening_used: number; addon_limit: number; addon_used: number; pools: Record<'automatic' | 'manual_read' | 'write' | 'emergency' | 'addon', { used: number; limit: number; remaining: number }> }; pending: number; evening: { evening_eligible_date: string | null; pause_reason: string | null; completed_at: string | null } | null; deletions: { mode: string; configured: boolean; pending: number; failed: number; last_received_at: string | null }; last_run: { status: string; summary: string; created_at: string } | null }> {
    return this.request('/integrations/zoho/sync-status')
  },
  async downloadZohoPdf(id: number): Promise<void> {
    const response = await fetch(`/api/integrations/zoho/records/${id}/pdf`, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } })
    if (!response.ok) throw new Error('PDF is awaiting the nightly sync. Manual record refresh does not download PDFs.')
    const url = URL.createObjectURL(await response.blob())
    const link = document.createElement('a')
    link.href = url
    link.download = `zoho-${id}.pdf`
    link.click()
    URL.revokeObjectURL(url)
  },
  async getZohoMonthlyAnalytics(): Promise<{ month: string; total: number }[]> {
    return this.request('/integrations/zoho/analytics/monthly')
  },
  async getZohoAll(params?: URLSearchParams): Promise<PaginatedResponse<ZohoAllRow>> {
    return this.request(`/integrations/zoho/all${params ? `?${params}` : ''}`)
  },
  async getZohoOpeningBalances(params?: URLSearchParams): Promise<PaginatedResponse<ZohoOpeningBalanceRow>> {
    return this.request(`/integrations/zoho/opening-balances${params ? `?${params}` : ''}`)
  },
  async linkZohoProject(id: number, project_id: number | null): Promise<any> {
    return this.request(`/integrations/zoho/${id}/link-project`, { method: 'PUT', body: JSON.stringify({ project_id }) })
  },

  // ── Client Portal ──
  async getPortalClients(): Promise<Record<string, unknown>[]> { return this.request('/portal/clients') },
  async togglePortal(clientId: number | string): Promise<{ message: string }> {
    return this.request(`/portal/clients/${clientId}/toggle`, { method: 'POST' })
  },
  async portalInviteAll(): Promise<{ ok: boolean; invited: number }> {
    return this.request('/portal/invite-all', { method: 'POST' })
  },
  async createPortal(data: { client_id: number; name?: string; email: string; password: string }): Promise<any> {
    return this.request('/portal/create', { method: 'POST', body: JSON.stringify(data) })
  },
  async resetUserPassword(userId: number | string, password: string): Promise<{ ok: boolean }> {
    return this.request(`/users/${userId}/reset-password`, { method: 'PUT', body: JSON.stringify({ password }) })
  },
  async resetPortalPassword(clientId: number | string, password: string): Promise<{ ok: boolean }> {
    return this.request(`/portal/clients/${clientId}/reset-password`, { method: 'POST', body: JSON.stringify({ password }) })
  },
  async portalBulk(action: 'enable' | 'disable' | 'delete', ids: number[]): Promise<{ ok: boolean; affected: number }> {
    return this.request('/portal/bulk', { method: 'POST', body: JSON.stringify({ action, ids }) })
  },
  async getPortalClientUsers(clientId: number): Promise<any[]> {
    return this.request(`/portal/clients/${clientId}/users`)
  },
  async addPortalClientUser(clientId: number, data: { name: string; email: string; password: string }): Promise<any> {
    return this.request(`/portal/clients/${clientId}/users`, { method: 'POST', body: JSON.stringify(data) })
  },
  async removePortalClientUser(clientId: number, userId: number): Promise<{ ok: boolean }> {
    return this.request(`/portal/clients/${clientId}/users/${userId}`, { method: 'DELETE' })
  },
  async resetPortalUserPassword(clientId: number, userId: number, password: string): Promise<{ ok: boolean }> {
    return this.request(`/portal/clients/${clientId}/users/${userId}/reset-password`, { method: 'POST', body: JSON.stringify({ password }) })
  },

  // ── Bulk operations ──
  async bulkExecute(data: { entity: string; ids: number[]; action: string; status?: string; stage?: string }): Promise<{ ok: boolean; affected: number }> {
    return this.request('/bulk/execute', { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Payroll ──
  async getPayrollRuns(): Promise<{ runs: PayrollRun[]; ytd_paid: number; can_manage: boolean; can_pay: boolean }> {
    return this.request('/payroll/runs')
  },
  async getPayrollRun(id: number | string): Promise<PayrollRun> { return this.request(`/payroll/runs/${id}`) },
  async createPayrollRun(period: string, runType?: 'Regular' | 'OffCycle'): Promise<{ run: PayrollRun; message: string; skipped_employees?: string[] }> {
    return this.request('/payroll/runs', { method: 'POST', body: JSON.stringify({ period, run_type: runType }) })
  },
  async deletePayrollRun(id: number | string): Promise<{ message: string }> {
    return this.request(`/payroll/runs/${id}`, { method: 'DELETE' })
  },
  async submitPayrollRunForApproval(id: number | string): Promise<PayrollRun> {
    return this.request(`/payroll/runs/${id}/submit-for-approval`, { method: 'POST' })
  },
  async approvePayrollRun(id: number | string): Promise<PayrollRun> {
    return this.request(`/payroll/runs/${id}/approve`, { method: 'POST' })
  },
  async rejectPayrollRun(id: number | string, reason: string): Promise<PayrollRun> {
    return this.request(`/payroll/runs/${id}/reject`, { method: 'POST', body: JSON.stringify({ reason }) })
  },
  async recallPayrollRun(id: number | string): Promise<PayrollRun> {
    return this.request(`/payroll/runs/${id}/recall`, { method: 'POST' })
  },
  async payPayrollRun(id: number | string): Promise<PayrollRun> {
    return this.request(`/payroll/runs/${id}/pay`, { method: 'POST' })
  },
  async payPayrollRunSelected(id: number | string, payslipIds: number[]): Promise<PayrollRun> {
    return this.request(`/payroll/runs/${id}/pay-selected`, { method: 'POST', body: JSON.stringify({ payslip_ids: payslipIds }) })
  },
  bankAdviceUrl(runId: number | string): string {
    return `/api/payroll/runs/${runId}/bank-advice`
  },
  async getBankAdvicePreview(runId: number | string): Promise<any> {
    return this.request(`/payroll/runs/${runId}/bank-advice/preview`)
  },
  async signBankAdvice(runId: number | string, entries: Array<{ payslip_id: number; pin: string; verified: boolean }>): Promise<any> {
    return this.request(`/payroll/runs/${runId}/bank-advice/sign`, { method: 'POST', body: JSON.stringify({ entries }) })
  },
  tdsWorksheetsUrl(runId: number | string): string {
    return `/api/payroll/runs/${runId}/tds-worksheets`
  },
  async updatePayslip(id: number | string, data: { lop_days?: number; tds?: number; health_insurance?: number; other_deductions?: number; reimbursements?: number; hours_logged?: string }): Promise<Payslip> {
    return this.request(`/payroll/payslips/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async signInternalPayslip(id: number | string): Promise<unknown> {
    return this.request(`/payroll/payslips/${id}/internal-sign`, { method: 'POST' })
  },
  async withholdPayslip(id: number | string, reason: string): Promise<Payslip> {
    return this.request(`/payroll/payslips/${id}/withhold`, { method: 'POST', body: JSON.stringify({ reason }) })
  },
  async revertWithholdPayslip(id: number | string): Promise<Payslip> {
    return this.request(`/payroll/payslips/${id}/revert-withhold`, { method: 'POST' })
  },
  async releaseWithheldSalary(employeeId: number | string, payrollRunId: number | string): Promise<Payslip> {
    return this.request(`/payroll/employees/${employeeId}/release-withheld`, { method: 'POST', body: JSON.stringify({ payroll_run_id: payrollRunId }) })
  },
  async getLopAdjustments(): Promise<LopAdjustment[]> {
    return this.request('/payroll/lop-adjustments')
  },
  async createLopAdjustment(data: { employee_id: number; adjustment_type: 'Reversal' | 'Correction'; days: number; period: string; reason?: string }): Promise<LopAdjustment> {
    return this.request('/payroll/lop-adjustments', { method: 'POST', body: JSON.stringify(data) })
  },
  async getMyPayslips(): Promise<Payslip[]> {
    const res = await this.request<PaginatedResponse<Payslip> | Payslip[]>('/payroll/my-slips')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Payslip>).data ?? [])
  },
  payslipPdfUrl(id: number | string): string {
    return `/api/payroll/payslips/${id}/pdf`
  },
  async downloadPdf(endpoint: string, filename: string): Promise<void> {
    const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/pdf' } })
    if (!response.ok) throw new Error(response.status === 401 ? 'Your session has expired. Please sign in again.' : 'Could not download the PDF.')
    const blob = await response.blob(); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = filename; link.click(); URL.revokeObjectURL(url)
  },
  yearlyPayslipPdfUrl(employeeId: number | string, fiscalYear?: number | string): string {
    return `/api/payroll/employees/${employeeId}/yearly-pdf${fiscalYear ? `?fiscal_year=${fiscalYear}` : ''}`
  },
  /** `from`/`to` as "YYYY-MM" — covers quarterly, half-yearly, and any custom range (max 12 months). */
  payslipSummaryUrl(employeeId: number | string, from: string, to: string): string {
    return `/api/payroll/employees/${employeeId}/payslip-summary?from=${from}&to=${to}`
  },
  salaryCertificateUrl(employeeId: number | string): string {
    return `/api/payroll/employees/${employeeId}/salary-certificate`
  },
  async downloadSalaryCertificate(employeeId: number | string): Promise<void> {
    return this.downloadPdf(this.salaryCertificateUrl(employeeId), `salary-certificate-${employeeId}.pdf`)
  },
  async getPayrollPortalDashboard(): Promise<{
    upcoming_run: { period: string; already_created: boolean }
    latest_run: PayrollRun | null
    employee_count: number
    ytd: { gross: number; deductions: number; net: number; pf: number; esi: number; tds: number }
    cost_trend: { period: string; gross: number; deductions: number; net: number }[]
  }> {
    return this.request('/payroll-portal/dashboard')
  },
  async getPendingReimbursements(): Promise<any[]> {
    return this.request('/payroll-portal/approvals/reimbursements')
  },
  async getLoans(): Promise<any[]> {
    return this.request('/loans')
  },
  async getMyLoans(): Promise<any[]> {
    return this.request('/loans/mine')
  },
  async createLoan(data: { employee_id: number; principal_amount: number; monthly_installment: number; start_period: string; notes?: string }): Promise<any> {
    return this.request('/loans', { method: 'POST', body: JSON.stringify(data) })
  },
  async closeLoan(id: number | string): Promise<any> {
    return this.request(`/loans/${id}/close`, { method: 'POST' })
  },
  async requestLoan(data: { principal_amount: number; monthly_installment: number; reason?: string }): Promise<any> {
    return this.request('/loans/request', { method: 'POST', body: JSON.stringify(data) })
  },
  async approveLoanRequest(id: number | string, data: { start_period: string; monthly_installment?: number }): Promise<any> {
    return this.request(`/loans/${id}/approve-request`, { method: 'POST', body: JSON.stringify(data) })
  },
  async rejectLoanRequest(id: number | string): Promise<any> {
    return this.request(`/loans/${id}/reject-request`, { method: 'POST' })
  },
  async getContractors(): Promise<any[]> {
    return this.request('/contractors')
  },
  async createContractor(data: Record<string, unknown>): Promise<any> {
    return this.request('/contractors', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateContractor(id: number | string, data: Record<string, unknown>): Promise<any> {
    return this.request(`/contractors/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteContractor(id: number | string): Promise<{ ok: boolean }> {
    return this.request(`/contractors/${id}`, { method: 'DELETE' })
  },
  async recordContractorPayment(id: number | string, data: { invoice_number: string; invoice_date: string; amount: number; tds_deducted?: number; status?: 'Pending' | 'Paid' }): Promise<any> {
    return this.request(`/contractors/${id}/payments`, { method: 'POST', body: JSON.stringify(data) })
  },
  async saveInvestmentDeclaration(data: {
    financial_year: number
    regime_choice: 'old' | 'new'
    section_80c_amount?: number
    section_80ccd1b_amount?: number
    section_80d_amount?: number
    section_80e_amount?: number
    section_80g_amount?: number
    section_80tta_amount?: number
    hra_rent_paid?: number
    housing_type?: 'rent' | 'self_occupied_loan'
    home_loan_interest_declared?: number
    other_income_declared?: number
    previous_employer_salary?: number
    lta_exemption_claimed?: number
    section_80gg_amount?: number
    section_80dd_amount?: number
    section_80ddb_amount?: number
    section_80ee_amount?: number
    section_80eea_amount?: number
    section_80gga_amount?: number
    section_80ggc_amount?: number
  }): Promise<any> {
    return this.request('/investment-declarations', { method: 'POST', body: JSON.stringify(data) })
  },
  async submitInvestmentDeclaration(id: number | string): Promise<any> {
    return this.request(`/investment-declarations/${id}/submit`, { method: 'POST' })
  },
  async getMyInvestmentDeclarations(): Promise<any[]> {
    return this.request('/investment-declarations/mine')
  },
  async getPendingInvestmentDeclarations(): Promise<any[]> {
    return this.request('/investment-declarations/pending')
  },
  async getInvestmentDeclarationsForEmployee(employeeId: number | string): Promise<any[]> {
    return this.request(`/investment-declarations/employee/${employeeId}`)
  },
  async saveInvestmentDeclarationForEmployee(employeeId: number | string, data: {
    financial_year: number
    regime_choice: 'old' | 'new'
    section_80c_amount?: number
    section_80ccd1b_amount?: number
    section_80d_amount?: number
    section_80e_amount?: number
    section_80g_amount?: number
    section_80tta_amount?: number
    hra_rent_paid?: number
    housing_type?: 'rent' | 'self_occupied_loan'
    home_loan_interest_declared?: number
    other_income_declared?: number
    previous_employer_salary?: number
    lta_exemption_claimed?: number
    section_80gg_amount?: number
    section_80dd_amount?: number
    section_80ddb_amount?: number
    section_80ee_amount?: number
    section_80eea_amount?: number
    section_80gga_amount?: number
    section_80ggc_amount?: number
  }): Promise<any> {
    return this.request(`/investment-declarations/employee/${employeeId}`, { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Proof of Investments (separate, document-backed review phase) ──
  async getPoiWindow(financialYear: number | string): Promise<{ financial_year: number; deadline_at: string; effective_payroll_month: string } | null> {
    return this.request(`/poi-windows/${financialYear}`)
  },
  async getPoiWindows(): Promise<any[]> {
    return this.request('/poi-windows')
  },
  async savePoiWindow(data: { financial_year: number; deadline_at: string; effective_payroll_month: string }): Promise<any> {
    return this.request('/poi-windows', { method: 'POST', body: JSON.stringify(data) })
  },
  async getMyProofOfInvestment(financialYear: number | string): Promise<any> {
    return this.request(`/proof-of-investments/mine/${financialYear}`)
  },
  async uploadPoiDocument(financialYear: number | string, section: string, file: File): Promise<any> {
    const formData = new FormData()
    formData.append('file', file)
    const response = await fetch(`/api/proof-of-investments/mine/${financialYear}/documents/${section}`, {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body: formData,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new Error((err as { message?: string }).message || 'Upload failed')
    }
    return response.json()
  },
  async deletePoiDocument(documentId: number | string): Promise<{ ok: boolean }> {
    return this.request(`/proof-of-investments/documents/${documentId}`, { method: 'DELETE' })
  },
  poiDocumentDownloadUrl(documentId: number | string): string {
    return `/api/proof-of-investments/documents/${documentId}/download`
  },
  async getPendingProofOfInvestments(financialYear: number | string): Promise<any[]> {
    return this.request(`/proof-of-investments/pending/${financialYear}`)
  },
  async getProofOfInvestmentsForEmployee(employeeId: number | string): Promise<any[]> {
    return this.request(`/proof-of-investments/employee/${employeeId}`)
  },
  async setPoiApprovedAmounts(id: number | string, data: {
    approved_section_80c_amount?: number; approved_section_80ccd1b_amount?: number; approved_section_80d_amount?: number
    approved_section_80e_amount?: number; approved_section_80g_amount?: number; approved_section_80tta_amount?: number
    approved_hra_rent_paid?: number; approved_home_loan_interest_amount?: number; approved_other_income_amount?: number
    approved_previous_employer_salary?: number
    approved_lta_exemption_claimed?: number; approved_section_80gg_amount?: number; approved_section_80dd_amount?: number
    approved_section_80ddb_amount?: number; approved_section_80ee_amount?: number; approved_section_80eea_amount?: number
    approved_section_80gga_amount?: number; approved_section_80ggc_amount?: number
    approved_other_deductions_json?: Record<string, number>; rejection_reasons_json?: Record<string, string>
  }): Promise<any> {
    return this.request(`/proof-of-investments/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async considerPoiForIt(id: number | string): Promise<any> {
    return this.request(`/proof-of-investments/${id}/consider-for-it`, { method: 'POST' })
  },
  async approveAllAndConsiderPoi(financialYear: number | string): Promise<{ considered_count: number }> {
    return this.request(`/proof-of-investments/${financialYear}/approve-all-and-consider`, { method: 'POST' })
  },
  async submitInvestmentDeclarationForEmployee(employeeId: number | string, id: number | string): Promise<any> {
    return this.request(`/investment-declarations/employee/${employeeId}/${id}/submit`, { method: 'POST' })
  },
  async getEmployeePayslips(employeeId: number | string, fiscalYear?: number | string): Promise<any[]> {
    return this.request(`/payroll/employees/${employeeId}/payslips${fiscalYear ? `?fiscal_year=${fiscalYear}` : ''}`)
  },
  async getSalaryRevisions(): Promise<any[]> {
    return this.request('/salary-revisions')
  },
  async getPendingSalaryRevisions(): Promise<any[]> {
    return this.request('/salary-revisions/pending')
  },
  async proposeSalaryRevision(data: { employee_id: number; new_base_pay: number; new_hra?: number; effective_period: string; reason?: string }): Promise<any> {
    return this.request('/salary-revisions', { method: 'POST', body: JSON.stringify(data) })
  },
  async getForm16Documents(financialYear: number | string): Promise<any[]> {
    return this.request(`/form16/${financialYear}`)
  },
  async uploadForm16PartAZip(financialYear: number | string, zip: File): Promise<{ matched: any[]; unmatched: string[] }> {
    const formData = new FormData()
    formData.append('zip', zip)
    const response = await fetch(`/api/form16/${financialYear}/upload-part-a`, {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body: formData,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new Error((err as { message?: string }).message || 'Upload failed')
    }
    return response.json()
  },
  form16DownloadUrl(employeeId: number | string, financialYear: number | string): string {
    return `/api/form16/${employeeId}/${financialYear}/download`
  },
  async requestForm16(financialYear: number | string): Promise<any> {
    return this.request(`/form16/${financialYear}/request`, { method: 'POST' })
  },
  async generateForm16(employeeId: number | string, financialYear: number | string): Promise<any> {
    return this.request(`/form16/${employeeId}/${financialYear}/generate`, { method: 'POST' })
  },
  async publishForm16(employeeId: number | string, financialYear: number | string): Promise<any> {
    return this.request(`/form16/${employeeId}/${financialYear}/publish`, { method: 'POST' })
  },
  async publishAllForm16(financialYear: number | string): Promise<{ published_count: number }> {
    return this.request(`/form16/${financialYear}/publish-all`, { method: 'POST' })
  },
  form24QExportUrl(financialYear: number | string): string {
    return `/api/form16/${financialYear}/export-24q`
  },

  // ── Taxes & Forms: TDS Liabilities, Challans, Form 24Q ──
  async getTdsLiabilities(params?: Record<string, string | number>): Promise<any[]> {
    const qs = params ? `?${new URLSearchParams(params as Record<string, string>).toString()}` : ''
    return this.request(`/tds-liabilities${qs}`)
  },
  async getChallans(params?: Record<string, string | number>): Promise<any[]> {
    const qs = params ? `?${new URLSearchParams(params as Record<string, string>).toString()}` : ''
    return this.request(`/challans${qs}`)
  },
  async recordChallan(data: {
    bsr_code: string; payment_date: string; serial_number: string; amount: number
    interest_amount?: number; penalty_amount?: number; financial_year: number; quarter?: number; notes?: string
  }): Promise<any> {
    return this.request('/challans', { method: 'POST', body: JSON.stringify(data) })
  },
  async associateChallan(id: number | string, allocations: { tds_liability_id: number; amount: number }[]): Promise<any> {
    return this.request(`/challans/${id}/associate`, { method: 'POST', body: JSON.stringify({ allocations }) })
  },
  async dissociateChallanAllocation(allocationId: number | string): Promise<{ ok: boolean }> {
    return this.request(`/challan-allocations/${allocationId}`, { method: 'DELETE' })
  },
  async getForm24QExceptions(financialYear: number | string, quarter: number | string): Promise<{ employee_code: string; employee_name: string; issue: string }[]> {
    return this.request(`/form-24q/${financialYear}/${quarter}/exceptions`)
  },
  form24QDownloadUrl(financialYear: number | string, quarter: number | string): string {
    return `/api/form-24q/${financialYear}/${quarter}/export`
  },

  async getTaxSlabConfigs(): Promise<any[]> {
    return this.request('/tax-slab-configs')
  },
  async createTaxSlabConfig(data: Record<string, unknown>): Promise<any> {
    return this.request('/tax-slab-configs', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateTaxSlabConfig(id: number | string, data: Record<string, unknown>): Promise<any> {
    return this.request(`/tax-slab-configs/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async updatePayrollSettings(data: {
    pt_nil_threshold: number
    pt_monthly_amount: number
    pt_february_amount: number
    pf_enabled?: boolean
    esi_enabled?: boolean
    company_name?: string
    company_address?: string
    company_logo_url?: string
    company_tan?: string
    pay_day?: number
    signatory_name?: string
    signatory_designation?: string
  }): Promise<{ ok: boolean }> {
    return this.request('/settings/payroll', { method: 'PUT', body: JSON.stringify(data) })
  },

  // ── Salary components (admin-configured template) ──
  async getSalaryComponents(): Promise<SalaryComponent[]> { return this.request('/salary-components') },
  async createSalaryComponent(data: Partial<SalaryComponent>): Promise<SalaryComponent> {
    return this.request('/salary-components', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateSalaryComponent(id: number | string, data: Partial<SalaryComponent>): Promise<SalaryComponent> {
    return this.request(`/salary-components/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteSalaryComponent(id: number | string): Promise<{ ok: boolean }> {
    return this.request(`/salary-components/${id}`, { method: 'DELETE' })
  },
  async reorderSalaryComponents(ids: number[]): Promise<SalaryComponent[]> {
    return this.request('/salary-components/reorder', { method: 'POST', body: JSON.stringify({ ids }) })
  },

  // ── Reimbursements ──
  async getReimbursementTypes(includeInactive = false): Promise<ReimbursementType[]> {
    const q = includeInactive ? '?include_inactive=1' : ''
    return this.request(`/reimbursement-types${q}`)
  },
  async createReimbursementType(data: Partial<ReimbursementType>): Promise<ReimbursementType> {
    return this.request('/reimbursement-types', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateReimbursementType(id: number | string, data: Partial<ReimbursementType>): Promise<ReimbursementType> {
    return this.request(`/reimbursement-types/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteReimbursementType(id: number | string): Promise<{ ok: boolean }> {
    return this.request(`/reimbursement-types/${id}`, { method: 'DELETE' })
  },
  async submitReimbursement(data: {
    reimbursement_type_id: number; amount: number; claim_date: string; description?: string; receipt: File;
  }): Promise<ExpenseClaim> {
    const formData = new FormData()
    formData.append('reimbursement_type_id', String(data.reimbursement_type_id))
    formData.append('amount', String(data.amount))
    formData.append('claim_date', data.claim_date)
    if (data.description) formData.append('description', data.description)
    formData.append('receipt', data.receipt)
    const response = await fetch('/api/reimbursements', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body: formData,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new Error((err as { message?: string }).message || 'Submission failed')
    }
    return response.json()
  },
  async getMyReimbursements(params?: URLSearchParams): Promise<PaginatedResponse<ExpenseClaim>> {
    return this.request(`/reimbursements/mine${params ? `?${params}` : ''}`)
  },
  reimbursementReceiptUrl(id: number | string): string {
    return `/api/reimbursements/${id}/receipt`
  },
  async getLifecycleStats(roleFilter?: string): Promise<Record<string, number>> {
    const q = roleFilter && roleFilter !== 'all' ? `?role_filter=${roleFilter}` : ''
    return this.request(`/projects/lifecycle-stats${q}`)
  },
  async getLifecycleServiceStats(roleFilter?: string): Promise<Record<string, number>> {
    const q = roleFilter && roleFilter !== 'all' ? `?role_filter=${roleFilter}` : ''
    return this.request(`/projects/lifecycle-service-stats${q}`)
  },
  async inspectImportSheets(file: File): Promise<{ sheets: { name: string; rows: number; is_data_sheet: boolean }[] }> {
    const formData = new FormData()
    formData.append('file', file)
    const response = await fetch('/api/projects/import/sheets', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body: formData,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new Error((err as { message?: string }).message || `Inspect failed: ${response.status}`)
    }
    return response.json()
  },
  async importProjects(clientId: number, file: File, skipDuplicates?: boolean, sheetName?: string): Promise<{ imported: number; skipped: number; errors: string[]; dockets: string[]; client: string } | { requires_confirmation: true; duplicates: { line: number; uin: string; reason: string }[] }> {
    const formData = new FormData()
    formData.append('client_id', String(clientId))
    formData.append('file', file)
    if (skipDuplicates !== undefined) formData.append('skip_duplicates', String(skipDuplicates))
    if (sheetName) formData.append('sheet_name', sheetName)
    const response = await fetch('/api/projects/import', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body: formData,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new Error((err as { message?: string }).message || `Import failed: ${response.status}`)
    }
    return response.json()
  },
  async getPatentPortfolioStats(clientId?: number | null, roleFilter?: string): Promise<any> {
    const params = new URLSearchParams()
    if (clientId) params.set('client_id', String(clientId))
    if (roleFilter && roleFilter !== 'all') params.set('role_filter', roleFilter)
    const q = params.toString() ? '?' + params.toString() : ''
    return this.request(`/patent-portfolio/stats${q}`)
  },
  async getMyPortalUsers(): Promise<any[]> {
    return this.request('/my-portal/users')
  },
  async getStaffUsers(search?: string): Promise<any[]> {
    return this.request(`/staff-users${search ? `?search=${encodeURIComponent(search)}` : ''}`)
  },
  async createStaffUser(data: { name: string; email: string; role: string; password: string }): Promise<any> {
    return this.request('/staff-users', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateStaffUser(id: number, data: Partial<{ name: string; email: string; role: string; status: string }>): Promise<any> {
    return this.request(`/staff-users/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteStaffUser(id: number): Promise<{ ok: boolean }> {
    return this.request(`/staff-users/${id}`, { method: 'DELETE' })
  },
  async createMyPortalUser(data: { name: string; email: string; password: string; role?: "client" | "client_finance" }): Promise<any> {
    return this.request('/my-portal/users', { method: 'POST', body: JSON.stringify(data) })
  },
  async deleteMyPortalUser(userId: number): Promise<{ ok: boolean }> {
    return this.request(`/my-portal/users/${userId}`, { method: 'DELETE' })
  },
  async createApproval(data: { client_id?: number; approver_id?: number; title: string; description?: string; kind?: 'budget' | 'technical'; project_id?: number; document_ids?: number[] }): Promise<any> {
    return this.request('/approvals', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateApproval(id: number | string, data: { client_id?: number; approver_id?: number; title: string; description?: string; kind?: 'budget' | 'technical'; project_id?: number; document_ids?: number[] }): Promise<any> {
    return this.request(`/approvals/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async cancelApproval(id: number | string): Promise<{ ok: boolean }> {
    return this.request(`/approvals/${id}/cancel`, { method: 'POST' })
  },

  // Performance goals
  async createGoal(data: { title: string; description?: string; target_date?: string; category?: string }): Promise<Record<string, unknown>> {
    return this.request('/performance/goals', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateGoal(id: number | string, data: { title?: string; progress?: number; status?: string }): Promise<Record<string, unknown>> {
    return this.request(`/performance/goals/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteGoal(id: number | string): Promise<{ message: string }> {
    return this.request(`/performance/goals/${id}`, { method: 'DELETE' })
  },
  async submitFeedback360(data: { reviewee_id: number; feedback: string; rating: number }): Promise<{ message: string }> {
    return this.request('/performance/feedback360', { method: 'POST', body: JSON.stringify(data) })
  },

  // Recruitment candidates
  async createCandidate(data: { job_id: number; name: string; email?: string; phone?: string; resume_url?: string; stage?: string }): Promise<Record<string, unknown>> {
    return this.request('/recruitment/candidates', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateCandidateStage(id: number | string, stage: string): Promise<Record<string, unknown>> {
    return this.request(`/recruitment/candidates/${id}`, { method: 'PUT', body: JSON.stringify({ stage }) })
  },

  // Portal recent activity
  async portalRecentActivity(): Promise<Record<string, unknown>[]> {
    return this.request('/portal/recent-activity')
  },

  async getClientTeam(): Promise<Record<string, unknown>[]> {
    return this.request('/portal/my-team')
  },

  // Integration logs
  async getIntegrationLogs(slug: string): Promise<Record<string, unknown>[]> {
    return this.request(`/integrations/${slug}/logs`)
  },

  // ── Recycle Bin ──
  async getRecycleBin(): Promise<{ projects: Record<string, unknown>[]; clients: Record<string, unknown>[]; documents: Record<string, unknown>[] }> {
    return this.request('/recycle-bin')
  },
  async recycleBinRestore(type: 'project' | 'client' | 'document', id: number): Promise<{ message: string }> {
    return this.request('/recycle-bin/restore', { method: 'POST', body: JSON.stringify({ type, id }) })
  },
  async recycleBinHardDelete(type: 'project' | 'client' | 'document', id: number): Promise<{ message: string }> {
    return this.request('/recycle-bin/hard-delete', { method: 'DELETE', body: JSON.stringify({ type, id }) })
  },
  async recycleBinBulkRestore(type: 'project' | 'client' | 'document', ids: number[]): Promise<{ message: string; restored: number }> {
    return this.request('/recycle-bin/bulk-restore', { method: 'POST', body: JSON.stringify({ type, ids }) })
  },
  async recycleBinBulkHardDelete(type: 'project' | 'client' | 'document', ids: number[]): Promise<{ message: string; deleted: number }> {
    return this.request('/recycle-bin/bulk-hard-delete', { method: 'DELETE', body: JSON.stringify({ type, ids }) })
  },

  async elevateProject(id: number, toService: string, note?: string): Promise<{ message: string; project: Project }> {
    return this.request(`/projects/${id}/elevate`, { method: 'POST', body: JSON.stringify({ to_service: toService, note }) })
  },

  async linkProjectPredecessor(id: number, predecessorId: number, note?: string): Promise<{ message: string }> {
    return this.request(`/projects/${id}/link-predecessor`, { method: 'POST', body: JSON.stringify({ predecessor_id: predecessorId, note }) })
  },

  async detectProjectChains(): Promise<{ chains: any[]; total: number }> {
    return this.request('/projects/detect-chains')
  },

  async bulkLinkProjectChains(pairs: { predecessor_id: number; successor_id: number; note?: string }[]): Promise<{ message: string; created: number; skipped: number }> {
    return this.request('/projects/bulk-link-chains', { method: 'POST', body: JSON.stringify({ pairs }) })
  },

  async docketImportPreview(file: File, mappingFile?: File | null): Promise<{
    total: number; importable: number; skipped: number; abandoned: number; granted: number;
    known_clients: number; unknown_clients: string[]; docket_conflicts: string[]; sample: any[];
    fuzzy_matches: any[];
  }> {
    const form = new FormData()
    form.append('file', file)
    if (mappingFile) form.append('mapping_file', mappingFile)
    const headers: Record<string, string> = { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() }
    const res = await fetch('/api/projects/docket-import/preview', { method: 'POST', body: form, headers, credentials: 'same-origin' })
    if (!res.ok) { const e = await res.json().catch(() => ({})); throw new Error((e as any).message || `Error ${res.status}`) }
    return res.json()
  },

  async docketImport(file: File, opts: {
    skip_conflicts?: boolean; skip_transferred?: boolean;
    skip_unknown_clients?: boolean;
    default_partner_id?: number; default_manager_id?: number;
    mapping_file?: File | null;
  }): Promise<{ imported: number; skipped: number; errors: string[] }> {
    const form = new FormData()
    form.append('file', file)
    if (opts.mapping_file) form.append('mapping_file', opts.mapping_file)
    if (opts.skip_conflicts !== undefined) form.append('skip_conflicts', opts.skip_conflicts ? '1' : '0')
    if (opts.skip_transferred !== undefined) form.append('skip_transferred', opts.skip_transferred ? '1' : '0')
    if (opts.skip_unknown_clients !== undefined) form.append('skip_unknown_clients', opts.skip_unknown_clients ? '1' : '0')
    if (opts.default_partner_id) form.append('default_partner_id', String(opts.default_partner_id))
    if (opts.default_manager_id) form.append('default_manager_id', String(opts.default_manager_id))
    const headers: Record<string, string> = { Accept: 'application/json', 'X-XSRF-TOKEN': getCsrfToken() }
    const res = await fetch('/api/projects/docket-import/import', { method: 'POST', body: form, headers, credentials: 'same-origin' })
    if (!res.ok) { const e = await res.json().catch(() => ({})); throw new Error((e as any).message || `Error ${res.status}`) }
    return res.json()
  },
}

// ── CSV Download Utility ──
export function downloadCSV(filename: string, rows: Record<string, unknown>[]): void {
  if (!rows.length) return
  const headers = Object.keys(rows[0])
  const csvContent = [
    headers.join(','),
    ...rows.map(row =>
      headers.map(h => {
        let val = row[h] == null ? '' : String(row[h])
        if (/^[=+\-@]/.test(val)) val = `'${val}`
        return val.includes(',') || val.includes('"') || val.includes('\n')
          ? `"${val.replace(/"/g, '""')}"` : val
      }).join(',')
    ),
  ].join('\n')
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
