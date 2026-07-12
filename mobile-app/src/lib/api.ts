import { Platform } from 'react-native';

import type {
  AppNotification,
  ApplyLeaveInput,
  ApprovalItem,
  AttendanceActionResponse,
  AttendanceLog,
  Client,
  ClientDetail,
  CreateClientInput,
  CreateProjectInput,
  DashboardMetrics,
  FinancialStats,
  Invoice,
  InvoiceDetail,
  LeaveListResponse,
  LeaveRequest,
  MobileAuthResponse,
  MobileCredentials,
  MobileSession,
  PaginatedResponse,
  Project,
  ProjectDetail,
  PushTokenPayload,
  RecordPaymentInput,
  InvoicePayment,
  Reminder,
  ReminderPayload,
  CreateTaskInput,
  Task,
  TaskLogPayload,
  TaskStatus,
  UserOption,
} from '../types/api';

function getBaseUrl(): string {
  const value = process.env.EXPO_PUBLIC_API_BASE_URL?.trim();

  if (!value) {
    throw new Error('EXPO_PUBLIC_API_BASE_URL is not configured.');
  }

  return value.replace(/\/$/, '');
}

async function request<T>(path: string, options: RequestInit = {}, token?: string): Promise<T> {
  const response = await fetch(`${getBaseUrl()}/api${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers ?? {}),
    },
  });

  if (!response.ok) {
    const payload = await response.json().catch(() => ({}));
    const firstError = typeof payload.errors === 'object' && payload.errors
      ? Object.values(payload.errors as Record<string, string[]>)
          .flat()
          .find((value): value is string => typeof value === 'string')
      : null;
    const message = typeof payload.message === 'string'
      ? payload.message
      : firstError
        ? firstError
        : `Request failed with status ${response.status}`;

    throw new Error(message);
  }

  return response.json() as Promise<T>;
}

export async function login(credentials: MobileCredentials): Promise<MobileSession> {
  const payload = await request<MobileAuthResponse>('/mobile/auth/login', {
    method: 'POST',
    body: JSON.stringify({
      ...credentials,
      device_name: `${Platform.OS}-app`,
    }),
  });

  return {
    token: payload.token,
    user: payload.user,
  };
}

export async function logout(token: string): Promise<void> {
  await request('/mobile/auth/logout', { method: 'POST' }, token);
}

export async function getCurrentUser(token: string): Promise<MobileSession['user']> {
  return request('/mobile/me', { method: 'GET' }, token);
}

export async function getTasksForSession(token: string): Promise<Task[]> {
  const response = await request<PaginatedResponse<Task> | Task[]>('/tasks', { method: 'GET' }, token);

  return Array.isArray(response) ? response : response.data;
}

export async function updateTaskStatus(token: string, taskId: number, status: TaskStatus): Promise<Task> {
  return request(`/tasks/${taskId}`, {
    method: 'PUT',
    body: JSON.stringify({ status }),
  }, token);
}

export async function createTask(token: string, data: CreateTaskInput): Promise<Task> {
  return request('/tasks', {
    method: 'POST',
    body: JSON.stringify({
      title: data.title,
      description: data.description,
      priority: data.priority,
      due_date: data.due_date,
      project_id: data.project_id,
      status: 'Pending',
    }),
  }, token);
}

export async function deleteTask(token: string, taskId: number): Promise<void> {
  await request(`/tasks/${taskId}`, { method: 'DELETE' }, token);
}

export async function logTaskTime(
  token: string,
  payload: { taskId: number; projectId: number } & TaskLogPayload,
): Promise<void> {
  await request('/tasks/time-entries', {
    method: 'POST',
    body: JSON.stringify({
      project_id: payload.projectId,
      task_id: payload.taskId,
      duration_hours: payload.durationHours,
      entry_date: new Date().toISOString().slice(0, 10),
      description: payload.description,
      billable: true,
    }),
  }, token);
}

export async function getAttendanceLogs(token: string): Promise<AttendanceLog[]> {
  return request('/hrms/attendance', { method: 'GET' }, token);
}

export async function clockIn(token: string): Promise<AttendanceActionResponse> {
  return request('/hrms/clock-in', { method: 'POST', body: JSON.stringify({}) }, token);
}

export async function clockOut(token: string): Promise<AttendanceActionResponse> {
  return request('/hrms/clock-out', { method: 'POST', body: JSON.stringify({}) }, token);
}

export async function getApprovals(token: string): Promise<ApprovalItem[]> {
  const response = await request<PaginatedResponse<ApprovalItem>>('/approvals', { method: 'GET' }, token);
  return response.data;
}

export async function resolveApproval(
  token: string,
  payload: { type: ApprovalItem['type']; id: number; action: 'Approved' | 'Rejected'; comment?: string },
): Promise<void> {
  await request('/approvals/resolve', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, token);
}

export async function getNotifications(token: string): Promise<AppNotification[]> {
  const response = await request<PaginatedResponse<AppNotification>>('/notifications', { method: 'GET' }, token);
  return response.data;
}

export async function getUnreadNotificationCount(token: string): Promise<number> {
  const response = await request<{ count: number }>('/notifications/unread-count', { method: 'GET' }, token);
  return response.count;
}

export async function markNotificationRead(token: string, id: number): Promise<void> {
  await request(`/notifications/${id}/read`, { method: 'POST' }, token);
}

export async function markAllNotificationsRead(token: string): Promise<void> {
  await request('/notifications/mark-all-read', { method: 'POST' }, token);
}

export async function dismissNotification(token: string, id: number): Promise<void> {
  await request(`/notifications/${id}`, { method: 'DELETE' }, token);
}

export async function getReminders(token: string): Promise<Reminder[]> {
  const response = await request<PaginatedResponse<Reminder>>('/reminders', { method: 'GET' }, token);
  return response.data;
}

export async function createReminder(token: string, payload: ReminderPayload): Promise<void> {
  await request('/reminders', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, token);
}

export async function updateReminderCompletion(token: string, id: number, completed: boolean): Promise<void> {
  await request(`/reminders/${id}`, {
    method: 'PUT',
    body: JSON.stringify({ completed }),
  }, token);
}

export async function deleteReminder(token: string, id: number): Promise<void> {
  await request(`/reminders/${id}`, { method: 'DELETE' }, token);
}

export async function requestReminderHelp(
  token: string,
  id: number,
  targetUserId: number,
  note?: string,
): Promise<void> {
  await request(`/reminders/${id}/help-request`, {
    method: 'POST',
    body: JSON.stringify({ target_user_id: targetUserId, note: note ?? null }),
  }, token);
}

export async function getUsers(token: string): Promise<UserOption[]> {
  return request('/users', { method: 'GET' }, token);
}

export async function registerPushToken(token: string, payload: PushTokenPayload): Promise<void> {
  await request('/mobile/push-tokens', {
    method: 'POST',
    body: JSON.stringify(payload),
  }, token);
}

export async function unregisterPushToken(token: string, pushToken: string): Promise<void> {
  await request('/mobile/push-tokens', {
    method: 'DELETE',
    body: JSON.stringify({ push_token: pushToken }),
  }, token);
}

// ── Clients ────────────────────────────────────────────────────────────────

export async function getClients(
  token: string,
  params?: { search?: string; status?: string; page?: number; per_page?: number },
): Promise<PaginatedResponse<Client>> {
  const qs = new URLSearchParams();
  if (params?.search) qs.set('search', params.search);
  if (params?.status) qs.set('status', params.status);
  if (params?.page) qs.set('page', String(params.page));
  qs.set('per_page', String(params?.per_page ?? 25));
  return request(`/clients?${qs}`, { method: 'GET' }, token);
}

export async function getClient(token: string, id: number): Promise<ClientDetail> {
  return request(`/clients/${id}`, { method: 'GET' }, token);
}

export async function createClient(token: string, data: CreateClientInput): Promise<Client> {
  return request('/clients', {
    method: 'POST',
    body: JSON.stringify(data),
  }, token);
}

// ── Projects ───────────────────────────────────────────────────────────────

export async function getProjects(
  token: string,
  params?: { search?: string; status?: string; overdue?: boolean; project_type?: string; lifecycle_stage?: string; page?: number; per_page?: number },
): Promise<PaginatedResponse<Project>> {
  const qs = new URLSearchParams();
  if (params?.search) qs.set('search', params.search);
  if (params?.status) qs.set('status', params.status);
  if (params?.overdue) qs.set('overdue', 'true');
  if (params?.project_type) qs.set('project_type', params.project_type);
  if (params?.lifecycle_stage) qs.set('lifecycle_stage', params.lifecycle_stage);
  if (params?.page) qs.set('page', String(params.page));
  qs.set('per_page', String(params?.per_page ?? 25));
  return request(`/projects?${qs}`, { method: 'GET' }, token);
}

export async function getProject(token: string, id: number): Promise<ProjectDetail> {
  return request(`/projects/${id}`, { method: 'GET' }, token);
}

export async function advanceProjectStage(token: string, id: number, stageName: string): Promise<void> {
  await request(`/projects/${id}/stage`, {
    method: 'POST',
    body: JSON.stringify({ stage_name: stageName, status: 'In Progress' }),
  }, token);
}

export async function createProject(token: string, data: CreateProjectInput): Promise<Project> {
  return request('/projects', {
    method: 'POST',
    body: JSON.stringify({ ...data, record_mode: 'new' }),
  }, token);
}

// ── Invoices ───────────────────────────────────────────────────────────────

export async function getFinancialStats(token: string): Promise<FinancialStats> {
  return request('/financial/stats', { method: 'GET' }, token);
}

export async function getInvoices(
  token: string,
  params?: { status?: string; page?: number; per_page?: number },
): Promise<PaginatedResponse<Invoice>> {
  const qs = new URLSearchParams();
  if (params?.status) qs.set('status', params.status);
  if (params?.page) qs.set('page', String(params.page));
  qs.set('per_page', String(params?.per_page ?? 25));
  return request(`/financial/invoices?${qs}`, { method: 'GET' }, token);
}

export async function getInvoice(token: string, id: number): Promise<InvoiceDetail> {
  return request(`/financial/invoices/${id}`, { method: 'GET' }, token);
}

export async function recordPayment(token: string, data: RecordPaymentInput): Promise<InvoicePayment> {
  return request('/financial/payments', {
    method: 'POST',
    body: JSON.stringify(data),
  }, token);
}

export async function createInvoice(token: string, data: {
  client_id: number;
  project_id?: number | null;
  due_date: string;
  items: { description: string; amount: number }[];
  currency?: string;
  payment_terms?: string;
  notes?: string;
}): Promise<Invoice> {
  return request('/financial/invoices', {
    method: 'POST',
    body: JSON.stringify(data),
  }, token);
}

export async function updateInvoiceStatus(token: string, id: number, status: string): Promise<Invoice> {
  return request(`/financial/invoices/${id}`, {
    method: 'PUT',
    body: JSON.stringify({ status }),
  }, token);
}

export async function getDashboardMetrics(token: string): Promise<DashboardMetrics> {
  return request('/dashboard/metrics', { method: 'GET' }, token);
}

export async function getLifecycleStats(token: string): Promise<Record<string, number>> {
  return request('/projects/lifecycle-stats', { method: 'GET' }, token);
}

// ── Leave ──────────────────────────────────────────────────────────────────

export async function getLeaves(token: string): Promise<LeaveListResponse> {
  return request('/hrms/leaves', { method: 'GET' }, token);
}

export async function applyLeave(token: string, data: ApplyLeaveInput): Promise<LeaveRequest> {
  return request('/hrms/leaves', {
    method: 'POST',
    body: JSON.stringify(data),
  }, token);
}
