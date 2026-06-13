function getCsrfToken(): string {
  if (typeof document === 'undefined') return ''
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

export interface User {
  id: number
  name: string
  email: string
  role: string
  status: string
  avatar_url?: string | null
  permissions?: Record<string, string>
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
      throw new Error((errData as any).message || `Request failed: ${response.status}`)
    }
    return response.json()
  },

  // ── Users ──
  async getUsers(): Promise<any[]> { return this.request('/users') },

  // ── Dashboard ──
  async getDashboardMetrics(): Promise<{ metrics: Record<string, number>; charts: any }> {
    return this.request('/dashboard/metrics')
  },

  // ── Clients ──
  async getClientStats(): Promise<{ total: number; active: number; inactive: number; prospect: number; b2b: number; b2c: number; export: number; unregistered: number }> {
    return this.request('/clients/stats')
  },
  async getClients(params?: string | URLSearchParams): Promise<any> {
    let query = '';
    if (params instanceof URLSearchParams) {
      query = '?' + params.toString();
    } else if (typeof params === 'string') {
      query = `?search=${encodeURIComponent(params)}`;
    }
    return this.request(`/clients${query}`);
  },
  async getClient(id: number | string): Promise<any> { return this.request(`/clients/${id}`) },
  async createClient(data: any): Promise<any> {
    return this.request('/clients', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateClient(id: number | string, data: any): Promise<any> {
    return this.request(`/clients/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteClient(id: number | string): Promise<any> {
    return this.request(`/clients/${id}`, { method: 'DELETE' })
  },
  async addClientContact(id: number | string, data: any): Promise<any> {
    return this.request(`/clients/${id}/contacts`, { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Cases / Projects ──
  async getProjects(search?: string): Promise<any[]> {
    const params = new URLSearchParams({ per_page: '500' })
    if (search) params.set('search', search)
    const res: any = await this.request(`/projects?${params.toString()}`)
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async getProject(id: number | string): Promise<any> { return this.request(`/projects/${id}`) },
  async createProject(data: any): Promise<any> {
    return this.request('/projects', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateProject(id: number | string, data: any): Promise<any> {
    return this.request(`/projects/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteProject(id: number | string): Promise<any> {
    return this.request(`/projects/${id}`, { method: 'DELETE' })
  },
  async updateProjectStage(id: number | string, stageName: string): Promise<any> {
    return this.request(`/projects/${id}/stage`, { method: 'POST', body: JSON.stringify({ stage_name: stageName }) })
  },

  // ── Tasks ──
  async getTasks(status?: string): Promise<any[]> {
    const res: any = await this.request(`/tasks${status ? `?status=${encodeURIComponent(status)}` : ''}`)
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async createTask(data: any): Promise<any> {
    return this.request('/tasks', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateTask(id: number | string, data: any): Promise<any> {
    return this.request(`/tasks/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteTask(id: number | string): Promise<any> {
    return this.request(`/tasks/${id}`, { method: 'DELETE' })
  },
  async logTimeEntry(data: {
    project_id: number; task_id?: number | null
    duration_hours: number; entry_date: string; description: string; billable?: boolean
  }): Promise<any> {
    return this.request('/tasks/time-entries', { method: 'POST', body: JSON.stringify(data) })
  },

  // ── HRMS ──
  async getEmployees(): Promise<any[]> {
    const res: any = await this.request('/hrms/employees')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async createEmployee(data: any): Promise<any> {
    return this.request('/hrms/employees', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateEmployee(id: number | string, data: any): Promise<any> {
    return this.request(`/hrms/employees/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteEmployee(id: number | string): Promise<any> {
    return this.request(`/hrms/employees/${id}`, { method: 'DELETE' })
  },
  async getAttendance(): Promise<any[]> { return this.request('/hrms/attendance') },
  async clockIn(locationGps?: string): Promise<any> {
    return this.request('/hrms/clock-in', { method: 'POST', body: JSON.stringify({ location_gps: locationGps }) })
  },
  async clockOut(): Promise<any> {
    return this.request('/hrms/clock-out', { method: 'POST' })
  },
  async getLeaves(): Promise<{ requests: any[]; balances: any }> { return this.request('/leaves') },
  async applyLeave(data: { leave_type: string; from_date: string; to_date: string; reason?: string }): Promise<any> {
    return this.request('/leaves', { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Financial ──
  async getInvoices(): Promise<any[]> {
    const res: any = await this.request('/financial/invoices')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async createInvoice(data: any): Promise<any> {
    return this.request('/financial/invoices', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateInvoice(id: number | string, data: any): Promise<any> {
    return this.request(`/financial/invoices/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteInvoice(id: number | string): Promise<any> {
    return this.request(`/financial/invoices/${id}`, { method: 'DELETE' })
  },
  async getQuotations(): Promise<any[]> {
    const res: any = await this.request('/financial/quotations')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async recordPayment(data: {
    invoice_id: number; amount: number; payment_method: string
    transaction_reference?: string; notes?: string
  }): Promise<any> {
    return this.request('/financial/payments', { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Reports ──
  async getReportData(type: string): Promise<{ type: string; rows: any[]; generated_at: string }> {
    return this.request(`/reports/data?type=${encodeURIComponent(type)}`)
  },

  // ── AI ──
  async queryAI(query: string): Promise<{ query: string; response: string; sql_query?: string; results?: any[] }> {
    return this.request('/ai/query', { method: 'POST', body: JSON.stringify({ query }) })
  },

  // ── Calendar ──
  async getCalendarEvents(): Promise<any[]> { return this.request('/calendar/events') },

  // ── Analytics ──
  async getTrackerAnalytics(): Promise<any> { return this.request('/analytics/tracker') },

  // ── Notifications ──
  async getNotifications(): Promise<any[]> {
    const res: any = await this.request('/notifications')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async markAllNotificationsRead(): Promise<any> { return this.request('/notifications/mark-all-read', { method: 'POST' }) },
  async markNotificationRead(id: number | string): Promise<any> { return this.request(`/notifications/${id}/read`, { method: 'POST' }) },
  async dismissNotification(id: number | string): Promise<any> { return this.request(`/notifications/${id}`, { method: 'DELETE' }) },

  // ── Project Tracker ──
  async getTrackerProjects(q?: string): Promise<any[]> {
    return this.request(`/tracker/projects${q ? `?q=${encodeURIComponent(q)}` : ''}`)
  },
  async getTrackerCircles(): Promise<any[]> { return this.request('/tracker/circles') },
  async getTrackerRows(circle: string): Promise<any[]> {
    return this.request(`/tracker/rows?circle=${circle}`)
  },
  async createTrackerRow(data: { circle_slug: string }): Promise<any> {
    return this.request('/tracker/rows', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateTrackerRow(id: number | string, data: Record<string, any>): Promise<any> {
    return this.request(`/tracker/rows/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async deleteTrackerRow(id: number | string): Promise<any> {
    return this.request(`/tracker/rows/${id}`, { method: 'DELETE' })
  },
  async addCircleMember(circleId: number | string, userId: number | string): Promise<any> {
    return this.request(`/tracker/circles/${circleId}/members`, { method: 'POST', body: JSON.stringify({ user_id: userId }) })
  },
  async removeCircleMember(circleId: number | string, userId: number | string): Promise<any> {
    return this.request(`/tracker/circles/${circleId}/members/${userId}`, { method: 'DELETE' })
  },

  // ── Documents ──
  async getDocuments(): Promise<any[]> {
    const res: any = await this.request('/documents')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async uploadDocument(file: File, folder?: string): Promise<any> {
    const formData = new FormData()
    formData.append('file', file)
    if (folder) formData.append('folder', folder)
    const response = await fetch('/api/documents', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' },
      credentials: 'same-origin',
      body: formData,
    })
    if (!response.ok) {
      const err = await response.json().catch(() => ({}))
      throw new Error((err as any).message || 'Upload failed')
    }
    return response.json()
  },
  async deleteDocument(path: string): Promise<any> {
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
  async getApprovals(): Promise<any[]> {
    const res: any = await this.request('/approvals')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async resolveApproval(type: string, id: number, action: 'Approved' | 'Rejected'): Promise<any> {
    return this.request('/approvals/resolve', { method: 'POST', body: JSON.stringify({ type, id, action }) })
  },

  // ── Discussions ──
  async getDiscussions(): Promise<any[]> {
    const res: any = await this.request('/discussions')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async createDiscussion(data: { title: string; tag: string; message: string }): Promise<any> {
    return this.request('/discussions', { method: 'POST', body: JSON.stringify(data) })
  },
  async replyDiscussion(threadId: number, message: string): Promise<any> {
    return this.request(`/discussions/${threadId}/reply`, { method: 'POST', body: JSON.stringify({ message }) })
  },

  // ── Settings ──
  async getSettings(): Promise<any> {
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

  // ── Compliance ──
  async getCompliance(): Promise<any[]> {
    const res: any = await this.request('/compliance')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async updateCompliance(id: number | string, data: { assignee?: string; note?: string; resolved?: boolean }): Promise<any> {
    return this.request(`/compliance/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async remindCompliance(id: number | string): Promise<any> {
    return this.request(`/compliance/${id}/remind`, { method: 'POST' })
  },

  // ── Reminders ──
  async getReminders(): Promise<any[]> {
    const res: any = await this.request('/reminders')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async createReminder(data: any): Promise<any> {
    return this.request('/reminders', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateReminder(id: number | string, data: { completed: boolean }): Promise<any> {
    return this.request(`/reminders/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },

  // ── Feedback / CSAT ──
  async getFeedback(): Promise<any[]> {
    const res: any = await this.request('/feedback')
    return Array.isArray(res) ? res : (res?.data ?? [])
  },
  async requestFeedback(data: { client: string; subject: string }): Promise<any> {
    return this.request('/feedback/request', { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Performance ──
  async getPerformance(): Promise<{ reviews: any[]; goals: any[]; feedback360: any[] }> {
    return this.request('/performance')
  },
  async submitPerformanceReview(id: number | string, data: { scores: Record<string, number>; comments?: string }): Promise<any> {
    return this.request(`/performance/reviews/${id}/submit`, { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Recruitment ──
  async getRecruitment(): Promise<{ jobs: any[]; pipeline: any[] }> { return this.request('/recruitment') },
  async createJob(data: any): Promise<any> {
    return this.request('/recruitment/jobs', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateJob(id: number | string, data: { status: string }): Promise<any> {
    return this.request(`/recruitment/jobs/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },

  // ── Offboarding ──
  async getOffboarding(): Promise<{ cases: any[]; completed: any[] }> { return this.request('/offboarding') },
  async createOffboarding(data: any): Promise<any> {
    return this.request('/offboarding', { method: 'POST', body: JSON.stringify(data) })
  },
  async updateOffboardingChecklist(id: number | string, checklist: boolean[]): Promise<any> {
    return this.request(`/offboarding/${id}/checklist`, { method: 'PUT', body: JSON.stringify({ checklist }) })
  },

  // ── Integrations ──
  async getIntegrations(): Promise<any[]> { return this.request('/integrations') },
  async toggleIntegration(slug: string): Promise<any> {
    return this.request(`/integrations/${slug}/toggle`, { method: 'POST' })
  },
  async saveIntegrationConfig(slug: string, apiKey: string): Promise<any> {
    return this.request(`/integrations/${slug}/config`, { method: 'POST', body: JSON.stringify({ api_key: apiKey }) })
  },
  async testIntegration(slug: string): Promise<{ ok: boolean }> {
    return this.request(`/integrations/${slug}/test`, { method: 'POST' })
  },

  // ── Client Portal ──
  async getPortalClients(): Promise<any[]> { return this.request('/portal/clients') },
  async togglePortal(clientId: number | string): Promise<any> {
    return this.request(`/portal/clients/${clientId}/toggle`, { method: 'POST' })
  },
  async portalInviteAll(): Promise<{ ok: boolean; invited: number }> {
    return this.request('/portal/invite-all', { method: 'POST' })
  },
  async createPortal(data: { client_id: number; email: string }): Promise<any> {
    return this.request('/portal/create', { method: 'POST', body: JSON.stringify(data) })
  },
  async resetUserPassword(userId: number | string, password: string): Promise<{ ok: boolean }> {
    return this.request(`/users/${userId}/reset-password`, { method: 'PUT', body: JSON.stringify({ password }) })
  },

  // ── Bulk operations ──
  async bulkExecute(data: { entity: string; ids: number[]; action: string; status?: string }): Promise<{ ok: boolean; affected: number }> {
    return this.request('/bulk/execute', { method: 'POST', body: JSON.stringify(data) })
  },

  // ── Payroll ──
  async getPayrollRuns(): Promise<{ runs: any[]; ytd_paid: number; can_manage: boolean; can_pay: boolean }> {
    return this.request('/payroll/runs')
  },
  async getPayrollRun(id: number | string): Promise<any> { return this.request(`/payroll/runs/${id}`) },
  async createPayrollRun(period: string): Promise<any> {
    return this.request('/payroll/runs', { method: 'POST', body: JSON.stringify({ period }) })
  },
  async deletePayrollRun(id: number | string): Promise<any> {
    return this.request(`/payroll/runs/${id}`, { method: 'DELETE' })
  },
  async finalizePayrollRun(id: number | string): Promise<any> {
    return this.request(`/payroll/runs/${id}/finalize`, { method: 'POST' })
  },
  async payPayrollRun(id: number | string): Promise<any> {
    return this.request(`/payroll/runs/${id}/pay`, { method: 'POST' })
  },
  async updatePayslip(id: number | string, data: { lop_days?: number; tds?: number }): Promise<any> {
    return this.request(`/payroll/payslips/${id}`, { method: 'PUT', body: JSON.stringify(data) })
  },
  async getMyPayslips(): Promise<any[]> {
    const res: any = await this.request('/payroll/my-slips')
    return Array.isArray(res) ? res : (res?.data ?? [])
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
