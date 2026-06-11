const BASE_URL = import.meta.env.VITE_API_BASE_URL ?? "/api";

export interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  status: string;
  avatar_url?: string | null;
  permissions?: Record<string, string>;
}

export const api = {
  getToken(): string | null {
    if (typeof window !== "undefined") {
      return localStorage.getItem("ipflow_token");
    }
    return null;
  },

  setToken(token: string) {
    if (typeof window !== "undefined") {
      localStorage.setItem("ipflow_token", token);
    }
  },

  clearToken() {
    if (typeof window !== "undefined") {
      localStorage.removeItem("ipflow_token");
      localStorage.removeItem("ipflow_user");
    }
  },

  getUser(): User | null {
    if (typeof window !== "undefined") {
      const userStr = localStorage.getItem("ipflow_user");
      return userStr ? JSON.parse(userStr) : null;
    }
    return null;
  },

  setUser(user: User) {
    if (typeof window !== "undefined") {
      localStorage.setItem("ipflow_user", JSON.stringify(user));
    }
  },

  async request<T>(endpoint: string, options: RequestInit = {}): Promise<T> {
    const token = this.getToken();
    const headers = {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(options.headers || {}),
    } as Record<string, string>;

    if (token) {
      headers["Authorization"] = `Bearer ${token}`;
    }

    const response = await fetch(`${BASE_URL}${endpoint}`, {
      ...options,
      headers,
    });

    if (!response.ok) {
      if (response.status === 401) {
        this.clearToken();
        if (typeof window !== "undefined" && window.location.pathname !== "/login") {
          window.location.href = "/login";
        }
      }
      const errData = await response.json().catch(() => ({}));
      throw new Error(errData.message || `Request failed with status ${response.status}`);
    }

    return response.json();
  },

  // Auth Operations
  async login(email: string, password: string): Promise<{ access_token: string; user: User }> {
    const data = await this.request<{ access_token: string; user: User }>("/login", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    });
    this.setToken(data.access_token);
    this.setUser(data.user);
    return data;
  },

  async logout(): Promise<void> {
    try {
      await this.request("/logout", { method: "POST" });
    } finally {
      this.clearToken();
    }
  },

  async getMe(): Promise<User> {
    const user = await this.request<User>("/me");
    this.setUser(user);
    return user;
  },

  // Dashboard Operations
  async getDashboardMetrics(): Promise<{ metrics: Record<string, number>; charts: any }> {
    return this.request("/dashboard/metrics");
  },

  // CRM / Clients Operations
  async getClients(search?: string): Promise<any[]> {
    const query = search ? `?search=${encodeURIComponent(search)}` : "";
    return this.request(`/clients${query}`);
  },

  async getClient(id: number | string): Promise<any> {
    return this.request(`/clients/${id}`);
  },

  async createClient(data: any): Promise<any> {
    return this.request("/clients", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async updateClient(id: number | string, data: any): Promise<any> {
    return this.request(`/clients/${id}`, {
      method: "PUT",
      body: JSON.stringify(data),
    });
  },

  async deleteClient(id: number | string): Promise<any> {
    return this.request(`/clients/${id}`, {
      method: "DELETE",
    });
  },

  // Projects Operations
  async getProjects(search?: string): Promise<any[]> {
    const query = search ? `?search=${encodeURIComponent(search)}` : "";
    return this.request(`/projects${query}`);
  },

  async getProject(id: number | string): Promise<any> {
    return this.request(`/projects/${id}`);
  },

  async createProject(data: any): Promise<any> {
    return this.request("/projects", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async updateProjectStage(id: number | string, stageName: string): Promise<any> {
    return this.request(`/projects/${id}/stage`, {
      method: "POST",
      body: JSON.stringify({ stage_name: stageName }),
    });
  },

  // Tasks Operations
  async getTasks(status?: string): Promise<any[]> {
    const query = status ? `?status=${encodeURIComponent(status)}` : "";
    return this.request(`/tasks${query}`);
  },

  async createTask(data: any): Promise<any> {
    return this.request("/tasks", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  async updateTask(id: number | string, data: any): Promise<any> {
    return this.request(`/tasks/${id}`, {
      method: "PUT",
      body: JSON.stringify(data),
    });
  },

  async logTimeEntry(data: { project_id: number; task_id?: number | null; duration_hours: number; entry_date: string; description: string; billable?: boolean }): Promise<any> {
    return this.request("/tasks/time-entries", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  // HRMS Operations
  async getEmployees(): Promise<any[]> {
    return this.request("/hrms/employees");
  },

  async getAttendance(): Promise<any[]> {
    return this.request("/hrms/attendance");
  },

  async clockIn(locationGps?: string): Promise<any> {
    return this.request("/hrms/clock-in", {
      method: "POST",
      body: JSON.stringify({ location_gps: locationGps }),
    });
  },

  async clockOut(): Promise<any> {
    return this.request("/hrms/clock-out", {
      method: "POST",
    });
  },

  async getLeaves(): Promise<{ requests: any[]; balances: any }> {
    return this.request("/hrms/leaves");
  },

  async applyLeave(data: { leave_type: string; from_date: string; to_date: string; reason: string }): Promise<any> {
    return this.request("/hrms/leaves", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  // Financial Operations
  async getInvoices(): Promise<any[]> {
    return this.request("/financial/invoices");
  },

  async getQuotations(): Promise<any[]> {
    return this.request("/financial/quotations");
  },

  async recordPayment(data: { invoice_id: number; amount: number; payment_method: string; transaction_reference?: string; notes?: string }): Promise<any> {
    return this.request("/financial/payments", {
      method: "POST",
      body: JSON.stringify(data),
    });
  },

  // AI Chat
  async queryAI(query: string): Promise<{ query: string; response: string; sql_query?: string; results?: any[] }> {
    return this.request("/ai/query", {
      method: "POST",
      body: JSON.stringify({ query }),
    });
  },
};
