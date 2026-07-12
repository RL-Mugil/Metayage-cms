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
