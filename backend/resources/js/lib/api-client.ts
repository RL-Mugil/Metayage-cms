import type {
  User, Client, ClientContact, Project, Task, Invoice, InvoiceItem,
  Employee, Attendance, LeaveRequest, LeaveBalance, PayrollRun, Payslip,
  Notification, ReportResponse, AIResponse, DashboardMetrics,
  PaginatedResponse, SearchResult,
} from '@/types'

function getCsrfToken(): string {
  if (typeof document === 'undefined') return ''
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

export type { User, Client, Project, Task, Invoice, Employee, PaginatedResponse, SearchResult }

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
      throw new Error((errData as { message?: string }).message || `Request failed: ${response.status}`)
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
  async getProjectStats(roleFilter?: string): Promise<{ total: number; open: number; in_progress: number; on_hold: number; overdue: number }> {
    const q = roleFilter && roleFilter !== 'all' ? `?role_filter=${roleFilter}` : ''
    return this.request(`/projects/stats${q}`)
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
  async getProjects(search?: string): Promise<Project[]> {
    const params = new URLSearchParams({ per_page: '500' })
    if (search) params.set('search', search)
    const res = await this.request<PaginatedResponse<Project> | Project[]>(`/projects?${params.toString()}`)
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Project>).data ?? [])
  },
  async getProjectsPaged(params?: URLSearchParams): Promise<PaginatedResponse<Project>> {
    const query = params ? '?' + params.toString() : ''
    return this.request(`/projects${query}`)
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
  async getEmployees(): Promise<Employee[]> {
    const res = await this.request<PaginatedResponse<Employee> | Employee[]>('/hrms/employees')
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
  async deleteEmployee(id: number | string): Promise<{ message: string }> {
    return this.request(`/hrms/employees/${id}`, { method: 'DELETE' })
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
  async getQuotations(): Promise<Invoice[]> {
    const res = await this.request<PaginatedResponse<Invoice> | Invoice[]>('/financial/quotations')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Invoice>).data ?? [])
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
  async generateReport(data: { type: string; format: "PDF" | "Excel" | "CSV"; fromDate?: string; toDate?: string }): Promise<ReportResponse & { export_id: number }> {
    return this.request('/reports/generate', { method: 'POST', body: JSON.stringify({
      type: data.type,
      format: data.format,
      from_date: data.fromDate || null,
      to_date: data.toDate || null,
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
  async getDocuments(): Promise<Record<string, unknown>[]> {
    const res = await this.request<PaginatedResponse<Record<string, unknown>> | Record<string, unknown>[]>('/documents')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Record<string, unknown>>).data ?? [])
  },
  async uploadDocument(file: File, folder?: string, clientId?: number | null): Promise<Record<string, unknown>> {
    const formData = new FormData()
    formData.append('file', file)
    if (folder) formData.append('folder', folder)
    if (clientId) formData.append('client_id', String(clientId))
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
  async updateNotifications(data: Record<string, boolean>): Promise<any> {
    return this.request('/settings/notifications', { method: 'PUT', body: JSON.stringify(data) })
  },
  async updateSystemSettings(data: { company: string; currency: string; fiscalMonth: string; maxUploadMB: string }): Promise<any> {
    return this.request('/settings/system', { method: 'PUT', body: JSON.stringify(data) })
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
  async updateCompliance(id: number | string, data: { assignee?: string; note?: string; resolved?: boolean }): Promise<Record<string, unknown>> {
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
  async testIntegration(slug: string): Promise<{ ok: boolean; message: string }> {
    return this.request(`/integrations/${slug}/test`, { method: 'POST' })
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

  // ── Project codes (custom office / service) ──
  async getProjectCodes(type: 'office' | 'service'): Promise<{ code: string; description: string }[]> {
    return this.request(`/project-codes?type=${type}`)
  },
  async addProjectCode(type: 'office' | 'service', code: string, description: string): Promise<{ code: string; description: string }> {
    return this.request('/project-codes', { method: 'POST', body: JSON.stringify({ type, code, description }) })
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
  async createPayrollRun(period: string): Promise<{ run: PayrollRun; message: string; skipped_employees?: string[] }> {
    return this.request('/payroll/runs', { method: 'POST', body: JSON.stringify({ period }) })
  },
  async deletePayrollRun(id: number | string): Promise<{ message: string }> {
    return this.request(`/payroll/runs/${id}`, { method: 'DELETE' })
  },
  async finalizePayrollRun(id: number | string): Promise<PayrollRun> {
    return this.request(`/payroll/runs/${id}/finalize`, { method: 'POST' })
  },
  async payPayrollRun(id: number | string): Promise<PayrollRun> {
    return this.request(`/payroll/runs/${id}/pay`, { method: 'POST' })
  },
  async updatePayslip(id: number | string, data: { lop_days?: number; tds?: number }): Promise<Payslip> {
    return this.request(`/payroll/payslips/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async getMyPayslips(): Promise<Payslip[]> {
    const res = await this.request<PaginatedResponse<Payslip> | Payslip[]>('/payroll/my-slips')
    return Array.isArray(res) ? res : ((res as PaginatedResponse<Payslip>).data ?? [])
  },
  async getLifecycleStats(): Promise<Record<string, number>> {
    return this.request('/projects/lifecycle-stats')
  },
  async importProjects(clientId: number, file: File, skipDuplicates?: boolean): Promise<{ imported: number; skipped: number; errors: string[]; dockets: string[]; client: string } | { requires_confirmation: true; duplicates: { line: number; uin: string; reason: string }[] }> {
    const formData = new FormData()
    formData.append('client_id', String(clientId))
    formData.append('file', file)
    if (skipDuplicates !== undefined) formData.append('skip_duplicates', String(skipDuplicates))
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
  async createMyPortalUser(data: { name: string; email: string; password: string }): Promise<any> {
    return this.request('/my-portal/users', { method: 'POST', body: JSON.stringify(data) })
  },
  async deleteMyPortalUser(userId: number): Promise<{ ok: boolean }> {
    return this.request(`/my-portal/users/${userId}`, { method: 'DELETE' })
  },
  async createApproval(data: { client_id?: number; approver_id?: number; title: string; description?: string }): Promise<any> {
    return this.request('/approvals', { method: 'POST', body: JSON.stringify(data) })
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

  // Integration logs
  async getIntegrationLogs(slug: string): Promise<Record<string, unknown>[]> {
    return this.request(`/integrations/${slug}/logs`)
  },
}

// ── CSV Download Utility ──
export function downloadCSV(filename: string, rows: Record<string, any>[]): void {
  if (!rows.length) return
  const headers = Object.keys(rows[0])
  const csvContent = [
    headers.join(','),
    ...rows.map(row =>
      headers.map(h => {
        const val = row[h] == null ? '' : String(row[h])
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
