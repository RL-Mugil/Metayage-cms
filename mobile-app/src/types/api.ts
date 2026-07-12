export type UserRole =
  | 'super_admin'
  | 'partner'
  | 'manager'
  | 'hr'
  | 'finance'
  | 'associate'
  | 'paralegal'
  | 'client'
  | 'client_admin';

export type TaskStatus = 'Pending' | 'In Progress' | 'Review' | 'Completed' | 'Blocked';
export type TaskPriority = 'Low' | 'Medium' | 'High' | 'Urgent';
export type ApprovalStatus = 'pending' | 'approved' | 'rejected';
export type ReminderCategory = 'Deadline' | 'Meeting' | 'Follow-up' | 'Renewal';

export type MobileCredentials = {
  email: string;
  password: string;
};

export type MobileUser = {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  status: 'Active' | 'Inactive';
  avatar_url?: string | null;
  permissions: Record<string, string | boolean>;
};

export type MobileAuthResponse = {
  token: string;
  token_type: 'Bearer';
  user: MobileUser;
};

export type MobileSession = {
  token: string;
  user: MobileUser;
};

export type PushTokenPayload = {
  push_token: string;
  platform: 'android' | 'ios';
  device_name?: string | null;
  app_version?: string | null;
};

export type PaginatedResponse<T> = {
  data: T[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
  has_more: boolean;
};

export type Task = {
  id: number;
  title: string;
  status: TaskStatus;
  priority: TaskPriority;
  project_id?: number | null;
  description?: string | null;
  due_date?: string | null;
  actual_hours?: number | null;
  project?: {
    id: number;
    project_code: string;
    project_name: string;
  } | null;
};

export type AttendanceSession = {
  in: string;
  out: string | null;
  duration_minutes: number | null;
};

export type AttendanceLog = {
  id: number;
  attendance_date: string;
  status: string;
  duration_minutes: number;
  sessions: AttendanceSession[];
  session_count: number;
  has_open_session: boolean;
  can_clock_in: boolean;
  can_clock_out: boolean;
  is_today: boolean;
};

export type AttendanceActionResponse = {
  message: string;
  id?: number;
  duration_minutes?: number;
};

export type ApprovalItem = {
  id: number;
  type: 'Leave' | 'Expense' | 'Client' | 'Colleague';
  requester: string;
  title?: string;
  description: string;
  amount?: string | null;
  from_date?: string | null;
  to_date?: string | null;
  submitted: string;
  status: ApprovalStatus;
  urgency: string;
  comments?: string | null;
  can_resolve?: boolean;
  created_at: string;
};

export type AppNotification = {
  id: number;
  type: string;
  title: string;
  description: string;
  meta: Record<string, unknown>;
  action_url?: string | null;
  read: boolean;
  created_at: string;
};

export type Reminder = {
  id: number;
  title: string;
  description: string;
  category: ReminderCategory;
  dueDate: string;
  dueTime?: string | null;
  assignedTo: string;
  completed: boolean;
  section: 'today' | 'week' | 'upcoming';
};

export type ReminderPayload = {
  title: string;
  description?: string;
  category: ReminderCategory;
  due_date: string;
  due_time?: string;
  scope: 'self' | 'team';
};

export type UserOption = {
  id: number;
  name: string;
  email: string;
  role: UserRole;
};

export type TaskLogPayload = {
  durationHours: number;
  description: string;
};

export type CreateTaskInput = {
  title: string;
  description?: string;
  priority: TaskPriority;
  due_date?: string;
  project_id?: number;
};

// ── Clients ────────────────────────────────────────────────────────────────

export type ClientStatus = 'Active' | 'Inactive' | 'Prospect' | 'On Hold';

export type Client = {
  id: number;
  company_name: string;
  legal_name: string;
  client_code: string;
  status: ClientStatus;
  gst_type: 'B2B' | 'B2C' | 'Export' | 'Unregistered';
  pan_number?: string | null;
  contact_name?: string | null;
  contact_email?: string | null;
  phone?: string | null;
  address?: string | null;
  state?: string | null;
  account_manager?: { id: number; name: string; email: string } | null;
};

export type ClientContact = {
  id: number;
  name: string;
  title?: string | null;
  email: string;
  phone?: string | null;
  role_type?: string | null;
};

export type ProjectSummary = {
  id: number;
  project_code: string;
  docket_number: string;
  project_name: string;
  status: string;
  hard_deadline?: string | null;
};

export type ClientDetail = Client & {
  contacts: ClientContact[];
  projects: ProjectSummary[];
};

export type CreateClientInput = {
  legal_name: string;
  company_name?: string;
  client_type: 'individual' | 'organization';
  nationality: string;
  contact_name?: string;
  contact_email?: string;
  phone?: string;
  account_manager_id?: number;
  status?: string;
};

// ── Projects ───────────────────────────────────────────────────────────────

export type ProjectStatus = 'Draft' | 'Open' | 'Active' | 'In Progress' | 'On Hold' | 'Closed' | 'Completed';

export type Project = {
  id: number;
  project_name: string;
  project_code: string;
  docket_number: string;
  status: ProjectStatus;
  hard_deadline?: string | null;
  filing_date?: string | null;
  client: { id: number; company_name: string };
  manager?: { id: number; name: string } | null;
  patentEngineer?: { id: number; name: string } | null;
};

export type Stage = {
  id: number;
  stage_name: string;
  status: 'Pending' | 'In Progress' | 'Completed';
  sequence_order: number;
  due_date?: string | null;
};

export type ProjectDetail = Project & {
  partner?: { id: number; name: string } | null;
  stages: Stage[];
  tasks: Task[];
};

export type CreateProjectInput = {
  client_id: number;
  project_name: string;
  patent_office_code: string;
  project_type: string;
  hard_deadline?: string;
  assigned_partner_id?: number;
  assigned_manager_id?: number;
  record_mode?: 'new' | 'existing';
};

// ── Invoices ───────────────────────────────────────────────────────────────

export type InvoiceStatus = 'Draft' | 'Sent' | 'Viewed' | 'Partially Paid' | 'Paid' | 'Overdue' | 'Cancelled';

export type Invoice = {
  id: number;
  invoice_code: string;
  issue_date: string;
  due_date: string;
  currency: string;
  subtotal: number;
  tax_amount: number;
  total_amount: number;
  balance_due: number;
  status: InvoiceStatus;
  client: { id: number; company_name: string };
  project?: { id: number; project_name: string } | null;
};

export type InvoiceItem = {
  id: number;
  description: string;
  quantity: number;
  unit_rate: number;
  amount: number;
  tax_rate: number;
};

export type InvoicePayment = {
  id: number;
  receipt_code: string;
  amount: number;
  payment_method: string;
  payment_date: string;
  transaction_reference?: string | null;
};

export type InvoiceDetail = Invoice & {
  items: InvoiceItem[];
  payments: InvoicePayment[];
};

export type FinancialStats = {
  total_billed: number;
  total_received: number;
  total_outstanding: number;
  overdue_count: number;
  draft_count: number;
  paid_count: number;
};

export type RecordPaymentInput = {
  invoice_id: number;
  amount: number;
  payment_method: string;
  transaction_reference?: string;
  notes?: string;
};

// ── Leave ──────────────────────────────────────────────────────────────────

export type LeaveType = 'Earned' | 'Casual' | 'Sick' | 'Compensatory' | 'Maternity' | 'Paternity' | 'Unpaid';
export type LeaveStatus = 'Pending' | 'Approved' | 'Rejected' | 'Cancelled';

export type LeaveRequest = {
  id: number;
  employee_id: number;
  employee_name?: string | null;
  leave_type: LeaveType;
  from_date: string;
  to_date: string;
  total_days: number;
  reason: string;
  status: LeaveStatus;
  comments?: string | null;
  is_mine: boolean;
};

export type LeaveBalance = {
  id: number;
  year: number;
  earned_leave: number;
  casual_leave: number;
  sick_leave: number;
  lop_days: number;
};

export type LeaveListResponse = {
  requests: LeaveRequest[];
  total: number;
  balances: LeaveBalance | null;
  is_approver: boolean;
};

export type ApplyLeaveInput = {
  leave_type: LeaveType;
  from_date: string;
  to_date: string;
  reason: string;
};

export type DashboardMetrics = {
  metrics: {
    active_matters: number;
    inactive_matters?: number;
    clients: number;
    pending_tasks: number;
    wip_balance?: number;
    received_payments?: number;
    invoiced_total?: number;
    realization_rate?: number;
    active_matters_delta?: number;
    clients_delta?: number;
    revenue_delta?: number;
  };
  charts: {
    stage_distribution: { stage_name: string; count: number }[];
  };
};

// ── Queue ──────────────────────────────────────────────────────────────────

export type QueuedAction =
  | { id: string; kind: 'attendance.clockIn'; createdAt: string }
  | { id: string; kind: 'attendance.clockOut'; createdAt: string }
  | { id: string; kind: 'tasks.status'; createdAt: string; payload: { taskId: number; status: TaskStatus } }
  | {
      id: string;
      kind: 'tasks.timeLog';
      createdAt: string;
      payload: {
        taskId: number;
        projectId: number;
        durationHours: number;
        description: string;
      };
    };
