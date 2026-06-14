<!-- converted from IPFlow_Enterprise_Product_Specification_v2.docx -->

IPFlow
Enterprise IP Management Platform
Product Specification Document
with Integrated Enterprise HRMS
For Metayage Staff & Internal Clients Only
Version 2.0 | June 2026
CONFIDENTIAL — INTERNAL USE ONLY

Table of Contents
1.  Executive Summary
2.  Platform Scope & Boundaries
3.  Core Platform Pillars
4.  Module 1: Client Management (CRM)
5.  Module 2: Project / Matter Management
6.  Module 3: Project Tracker & Stage Management
7.  Module 4: Kanban Board
8.  Module 5: Task Management
9.  Module 6: Reminder & Notification Engine
10.  Module 7: Client Portal
11.  Module 8: Project Timeline & Milestones
12.  Module 9: Document Management System (DMS)
13.  Module 10: Client Questions & Discussions
14.  Module 11: Approval Workflow Engine
15.  Module 12: Real-Time Notifications
16.  Module 13: Dashboards & Analytics
17.  Module 14: Reporting Engine
18.  Module 15: Financial Suite
19.  Module 16: AI Assistant (FastAPI Sidecar)
20.  Module 17: Internal Team Workspace
21.  Module 18: Client Satisfaction & Feedback
22.  Module 19: Bulk Operations Center
23.  Module 20: Calendar & Scheduling
24.  Module 21: Governance, Compliance & Audit
25.  Module 22: Integrations
26.  Module 23: Mobile Responsiveness
27.  Module 24: Settings & Administration
28.  Module 25: Enterprise HRMS
29.  Technical Architecture
30.  Security Requirements
31.  Performance & Scalability
32.  Implementation Phases
33.  Success Metrics

1. Executive Summary
IPFlow is a comprehensive, enterprise-grade Intellectual Property management platform designed specifically for Metayage's internal operations. The platform unifies client management, project lifecycle tracking, document governance, financial operations, team collaboration, and human resource management into a single source of truth. Built exclusively for Metayage staff and their assigned internal clients — no external or outbound client access is permitted.
This specification defines the complete functional and non-functional requirements for the platform, including a fully-integrated Enterprise HRMS module for staff management, attendance, leave, payroll, performance evaluation, and organizational governance.

2. Platform Scope & Boundaries
2.1 Access Scope
- The platform is strictly for Metayage internal staff and their assigned internal clients only.
- No external, third-party, or outbound client onboarding is permitted.
- All user accounts are provisioned by the Metayage IT/HR administrator.
- Self-registration is disabled. All access requires admin approval.
- Client accounts are limited to designated contacts within Metayage's client organizations.
- No public-facing portal or guest access is available.
2.2 Data Sovereignty
- All data resides on Metayage-managed infrastructure.
- No third-party SaaS data processing without explicit security review.
- Client data is isolated per engagement with row-level security.
- HRMS data is strictly confidential and accessible only to authorized HR and management personnel.
2.3 Compliance Boundaries
- Platform must comply with applicable labor laws for HRMS functionality.
- Financial modules must support jurisdiction-specific tax and invoicing requirements.
- Audit trails must be immutable and retained for 7 years minimum.
- Data retention policies must align with Metayage internal governance standards.

3. Core Platform Pillars
Client Hub: Complete client lifecycle, portal, and communication — exclusively for Metayage's internal client engagements.
Project Engine: End-to-end IP matter management with configurable workflows and stage tracking.
Financial Suite: Full billing, invoicing, quotations, proformas, tax invoices, and payment ledger management.
Document Vault: Enterprise document management with version control, audit trails, and access governance.
Team Workspace: Internal collaboration, workload management, and knowledge sharing for Metayage staff.
HRMS: Enterprise-grade human resource management — employee records, attendance, leave, payroll, performance, and organizational governance.
Intelligence Layer: Reporting, analytics, AI-assisted search, and business insights.
Governance & Compliance: Audit trails, access control, data retention, and regulatory compliance.

4. Module 1: Client Management (CRM)
4.1 Client Profile

4.2 Contact Management
- Unlimited contacts per client with role-based categorization: Primary Contact, Technical Contact, Billing Contact, Legal Contact, Authorized Signatory.
- Contact fields: Name, Title, Department, Email, Phone, Mobile, Timezone, Preferred Language, Notification Preferences.
- Email verification required before portal access.
- Log of all communications per contact.
4.3 Client Relationships
- Parent/Subsidiary Hierarchy: Link related entities for group billing and reporting.
- Referral Tracking: Track who referred the client.
- Conflict Check Integration: Flag potential conflicts with existing clients/opposing parties.
4.4 Client Portal (Internal Clients Only)
- Branded Portal: Custom logo, colors per client engagement.
- Self-Service: View matters, upload documents, approve/reject with digital signature, submit questions, view invoices and payment history, download receipts.
- Portal Access Control: Granular permissions per contact (view-only, upload, approve, message).
- Portal Activity Log: Every view, download, and action tracked.
4.5 Client Communication Hub
- Unified Inbox: All emails, portal messages in one thread.
- Email Integration: Sync with Outlook/Gmail; auto-attach to client record.
- Bulk Communication Center: Send updates to multiple clients with mail merge.
- Email Templates: Pre-built for common scenarios.
- Read Receipts & Tracking: Know when client opened/clicked.

5. Module 2: Project / Matter Management
5.1 Project Types (Configurable)

5.2 Project Fields

5.3 Project Status Workflow
Draft → Open → Active → On Hold → Waiting for Client → Under Review → Ready for Filing → Filed → Prosecution → Granted / Registered → Completed → Archived → Cancelled
- Status Transitions: Configurable rules (e.g., cannot move to 'Filed' without approval).
- Status Change Reasons: Mandatory note when moving to On Hold or Cancelled.
- Auto-Status Rules: Trigger status changes based on events.
5.4 Project Templates
- Pre-built Templates: For each project type with default stages, tasks, and timelines.
- Custom Templates: Users can create and save firm-specific templates.
- Template Variables: Auto-populate client name, matter reference, dates.
- Template Cloning: Duplicate existing projects as templates.

6. Module 3: Project Tracker & Stage Management
6.1 Configurable Stage Pipeline
Example: Patent Filing Pipeline

6.2 Stage Properties
- Owner: Single responsible person
- Due Date: Calculated from start date + duration, or manually set
- Actual Start/End: Tracked automatically
- Notes & Comments: Per stage
- Attachments: Documents specific to stage
- Checklist: Sub-tasks within stage
- Gate Criteria: Required conditions to complete stage
- Auto-Escalation: Escalate if stage exceeds due date by X hours
6.3 Stage Dependencies
- Linear, parallel, or conditional stages
- Branching logic: If provisional → skip certain stages; if PCT → add international stages

7. Module 4: Kanban Board
7.1 Board Configuration
- Default Columns: New | In Progress | Waiting for Client | Under Review | Completed | On Hold
- Custom Columns: Per team or project type
- WIP Limits: Set maximum items per column
- Swimlanes: Group by priority, client, or assignee
7.2 Drag & Drop (DnD Kit)
- Drag cards between columns
- Drag to reorder within column
- Bulk select and move
- Keyboard shortcuts for power users
7.3 Card Details
- Project name, client, type, priority indicator
- Due date with color coding (green/yellow/red)
- Assignee avatar
- Unread message count
- Document count
- Quick actions: View, Message, Add Task
7.4 Board Views
- My Board: Only projects/tasks assigned to me
- Team Board: All team projects
- Client Board: Filtered by client
- Type Board: Filtered by project type

8. Module 5: Task Management
8.1 Task Hierarchy
Project → Milestone → Task Group → Task
8.2 Task Fields

8.3 Task Views
- List view with sorting/filtering
- Gantt chart view (timeline)
- Calendar view
- My Tasks dashboard
8.4 Task Automation
- Auto-create tasks from project templates
- Auto-assign based on workload/rules
- Reminders at 50%, 80%, 100% of estimated time
- Auto-escalate overdue tasks to manager

9. Module 6: Reminder & Notification Engine
9.1 Reminder Types

9.2 Notification Channels
- In-app: Real-time badge, notification center, toast alerts
- Email: Configurable frequency (immediate, digest, none)
- SMS: For critical deadlines (optional)
- Slack/Teams: Integration for team channels
- Push: Mobile app notifications
9.3 Notification Rules
- Per-user notification preferences
- Do-not-disturb hours
- Escalation chains: If not acknowledged in X hours, notify manager
- Client notification preferences respected
9.4 Laravel Reverb Integration
- Real-time updates without page refresh
- Live activity indicators ('Client is viewing document')
- Live chat and commenting
- Real-time board updates (no refresh needed when teammate moves card)

10. Module 7: Client Portal
10.1 Portal Sections

10.2 Security & Privacy
- Data Isolation: Client A cannot see Client B's data (row-level security)
- Document Access Control: Per-document visibility settings
- Watermarking: Draft documents show 'CONFIDENTIAL — [Client Name]' watermark
- Download Restrictions: Prevent download of sensitive drafts (view-only)
- Session Management: Auto-logout after inactivity, concurrent session limits
- 2FA: Optional two-factor authentication for portal access
10.3 Client Self-Service
- New Matter Intake Form: Clients can submit new project requests
- Document Upload: Drag-and-drop with automatic virus scanning
- Digital Signatures: Integration with DocuSign/Adobe Sign for approvals
- Feedback Submission: Post-project satisfaction survey

11. Module 8: Project Timeline & Milestones
11.1 Auto-Generated Timeline
- Every status change, document upload, message, and approval auto-logs to timeline
- Visual timeline with icons and color coding
- Filter by event type (status, document, communication, approval)
11.2 Milestone Management
- Define key milestones per project type
- Milestone completion triggers actions (e.g., send update, create invoice)
- Milestone delay alerts
- Milestone comparison: Planned vs. Actual dates
11.3 Client-Facing Timeline
- Simplified view for clients (hide internal stages)
- Estimated completion dates
- 'What's Next' preview

12. Module 9: Document Management System (DMS)
12.1 Document Types

12.2 Document Properties
- Version history (unlimited versions)
- Check-in / Check-out (prevent concurrent editing)
- Metadata: Upload date, uploader, document type, related project, tags
- OCR for searchable PDFs
- Virus scanning on upload
- Retention policy tags
12.3 Storage (MinIO)
- S3-compatible object storage
- Encrypted at rest and in transit
- Cross-region replication (for disaster recovery)
- Lifecycle policies: Auto-archive old documents, delete after retention period
12.4 Access Control
- Role-based: Who can view, download, edit, delete
- Project-based: Only project team + client (if authorized)
- Document-level: Specific permissions per document
- Audit log: Who accessed what and when
12.5 Version Control
- Automatic versioning (v1.0, v1.1, v2.0)
- Compare versions (diff for text documents)
- Restore previous version
- Version notes required on upload

13. Module 10: Client Questions & Discussions
13.1 Thread Structure
- Project-specific threads: Tied to specific matters
- General threads: Not tied to any project
- Private threads: Internal team only
- Client-visible threads: Shared with client
13.2 Thread Features
- Rich text formatting (Tiptap editor)
- File attachments
- @mentions with notifications
- Thread pinning
- Thread resolution (mark as resolved)
- Thread templates for common questions
13.3 Knowledge Base Integration
- Suggest answers from previous similar threads
- Auto-tag threads for knowledge base indexing
- Convert resolved thread to FAQ

14. Module 11: Approval Workflow Engine
14.1 Approval Types

14.2 Configurable Workflow Designer
- Visual drag-and-drop workflow builder
- Steps: Upload → Review → Approve/Reject → Revise → Final Approval
- Parallel approvals (multiple approvers simultaneously)
- Sequential approvals (one after another)
- Conditional logic (e.g., if amount > $10K, require partner approval)
14.3 Approval Actions
- Approve with comments
- Approve with conditions
- Reject with reason and revision request
- Request clarification
- Delegate to another approver
- Escalate if no response in X days
14.4 Digital Signatures
- Integration with DocuSign, Adobe Sign, or native e-signature
- Audit trail of signature
- Certificate of completion
- Tamper-evident sealing

15. Module 12: Real-Time Notifications
15.1 Notification Events

15.2 Notification Preferences
- Granular control per event type
- Frequency: Immediate, Hourly Digest, Daily Digest, Weekly Digest, None
- Channel selection per event type
- Quiet hours (no notifications 8 PM — 8 AM unless critical)

16. Module 13: Dashboards & Analytics
16.1 Staff Dashboard
- Projects by Status (pie chart)
- Overdue Tasks (count + list)
- Pending Approvals (count + quick actions)
- Recent Client Activity (timeline)
- Upcoming Deadlines (next 7/14/30 days)
- My Workload (tasks assigned to me)
- Team Workload (heatmap by person)
- Revenue This Month vs. Target
- Unbilled Time (WIP)
- Client Messages Requiring Response
16.2 Partner / Management Dashboard
- Firm-wide KPIs
- Revenue pipeline
- Client acquisition and retention metrics
- Employee utilization rates
- Profitability by client/matter type
- Aged receivables
- Matter velocity (average time per stage)
16.3 Client Dashboard
- My Projects (active count, completed count)
- Pending Actions (approvals, document uploads, responses needed)
- Unread Messages
- Recent Documents
- Upcoming Deadlines
- Invoice Summary (total outstanding, last payment)
- Project Timeline (visual)
16.4 Custom Dashboards
- Users can create custom dashboards
- Drag-and-drop widget layout
- Save and share dashboard templates
- Schedule dashboard PDF export (daily/weekly)

17. Module 14: Reporting Engine
17.1 Standard Reports

17.2 Report Builder
- Visual report builder (drag fields, filters, groupings)
- Save custom reports
- Schedule reports (daily, weekly, monthly)
- Export: PDF, Excel, CSV
- Email reports to stakeholders
- Embed reports in dashboards

18. Module 15: Financial Suite
18.1 Chart of Accounts
- Configurable account structure
- Revenue accounts: Patent Filing Fees, Trademark Fees, Search Fees, Attorney Fees, Government Fees, Disbursements
- Expense tracking (optional)
- Multi-currency support with daily exchange rate updates
18.2 Proforma Invoice

- Status: Draft → Sent → Accepted → Converted to Invoice → Expired → Cancelled
- Conversion: One-click convert to formal invoice upon client acceptance
- Reminders: Auto-remind before expiry
- PDF Generation: Branded template with digital signature
18.3 Quotation / Fee Estimate

- Version Control: Quote revisions tracked (v1, v2)
- Comparison View: Show client what changed between versions
- Approval Workflow: Internal partner approval before sending
- Acceptance Tracking: Client accepts via portal or email
18.4 Invoice

- Invoice Status: Draft → Pending Approval → Sent → Viewed → Partially Paid → Paid → Overdue → Cancelled → Credit Note Issued
- Recurring Invoices: Monthly retainer invoices auto-generated
- Consolidated Invoicing: Combine multiple matters into single invoice per client
- Split Billing: Divide invoice across multiple clients/departments
- Invoice PDF: Branded, with QR code for payment
- Delivery: Email, portal, or both
- Payment Integration: Stripe, PayPal, Razorpay, bank transfer tracking
18.5 Tax Invoice / GST Invoice
- Jurisdiction-specific templates (US, EU, India, etc.)
- Tax breakdown: CGST, SGST, IGST (India); VAT (EU); Sales Tax (US states)
- HSN/SAC codes for India
- Tax registration numbers displayed
- E-invoice integration (where required by law, e.g., India GST)
- Tax report: Output tax liability by period
18.6 Credit Note / Debit Note
- Issue credit note for overpayment, discount, or service adjustment
- Link to original invoice
- Auto-adjust client ledger
- Debit note for additional charges post-invoice
18.7 Payment Ledger (Client Account Statement)

- Opening Balance: As of start date
- All Transactions: Invoices, credit notes, payments, adjustments, write-offs
- Running Balance: After each transaction
- Aging Analysis: Current, 1-30 days, 31-60 days, 61-90 days, 90+ days
- Statement Generation: Monthly auto-generated, email to client
- Reconciliation: Mark payments against invoices, handle partial payments
- Write-offs: Bad debt write-off with approval workflow
18.8 Time Tracking
- Timer: Start/stop timer per task
- Manual time entry with date, duration, description, billable flag
- Non-billable time tracking (for costing analysis)
- Time entry approval workflow (associate → partner)
- Bulk time entry
- Time entry rules: Minimum increment (0.1 hr, 0.25 hr)
- Integration with tasks and projects
18.9 Expense Tracking
- Record disbursements: Government fees, search fees, travel, courier
- Markup configuration: Auto-add X% to disbursements
- Receipt attachment
- Expense approval workflow
- Bill-to-client flag
18.10 Financial Dashboard
- Revenue this month/quarter/year
- Outstanding receivables
- Revenue by client (top 10)
- Revenue by matter type
- Collection rate (billed vs. collected)
- Realization rate (billed vs. worked)
- WIP (Work in Progress) value
- Unbilled disbursements

19. Module 16: AI Assistant (FastAPI Sidecar)
19.1 Smart Search
- Natural language queries: 'Show all projects waiting for client response'
- 'Projects for ABC Corp due this month'
- 'Overdue tasks assigned to John'
- 'Invoices unpaid for more than 60 days'
- Search across: Projects, tasks, documents, messages, invoices, clients
- Filter suggestions based on context
19.2 Document Search
- Full-text search across all documents
- Search within PDF content (OCR-enabled)
- Filter by document type, date, client, project
- Similar document suggestions
19.3 Smart Summaries
- Summarize long email threads
- Summarize project status from timeline
- Summarize client communication history
19.4 Out of Scope
- Legal analysis or advice
- Patent claim drafting
- Trademark similarity analysis
- Any practice of law

20. Module 17: Internal Team Workspace
20.1 Team Notes
- Project-specific internal notes (client cannot see)
- General team notes (not tied to project)
- Rich text with @mentions
- Pin important notes
- Note templates
20.2 Internal Comments
- Comment on any project, task, document, or invoice
- Threaded discussions
- @mentions with notifications
- Resolve/close comment threads
20.3 Escalations
- Escalate project/task to senior partner
- Escalation reason and urgency
- Track escalation resolution time
- Escalation dashboard for managers
20.4 Knowledge Base
- Internal wiki for procedures, templates, best practices
- Searchable and taggable
- Version history
- Access control by team/role
20.5 Team Calendar
- Shared calendar for deadlines, meetings, filings
- Personal calendar view
- Resource booking (conference rooms, tools)
- Calendar sync with Outlook/Google

21. Module 18: Client Satisfaction & Feedback
21.1 Post-Project Survey
- Auto-triggered on project completion
- Customizable questions (NPS, CSAT, open text)
- Rating: 1-5 stars
- Feedback categories: Communication, Quality, Timeliness, Value
21.2 Feedback Dashboard
- Average rating per client, per attorney, per matter type
- Trend analysis over time
- Negative feedback alerts (immediate notification to partner)
- Response workflow for negative feedback
21.3 Testimonials
- Request permission to use positive feedback as testimonial
- Testimonial library for marketing

22. Module 19: Bulk Operations Center
22.1 Bulk Email
- Select multiple clients or projects
- Compose with mail merge variables
- Attach documents
- Schedule send time
- Track opens and clicks
- Unsubscribe management
22.2 Bulk Document Actions
- Bulk download
- Bulk move/rename
- Bulk change access permissions
- Bulk delete (with approval)
22.3 Bulk Invoice Actions
- Generate monthly invoices in batch
- Bulk email invoices
- Bulk mark as paid (for reconciliation)

23. Module 20: Calendar & Scheduling
23.1 Calendar Views
- Month, week, day, agenda views
- Color coding by event type
- Filter by client, project, team member
23.2 Event Types
- Internal deadlines
- Client meetings
- Filing deadlines
- Court dates (for opposition proceedings)
- Reminder events
- Team events
23.3 Calendar Integration
- Two-way sync with Google Calendar, Outlook, Apple Calendar
- Meeting scheduling links (Calendly-style)
- Timezone handling for international clients

24. Module 21: Governance, Compliance & Audit
24.1 Audit Trail (Spatie Activity Log)
- Log every create, read, update, delete action
- Who, what, when, from where (IP address)
- Immutable log (cannot be deleted)
- Log retention: 7 years (configurable)
24.2 Access Control (Spatie Permission)
- Role-Based Access Control (RBAC):
- • Super Admin, Partner, Senior Attorney, Associate Attorney, Paralegal
- • Finance Manager, Billing Clerk, Client (portal only), Client Admin
- Permission granularity: View, Create, Edit, Delete, Approve, Export per module
- Data scope: Users can only see clients/projects they are assigned to
24.3 Data Retention & Privacy
- GDPR compliance tools: Data export, right to erasure (with legal hold exceptions)
- Data retention policies per document type
- Auto-archive old projects
- Secure deletion with certificate
24.4 Compliance Reporting
- User access review report
- Permission audit report
- Data handling log
- Security incident log

25. Module 22: Integrations
25.1 Email
- SMTP/IMAP integration
- Microsoft 365 / Exchange
- Gmail / Google Workspace
- Email parsing: Auto-create projects from client emails
25.2 Storage
- MinIO (primary)
- AWS S3 (alternative)
- Google Drive / Dropbox (client document import)
25.3 E-Signature
- DocuSign
- Adobe Sign
- HelloSign
25.4 Payment
- Stripe
- PayPal
- Razorpay (India)
- Bank transfer reconciliation (manual upload)
25.5 Communication
- Slack (notifications)
- Microsoft Teams
- Zoom (meeting links in calendar)
25.6 Accounting
- QuickBooks Online
- Xero
- Zoho Books
- Tally (India)
25.7 Patent Office
- USPTO Private PAIR (via API where available)
- EPO Online Filing
- WIPO ePCT
- INPO (India)

26. Module 23: Mobile Responsiveness
- Fully responsive web app (no separate mobile app required initially)
- Mobile-optimized client portal
- Touch-friendly Kanban board
- Mobile document upload (camera capture)
- Push notifications (via PWA)

27. Module 24: Settings & Administration
27.1 Firm Settings
- Firm profile and branding
- Default currencies, tax rates, payment terms
- Invoice templates and numbering sequences
- Email templates and signatures
- Notification defaults
- Working hours and holidays (affect deadline calculations)
27.2 User Management
- Add/edit/disable users
- Role assignment
- Department/team assignment
- Workload capacity settings
- Skill tags (for auto-assignment)
27.3 System Configuration
- Project type configuration
- Stage pipeline builder
- Task template builder
- Approval workflow designer
- Custom fields (add fields to any entity)
- Tags and categories management
- Integration settings
27.4 Data Management
- Import: Clients, projects, contacts (CSV/Excel)
- Export: Any data grid to CSV/Excel/PDF
- Backup configuration
- Archive management

28. Module 25: Enterprise HRMS
The Enterprise HRMS module is fully integrated into the IPFlow platform and serves exclusively Metayage internal staff. It covers the complete employee lifecycle from recruitment to exit, including attendance, leave, payroll, performance management, training, and organizational governance. All HRMS data is strictly confidential and accessible only to authorized HR personnel and management.
28.1 Employee Information Management (EIM)
28.1.1 Employee Master Record

28.1.2 Employee Self-Service (ESS) Portal
- Personal profile view and update (restricted fields require HR approval)
- View payslips and tax documents
- Apply for leave and track leave balance
- View attendance records and regularization requests
- Submit expense claims and reimbursements
- View assigned training and certifications
- Submit grievances and HR queries
- Update bank details (requires HR verification)
- Download Form 16, PF statements, experience letters
28.1.3 Document Management (HR)
- Offer Letter generation and storage
- Appointment Letter
- Confirmation Letter
- Increment Letter
- Promotion Letter
- Transfer Letter
- Warning / Show Cause Notice
- Termination / Resignation Letter
- Experience / Relieving Certificate
- Full & Final Settlement document
- All documents with digital signature and version control

28.2 Organizational Structure Management
28.2.1 Department Management
- Hierarchical department tree (e.g., Legal → Patents → Drafting Team)
- Department head assignment
- Department budget allocation (optional)
- Department-wise headcount and cost center tracking
- Department transfer workflow with approval chain
28.2.2 Designation / Job Role Management
- Job role catalog with descriptions and responsibilities
- Competency requirements per role
- Grade/band mapping
- Career progression paths
- Role-based access to platform modules
28.2.3 Reporting Hierarchy
- Visual org chart with drag-and-drop editing
- Primary reporting line (solid line)
- Secondary reporting line (dotted line) for matrix organizations
- Skip-level reporting visibility for senior management
- Delegation of authority during manager absence
28.2.4 Location & Branch Management
- Multi-location support for Metayage offices
- Location-specific holidays and work schedules
- Location-wise headcount and cost tracking
- Inter-location transfer workflow

28.3 Attendance & Time Management
28.3.1 Attendance Capture Methods

28.3.2 Shift Management
- Define multiple shifts: General, Morning, Evening, Night, Rotational
- Shift start time, end time, grace period, break duration
- Weekly off configuration per shift
- Shift rotation scheduling
- Overtime rules per shift
- Shift swap request and approval workflow
28.3.3 Attendance Policies
- Late arrival tolerance (e.g., 15 minutes grace)
- Early departure rules
- Half-day thresholds
- Absenteeism tracking and alerts
- Presenteeism metrics
- Work-from-home attendance marking
28.3.4 Attendance Regularization
- Employee can request correction for missed punch
- Reason selection: Forgot, Device issue, Official duty, Client visit, etc.
- Manager approval required
- HR can override with audit trail
- Bulk regularization for team outings/events
28.3.5 Overtime Management
- Auto-calculate overtime based on shift rules
- Overtime approval workflow (Employee → Manager → HR)
- Compensatory off (comp-off) accrual
- Overtime pay calculation (if applicable)
- Overtime reports and analytics
28.3.6 Attendance Reports
- Daily attendance register
- Monthly attendance summary
- Late coming report
- Early going report
- Absenteeism trend analysis
- Department-wise attendance comparison
- Individual attendance calendar view

28.4 Leave Management
28.4.1 Leave Types & Policies

28.4.2 Leave Accrual Engine
- Monthly / Quarterly / Annual accrual rules per leave type
- Pro-rata calculation for new joiners and resigning employees
- Carry forward rules (max days, expiry date)
- Leave encashment rules and processing
- Negative balance blocking (configurable per leave type)
- Leave balance dashboard for every employee
28.4.3 Leave Application Workflow
- Employee applies via ESS portal or mobile
- Select leave type, from date, to date, reason
- Attachment upload (medical certificate, etc.)
- Auto-check leave balance before submission
- Manager approval (with delegation support)
- HR approval for certain leave types (maternity, sabbatical)
- Auto-notify team members of absence
- Calendar block for approved leave
- Leave cancellation and modification workflow
28.4.4 Leave Reports
- Individual leave ledger (all transactions)
- Department leave calendar
- Leave balance summary
- Leave utilization trend
- Unplanned leave analysis
- Leave liability report for accounting

28.5 Payroll Management
28.5.1 Salary Structure Configuration

28.5.2 Payroll Processing
- Monthly payroll cycle configuration (cut-off date, payment date)
- Auto-import attendance data for LOP calculation
- Auto-import approved expense reimbursements
- Arrears calculation for salary revisions
- Full and Final Settlement (FnF) for exiting employees
- Payroll preview and validation before finalization
- Payroll lock after finalization (no edits without reversal)
- Payslip generation and distribution (email + portal)
28.5.3 Tax Management
- Old vs. New tax regime selection (India)
- Section 80C, 80D, HRA, LTA declarations collection
- Investment proof upload and verification
- Monthly TDS calculation and projection
- Form 16 generation (Part A and Part B)
- Form 24Q quarterly filing support
- Tax reconciliation at year-end
28.5.4 Statutory Compliance
- PF contribution calculation and challan generation
- ESI contribution calculation and payment
- Professional Tax (PT) calculation and payment
- Gratuity provision calculation
- Bonus calculation (as per Payment of Bonus Act)
- Minimum Wage compliance check
- Compliance calendar with due date reminders
28.5.5 Payroll Reports
- Monthly salary register
- Payslip download (individual and bulk)
- Bank transfer statement (for salary disbursement)
- PF/ESI/PT challan reports
- TDS summary and Form 16
- Cost-to-company (CTC) breakdown per employee
- Department-wise payroll cost analysis
- Year-to-date (YTD) earnings and deductions

28.6 Performance Management
28.6.1 Goal Setting (OKR / KPI)
- Annual / Quarterly goal setting cycle
- Goal categories: Project delivery, Client satisfaction, Knowledge sharing, Process improvement, Business development
- SMART goal framework with weightage allocation
- Manager-employee goal alignment and approval
- Goal progress tracking with quarterly check-ins
- Auto-capture project metrics into performance goals
28.6.2 Performance Review Cycle
- Self-appraisal by employee
- Manager review and rating (1-5 scale)
- 360-degree feedback: Peer, subordinate, client feedback (optional)
- Skip-level review for senior roles
- Calibration session support for rating normalization
- Final rating and compensation recommendation
- Performance improvement plan (PIP) trigger for low ratings
28.6.3 Competency Assessment
- Role-specific competency framework
- Technical skills: Patent drafting, Search, Filing, Prosecution
- Behavioral competencies: Communication, Leadership, Teamwork, Initiative
- Proficiency levels: Beginner, Intermediate, Advanced, Expert
- Skill gap analysis and training recommendations
- Certification tracking
28.6.4 Promotion & Career Progression
- Promotion eligibility criteria based on tenure and performance
- Promotion nomination workflow
- Promotion panel review and decision
- Increment letter generation
- Career path visualization for employees
- Succession planning for critical roles

28.7 Recruitment & Onboarding
28.7.1 Job Requisition
- Department head raises requisition with justification
- Budget approval from finance
- JD creation with role requirements and competencies
- Approval workflow: HOD → HR → Management
- Job posting to internal career portal
28.7.2 Candidate Management
- Resume parsing and candidate profile creation
- Application tracking: Applied → Screened → Interview → Offer → Hired
- Interview scheduling with panel assignment
- Interview feedback forms per round
- Background verification tracking
- Offer letter generation with CTC breakdown
- Candidate communication (acceptance, rejection, hold)
28.7.3 Onboarding Workflow
- Pre-joining checklist: Document collection, reference checks
- Welcome kit assignment (laptop, ID card, access cards)
- IT asset allocation and tracking
- System access provisioning (platform modules per role)
- Buddy/mentor assignment
- Induction schedule and training plan
- 30/60/90-day review checkpoints
- Probation completion workflow and confirmation

28.8 Offboarding & Exit Management
28.8.1 Resignation Workflow
- Employee submits resignation with notice period
- Manager acceptance and counter-offer option
- Notice period tracking and waiver approval
- Knowledge transfer task assignment
- Exit interview scheduling
28.8.2 Clearance Process
- Department-wise clearance checklist:
- • IT: Laptop, access cards, software licenses returned
- • Finance: Loans/advances settled, expense claims cleared
- • HR: Documents returned, ID card surrendered
- • Manager: Project handover, knowledge transfer complete
- Auto-block system access on last working day
- Auto-notify payroll for Full & Final settlement
28.8.3 Full & Final Settlement
- Auto-calculate: Pending salary, leave encashment, gratuity, bonus prorata
- Auto-deduct: Notice period shortfall, loans, damages
- Settlement statement generation
- Payment processing and payslip generation
- Experience certificate and relieving letter auto-generation
28.8.4 Exit Interview
- Configurable exit interview questionnaire
- Reason for leaving analysis (trend reporting)
- Feedback on management, culture, workload
- Would you recommend Metayage? (eNPS)
- Alumni network registration option

28.9 Training & Development
28.9.1 Training Catalog
- Internal training programs: IP law updates, drafting workshops, soft skills
- External training tracking: Conferences, certifications, courses
- Mandatory training: Compliance, data privacy, sexual harassment prevention
- Training calendar and enrollment
- Trainer and venue management
28.9.2 Learning Management
- Online course hosting (video, documents, quizzes)
- Progress tracking and completion certificates
- Skill assessment pre/post training
- Training budget tracking per employee/department
- Training effectiveness evaluation (Kirkpatrick model)
28.9.3 Certification Tracking
- Professional certifications: Patent Agent, Trademark Attorney, etc.
- Renewal date reminders (90/60/30 days)
- Certification expiry alerts
- Certification cost reimbursement workflow

28.10 Expense & Reimbursement Management
28.10.1 Expense Categories

28.10.2 Expense Claim Workflow
- Employee submits claim with receipts (photo upload)
- OCR auto-extracts amount, date, vendor from receipt
- Manager approval
- Finance verification and approval
- Reimbursement in next payroll cycle or separate payment
- Expense report generation
28.10.3 Travel Management
- Travel request pre-approval workflow
- Travel itinerary management
- Advance request and settlement
- Travel policy enforcement (class of travel, hotel category)

28.11 Asset & Inventory Management
28.11.1 Asset Catalog
- Laptops, monitors, printers, phones, furniture
- Software licenses (Adobe, MS Office, patent search tools)
- Asset tagging with QR/barcode
- Asset category, brand, model, serial number, purchase date, warranty
28.11.2 Asset Allocation
- Issue asset to employee with acknowledgment
- Asset transfer between employees
- Asset return on resignation/transfer
- Damage/loss reporting and penalty calculation
28.11.3 Maintenance & Disposal
- Maintenance schedule and history
- Warranty expiry alerts
- Asset depreciation tracking
- Disposal workflow with approval

28.12 Grievance & Disciplinary Management
28.12.1 Grievance Redressal
- Employee submits grievance (anonymous option available)
- Grievance categorization: Harassment, Discrimination, Pay dispute, Work environment, Other
- Auto-assign to HR with confidentiality flag
- Investigation workflow with timeline
- Resolution tracking and closure
- Appeal process for unsatisfied resolutions
28.12.2 Disciplinary Actions
- Warning levels: Verbal → Written → Final → Termination
- Show cause notice generation
- Hearing scheduling and minutes
- Action letter generation with digital signature
- Appeal tracking
- All records sealed and accessible only to HR and legal
28.12.3 POSH Compliance (India)
- Internal Complaints Committee (ICC) member management
- Sexual harassment complaint filing (anonymous option)
- Investigation timeline tracking (90 days as per law)
- Action taken report generation
- Annual compliance report

28.13 HR Analytics & Dashboards
28.13.1 HR Dashboard (Management View)
- Headcount: Total, by department, by location, by gender, by tenure
- Attrition rate: Monthly, quarterly, annual
- Joining vs. Exiting trend
- Average tenure and tenure distribution
- Department-wise salary cost
- Recruitment funnel: Applications → Interviews → Offers → Joined
- Time-to-hire and cost-per-hire
- Training hours per employee
- Performance rating distribution
- High-potential employee identification
28.13.2 Attendance Analytics
- Daily/weekly/monthly attendance percentage
- Late coming trend analysis
- Absenteeism heatmap by department
- Overtime trend and cost
- Leave utilization pattern
28.13.3 Payroll Analytics
- Monthly payroll cost breakdown
- CTC trend per employee
- Statutory compliance cost
- Leave liability projection
- Gratuity provision forecast
28.13.4 Employee Dashboard (Self View)
- My attendance calendar
- My leave balance
- My payslip history
- My tax summary
- My performance history
- My training completed and pending
- My assets allocated

28.14 HR Policy & Document Repository
28.14.1 Policy Management
- Centralized policy repository: Leave policy, Attendance policy, Code of conduct, Dress code, IT policy, Travel policy
- Version control for policies
- Policy acknowledgment tracking (employee must read and accept)
- Policy effective date and expiry
- Policy change notification to all affected employees
28.14.2 Employee Handbook
- Digital employee handbook with search
- Section-wise access (some sections HR-only)
- Acknowledgment and acceptance tracking
- Annual review and update workflow

28.15 HRMS Administration & Configuration
28.15.1 Leave Configuration
- Leave type creation and configuration
- Accrual rules: Monthly, quarterly, annual, pro-rata
- Carry forward and encashment rules
- Weekend and holiday treatment
- Leave year configuration (calendar year or financial year)
28.15.2 Payroll Configuration
- Salary component configuration
- Tax slab configuration (old/new regime)
- PF/ESI/PT rates and limits
- Bonus and gratuity rules
- Payroll cycle and cut-off dates
- Bank details for bulk transfer
28.15.3 Attendance Configuration
- Shift definition and assignment
- Holiday calendar per location
- Weekend configuration
- Biometric device integration settings
- Overtime calculation rules
- Late/early penalty rules (if applicable)
28.15.4 Approval Hierarchy
- Define approval chains per workflow type
- Delegation rules for manager absence
- Escalation rules for pending approvals
- Approval authority limits (e.g., expense approval up to $X)

29. Technical Architecture
29.1 Technology Stack

29.2 System Architecture
- Monolithic Laravel application with modular service separation
- Frontend SPA using Inertia.js for seamless Laravel-React integration
- API-first design for future mobile app and third-party integrations
- Event-driven architecture using Laravel Events and Listeners
- Background job processing via Redis queues and Laravel Horizon
- Real-time updates via Laravel Reverb WebSocket server
- AI services decoupled via FastAPI sidecar with REST API communication
- File storage abstracted via Laravel Storage facade (MinIO/S3 compatible)
29.3 Database Design Principles
- Multi-tenant row-level security for client data isolation
- Soft deletes on all major entities for data recovery
- UUID primary keys for external-facing references
- JSONB columns for flexible metadata and custom fields
- Partitioning strategy for high-volume tables (attendance, audit logs)
- Read replicas for reporting queries (optional)
29.4 Deployment Architecture
- Containerized deployment using Docker
- Orchestration: Docker Compose (initial) → Kubernetes (scale)
- Reverse proxy: Nginx with SSL termination
- Load balancer for horizontal scaling
- CI/CD pipeline: GitHub Actions / GitLab CI
- Environment strategy: Development → Staging → Production
- Blue-green deployment for zero-downtime updates

30. Security Requirements
30.1 Authentication & Authorization

30.2 Data Protection
- Encryption at Rest: AES-256 for database and file storage
- Encryption in Transit: TLS 1.3 for all communications
- Field-level encryption for PII: Aadhaar, bank accounts, passwords
- Database encryption keys stored in environment variables / HashiCorp Vault
- Secure key rotation policy
30.3 File Security
- Virus scanning on all uploads (ClamAV integration)
- File type whitelist validation
- File size limits per type (max 500MB)
- Document watermarking for sensitive drafts
- Download restrictions and view-only options
- Secure file deletion with overwrite
30.4 Audit & Compliance
- Immutable audit logs using append-only tables
- Log every CRUD operation with user, timestamp, IP, user agent
- 7-year log retention (configurable)
- SOC 2 Type II roadmap
- GDPR compliance: Right to access, rectification, erasure (with legal hold)
- ISO 27001 alignment
- Annual security penetration testing
30.5 Infrastructure Security
- Network segmentation: DMZ, application tier, database tier
- WAF (Web Application Firewall) for DDoS and SQL injection protection
- DDoS mitigation via Cloudflare / AWS Shield
- Automated vulnerability scanning (OWASP ZAP)
- Dependency vulnerability monitoring (Snyk / Dependabot)
- Security incident response plan with escalation matrix

31. Performance & Scalability
31.1 Performance Targets

31.2 Scalability Strategy
- Horizontal scaling: Stateless application servers behind load balancer
- Database read replicas for reporting and analytics
- Redis cluster for caching and session storage
- MinIO cluster for distributed file storage
- Queue workers scale independently based on job volume
- CDN for static assets and document previews
- Database partitioning for high-volume tables
31.3 Caching Strategy
- Redis for session storage and rate limiting
- Query result caching for dashboard widgets (5 min TTL)
- File metadata caching for DMS
- API response caching for read-heavy endpoints
- Cache invalidation on data mutation

32. Implementation Phases
The implementation follows a phased, agile approach to minimize risk and ensure early value delivery. Each phase includes planning, development, testing, UAT, and deployment with a hypercare period.
32.1 Phase 1: Foundation (Weeks 1-8)
- Core infrastructure setup: Server provisioning, CI/CD pipeline, database schema
- Authentication & Authorization: SSO, RBAC, user management
- Client Management Module: Profiles, contacts, onboarding workflow
- Basic Project Management: Project creation, types, status tracking
- Document Management: Upload, download, version control, MinIO integration
- Basic Client Portal: Login, project view, document access
- Deliverable: Core platform operational for pilot team
32.2 Phase 2: Workflow Engine (Weeks 9-14)
- Project Tracker: Configurable stage pipelines, gate criteria, auto-escalation
- Kanban Board: DnD functionality, WIP limits, swimlanes
- Task Management: Hierarchy, dependencies, Gantt view, time tracking
- Approval Workflow Engine: Visual designer, digital signatures
- Reminder & Notification Engine: Multi-channel, escalation chains
- Real-time updates via Laravel Reverb
- Deliverable: Full project lifecycle management operational
32.3 Phase 3: Financial Suite (Weeks 15-20)
- Chart of Accounts and financial configuration
- Proforma Invoice: Creation, sending, acceptance, conversion
- Quotation / Fee Estimate: Templates, versioning, approval
- Invoice Generation: Time-entry integration, recurring invoices, consolidated billing
- Tax Invoice / GST Invoice: Jurisdiction-specific templates, e-invoice
- Credit Note / Debit Note management
- Payment Ledger: Client account statements, aging, reconciliation
- Expense Tracking: Receipt capture, approval, bill-to-client
- Deliverable: End-to-end billing and payment tracking operational
32.4 Phase 4: Intelligence & Collaboration (Weeks 21-24)
- Dashboards & Analytics: Staff, partner, client views with widgets
- Reporting Engine: Standard reports, report builder, scheduling
- AI Assistant: Smart search, document search, summaries
- Client Questions & Discussions: Threaded messaging, knowledge base
- Internal Team Workspace: Notes, comments, escalations, knowledge base
- Client Satisfaction: Post-project surveys, feedback dashboard
- Deliverable: Full analytics and collaboration suite operational
32.5 Phase 5: Enterprise HRMS (Weeks 25-34)
- Employee Information Management: Master records, ESS portal, document vault
- Organizational Structure: Departments, designations, reporting hierarchy, locations
- Attendance & Time Management: Biometric integration, shifts, overtime, regularization
- Leave Management: Accrual engine, application workflow, balance tracking
- Payroll Management: Salary structure, processing, tax, statutory compliance
- Performance Management: Goal setting, reviews, 360 feedback, competency
- Recruitment & Onboarding: Requisitions, candidate tracking, onboarding workflow
- Offboarding & Exit Management: Clearance, FnF settlement, exit interview
- Training & Development: Catalog, LMS, certification tracking
- Expense & Reimbursement: Claims, travel, OCR receipt scanning
- Asset Management: Catalog, allocation, maintenance, disposal
- Grievance & Disciplinary: Redressal, actions, POSH compliance
- HR Analytics: Dashboards, trends, compliance reports
- Deliverable: Complete HRMS operational for all Metayage staff
32.6 Phase 6: Scale & Harden (Weeks 35-40)
- Integrations: Email, e-signature, payment gateways, accounting software, patent offices
- Mobile Optimization: PWA, touch-friendly interfaces, camera capture
- Performance Tuning: Query optimization, caching, CDN, database partitioning
- Security Hardening: Penetration testing, WAF, DDoS protection, compliance audit
- Disaster Recovery: Backup automation, cross-region replication, DR drills
- User Training: Role-based training, documentation, video tutorials
- Hypercare: 30-day intensive support post go-live
- Deliverable: Production-ready, enterprise-grade platform

33. Success Metrics
33.1 Platform Adoption Metrics
- Client Portal Adoption: > 80% of internal clients active monthly
- Staff Daily Active Users: > 90% of Metayage staff logging in daily
- Mobile Usage: > 40% of staff using mobile-responsive features
- Self-Service Utilization: > 70% of clients using portal without email follow-up
33.2 Operational Efficiency Metrics
- Invoice Payment Time: Reduce by 30% vs. manual process
- Project Visibility: 100% of projects trackable in real-time
- Task Completion Rate: > 95% on-time completion
- Document Retrieval Time: < 30 seconds
- Billing Accuracy: > 99% (reduce write-offs)
- Matter Velocity: Average time per stage reduced by 20%
33.3 HRMS Metrics
- Payroll Accuracy: 100% error-free salary disbursement
- Attendance Capture Rate: > 95% daily attendance marked
- Leave Application Turnaround: < 24 hours for manager approval
- Expense Reimbursement Cycle: < 7 days from submission to payment
- Employee Satisfaction (eNPS): > 50 score
- Attrition Rate: Track and trend monthly
- Time-to-Hire: < 30 days average from requisition to offer
- Training Completion Rate: > 90% of assigned training completed on time
33.4 Financial Metrics
- Revenue Realization: > 95% of billed amount collected within payment terms
- WIP Value Visibility: 100% of unbilled time tracked and visible
- Aged Receivables: < 10% of total outstanding > 90 days
- Cost Per Matter: Track and optimize over time
- Profitability by Client: > 20% margin on all active clients
33.5 Quality & Compliance Metrics
- Client Satisfaction: > 4.5/5 average rating
- Audit Trail Completeness: 100% of actions logged
- Data Accuracy: > 99.5% accuracy in client and employee records
- Compliance Score: 100% statutory compliance (PF, ESI, PT, TDS)
- Security Incidents: Zero critical security breaches
- Uptime: > 99.9% platform availability

Appendix A: Glossary
CTC: Cost to Company — total annual compensation package including salary, benefits, and employer contributions
DMS: Document Management System
DnD: Drag and Drop — user interface interaction pattern
EL/PL: Earned Leave / Privilege Leave
ESI: Employees' State Insurance — Indian social security scheme
ESS: Employee Self-Service — portal for employees to manage their own HR data
FnF: Full and Final Settlement — final payment to exiting employee
FTO: Freedom to Operate — patent clearance analysis
GST: Goods and Services Tax — Indian indirect tax
HRMS: Human Resource Management System
HSN: Harmonized System of Nomenclature — GST product classification
ICC: Internal Complaints Committee — POSH compliance body
Inertia.js: Full-stack framework for building single-page applications without API layer
LOP: Loss of Pay — unpaid leave deduction
MinIO: High-performance object storage compatible with Amazon S3 API
NPS: Net Promoter Score — customer/employee satisfaction metric
PCT: Patent Cooperation Treaty — international patent filing system
PF: Provident Fund — Indian retirement savings scheme
PIP: Performance Improvement Plan
POSH: Prevention of Sexual Harassment — Indian workplace law
PT: Professional Tax — state-level tax in India
RBAC: Role-Based Access Control
SaaS: Software as a Service
SLA: Service Level Agreement
TDS: Tax Deducted at Source — Indian income tax withholding
UAN: Universal Account Number — PF identifier
UAT: User Acceptance Testing
WIP: Work in Progress — unbilled time and expenses
WIP Limits: Work in Progress Limits — Kanban constraint

Appendix B: Platform Role Permissions Matrix


Appendix C: Data Retention & Archival Policy


Appendix D: Compliance & Regulatory Checklist
D.1 Indian Labour Law Compliance
- Payment of Wages Act, 1936 — timely salary disbursement
- Minimum Wages Act, 1948 — minimum wage verification in payroll
- Payment of Bonus Act, 1965 — annual bonus calculation
- Employees' Provident Fund Act, 1952 — PF contribution and challan
- Employees' State Insurance Act, 1948 — ESI contribution (if applicable)
- Maternity Benefit Act, 1961 — maternity leave tracking
- Payment of Gratuity Act, 1972 — gratuity provision calculation
- Professional Tax Act — state-wise PT deduction and payment
- Income Tax Act, 1961 — TDS calculation, Form 16, Form 24Q
- Sexual Harassment of Women at Workplace Act, 2013 — POSH compliance
- Right to Information Act, 2005 — data access provisions
- Information Technology Act, 2000 — data security and privacy
D.2 Data Protection Compliance
- GDPR (if serving EU clients) — data subject rights, DPO appointment
- Indian Personal Data Protection Bill — readiness assessment
- Data localization — all HRMS and client data on Indian servers
- Breach notification — within 72 hours of discovery
- Consent management — explicit consent for data processing
D.3 Financial Compliance
- GST Act — invoice generation, HSN codes, e-invoice (where applicable)
- TDS Compliance — monthly returns, quarterly Form 24Q/26Q
- Accounting Standards — IND AS / IFRS alignment for financial reporting
- Invoice numbering — sequential, non-editable, audit trail
D.4 Intellectual Property Compliance
- Client confidentiality agreements — digital storage and access control
- Attorney-client privilege — document handling protocols
- Conflict of interest checks — automated flagging system
- Patent filing deadlines — statutory deadline tracking with alerts
| Field | Type | Notes |
| --- | --- | --- |
| Client Code | Auto-generated, unique | Format: CLI-YYYY-XXXX |
| Company Name | Text | Legal entity name |
| Trade Name / DBA | Text | Optional operating name |
| Entity Type | Dropdown | Corporation, LLC, Partnership, Individual, University, Government |
| Tax ID / VAT / GST Number | Text | Jurisdiction-specific |
| Industry / Sector | Dropdown | For analytics and templating |
| Primary Jurisdiction | Dropdown | Default filing jurisdiction |
| Secondary Jurisdictions | Multi-select | For PCT and multi-country filings |
| Website | URL |  |
| Date Onboarded | Date | Auto-populated |
| Account Manager | User reference | Primary relationship owner |
| Credit Limit | Currency | For credit control |
| Payment Terms | Dropdown | Net 15, Net 30, Net 60, etc. |
| Currency Preference | Dropdown | Default billing currency |
| Billing Frequency | Dropdown | Per-project, Monthly, Quarterly |
| Communication Preference | Multi-select | Email, Portal, Phone |
| Language Preference | Dropdown | For portal localization |
| SLA Tier | Dropdown | Standard, Premium, Enterprise |
| Status | Dropdown | Active, On Hold, Inactive, Blacklisted |
| Type | Description |
| --- | --- |
| Patent Filing (Utility) | Standard patent application |
| Patent Filing (Provisional) | Provisional application |
| PCT Filing | International phase |
| National Phase Entry | Entering national stages from PCT |
| Trademark Filing | Word mark, logo, or combined |
| Design Filing | Industrial design registration |
| Copyright Registration | Copyright filing |
| Prior Art Search | Patentability/landscape search |
| Patent Drafting | Specification and claims drafting |
| Freedom to Operate (FTO) | Clearance search and opinion |
| Invalidity/Validity Search | Challenge existing patents |
| IP Audit | Portfolio review and valuation |
| Opposition/Invalidation | Contested proceedings |
| Licensing Negotiation | IP licensing matters |
| Custom / Other | User-defined types |
| Field | Type | Notes |
| --- | --- | --- |
| Project ID | Auto-generated | Format: PRJ-YYYY-XXXXX |
| Matter Reference | Text | Client's internal reference |
| Client | Reference | Links to Client Profile |
| Project Type | Dropdown | From configurable list |
| Project Name | Text | Descriptive title |
| Title of Invention | Text | For patent matters |
| Technology Field | Dropdown | For categorization and routing |
| Priority Application | Reference | Links to parent application |
| Priority Date | Date | For Paris Convention claims |
| Assigned Partner | User reference | Responsible partner |
| Assigned Manager | User reference | Day-to-day manager |
| Assigned Team | Multi-user | Associates, paralegals, drafters |
| Start Date | Date |  |
| Target Filing Date | Date |  |
| Hard Deadline | Date | Statutory or client-imposed |
| Estimated Hours | Number | For resource planning |
| Budget | Currency | Client-approved budget |
| Fee Arrangement | Dropdown | Hourly, Fixed Fee, Capped, Contingency, Hybrid |
| Status | Dropdown | See status workflow below |
| Urgency | Dropdown | Normal, High, Critical |
| Confidentiality Level | Dropdown | Standard, Confidential, Strictly Confidential |
| Tags | Multi-select | Custom tags for filtering |
| Stage | Owner | Duration | Auto-Actions |
| --- | --- | --- | --- |
| 1. Intake & Disclosure | Paralegal | 2 days | Create task list, notify manager |
| 2. Disclosure Review | Attorney | 3 days | Schedule internal call |
| 3. Prior Art Search | Search Team | 5 days |  |
| 4. Drafting | Patent Attorney | 14 days | Create drafting tasks |
| 5. Internal Review | Senior Attorney | 3 days |  |
| 6. Client Review | Client | 7 days | Send portal notification + email |
| 7. Revision Cycle | Patent Attorney | 5 days |  |
| 8. Filing Preparation | Paralegal | 3 days | Generate filing checklist |
| 9. Client Approval | Client | 2 days | Request digital signature |
| 10. Filed | Paralegal | 1 day | Update status, trigger invoice |
| 11. Acknowledgment | Paralegal | 5 days | Track filing receipt |
| 12. Completed | Manager | 1 day | Send completion notice, request feedback |
| Field | Type |
| --- | --- |
| Task ID | Auto-generated |
| Title | Text |
| Description | Rich text |
| Assignee | User reference |
| Reviewer | User reference (optional) |
| Priority | Low, Normal, High, Critical |
| Due Date | Date/Time |
| Estimated Hours | Number |
| Actual Hours | Number (time tracking) |
| Status | Not Started, In Progress, Awaiting Review, Completed, Cancelled |
| Dependencies | Blocked by other tasks |
| Tags | Custom |
| Recurring | Yes/No with pattern |
| Billable | Yes/No |
| Category | Examples |
| --- | --- |
| Deadline Reminders | Project due dates, statutory deadlines, filing deadlines |
| Client Response | Pending client approval, document requests, information gaps |
| Internal Tasks | Overdue tasks, review reminders, unassigned items |
| Financial | Unpaid invoices, credit limit approaching, budget threshold |
| System | Password expiry, license renewal, data backup |
| Section | Client Capability |
| --- | --- |
| Dashboard | Overview of all matters, pending actions, recent updates |
| My Projects | List view with status, due dates, team contacts |
| Project Detail | Full timeline, stage details, documents, messages |
| Documents | Upload, download, view (with watermark for drafts) |
| Messages | Project-specific and general threads |
| Invoices & Payments | View all invoices, download PDFs, see payment status |
| Approvals | Pending approvals with one-click approve/reject |
| Questions | Submit new questions, view history |
| Profile | Update contact info, change password, notification prefs |
| Category | Examples |
| --- | --- |
| Invention Disclosures | Initial client submissions |
| Drafts | Patent specifications, trademark descriptions |
| Correspondence | Emails, letters, official communications |
| Forms | Power of attorney, declarations, assignments |
| Filing Documents | Applications, claims, drawings |
| Office Actions | Examiner reports, responses |
| Receipts | Filing receipts, registration certificates |
| Financial | Invoices, quotations, proformas |
| Internal | Notes, memos, strategy documents |
| Type | Description |
| --- | --- |
| Document Approval | Client reviews and approves draft |
| Filing Authorization | Client authorizes filing |
| Budget Approval | Client approves estimated fees |
| Internal Approval | Partner reviews associate work |
| Invoice Approval | Finance approves invoice before sending |
| Event | Recipients | Channels |
| --- | --- | --- |
| New message in thread | Thread participants | In-app, Email |
| Document uploaded | Project team + client (if visible) | In-app, Email |
| Task assigned | Assignee | In-app, Email, Slack |
| Stage completed | Project team, client | In-app, Email |
| Approval requested | Approver | In-app, Email, SMS |
| Approval completed | Requester, project team | In-app, Email |
| Invoice generated | Client billing contact | Email, Portal |
| Payment received | Finance team, account manager | In-app, Email |
| Deadline approaching | Responsible person, manager | In-app, Email, SMS |
| Client portal login | Account manager | In-app |
| Report | Description |
| --- | --- |
| Projects Completed | By period, by client, by type, by attorney |
| Projects In Progress | Pipeline status, bottlenecks |
| Average Completion Time | By project type, by team, by attorney |
| Client Activity | Matters opened, messages sent, documents uploaded |
| Employee Workload | Hours, tasks, projects per person |
| Revenue by Client | Top clients, trends, comparisons |
| Revenue by Matter Type | Which services are most profitable |
| Aged Receivables | Overdue invoices by client |
| Utilization Rate | Billable vs. non-billable hours |
| Client Satisfaction | Ratings and feedback scores |
| Deadline Compliance | On-time vs. late completions |
| Document Activity | Uploads, downloads, approvals |
| Field | Description |
| --- | --- |
| Proforma Number | Auto-generated: PRO-YYYY-XXXXX |
| Client | Reference to client profile |
| Project | Link to specific matter |
| Issue Date | Auto |
| Valid Until | Default 30 days, configurable |
| Currency | Client preference or manual |
| Line Items | Description, Qty, Rate, Amount |
| Subtotal | Auto-calculated |
| Tax | Configurable tax rates per jurisdiction |
| Discount | Percentage or fixed amount |
| Total | Auto-calculated |
| Terms & Conditions | Template-based |
| Notes | Custom text |
| Field | Description |
| --- | --- |
| Quote Number | Auto-generated: QUO-YYYY-XXXXX |
| Client | Reference |
| Project | Link |
| Validity Period | Default 30 days |
| Fee Structure | Hourly estimate, Fixed fee, or Capped |
| Estimated Hours | If hourly |
| Hourly Rates | Per role (Partner, Associate, Paralegal) |
| Disbursements | Estimated government fees, search fees, translation |
| Contingencies | Optional buffer percentage |
| Total Estimated | Auto-calculated |
| Acceptance | Digital signature or email confirmation |
| Field | Description |
| --- | --- |
| Invoice Number | Auto-generated: INV-YYYY-XXXXX |
| Invoice Type | Standard, Recurring, Interim, Final |
| Client | Reference |
| Project | Link (optional for general invoices) |
| Issue Date |  |
| Due Date | Based on payment terms |
| Purchase Order | Client PO number |
| Line Items | Time entries, fixed fees, disbursements |
| Time Entries | Auto-pull from time tracking or manual entry |
| Tax | VAT/GST per line or summary |
| Withholding Tax | If applicable |
| Total |  |
| Amount Paid |  |
| Balance Due |  |
| Payment Terms | Net 30, etc. |
| Column | Description |
| --- | --- |
| Date | Transaction date |
| Document | Invoice, Credit Note, Payment, Adjustment |
| Reference | Document number |
| Debit (Charge) | Amount charged to client |
| Credit (Payment) | Amount received from client |
| Balance | Running balance after each transaction |
| Field | Type | Notes |
| --- | --- | --- |
| Employee ID | Auto-generated | Format: EMP-YYYY-XXXX |
| Full Name | Text | As per official records |
| Date of Birth | Date |  |
| Gender | Dropdown | Male, Female, Other, Prefer not to say |
| Nationality | Dropdown |  |
| Marital Status | Dropdown | Single, Married, Divorced, Widowed |
| Blood Group | Dropdown | A+, A-, B+, B-, AB+, AB-, O+, O- |
| Emergency Contact | Text | Name, relationship, phone |
| Personal Email | Email | Non-work contact |
| Work Email | Email | Auto-assigned |
| Phone | Text | Mobile number |
| Address | Text | Current residential address |
| Permanent Address | Text | Home town address |
| Aadhaar / SSN / National ID | Text | Encrypted storage |
| PAN / Tax ID | Text | For payroll taxation |
| UAN / PF Number | Text | If applicable |
| ESI Number | Text | If applicable |
| Bank Account | Text | For salary disbursement |
| IFSC / Routing Code | Text |  |
| Bank Name | Text |  |
| Date of Joining | Date |  |
| Confirmation Date | Date | After probation |
| Employment Type | Dropdown | Full-time, Part-time, Contract, Intern, Consultant |
| Employment Status | Dropdown | Active, On Probation, Suspended, On Leave, Resigned, Terminated, Retired |
| Department | Reference | Links to org structure |
| Designation | Reference | Job title/role |
| Grade / Band | Reference | Salary grade |
| Reporting Manager | User reference | Direct supervisor |
| dotted-line Manager | User reference | Secondary reporting |
| Work Location | Dropdown | Office, Remote, Hybrid |
| Shift | Dropdown | Day, Night, Flexible |
| Biometric ID | Text | For attendance system |
| Photo | Image | Employee photograph |
| Resume / CV | Document | Uploaded at onboarding |
| ID Documents | Documents | Passport, ID card scans |
| Education Certificates | Documents | Degree, diploma scans |
| Method | Description |
| --- | --- |
| Biometric Integration | Fingerprint / Face recognition device integration |
| Web Check-in | One-click check-in from dashboard (IP-restricted) |
| Mobile Check-in | GPS-enabled mobile check-in for remote workers |
| QR Code Scan | Scan QR at office entrance |
| Manual Entry | HR/admin can mark attendance with reason |
| Auto-mark | System marks present if logged into platform during work hours |
| Leave Type | Description | Configurable |
| --- | --- | --- |
| Casual Leave (CL) | Short-term personal leave | Yes — accrual rules, max carry forward |
| Sick Leave (SL) | Medical leave with/without certificate | Yes — max per year, proof requirement |
| Earned Leave / Privilege Leave (EL/PL) | Accrued based on service | Yes — accrual rate, encashment rules |
| Maternity Leave | As per labor law (26 weeks in India) | Yes — duration per jurisdiction |
| Paternity Leave | As per company policy | Yes — duration |
| Bereavement Leave | Death of immediate family | Yes — days allowed |
| Marriage Leave | Employee's own marriage | Yes — days allowed |
| Compensatory Off | Earned from overtime/weekend work | Auto-accrual from overtime |
| Loss of Pay (LOP) | Unpaid leave when balance exhausted | Auto-applied |
| Sabbatical | Extended unpaid leave | Requires senior approval |
| Work From Home | Remote work day | Max days per month configurable |
| On Duty / Official Tour | Client visit, conference, training | Requires pre-approval |
| Component | Type | Description |
| --- | --- | --- |
| Basic Salary | Earning | Fixed monthly basic |
| Dearness Allowance (DA) | Earning | Variable based on CPI/index |
| House Rent Allowance (HRA) | Earning | Tax-exempt up to limits |
| Conveyance Allowance | Earning | Transport reimbursement |
| Medical Allowance | Earning | Reimbursement or fixed |
| Special Allowance | Earning | Balancing component |
| Performance Bonus | Earning | Variable, linked to appraisal |
| Overtime Pay | Earning | Calculated from attendance |
| Provident Fund (PF) — Employee | Deduction | 12% of basic (India) |
| Provident Fund (PF) — Employer | Employer Cost | 12% of basic + admin charges |
| ESI — Employee | Deduction | 0.75% of gross (if applicable) |
| ESI — Employer | Employer Cost | 3.25% of gross |
| Professional Tax | Deduction | State-wise slab |
| Income Tax (TDS) | Deduction | Auto-calculated per tax regime |
| Loan EMI | Deduction | Salary advance recovery |
| Category | Description | Limit |
| --- | --- | --- |
| Travel | Air, train, taxi, fuel | Per policy |
| Accommodation | Hotel stays | Per diem limit |
| Meals | Client meals, team dinners | Per day limit |
| Communication | Phone, internet | Monthly limit |
| Office Supplies | Stationery, printing | Per transaction limit |
| Professional Fees | Conference registration, membership | Pre-approved |
| Client Entertainment | Gifts, events | Pre-approved |
| Layer | Technology |
| --- | --- |
| Backend Framework | Laravel 12 (PHP) |
| Frontend Framework | React 19 + TypeScript |
| Full-Stack Bridge | Inertia.js |
| UI Component Library | Radix UI + Tailwind CSS |
| Rich Text Editor | Tiptap |
| Database | PostgreSQL 16 |
| Cache & Queue | Redis |
| Queue Worker | Laravel Horizon |
| Real-Time Engine | Laravel Reverb (WebSockets) |
| File Storage | MinIO (S3-compatible object storage) |
| AI Sidecar | FastAPI (Python) |
| LLM Provider | Groq (optional) |
| Authentication | Laravel Sanctum |
| Authorization | Spatie Permission |
| Audit Logging | Spatie Activity Log |
| Search Engine | PostgreSQL Full-Text Search + AI Sidecar |
| PDF Generation | Laravel DomPDF / Puppeteer |
| Email | SMTP / SendGrid / Amazon SES |
| SMS | Twilio / MSG91 |
| Monitoring | Laravel Telescope + Prometheus (optional) |
| Requirement | Implementation |
| --- | --- |
| Multi-Factor Authentication | TOTP (Google Authenticator) + SMS OTP fallback |
| Password Policy | Min 12 chars, complexity, breach detection via HaveIBeenPwned API |
| Session Security | Short-lived JWT tokens (15 min), refresh tokens (7 days), concurrent session limits |
| Account Lockout | 5 failed attempts → 15 min lockout → alert admin |
| RBAC | Spatie Permission with granular module-level permissions |
| Data Scope | Users see only assigned clients/projects (row-level security) |
| API Security | Rate limiting (100 req/min), API key rotation, request signing |
| Metric | Target |
| --- | --- |
| Dashboard Page Load | < 2 seconds |
| Search Response Time | < 500ms |
| Document Upload (100MB) | < 30 seconds |
| Report Generation (10K records) | < 10 seconds |
| Payroll Processing (500 employees) | < 5 minutes |
| Concurrent Users | 500+ without degradation |
| API Response Time (p95) | < 200ms |
| Database Query Time (p95) | < 100ms |
| Module | Super Admin | Partner | Manager | Associate | Paralegal | Finance | HR | Client |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Client Management | All | All | View/Edit | View | View | View | — | Self only |
| Project Management | All | All | All | View/Edit | View/Edit | View | — | View only |
| Kanban Board | All | All | All | All | All | View | — | — |
| Task Management | All | All | All | All | All | View | — | — |
| Document DMS | All | All | All | View/Edit | View/Edit | View | — | View/Download |
| Client Portal | All | All | All | All | All | View | — | Self only |
| Approval Workflow | All | All | Approve | Submit | Submit | Approve | — | Approve |
| Financial Suite | All | All | View | View | — | All | — | View only |
| HRMS — Employee Records | All | View | View | Self only | Self only | — | All | — |
| HRMS — Attendance | All | View | View/Approve | Self only | Self only | — | All | — |
| HRMS — Leave | All | View | View/Approve | Self only | Self only | — | All | — |
| HRMS — Payroll | All | View | View | Self only | Self only | Approve | All | — |
| HRMS — Performance | All | View | Review | Self only | Self only | — | All | — |
| HRMS — Recruitment | All | View | View | — | — | — | All | — |
| HRMS — Reports | All | All | Department | Self only | Self only | Financial | All | — |
| Reporting Engine | All | All | Department | Self only | Self only | Financial | HR only | — |
| AI Assistant | All | All | All | All | All | All | All | — |
| Settings & Admin | All | View | — | — | — | — | HR only | — |
| Data Type | Active Retention | Archive Retention | Disposal |
| --- | --- | --- | --- |
| Client Project Data | 7 years post-completion | 3 years | Secure deletion with certificate |
| Financial Records (Invoices) | 7 years | 3 years | Secure deletion with certificate |
| Employee Records (Active) | Duration of employment + 7 years | — | — |
| Employee Records (Exited) | 7 years post-exit | 3 years | Secure deletion with certificate |
| Payroll Data | 7 years | 3 years | Secure deletion with certificate |
| Attendance Logs | 2 years | 3 years | Anonymized aggregation only |
| Audit Logs | 7 years | — | — |
| Email Communications | 3 years | 2 years | Secure deletion |
| Document Versions | Current + 10 versions | — | Auto-purge old versions |
| System Logs | 1 year | 2 years | Anonymized aggregation |