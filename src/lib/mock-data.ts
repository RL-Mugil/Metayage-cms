export const clients = [
  { id: "C-1041", name: "Helios Robotics", type: "Corporate", contact: "Priya Raman", country: "IN", matters: 14, billed: "₹ 38.4L", status: "Active" },
  { id: "C-1037", name: "Northwind Biotech", type: "Corporate", contact: "Daniel Cho", country: "US", matters: 9, billed: "$ 142K", status: "Active" },
  { id: "C-1029", name: "Aurelia Foods Pvt Ltd", type: "Corporate", contact: "Rhea Kapoor", country: "IN", matters: 6, billed: "₹ 12.1L", status: "Active" },
  { id: "C-1024", name: "Quantix Semiconductors", type: "Enterprise", contact: "Tomás Vidal", country: "ES", matters: 22, billed: "€ 96K", status: "Strategic" },
  { id: "C-1018", name: "Lumen Therapeutics", type: "Startup", contact: "S. Mistry", country: "IN", matters: 3, billed: "₹ 4.2L", status: "Onboarding" },
  { id: "C-1009", name: "Mariner Logistics LLP", type: "Corporate", contact: "K. Iyer", country: "AE", matters: 5, billed: "AED 88K", status: "Active" },
];

export const projects = [
  { id: "M-2057", title: "Compact Lithium Cell Array", client: "Helios Robotics", type: "Patent — Utility", stage: "Examination", lead: "Anika Mehra", priority: "High", due: "2026-07-12" },
  { id: "M-2054", title: "ARGYLE word mark — Class 30", client: "Aurelia Foods Pvt Ltd", type: "Trademark", stage: "Opposition", lead: "Ravi N.", priority: "Medium", due: "2026-06-28" },
  { id: "M-2049", title: "mRNA Cold-Chain Capsule", client: "Northwind Biotech", type: "Patent — PCT", stage: "National Phase", lead: "K. Suresh", priority: "High", due: "2026-09-04" },
  { id: "M-2047", title: "Quantix Logo Refresh", client: "Quantix Semiconductors", type: "Trademark", stage: "Filing", lead: "Anika Mehra", priority: "Low", due: "2026-08-15" },
  { id: "M-2043", title: "PhotoSynth Algorithm", client: "Lumen Therapeutics", type: "Copyright", stage: "Drafting", lead: "M. Bhat", priority: "Medium", due: "2026-07-30" },
  { id: "M-2039", title: "Container Tracking System", client: "Mariner Logistics LLP", type: "Patent — Utility", stage: "Pre-Filing", lead: "Ravi N.", priority: "Medium", due: "2026-10-02" },
];

export const stages = ["Intake", "Drafting", "Filing", "Examination", "Opposition", "Registered", "Renewal"];

export const tasks = [
  { id: "T-9112", title: "Draft Office Action response — §103", matter: "M-2057", assignee: "Anika Mehra", due: "Today", status: "In Progress", priority: "High" },
  { id: "T-9108", title: "File counter-statement (Form TM-O)", matter: "M-2054", assignee: "Ravi N.", due: "Tomorrow", status: "Review", priority: "High" },
  { id: "T-9101", title: "Collect priority documents from inventor", matter: "M-2049", assignee: "K. Suresh", due: "Jun 09", status: "Blocked", priority: "Medium" },
  { id: "T-9098", title: "Client review of trademark search report", matter: "M-2047", assignee: "Client", due: "Jun 12", status: "Awaiting Client", priority: "Low" },
  { id: "T-9094", title: "Invoice draft INV-2026-0341", matter: "M-2043", assignee: "Finance", due: "Jun 14", status: "Todo", priority: "Low" },
];

export const documents = [
  { id: "D-44021", name: "Helios — Specification v3.2.docx", type: "Specification", matter: "M-2057", size: "1.4 MB", version: "v3.2", uploaded: "2 hours ago", by: "Anika M." },
  { id: "D-44018", name: "Aurelia — TM-O Counter Statement.pdf", type: "Form", matter: "M-2054", size: "612 KB", version: "v1.0", uploaded: "Yesterday", by: "Ravi N." },
  { id: "D-44012", name: "Northwind — National Phase Strategy.pdf", type: "Memo", matter: "M-2049", size: "880 KB", version: "v2.1", uploaded: "2 days ago", by: "K. Suresh" },
  { id: "D-44008", name: "Quantix — Logo Vector Pack.zip", type: "Asset", matter: "M-2047", size: "8.2 MB", version: "v1.0", uploaded: "3 days ago", by: "Design" },
  { id: "D-44001", name: "Lumen — Source Code Deposit.zip", type: "Deposit", matter: "M-2043", size: "21 MB", version: "v1.0", uploaded: "Last week", by: "M. Bhat" },
];

export const employees = [
  { id: "E-001", name: "Anika Mehra", role: "Senior Associate", dept: "Patents", email: "anika@metayage.com", location: "Bengaluru", join: "2022-04-11", status: "Active" },
  { id: "E-002", name: "Ravi Nair", role: "Associate", dept: "Trademarks", email: "ravi@metayage.com", location: "Mumbai", join: "2023-08-01", status: "Active" },
  { id: "E-003", name: "K. Suresh", role: "Partner", dept: "Patents", email: "suresh@metayage.com", location: "Bengaluru", join: "2018-01-15", status: "Active" },
  { id: "E-004", name: "Maya Bhat", role: "Paralegal", dept: "Litigation", email: "maya@metayage.com", location: "Delhi", join: "2024-02-20", status: "Active" },
  { id: "E-005", name: "Aarav Khanna", role: "Junior Associate", dept: "Trademarks", email: "aarav@metayage.com", location: "Mumbai", join: "2025-06-01", status: "Probation" },
  { id: "E-006", name: "Lina Joseph", role: "HR Manager", dept: "People Ops", email: "lina@metayage.com", location: "Bengaluru", join: "2021-11-09", status: "Active" },
  { id: "E-007", name: "Devika Rao", role: "Finance Lead", dept: "Finance", email: "devika@metayage.com", location: "Bengaluru", join: "2020-03-23", status: "Active" },
];

export const departments = [
  { name: "Patents", head: "K. Suresh", count: 14, budget: "₹ 92L" },
  { name: "Trademarks", head: "P. Anand", count: 9, budget: "₹ 51L" },
  { name: "Litigation", head: "S. Bose", count: 6, budget: "₹ 38L" },
  { name: "Finance", head: "Devika Rao", count: 4, budget: "₹ 21L" },
  { name: "People Ops", head: "Lina Joseph", count: 3, budget: "₹ 18L" },
];

export const invoices = [
  { id: "INV-2026-0341", client: "Helios Robotics", amount: "₹ 4,82,000", status: "Sent", due: "2026-06-20" },
  { id: "INV-2026-0340", client: "Quantix Semiconductors", amount: "€ 12,400", status: "Paid", due: "2026-05-30" },
  { id: "INV-2026-0339", client: "Northwind Biotech", amount: "$ 18,750", status: "Overdue", due: "2026-05-15" },
  { id: "INV-2026-0338", client: "Aurelia Foods", amount: "₹ 1,20,000", status: "Draft", due: "2026-06-28" },
  { id: "INV-2026-0337", client: "Mariner Logistics", amount: "AED 9,200", status: "Sent", due: "2026-06-22" },
];

export const teamMembers = ["Anika Mehra", "Ravi Nair", "K. Suresh", "Maya Bhat", "Aarav Khanna"];

// Re-exported from utils so routes can be migrated to import from "@/lib/utils" incrementally.
export { statusColor } from "@/lib/utils";
