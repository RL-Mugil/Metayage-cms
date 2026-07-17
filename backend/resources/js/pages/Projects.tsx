import { Head, Link, router, usePage } from "@inertiajs/react";
import { useEffect, useState, useMemo, useRef, useCallback } from "react";
import { createPortal } from "react-dom";
import {
  Plus, Search, Loader2, X, Download, Pencil, Trash2, AlertCircle,
  ChevronDown, ChevronUp, ChevronLeft, ChevronRight, ArrowUpDown, ArrowUp, ArrowDown,
  Upload, FileSpreadsheet, CheckCircle, FileText, Scroll,
  ExternalLink, Calendar, Clock, ReceiptText, BookOpen,
  ArrowUpCircle, Link2, GitMerge,
} from "lucide-react";
import AppLayout from "@/layouts/AppLayout";
import { PageHeader } from "@/components/page-header";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { api, downloadCSV } from "@/lib/api-client";
import { statusColor } from "@/lib/utils";
import { AnalystRoleFilter, useAnalystRoleFilter } from "@/components/analyst-role-filter";

// ── Date helper ───────────────────────────────────────────────────────────────

function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const part = d.split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d;
  return `${day}-${m}-${y}`;
}

// ── WIPO Patent Office Codes ──────────────────────────────────────────────────

const PATENT_OFFICES = [
  { code: "IN", label: "IN – India (IPO)" },
  { code: "US", label: "US – United States (USPTO)" },
  { code: "EP", label: "EP – European Patent Office (EPO)" },
  { code: "WO", label: "WO – WIPO / PCT (International)" },
  { code: "CN", label: "CN – China (CNIPA)" },
  { code: "JP", label: "JP – Japan (JPO)" },
  { code: "KR", label: "KR – South Korea (KIPO)" },
  { code: "AU", label: "AU – Australia (IP Australia)" },
  { code: "CA", label: "CA – Canada (CIPO)" },
  { code: "GB", label: "GB – United Kingdom (UKIPO)" },
  { code: "DE", label: "DE – Germany (DPMA)" },
  { code: "FR", label: "FR – France (INPI)" },
  { code: "RU", label: "RU – Russia (Rospatent)" },
  { code: "BR", label: "BR – Brazil (INPI-BR)" },
  { code: "MX", label: "MX – Mexico (IMPI)" },
  { code: "SG", label: "SG – Singapore (IPOS)" },
  { code: "HK", label: "HK – Hong Kong (HKIPO)" },
  { code: "MY", label: "MY – Malaysia (MyIPO)" },
  { code: "TH", label: "TH – Thailand (DIP)" },
  { code: "ID", label: "ID – Indonesia (DGIP)" },
  { code: "PH", label: "PH – Philippines (IPOPHL)" },
  { code: "VN", label: "VN – Vietnam (NOIP)" },
  { code: "ZA", label: "ZA – South Africa (CIPC)" },
  { code: "NZ", label: "NZ – New Zealand (IPONZ)" },
  { code: "IL", label: "IL – Israel (ILPO)" },
  { code: "TR", label: "TR – Turkey (TPTO)" },
  { code: "AE", label: "AE – United Arab Emirates (MOIAT)" },
  { code: "SA", label: "SA – Saudi Arabia (SAIP)" },
  { code: "EG", label: "EG – Egypt (EGPO)" },
  { code: "NG", label: "NG – Nigeria (FIPO)" },
  { code: "KE", label: "KE – Kenya (KEIPO)" },
  { code: "MA", label: "MA – Morocco (OMPIC)" },
  { code: "PK", label: "PK – Pakistan (IPO-Pakistan)" },
  { code: "BD", label: "BD – Bangladesh (DPDT)" },
  { code: "LK", label: "LK – Sri Lanka (NIPO)" },
  { code: "CH", label: "CH – Switzerland (IGE/IPI)" },
  { code: "SE", label: "SE – Sweden (PRV)" },
  { code: "NO", label: "NO – Norway (NIPO)" },
  { code: "DK", label: "DK – Denmark (DKPTO)" },
  { code: "FI", label: "FI – Finland (PRH)" },
  { code: "NL", label: "NL – Netherlands (RNLIP)" },
  { code: "BE", label: "BE – Belgium (BOIP)" },
  { code: "AT", label: "AT – Austria (OPA)" },
  { code: "IT", label: "IT – Italy (UIBM)" },
  { code: "ES", label: "ES – Spain (OEPM)" },
  { code: "PT", label: "PT – Portugal (INPI-PT)" },
  { code: "PL", label: "PL – Poland (PPO)" },
  { code: "CZ", label: "CZ – Czech Republic (IPO)" },
  { code: "HU", label: "HU – Hungary (HIPO)" },
  { code: "RO", label: "RO – Romania (OSIM)" },
  { code: "UA", label: "UA – Ukraine (UKRPATENT)" },
  { code: "AR", label: "AR – Argentina (INPI-AR)" },
  { code: "CL", label: "CL – Chile (INAPI)" },
  { code: "CO", label: "CO – Colombia (SIC)" },
  { code: "PE", label: "PE – Peru (INDECOPI)" },
  { code: "QA", label: "QA – Qatar" },
  { code: "KW", label: "KW – Kuwait" },
  { code: "OM", label: "OM – Oman" },
  { code: "BH", label: "BH – Bahrain" },
  { code: "JO", label: "JO – Jordan" },
];

// ── Service Codes ─────────────────────────────────────────────────────────────

const SERVICE_CODES = [
  // ── Indian Patent Prosecution ──────────────────────────────────────────────
  { code: "PAS",  label: "PAS – Prior Art Search" },
  { code: "SRH",  label: "SRH – Search Report (Standalone)" },
  { code: "FTO",  label: "FTO – Freedom to Operate Study" },
  { code: "PRV",  label: "PRV – Provisional Application (India)" },
  { code: "CPD",  label: "CPD – Complete Specification – Direct Filing" },
  { code: "CPT",  label: "CPT – Complete Specification – from Provisional" },
  { code: "CVP",  label: "CVP – Convention Priority Application" },
  { code: "CPE",  label: "CPE – Convention Complete Specification" },
  { code: "PCT",  label: "PCT – PCT International Application" },
  { code: "NAP",  label: "NAP – PCT National Phase Entry (India)" },
  { code: "NPE",  label: "NPE – PCT National Phase Entry (variant)" },
  { code: "NAF",  label: "NAF – PCT National Phase Entry (variant)" },
  { code: "DVA",  label: "DVA – Divisional Application" },
  { code: "PAD",  label: "PAD – Patent of Addition" },
  { code: "9EP",  label: "9EP – Publication (18-month, Section 11A)" },
  { code: "98A",  label: "98A – Publication (alternate form)" },
  { code: "18F",  label: "18F – Request for Examination (Form 18)" },
  { code: "18A",  label: "18A – Accelerated Examination (Form 18A)" },
  { code: "FER",  label: "FER – First Examination Report Response" },
  { code: "SER",  label: "SER – Second Examination Report Response" },
  { code: "TER",  label: "TER – Third / Subsequent Exam Report Response" },
  { code: "HRG",  label: "HRG – Hearing Representation" },
  { code: "GRT",  label: "GRT – Grant / Patent Certificate" },
  { code: "RNF",  label: "RNF – Annual Renewal Fee" },
  { code: "PGO",  label: "PGO – Pre-Grant Opposition (S.25(1))" },
  { code: "OPP",  label: "OPP – Post-Grant Opposition (S.25(2))" },
  { code: "27F",  label: "27F – Form 27 Statement of Working (every 3 FY)" },
  { code: "ROA",  label: "ROA – Refused (Review / Appeal)" },
  { code: "ERH",  label: "ERH – Appeal to High Court (S.117A)" },
  { code: "24F",  label: "24F – Revocation Petition (Form 24)" },
  { code: "RPO",  label: "RPO – Lapse & Restoration (S.60, Form 15)" },
  { code: "ABN",  label: "ABN – Deemed Abandoned (S.21(1))" },
  { code: "WDR",  label: "WDR – Withdrawal (S.11B(4))" },
  // ── Trademark ─────────────────────────────────────────────────────────────
  { code: "TMS",   label: "TMS – Trademark Search" },
  { code: "TMD",   label: "TMD – Trademark Drafting / Advice" },
  { code: "TMF",   label: "TMF – Trademark Filing" },
  { code: "TMC",   label: "TMC – Trademark Multi-Class Filing" },
  { code: "TME",   label: "TME – TM Examination Report Response" },
  { code: "TMJ",   label: "TMJ – TM Journal / Gazette Monitoring" },
  { code: "TMO",   label: "TMO – TM Opposition Filing" },
  { code: "TMOR",  label: "TMOR – TM Opposition Reply / Counter-Statement" },
  { code: "TMH",   label: "TMH – TM Hearing Representation" },
  { code: "TMG",   label: "TMG – TM Grant / Registration Certificate" },
  { code: "TMRN",  label: "TMRN – Trademark Renewal" },
  { code: "TMA",   label: "TMA – TM Assignment / Transfer" },
  { code: "TMWT",  label: "TMWT – Trademark Watch Service" },
  { code: "TMINF", label: "TMINF – TM Infringement Notice / Action" },
  { code: "TMMD",  label: "TMMD – Madrid Protocol / International TM Filing" },
  // ── Design ────────────────────────────────────────────────────────────────
  { code: "DSF",  label: "DSF – Design Registration Filing" },
  { code: "DSE",  label: "DSE – Design Examination Response" },
  { code: "DSR",  label: "DSR – Design Registration Certificate" },
  { code: "DSN",  label: "DSN – Design Renewal" },
  { code: "DSA",  label: "DSA – Design Assignment" },
  // ── Copyright ─────────────────────────────────────────────────────────────
  { code: "CRF",  label: "CRF – Copyright Registration Filing" },
  { code: "CRL",  label: "CRL – Copyright Licence Drafting" },
  { code: "CRA",  label: "CRA – Copyright Assignment" },
  // ── Geographical Indication / Plant Variety ────────────────────────────────
  { code: "GIF",  label: "GIF – GI Tag Application Filing" },
  { code: "GIE",  label: "GIE – GI Examination Response" },
  { code: "GIR",  label: "GIR – GI Registration" },
  { code: "PVF",  label: "PVF – Plant Variety Protection Filing" },
  { code: "PVR",  label: "PVR – Plant Variety Certificate" },
  // ── Advisory / Transactional ──────────────────────────────────────────────
  { code: "PAT",  label: "PAT – Patentability Opinion" },
  { code: "VAL",  label: "VAL – Validity / Invalidity Opinion" },
  { code: "INF",  label: "INF – Infringement Analysis" },
  { code: "LND",  label: "LND – Patent Landscape Study" },
  { code: "AUD",  label: "AUD – IP Portfolio Audit" },
  { code: "INV",  label: "INV – Invention Disclosure Review" },
  { code: "LIC",  label: "LIC – Licensing Agreement Drafting" },
  { code: "ASG",  label: "ASG – Assignment / Transfer Registration" },
  { code: "CDL",  label: "CDL – Compulsory Licence / Revocation" },
  { code: "TRN",  label: "TRN – Technology Transfer Agreement" },
  { code: "DUE",  label: "DUE – Due Diligence" },
  { code: "STR",  label: "STR – IP Strategy / Portfolio Review" },
  // ── Formalities / Admin ────────────────────────────────────────────────────
  { code: "POA",  label: "POA – Power of Attorney" },
  { code: "NOT",  label: "NOT – Notarization" },
  { code: "LEG",  label: "LEG – Legalization / Apostille" },
  { code: "TRL",  label: "TRL – Translation Services" },
  { code: "RPT",  label: "RPT – Status Report / Reporting" },
  // ── Litigation / Dispute ──────────────────────────────────────────────────
  { code: "MED",  label: "MED – Mediation Assistance" },
  { code: "ARB",  label: "ARB – Arbitration Support" },
  { code: "LTG",  label: "LTG – IP Litigation Support" },
  { code: "CRT",  label: "CRT – Court Filing / Representation" },
  { code: "CSL",  label: "CSL – Legal Consultation / Advisory" },
  { code: "VAR",  label: "VAR – Various / Miscellaneous" },
];

const CASE_TYPES = [
  "Patent – Utility", "Patent – Design", "Patent – PCT",
  "Trademark", "Copyright", "Geographical Indication",
  "Plant Variety", "Semiconductor Layout Design",
  "Trade Secret", "IP Litigation", "IP Licensing",
  "IP Audit", "Technology Transfer", "General Advisory",
];

const URGENCIES = ["Low", "Normal", "High", "Critical"];
const STATUSES  = ["Open", "In Progress", "On Hold", "Closed", "Completed", "Granted", "Refused", "Abandoned"];

const ELEVATION_PATHS: Record<string, string[]> = {
  PAS:  ["PRV", "CPD", "CVP", "PCT", "DVA", "PAD"],
  SRH:  ["PRV", "CPD", "CVP", "PCT"],
  PAT:  ["PRV", "CPD", "CVP", "PCT"],
  FTO:  ["PRV", "CPD"],
  PRV:  ["CPT", "WDR"],
  CPT:  ["9EP", "18F", "WDR"],
  CPD:  ["9EP", "18F", "WDR"],
  CVP:  ["CPE", "9EP", "18F", "WDR"],
  CPE:  ["9EP", "18F", "WDR"],
  PCT:  ["NAP", "NPE", "NAF"],
  NAP:  ["9EP", "18F", "WDR"],
  NPE:  ["9EP", "18F", "WDR"],
  NAF:  ["9EP", "18F", "WDR"],
  NPA:  ["9EP", "18F", "WDR"],
  DVA:  ["9EP", "18F", "WDR"],
  PAD:  ["9EP", "18F", "WDR"],
  "9EP": ["18F", "18A", "PGO", "WDR"],
  "98A": ["18F", "18A", "PGO", "WDR"],
  "18F": ["FER", "PGO", "WDR"],
  "18A": ["FER", "PGO", "WDR"],
  FER:  ["SER", "HRG", "GRT", "ABN", "PGO", "WDR"],
  SER:  ["TER", "HRG", "GRT", "ABN", "PGO"],
  TER:  ["HRG", "GRT", "ABN", "PGO"],
  HRG:  ["GRT", "ROA", "ABN"],
  PGO:  ["GRT", "ROA"],
  GRT:  ["RNF", "OPP", "27F", "PAD", "24F"],
  RNF:  ["RNF", "RPO"],
  OPP:  [],
  ROA:  ["ERH"],
  ERH:  [],
  "27F": [],
  RPO:  ["RNF"],
  ABN:  [],
  WDR:  [],
  "24F": [],
};

const SERVICE_LABELS: Record<string, string> = {
  PAS: "PAS — Prior Art Search",
  SRH: "SRH — Search Report",
  PAT: "PAT — Patentability Opinion",
  FTO: "FTO — Freedom to Operate",
  PRV: "PRV — Provisional Application",
  CPD: "CPD — Complete Spec (Direct)",
  CPT: "CPT — Complete Spec (from PRV)",
  CVP: "CVP — Convention Priority",
  CPE: "CPE — Convention Complete Spec",
  PCT: "PCT — International Application",
  NAP: "NAP — PCT National Phase",
  NPE: "NPE — PCT National Phase",
  NAF: "NAF — PCT National Phase",
  NPA: "NPA — PCT National Phase",
  DVA: "DVA — Divisional Application",
  PAD: "PAD — Patent of Addition",
  "9EP": "9EP — Publication (18-month)",
  "98A": "98A — Publication",
  "18F": "18F — Request for Examination",
  "18A": "18A — Accelerated Examination",
  FER: "FER — First Examination Report",
  SER: "SER — Second Examination Report",
  TER: "TER — Third Examination Report",
  HRG: "HRG — Hearing",
  GRT: "GRT — Grant",
  RNF: "RNF — Annual Renewal",
  PGO: "PGO — Pre-Grant Opposition",
  OPP: "OPP — Post-Grant Opposition",
  "27F": "27F — Form 27 (Working)",
  ROA: "ROA — Refused (Review / Appeal)",
  ERH: "ERH — Appeal (High Court)",
  "24F": "24F — Revocation Petition",
  RPO: "RPO — Lapse & Restoration",
  ABN: "ABN — Deemed Abandoned",
  WDR: "WDR — Withdrawal",
};

const STAGES_BY_SERVICE: Record<string, string[]> = {
  PAS: [
    "Matter Created", "Inventor / Technology Disclosure Requested", "Disclosure Received",
    "Search Parameters Defined", "Prior Art Search In Progress", "Search Report Drafted",
    "Search Report Reviewed Internally", "Search Report Shared with Client",
  ],
  SRH: [
    "Matter Created", "Inventor / Technology Disclosure Requested", "Disclosure Received",
    "Search Parameters Defined", "Prior Art Search In Progress", "Search Report Drafted",
    "Search Report Reviewed Internally", "Search Report Shared with Client",
  ],
  PAT: [
    "Matter Created", "Inventor / Technology Disclosure Requested", "Disclosure Received",
    "Search Parameters Defined", "Prior Art Search In Progress", "Search Report Drafted",
    "Search Report Reviewed Internally", "Search Report Shared with Client",
  ],
  FTO: [
    "Matter Created", "Inventor / Technology Disclosure Requested", "Disclosure Received",
    "Search Parameters Defined", "Prior Art Search In Progress", "Search Report Drafted",
    "Search Report Reviewed Internally", "Search Report Shared with Client",
  ],
  PRV: [
    "Matter Created", "Inventor Disclosure Requested", "Inventor Disclosure Received",
    "Prior Art Search (Optional)", "Draft Started", "Draft Completed", "Internal Review",
    "Corrections Incorporated", "Partner Review", "Client Review", "Client Approved",
    "Forms Prepared (Form 1, 2, 3)", "Government Fees Calculated", "Filed with IPO",
    "Application Number Received", "Completed — CPT Deadline Set (12 months)",
  ],
  CPT: [
    "Matter Created", "Inventor Disclosure Reviewed", "Claims Drafted", "Claims Shared with Client",
    "Claims Approved by Client", "Specification Drafting Started", "Draft Completed",
    "Internal Review", "Corrections Incorporated", "Partner Review", "Draft Shared with Client",
    "Client Feedback Received", "Revised Draft Completed", "Forms Prepared (Form 1, 2, 3)",
    "Government Fees Paid", "Filed with IPO", "Completed — Awaiting Publication",
  ],
  CPD: [
    "Matter Created", "Inventor Disclosure Requested", "Inventor Disclosure Received",
    "Claims Drafted", "Claims Shared with Client", "Claims Approved by Client",
    "Specification Drafting Started", "Draft Completed", "Internal Review",
    "Corrections Incorporated", "Partner Review", "Draft Shared with Client",
    "Client Feedback Received", "Revised Draft Completed", "Forms Prepared (Form 1, 2, 3)",
    "Government Fees Paid", "Filed with IPO — Awaiting Publication",
  ],
  CVP: [
    "Matter Created", "Priority Application Documents Received", "Priority Date Verified",
    "12-Month Deadline Confirmed", "Claims Drafted (adapted for Indian law)", "Specification Drafted",
    "Internal Review", "Partner Review", "Client Approval",
    "Forms Prepared (Form 1, 2, 3, 4 — Priority)",
    "Filed with IPO (within 12 months of priority)", "Completed — Awaiting Publication",
  ],
  CPE: [
    "Matter Created", "Inventor Disclosure Reviewed", "Claims Drafted", "Claims Shared with Client",
    "Claims Approved by Client", "Specification Drafting Started", "Draft Completed",
    "Internal Review", "Corrections Incorporated", "Partner Review", "Draft Shared with Client",
    "Client Feedback Received", "Revised Draft Completed", "Forms Prepared (Form 1, 2, 3)",
    "Government Fees Paid", "Filed with IPO", "Completed — Awaiting Publication",
  ],
  PCT: [
    "Matter Created", "Priority Date Verified", "International Application Drafted",
    "Receiving Office Selected (RO/IN or others)", "International Fees Calculated",
    "Application Filed at Receiving Office", "Filing Receipt / IB Reference Received",
    "International Search Report (ISR) Received", "Written Opinion Received",
    "Chapter II Examination (Optional)", "Client Review of ISR / Written Opinion",
    "National Phase Entry Deadline Set (India: 31 months from priority)",
    "International Publication Confirmed (18 months)", "Completed — National Phase Entry Pending",
  ],
  NAP: [
    "Matter Created", "PCT Application Documents Received",
    "31-Month National Phase Deadline Verified", "National Phase Entry Decision Confirmed",
    "Translation Prepared (if required)", "National Phase Entry Application Drafted",
    "Claims Adapted for Indian Law", "Internal Review", "Partner Review",
    "Forms Prepared (Form 1, 2, 3 — National Phase)", "Government Fees Paid",
    "Filed with IPO (within 31 months)", "Application Number Received",
    "Completed — Awaiting Publication",
  ],
  NPE: [
    "Matter Created", "PCT Application Documents Received",
    "31-Month National Phase Deadline Verified", "National Phase Entry Decision Confirmed",
    "Translation Prepared (if required)", "National Phase Entry Application Drafted",
    "Claims Adapted for Indian Law", "Internal Review", "Partner Review",
    "Forms Prepared (Form 1, 2, 3 — National Phase)", "Government Fees Paid",
    "Filed with IPO (within 31 months)", "Application Number Received",
    "Completed — Awaiting Publication",
  ],
  NAF: [
    "Matter Created", "PCT Application Documents Received",
    "31-Month National Phase Deadline Verified", "National Phase Entry Decision Confirmed",
    "Translation Prepared (if required)", "National Phase Entry Application Drafted",
    "Claims Adapted for Indian Law", "Internal Review", "Partner Review",
    "Forms Prepared (Form 1, 2, 3 — National Phase)", "Government Fees Paid",
    "Filed with IPO (within 31 months)", "Application Number Received",
    "Completed — Awaiting Publication",
  ],
  NPA: [
    "Matter Created", "PCT Application Documents Received",
    "31-Month National Phase Deadline Verified", "National Phase Entry Decision Confirmed",
    "Translation Prepared (if required)", "National Phase Entry Application Drafted",
    "Claims Adapted for Indian Law", "Internal Review", "Partner Review",
    "Forms Prepared (Form 1, 2, 3 — National Phase)", "Government Fees Paid",
    "Filed with IPO (within 31 months)", "Application Number Received",
    "Completed — Awaiting Publication",
  ],
  DVA: [
    "Matter Created", "Parent Application Identified", "Claims to Divide Identified",
    "Controller Objection / Invitation Noted", "Divisional Claims Drafted", "Specification Prepared",
    "Internal Review", "Partner Review", "Client Approval", "Forms Prepared (Form 1, 2)",
    "Government Fees Paid", "Filed with IPO — Linked to Parent", "Completed — Awaiting Publication",
  ],
  PAD: [
    "Matter Created", "Parent Patent Identified", "Improvement / Addition Defined",
    "Addition Claims Drafted", "Claims Reviewed Internally", "Partner Review", "Client Approval",
    "Forms Prepared (Form 1, 2 — Addition)", "Government Fees Paid", "Filed with IPO",
    "Application Number Received", "Completed — Awaiting Publication",
  ],
  "9EP": [
    "Application Filed and Priority Date Recorded",
    "Publication Date Calculated (18 months from earliest priority — S.11A)",
    "Early Publication Requested (Form 9 — optional)",
    "Published in Official Journal", "Publication Number Confirmed",
    "Completed — Ready for Examination Request",
  ],
  "98A": [
    "Application Filed and Priority Date Recorded",
    "Publication Date Calculated (18 months from earliest priority — S.11A)",
    "Early Publication Requested (Form 9 — optional)",
    "Published in Official Journal", "Publication Number Confirmed",
    "Completed — Ready for Examination Request",
  ],
  "18F": [
    "Application Published (18F Trigger)",
    "RFE Deadline Docketed (31 months from earliest priority; 48 months if filed before 15.03.2024)",
    "Examination Request Decision Made", "Form 18 Prepared", "Government Fee Calculated",
    "RFE Filed with IPO", "Completed — Awaiting First Examination Report",
  ],
  "18A": [
    "Application Published (18A Trigger)",
    "RFE Deadline Docketed (31 months from earliest priority; 48 months if filed before 15.03.2024)",
    "Grounds for Acceleration Verified (Rule 24C eligibility)", "Examination Request Decision Made",
    "Form 18A Prepared", "Government Fee Calculated", "RFE Filed with IPO",
    "Completed — Awaiting First Examination Report (Expedited)",
  ],
  FER: [
    "Examination Report Received",
    "Response Deadline Docketed (6 months from FER; +3 months via Form 4 — Rule 24B)",
    "Objections Analyzed", "Response Strategy Formulated",
    "Claims Amended / Arguments Drafted", "Internal Review", "Partner Review",
    "Client Communicated", "Response Filed (Form 3 / 13)", "Completed — Awaiting Controller Decision",
  ],
  SER: [
    "Examination Report Received",
    "Response Deadline Docketed (6 months from FER; +3 months via Form 4 — Rule 24B)",
    "Objections Analyzed", "Response Strategy Formulated",
    "Claims Amended / Arguments Drafted", "Internal Review", "Partner Review",
    "Client Communicated", "Response Filed (Form 3 / 13)", "Completed — Awaiting Controller Decision",
  ],
  TER: [
    "Examination Report Received",
    "Response Deadline Docketed (6 months from FER; +3 months via Form 4 — Rule 24B)",
    "Objections Analyzed", "Response Strategy Formulated",
    "Claims Amended / Arguments Drafted", "Internal Review", "Partner Review",
    "Client Communicated", "Response Filed (Form 3 / 13)", "Completed — Awaiting Controller Decision",
  ],
  HRG: [
    "Hearing Notice Received", "Hearing Date Set (max 2 adjournments of 30 days each — Rule 129A)",
    "Arguments Prepared", "Prior Art / Documents Compiled", "Internal Review", "Partner Review",
    "Hearing Attended", "Written Submissions Filed (within 15 days of hearing — Rule 28(7))",
    "Awaiting Hearing Order",
  ],
  GRT: [
    "Grant Order Received", "Patent Certificate Issued", "Patent Number Recorded",
    "Accumulated Renewal Fees Docketed (due 3 months from grant recordal — Rule 80(3))",
    "Renewal Schedule Set (Years 3–20)", "Form 27 Schedule Set (once every 3 financial years)",
    "Completed — Patent Active",
  ],
  RNF: [
    "Renewal Year Identified", "Renewal Fee Due Date Confirmed", "Renewal Decision Made by Client",
    "Renewal Fee Paid", "Completed — Next Renewal Set",
  ],
  RPO: [
    "Patent Lapse Identified (renewal fee missed — S.53)",
    "Restoration Window Verified (18 months from lapse — S.60)",
    "Restoration Petition Prepared (Form 15)", "Evidence of Unintentional Lapse Compiled",
    "Restoration Petition Filed", "Controller Decision Received",
    "Completed — Patent Restored or Ceased",
  ],
  ABN: [
    "Abandonment Trigger Identified (missed response deadline — S.21(1))",
    "Rule 138 Extension Window Evaluated (up to 6 months)", "Client Advised of Options",
    "Extension Petition Filed / Matter Closed", "Completed — Restored to Prosecution or Abandoned",
  ],
  PGO: [
    "Pre-Grant Opposition Received / Filed (S.25(1))", "Representation Analyzed",
    "Reply Statement Drafted (within 2 months of notice — Rule 55(4))", "Evidence Prepared",
    "Reply Filed with IPO", "Hearing Scheduled (if requested)", "Hearing Attended",
    "Controller Order Received", "Completed — Application Proceeds or Refused",
  ],
  WDR: [
    "Withdrawal Decision by Client",
    "Pre-Publication Check (withdraw before publication to preserve secrecy — S.11B(4))",
    "Withdrawal Request Prepared", "Withdrawal Request Filed", "Withdrawal Recorded by IPO",
    "Completed — Application Withdrawn",
  ],
  OPP: [
    "Post-Grant Opposition Filed / Received (S.25(2) — within 12 months of grant publication)",
    "Opposition Petition Analyzed", "Reply Statement Drafted",
    "Evidence Affidavit Prepared", "Evidence of Opponent Received", "Evidence Reply Prepared",
    "Hearing Scheduled", "Hearing Arguments Prepared", "Hearing Attended",
    "Order Received", "Completed — Patent Maintained or Revoked",
  ],
  "27F": [
    "Form 27 Due Date Identified (once every 3 financial years)", "Working Statement Prepared",
    "Client Approval", "Form 27 Filed",
  ],
  ROA: [
    "Refusal Order Received", "Review Petition Evaluated (S.77(1)(f) — within 1 month)",
    "Appeal Decision Made (High Court — S.117A)", "Completed — Review/Appeal Filed or Matter Closed",
  ],
  ERH: [
    "Appeal Decision Made", "Appeal Filed at High Court (S.117A)", "Grounds of Appeal Prepared",
    "Counter-Statement by Respondent Received", "Reply Filed", "Oral Arguments Scheduled",
    "Hearing Attended", "Judgment / Order Received", "Completed — Decision",
  ],
  "24F": [
    "Revocation Petition Received", "Reply Statement Prepared", "Evidence Filed",
    "Counter-Evidence Received", "Hearing Scheduled", "Hearing Attended",
    "Order Received", "Completed — Patent Maintained or Revoked",
  ],
};

const PIPELINE_STAGES_LEGACY = [
  "Invention Disclosure", "Patent Search", "Search Report", "Provisional or Complete Application",
  "Provisional Filing", "Patent Drafting", "Applicant/Inventor Review", "Filing with Patent Office",
  "First Examination Report", "FER Response Preparation", "FER Response Filing",
  "Hearing with Examiner", "Hearing Response Preparation", "Hearing Response Filing",
];

function stagesForProject(p: { service_code?: string | null; docket_number?: string | null; stages?: any[] }): string[] {
  // service_code takes precedence; fall back to extracting from docket_number (chars 9+)
  const svc = (p.service_code ?? p.docket_number?.slice(9) ?? "").toUpperCase();
  return STAGES_BY_SERVICE[svc] ?? p.stages?.map((s: any) => s.stage_name) ?? PIPELINE_STAGES_LEGACY;
}

// Keep alias so rest of file compiles without changes
const PIPELINE_STAGES = [
  ...Object.values(STAGES_BY_SERVICE).flat().filter((v, i, a) => a.indexOf(v) === i),
  ...PIPELINE_STAGES_LEGACY,
];

// ── Shared UI ─────────────────────────────────────────────────────────────────

const ic = "w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold";
const tc = "w-full rounded-md border border-border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-gold resize-none";

const Lbl = ({ children, req }: { children: React.ReactNode; req?: boolean }) => (
  <label className="block text-xs text-muted-foreground mb-1">
    {children}{req && <span className="text-destructive ml-0.5">*</span>}
  </label>
);

// Section — resizable when expanded (drag bottom-right corner), portalled dropdowns never clipped
function Section({ title, children, open: defaultOpen = true }: {
  title: string; children: React.ReactNode; open?: boolean;
}) {
  const [expanded, setExpanded] = useState(defaultOpen);
  return (
    <div
      className="border border-border rounded-lg"
      style={expanded ? { resize: "vertical", overflow: "auto", minHeight: 80 } : undefined}
    >
      <button type="button" onClick={() => setExpanded(!expanded)}
        className={`w-full flex items-center justify-between px-4 py-2.5 bg-muted/30 text-xs font-semibold uppercase tracking-wider text-muted-foreground hover:bg-muted/50 ${expanded ? "rounded-t-lg" : "rounded-lg"}`}>
        {title}
        {expanded ? <ChevronUp className="h-3.5 w-3.5" /> : <ChevronDown className="h-3.5 w-3.5" />}
      </button>
      {expanded && <div className="p-4 space-y-3">{children}</div>}
    </div>
  );
}

// Portal-based combobox — dropdown renders in document.body, never clipped
function Combobox({ value, options, onSelect, placeholder }: {
  value: string;
  options: { id: string; label: string }[];
  onSelect: (id: string) => void;
  placeholder?: string;
}) {
  const [q, setQ]       = useState("");
  const [open, setOpen] = useState(false);
  const [rect, setRect] = useState<DOMRect | null>(null);
  const inputRef        = useRef<HTMLInputElement>(null);

  const selected = options.find((o) => o.id === value);
  const filtered = options
    .filter((o) => !q || o.label.toLowerCase().includes(q.toLowerCase()))
    .slice(0, 25);

  // Reposition dropdown on scroll so it follows the input instead of closing
  useEffect(() => {
    if (!open) return;
    const updatePos = () => {
      if (inputRef.current) setRect(inputRef.current.getBoundingClientRect());
    };
    window.addEventListener("scroll", updatePos, true);
    return () => window.removeEventListener("scroll", updatePos, true);
  }, [open]);

  function handleFocus() {
    if (inputRef.current) setRect(inputRef.current.getBoundingClientRect());
    setOpen(true);
    setQ("");
  }

  const dropdown = open && rect
    ? createPortal(
        <div
          style={{ position: "fixed", top: rect.bottom + 2, left: rect.left, width: rect.width, zIndex: 9999 }}
          className="bg-background border border-border rounded-md shadow-2xl max-h-52 overflow-y-auto"
        >
          {filtered.map((o) => (
            <button key={o.id} type="button"
              onMouseDown={(e) => { e.preventDefault(); onSelect(o.id); setOpen(false); }}
              className={`w-full text-left px-3 py-2 text-sm hover:bg-muted/40 ${o.id === value ? "bg-gold/10 text-gold font-medium" : ""}`}>
              {o.label}
            </button>
          ))}
          {filtered.length === 0 && <p className="px-3 py-2 text-xs text-muted-foreground">No matches</p>}
        </div>,
        document.body
      )
    : null;

  return (
    <div>
      <input
        ref={inputRef}
        value={open ? q : (selected?.label ?? "")}
        onFocus={handleFocus}
        onBlur={() => setTimeout(() => setOpen(false), 200)}
        onChange={(e) => setQ(e.target.value)}
        placeholder={placeholder}
        className={ic}
      />
      {dropdown}
    </div>
  );
}

// Combobox with optional "Add entry" — uses Settings-managed codes (baseOptions) only
function CodeCombobox({ value, baseOptions, codeType, onSelect, placeholder, locked }: {
  value: string;
  baseOptions: { id: string; label: string }[];
  codeType: "office" | "service";
  onSelect: (id: string) => void;
  placeholder?: string;
  locked?: boolean;
}) {
  const [q, setQ]               = useState("");
  const [open, setOpen]         = useState(false);
  const [rect, setRect]         = useState<DOMRect | null>(null);
  const inputRef                = useRef<HTMLInputElement>(null);
  const [adding, setAdding]     = useState(false);
  const [newCode, setNewCode]   = useState("");
  const [newDesc, setNewDesc]   = useState("");
  const [addErr, setAddErr]     = useState("");

  const selected = baseOptions.find((o) => o.id === value);
  const filtered = baseOptions
    .filter((o) => !q || o.label.toLowerCase().includes(q.toLowerCase()))
    .slice(0, 50);

  useEffect(() => {
    if (!open) return;
    const update = () => { if (inputRef.current) setRect(inputRef.current.getBoundingClientRect()); };
    window.addEventListener("scroll", update, true);
    return () => window.removeEventListener("scroll", update, true);
  }, [open]);

  function handleFocus() {
    if (inputRef.current) setRect(inputRef.current.getBoundingClientRect());
    setOpen(true);
    setAdding(false);
    setQ("");
  }

  function handleAdd() {
    const code = newCode.trim().toUpperCase();
    const desc = newDesc.trim();
    if (!code || !desc) { setAddErr("Code and description are required."); return; }
    // Redirect to Settings → System to add new codes
    setAddErr("New codes must be added via Settings → System → Service/Country Codes.");
  }

  const dropdownContent = adding ? (
    <div className="p-3 space-y-2" onMouseDown={(e) => e.preventDefault()}>
      <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-1">Add new code</p>
      <input
        autoFocus
        value={newCode}
        onChange={(e) => setNewCode(e.target.value.toUpperCase())}
        placeholder="Code (e.g. IN, INPAT)"
        className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold uppercase"
        onMouseDown={(e) => e.stopPropagation()}
      />
      <input
        value={newDesc}
        onChange={(e) => setNewDesc(e.target.value)}
        placeholder="Description"
        className="w-full h-8 rounded border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
        onMouseDown={(e) => e.stopPropagation()}
      />
      {addErr && <p className="text-xs text-destructive">{addErr}</p>}
      <div className="flex gap-2">
        <button type="button" onMouseDown={(e) => { e.preventDefault(); handleAdd(); }}
          disabled={saving}
          className="flex-1 h-7 rounded bg-gold/90 hover:bg-gold text-xs font-medium text-black disabled:opacity-50">
          {saving ? "Saving…" : "Save"}
        </button>
        <button type="button" onMouseDown={(e) => { e.preventDefault(); setAdding(false); setAddErr(""); setNewCode(""); setNewDesc(""); }}
          className="flex-1 h-7 rounded border border-border text-xs hover:bg-muted/40">
          Cancel
        </button>
      </div>
    </div>
  ) : (
    <>
      {filtered.map((o) => (
        <button key={o.id} type="button"
          onMouseDown={(e) => { e.preventDefault(); onSelect(o.id); setOpen(false); }}
          className={`w-full text-left px-3 py-2 text-sm hover:bg-muted/40 ${o.id === value ? "bg-gold/10 text-gold font-medium" : ""}`}>
          {o.label}
        </button>
      ))}
      {filtered.length === 0 && <p className="px-3 py-2 text-xs text-muted-foreground">No matches</p>}
      {!locked && (
        <button type="button"
          onMouseDown={(e) => { e.preventDefault(); setAdding(true); setNewCode(q.toUpperCase()); setNewDesc(""); setAddErr(""); }}
          className="w-full text-left px-3 py-2 text-xs text-gold hover:bg-gold/10 border-t border-border font-medium">
          + Add entry
        </button>
      )}
    </>
  );

  const dropdown = open && rect
    ? createPortal(
        <div
          style={{ position: "fixed", top: rect.bottom + 2, left: rect.left, width: rect.width, zIndex: 9999 }}
          className="bg-background border border-border rounded-md shadow-2xl max-h-64 overflow-y-auto"
        >
          {dropdownContent}
        </div>,
        document.body
      )
    : null;

  return (
    <div>
      <input
        ref={inputRef}
        value={open ? q : (selected?.label ?? "")}
        onFocus={handleFocus}
        onBlur={() => setTimeout(() => { if (!adding) setOpen(false); }, 200)}
        onChange={(e) => setQ(e.target.value)}
        placeholder={placeholder}
        className={ic}
      />
      {dropdown}
    </div>
  );
}

// ── Form state ────────────────────────────────────────────────────────────────

interface PF {
  record_mode: "new" | "existing"; project_code: string; docket_number: string;
  project_name: string; client_id: string; case_type: string;
  invention_title: string; technology_field: string;
  application_number: string; patent_office_code: string; service_code: string;
  filing_date: string; target_filing_date: string; hard_deadline: string;
  idf_received_date: string; advance_payment_date: string;
  partial_payment_date: string; full_payment_date: string;
  urgency: string; status: string;
  patent_granted: boolean;
  assigned_partner_id: string; assigned_manager_id: string;
  secondary_manager_id: string; patent_engineer_id: string;
  notes: string; circle: string;
}

const BLANK: PF = {
  record_mode: "new", project_code: "", docket_number: "",
  project_name: "", client_id: "", case_type: "Patent – Utility",
  invention_title: "", technology_field: "",
  application_number: "", patent_office_code: "IN", service_code: "FIL",
  filing_date: "", target_filing_date: "", hard_deadline: "",
  idf_received_date: "", advance_payment_date: "",
  partial_payment_date: "", full_payment_date: "",
  urgency: "Normal", status: "Open",
  patent_granted: false,
  assigned_partner_id: "", assigned_manager_id: "",
  secondary_manager_id: "", patent_engineer_id: "",
  notes: "", circle: "",
};

// ── KPI ───────────────────────────────────────────────────────────────────────

interface KpiDef {
  label: string;
  key: string;
  color: string;
  filterParams: Record<string, string>;
}

const KPI_DEFS: KpiDef[] = [
  { label: "Total Cases",  key: "total",       color: "text-gold",           filterParams: {} },
  { label: "Open",         key: "open",        color: "text-blue-500",       filterParams: { status: "Open" } },
  { label: "In Progress",  key: "in_progress", color: "text-primary",        filterParams: { status: "In Progress" } },
  { label: "On Hold",      key: "on_hold",     color: "text-yellow-500",     filterParams: { status: "On Hold" } },
  { label: "Overdue",      key: "overdue",     color: "text-destructive",    filterParams: { overdue: "1" } },
  { label: "Granted",      key: "granted",     color: "text-green-500",      filterParams: { patent_granted: "1" } },
  { label: "Completed",    key: "completed",   color: "text-emerald-600",    filterParams: { status: "Completed" } },
  { label: "Closed",       key: "closed",      color: "text-muted-foreground", filterParams: { status: "Closed" } },
];

function ProjectKpiModal({ kpi, onClose, onOpenDetail }: { kpi: KpiDef; onClose: () => void; onOpenDetail?: (id: number) => void }) {
  const [result, setResult]   = useState<{ data: any[]; total: number }>({ data: [], total: 0 });
  const [search, setSearch]   = useState("");
  const [loading, setLoading] = useState(false);
  const [page, setPage]       = useState(1);
  const [sortBy, setSortBy]   = useState("hard_deadline");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("asc");
  const PER_PAGE = 15;

  const fetchPage = useCallback(
    async (pg: number, q: string, sb: string, sd: string) => {
      setLoading(true);
      try {
        const params = new URLSearchParams({
          per_page: String(PER_PAGE),
          page: String(pg),
          sort_by: sb,
          sort_dir: sd,
          ...kpi.filterParams,
        });
        if (q) params.set("search", q);
        const res: any = await api.getProjectsPaged(params);
        setResult({ data: Array.isArray(res) ? res : (res?.data ?? []), total: res?.total ?? 0 });
      } finally {
        setLoading(false);
      }
    },
    [kpi]
  );

  useEffect(() => { fetchPage(1, "", "hard_deadline", "asc"); }, [fetchPage]);

  function handleSearch(q: string) { setSearch(q); setPage(1); fetchPage(1, q, sortBy, sortDir); }
  function handleSort(col: string) {
    const nd = col === sortBy && sortDir === "asc" ? "desc" : "asc";
    setSortBy(col); setSortDir(nd); fetchPage(page, search, col, nd);
  }
  function goPage(pg: number) { setPage(pg); fetchPage(pg, search, sortBy, sortDir); }

  const totalPages = Math.max(1, Math.ceil(result.total / PER_PAGE));
  const SortIcon = ({ col }: { col: string }) =>
    col !== sortBy ? <ArrowUpDown className="h-3 w-3 ml-1 opacity-40" /> :
    sortDir === "asc" ? <ArrowUp className="h-3 w-3 ml-1" /> : <ArrowDown className="h-3 w-3 ml-1" />;

  function fmtD(d: string | null | undefined) {
    if (!d) return "—";
    const p = d.split("T")[0]; const [y, m, day] = p.split("-");
    return (!y || !m || !day) ? d : `${day}-${m}-${y}`;
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
      <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-4xl max-h-[88vh] flex flex-col">
        <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
          <div>
            <h2 className="font-display text-lg font-semibold">{kpi.label}</h2>
            <p className="text-xs text-muted-foreground mt-0.5">{result.total} case{result.total !== 1 ? "s" : ""}</p>
          </div>
          <button onClick={onClose}><X className="h-5 w-5 text-muted-foreground" /></button>
        </div>
        <div className="px-6 py-3 border-b border-border flex-shrink-0">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <input
              className="w-full h-9 pl-9 pr-3 rounded-md border border-border bg-background text-sm focus:outline-none focus:ring-1 focus:ring-gold"
              placeholder="Search docket, title…"
              value={search}
              onChange={(e) => handleSearch(e.target.value)}
            />
          </div>
        </div>
        <div className="flex-1 overflow-auto">
          {loading ? (
            <div className="flex items-center justify-center py-16"><Loader2 className="h-6 w-6 animate-spin text-gold" /></div>
          ) : (
            <table className="w-full text-sm min-w-[700px]">
              <thead className="sticky top-0 bg-muted/60 backdrop-blur text-xs uppercase tracking-wider text-muted-foreground">
                <tr>
                  <th className="px-4 py-3 text-left">
                    <button className="flex items-center" onClick={() => handleSort("docket_number")}>
                      Docket <SortIcon col="docket_number" />
                    </button>
                  </th>
                  <th className="px-4 py-3 text-left">
                    <button className="flex items-center" onClick={() => handleSort("project_name")}>
                      Patent Title <SortIcon col="project_name" />
                    </button>
                  </th>
                  <th className="px-4 py-3 text-left">Country</th>
                  <th className="px-4 py-3 text-left">
                    <button className="flex items-center" onClick={() => handleSort("status")}>
                      Status <SortIcon col="status" />
                    </button>
                  </th>
                  <th className="px-4 py-3 text-left">
                    <button className="flex items-center" onClick={() => handleSort("hard_deadline")}>
                      Deadline <SortIcon col="hard_deadline" />
                    </button>
                  </th>
                </tr>
              </thead>
              <tbody>
                {result.data.map((p) => {
                  const isOverdue = p.hard_deadline && new Date(p.hard_deadline) < new Date();
                  return (
                    <tr key={p.id} className="border-t border-border hover:bg-muted/30">
                      <td className="px-4 py-2.5 font-mono text-xs text-gold font-semibold whitespace-nowrap">
                        <button
                          className="hover:underline hover:text-primary transition-colors"
                          onClick={() => onOpenDetail?.(p.id)}
                        >
                          {p.docket_number ?? p.project_code ?? "—"}
                        </button>
                      </td>
                      <td className="px-4 py-2.5 max-w-[240px] truncate font-medium">{p.project_name}</td>
                      <td className="px-4 py-2.5 text-xs text-muted-foreground">{p.patent_office_code ?? "—"}</td>
                      <td className="px-4 py-2.5 text-xs">{p.status}</td>
                      <td className={`px-4 py-2.5 text-xs font-mono whitespace-nowrap ${isOverdue ? "text-destructive font-semibold" : "text-muted-foreground"}`}>
                        {fmtD(p.hard_deadline)}
                      </td>
                    </tr>
                  );
                })}
                {result.data.length === 0 && (
                  <tr><td colSpan={5} className="px-4 py-10 text-center text-muted-foreground">No cases found.</td></tr>
                )}
              </tbody>
            </table>
          )}
        </div>
        <div className="flex items-center justify-between px-6 py-3 border-t border-border flex-shrink-0 text-xs text-muted-foreground">
          <span>Showing {result.data.length} of {result.total}</span>
          <div className="flex items-center gap-1">
            <button onClick={() => goPage(page - 1)} disabled={page === 1}
              className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40">
              <ChevronLeft className="h-4 w-4" />
            </button>
            <span className="px-2">{page} / {totalPages}</span>
            <button onClick={() => goPage(page + 1)} disabled={page >= totalPages}
              className="p-1 rounded border border-border disabled:opacity-40 hover:bg-muted/40">
              <ChevronRight className="h-4 w-4" />
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

// ── Main ──────────────────────────────────────────────────────────────────────

export default function Projects() {
  const { props: pageProps } = usePage() as any;
  const currentUserRole = pageProps.auth?.user?.role ?? "";
  const isAnalyst = ["associate", "galvanizer", "partner", "director"].includes(currentUserRole);
  const canBulkImport = !["client", "client_admin"].includes(currentUserRole);

  // System settings from server (feature flags + configurable dropdowns)
  const sysCfg = (pageProps.systemSettings ?? {}) as any;
  const featLinkPred        = sysCfg.feature_link_predecessor   ?? true;
  const featLegacyCase      = sysCfg.feature_legacy_case        ?? true;
  const featLockCodeDropdown = sysCfg.feature_lock_code_dropdowns ?? true;
  const dynamicSvcCodes   = (sysCfg.dropdown_service_codes  ?? []) as {code:string;label:string}[];
  const dynamicCtyCodes   = (sysCfg.dropdown_country_codes  ?? []) as {code:string;label:string}[];
  const [roleFilter, setRoleFilter] = useAnalystRoleFilter();

  const [projects, setProjects]  = useState<any[]>([]);
  const [clients, setClients]    = useState<any[]>([]);

  // DocketTrak import modal
  const [showDocketImport, setShowDocketImport] = useState(false);
  const [docketFile, setDocketFile] = useState<File | null>(null);
  const [docketPreviewing, setDocketPreviewing] = useState(false);
  const [docketImporting, setDocketImporting] = useState(false);
  const [docketImportPreview, setDocketImportPreview] = useState<any | null>(null);
  const [docketResult, setDocketResult] = useState<{ imported: number; skipped: number; errors: string[] } | null>(null);
  const [docketError, setDocketError] = useState("");
  const [docketSkipTransferred, setDocketSkipTransferred] = useState(true);
  const [docketSkipUnknown, setDocketSkipUnknown] = useState(false);
  const [docketMappingFile, setDocketMappingFile] = useState<File | null>(null);

  // Bulk import modal
  const [showImport, setShowImport] = useState(false);
  const [importClientId, setImportClientId] = useState("");
  const [importClientSearch, setImportClientSearch] = useState("");
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importing, setImporting] = useState(false);
  const [importError, setImportError] = useState("");
  const [importResult, setImportResult] = useState<{ imported: number; skipped: number; errors: string[]; dockets: string[]; client: string } | null>(null);
  const [importDuplicates, setImportDuplicates] = useState<{ line: number; uin: string; reason: string }[] | null>(null);
  const [importSheets, setImportSheets] = useState<{ name: string; rows: number; is_data_sheet: boolean }[] | null>(null);
  const [importSelectedSheet, setImportSelectedSheet] = useState<string | null>(null);
  const [inspecting, setInspecting] = useState(false);
  const [users, setUsers]        = useState<any[]>([]);
  const [loading, setLoading]    = useState(true);
  const [search, setSearch]      = useState("");
  const [filterStatus, setFilter] = useState("All");
  const [page, setPage]           = useState(1);
  const [perPage, setPerPage]     = useState(25);
  const [stats, setStats]         = useState<Record<string, number>>({ total: 0, open: 0, on_hold: 0, overdue: 0 });
  const [kpiModal, setKpiModal]   = useState<KpiDef | null>(null);

  const [showModal, setShowModal] = useState(false);
  const [editProj, setEditProj]  = useState<any>(null);
  const [form, setForm]          = useState<PF>(BLANK);
  const [saving, setSaving]      = useState(false);
  const [saveErr, setSaveErr]    = useState("");

  const [delTarget, setDelTarget] = useState<any>(null);
  const [deleting, setDeleting]  = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [bulkAction, setBulkAction]   = useState("");

  const [detailId, setDetailId] = useState<number | null>(null);
  const [detailData, setDetailData] = useState<any>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailTab, setDetailTab] = useState<"stages" | "tasks" | "invoices" | "ledger">("stages");

  const openDetail = async (projectId: number) => {
    setDetailId(projectId);
    setDetailData(null);
    setDetailLoading(true);
    setDetailTab("stages");
    try {
      const res = await api.request(`/projects/${projectId}/detail`);
      setDetailData(res);
    } catch {
      setDetailData(null);
    } finally {
      setDetailLoading(false);
    }
  };

  const [stageMenu, setStageMenu] = useState<{ projectId: number; stages: string[]; rect: DOMRect } | null>(null);
  const [statusMenu, setStatusMenu] = useState<{ projectId: number; rect: DOMRect } | null>(null);
  const [raiseModal, setRaiseModal] = useState<{ type: "invoice" | "quote"; project: any } | null>(null);

  // ── Elevate / Link Predecessor modals ─────────────────────────────────────
  const [elevateModal, setElevateModal] = useState<{ project: any } | null>(null);
  const [elevateToService, setElevateToService] = useState("");
  const [elevateNote, setElevateNote] = useState("");
  const [elevateSaving, setElevateSaving] = useState(false);
  const [elevateErr, setElevateErr] = useState("");

  const [linkModal, setLinkModal] = useState<{ project: any } | null>(null);
  const [linkSearch, setLinkSearch] = useState("");
  const [linkPredecessorId, setLinkPredecessorId] = useState<number | null>(null);
  const [linkNote, setLinkNote] = useState("");
  const [linkSaving, setLinkSaving] = useState(false);
  const [linkErr, setLinkErr] = useState("");

  // ── Chain auto-detection wizard ────────────────────────────────────────────
  const [chainWizard, setChainWizard] = useState<{ chains: any[]; total: number } | null>(null);
  const [chainLoading, setChainLoading] = useState(false);
  const [chainSelected, setChainSelected] = useState<Set<string>>(new Set());
  const [chainLinking, setChainLinking] = useState(false);
  const [chainResult, setChainResult] = useState<{ created: number; skipped: number } | null>(null);
  const [raiseForm, setRaiseForm] = useState<{ description: string; amount: string; due_date: string; notes: string }>({ description: "", amount: "", due_date: "", notes: "" });
  const [raiseSaving, setRaiseSaving] = useState(false);
  const [raiseErr, setRaiseErr] = useState("");
  const [pickerSearch, setPickerSearch] = useState("");
  useEffect(() => { if (!stageMenu && !statusMenu) setPickerSearch(""); }, [stageMenu, statusMenu]);


  const isClientUser   = ["client", "client_admin"].includes(currentUserRole);
  const canElevate     = !isClientUser;
  const canLinkPred    = !isClientUser;

  const loadProjects = (rf: string) => {
    setLoading(true);
    // Only apply role_filter for Patent Analysts; all other roles get unrestricted data.
    const rf_param = isAnalyst && rf !== 'all' ? rf : undefined;
    api.getProjectStats(rf_param).then(setStats).catch(() => {});
    const params = new URLSearchParams({ per_page: '5000' });
    if (rf_param) params.set('role_filter', rf_param);
    // Client users get 403 on /api/users and /api/clients — skip those calls entirely.
    const extraCalls = isClientUser
      ? [Promise.resolve([] as any[]), Promise.resolve([] as any[])]
      : [api.getAllClients(), api.getUsers()];
    Promise.all([api.getProjectsPaged(params), ...extraCalls])
      .then(([p, c, u]) => {
        const list = Array.isArray(p) ? p : (p as any).data || [];
        setProjects(list);
        setClients(c as any[]);
        setUsers(u as any[]);
        const sp = new URLSearchParams(window.location.search);
        const openId = sp.get("open");
        if (openId) {
          const target = list.find((proj: any) => String(proj.id) === openId);
          if (target) openEdit(target);
          sp.delete("open");
          window.history.replaceState({}, "", window.location.pathname + (sp.toString() ? "?" + sp.toString() : ""));
        }
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  };

  useEffect(() => { loadProjects(roleFilter); }, [roleFilter]);

  const sf = (f: keyof PF, v: string | boolean) => setForm((p) => ({ ...p, [f]: v }));

  const clientOptions = useMemo(() =>
    clients.map((c) => ({
      id: String(c.id),
      label: `${c.client_code ?? ""} – ${c.legal_name ?? c.company_name ?? ""}`.replace(/^–\s/, ""),
    })), [clients]);

  const staffOptions = useMemo(() =>
    users.map((u) => ({ id: String(u.id), label: u.name })), [users]);

  // Use DB-driven lists when available, otherwise fall back to local constants
  const officeOptions  = useMemo(() =>
    (dynamicCtyCodes.length > 0 ? dynamicCtyCodes : PATENT_OFFICES).map((o) => ({ id: o.code, label: o.label })),
  [dynamicCtyCodes]);
  const serviceOptions = useMemo(() =>
    (dynamicSvcCodes.length > 0 ? dynamicSvcCodes : SERVICE_CODES).map((s) => ({ id: s.code, label: s.label })),
  [dynamicSvcCodes]);

  const docketPreview = useMemo(() => {
    if (!form.client_id) return "—";
    const cl = clients.find((c) => String(c.id) === form.client_id);
    if (!cl?.client_code) return "—";
    const cc = cl.client_code;
    let maxSeq = 0;
    for (const p of projects) {
      if (String(p.client_id) === form.client_id && p.docket_number?.startsWith(cc)) {
        const seq = parseInt(p.docket_number.slice(cc.length, cc.length + 3), 10);
        if (!isNaN(seq) && seq > maxSeq) maxSeq = seq;
      }
    }
    const seq = String(maxSeq + 1).padStart(3, "0");
    return `${cc}${seq}${form.patent_office_code.toUpperCase()}${form.service_code.toUpperCase()}`;
  }, [form.client_id, form.patent_office_code, form.service_code, clients, projects]);

  const filtered = useMemo(() => {
    setPage(1);
    return projects.filter((p) => {
      const q = search.toLowerCase();
      if (q &&
        !p.project_name?.toLowerCase().includes(q) &&
        !p.docket_number?.toLowerCase().includes(q) &&
        !p.project_code?.toLowerCase().includes(q) &&
        !(p.client?.legal_name ?? p.client?.company_name ?? "").toLowerCase().includes(q) &&
        !p.application_number?.toLowerCase().includes(q) &&
        !((p as any).docket_trak_ref ?? "").toLowerCase().includes(q)) return false;
      if (filterStatus !== "All" && p.status !== filterStatus) return false;
      return true;
    });
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projects, search, filterStatus]);

  const totalPages  = Math.max(1, Math.ceil(filtered.length / perPage));
  const paginated   = filtered.slice((page - 1) * perPage, page * perPage);

  function openCreate() { setForm(BLANK); setEditProj(null); setSaveErr(""); setShowModal(true); }

  function openEdit(p: any) {
    setForm({
      record_mode:         "existing",
      project_code:        p.project_code         ?? "",
      docket_number:       p.docket_number        ?? "",
      project_name:        p.project_name         ?? "",
      client_id:           p.client_id             ? String(p.client_id) : "",
      case_type:           p.case_type             ?? p.project_type ?? "Patent – Utility",
      invention_title:     p.invention_title       ?? "",
      technology_field:    p.technology_field      ?? "",
      application_number:  p.application_number    ?? "",
      patent_office_code:  p.patent_office_code    ?? "IN",
      service_code:        p.service_code          ?? "FIL",
      filing_date:         p.filing_date           ? p.filing_date.split("T")[0] : "",
      patent_granted:      p.patent_granted        ?? false,
      target_filing_date:  p.target_filing_date    ? p.target_filing_date.split("T")[0] : "",
      hard_deadline:       p.hard_deadline         ? p.hard_deadline.split("T")[0] : "",
      idf_received_date:   p.idf_received_date     ? p.idf_received_date.split("T")[0] : "",
      advance_payment_date: p.advance_payment_date ? p.advance_payment_date.split("T")[0] : "",
      partial_payment_date: p.partial_payment_date ? p.partial_payment_date.split("T")[0] : "",
      full_payment_date:   p.full_payment_date     ? p.full_payment_date.split("T")[0] : "",
      urgency:             p.urgency               ?? "Normal",
      status:              p.status                ?? "Open",
      assigned_partner_id:   p.assigned_partner_id  ? String(p.assigned_partner_id) : "",
      assigned_manager_id:   p.assigned_manager_id  ? String(p.assigned_manager_id) : "",
      secondary_manager_id:  p.secondary_manager_id ? String(p.secondary_manager_id) : "",
      patent_engineer_id:    p.patent_engineer_id   ? String(p.patent_engineer_id) : "",
      notes:               p.notes                 ?? "",
      circle:              p.circle                ?? "",
    });
    setEditProj(p); setSaveErr(""); setShowModal(true);
  }

  async function handleSave() {
    if (!form.project_name.trim() || !form.client_id) {
      setSaveErr("Patent title and client are required."); return;
    }
    if (!editProj && form.record_mode === "existing" && !form.project_code.trim() && !form.docket_number.trim()) {
      setSaveErr("Existing case ID is required for legacy cases."); return;
    }
    if (!/^[A-Z0-9]{2}$/i.test(form.patent_office_code) || !/^[A-Z0-9]{3}$/i.test(form.service_code)) {
      setSaveErr("A 2-character patent office code and 3-character service code are required."); return;
    }
    setSaving(true); setSaveErr("");
    try {
      const canonicalCaseId = (form.project_code || form.docket_number).trim().toUpperCase();
      const payload = {
        ...form,
        project_code:         canonicalCaseId || null,
        docket_number:        canonicalCaseId || null,
        client_id:            parseInt(form.client_id),
        assigned_partner_id:  form.assigned_partner_id  ? parseInt(form.assigned_partner_id)  : null,
        assigned_manager_id:  form.assigned_manager_id  ? parseInt(form.assigned_manager_id)  : null,
        secondary_manager_id: form.secondary_manager_id ? parseInt(form.secondary_manager_id) : null,
        patent_engineer_id:   form.patent_engineer_id   ? parseInt(form.patent_engineer_id)   : null,
        project_type: form.case_type,
        circle: form.circle || null,
      };
      if (editProj) {
        const { record_mode, project_code, docket_number, ...updatePayload } = payload;
        const updated = await api.updateProject(editProj.id, updatePayload as any);
        setProjects((prev) => prev.map((p) => p.id === editProj.id
          ? { ...p, ...updated, client: p.client } : p));
      } else {
        const created = await api.createProject(payload as any);
        const clientObj = clients.find((c) => c.id === parseInt(form.client_id));
        setProjects((prev) => [{ ...created, client: clientObj ?? null }, ...prev]);
      }
      setShowModal(false);
    } catch (e: any) { setSaveErr(e.message || "Failed to save case."); }
    finally { setSaving(false); }
  }

  async function handleDelete() {
    if (!delTarget) return;
    setDeleting(true);
    try {
      await api.deleteProject(delTarget.id);
      setProjects((prev) => prev.filter((p) => p.id !== delTarget.id));
      setDelTarget(null);
    } finally { setDeleting(false); }
  }

  async function handleElevate() {
    if (!elevateModal || !elevateToService) return;
    setElevateSaving(true);
    setElevateErr("");
    try {
      const res = await api.elevateProject(elevateModal.project.id, elevateToService, elevateNote || undefined);
      setProjects((prev) => prev.map((p) => p.id === elevateModal.project.id ? { ...p, ...res.project } : p));
      setElevateModal(null);
      setElevateToService("");
      setElevateNote("");
    } catch (e: any) { setElevateErr(e.message || "Elevation failed."); }
    finally { setElevateSaving(false); }
  }

  async function handleLinkPredecessor() {
    if (!linkModal || !linkPredecessorId) return;
    setLinkSaving(true);
    setLinkErr("");
    try {
      await api.linkProjectPredecessor(linkModal.project.id, linkPredecessorId, linkNote || undefined);
      setLinkModal(null);
      setLinkSearch("");
      setLinkPredecessorId(null);
      setLinkNote("");
    } catch (e: any) { setLinkErr(e.message || "Linking failed."); }
    finally { setLinkSaving(false); }
  }

  async function openChainWizard() {
    setChainLoading(true);
    setChainWizard(null);
    setChainSelected(new Set());
    setChainResult(null);
    try {
      const res = await api.detectProjectChains();
      setChainWizard(res);
      // Pre-select all detected pairs
      const allKeys = new Set<string>();
      for (const chain of res.chains) {
        for (const pair of chain.pairs) {
          allKeys.add(`${pair.predecessor_id}-${pair.successor_id}`);
        }
      }
      setChainSelected(allKeys);
    } catch (e: any) { alert(e.message || "Detection failed"); }
    finally { setChainLoading(false); }
  }

  async function handleBulkLink() {
    if (!chainWizard) return;
    const pairs: { predecessor_id: number; successor_id: number; note: string }[] = [];
    for (const chain of chainWizard.chains) {
      for (const pair of chain.pairs) {
        const key = `${pair.predecessor_id}-${pair.successor_id}`;
        if (chainSelected.has(key)) {
          pairs.push({ predecessor_id: pair.predecessor_id, successor_id: pair.successor_id, note: "Auto-detected chain link" });
        }
      }
    }
    if (pairs.length === 0) { alert("No pairs selected."); return; }
    setChainLinking(true);
    try {
      const res = await api.bulkLinkProjectChains(pairs);
      setChainResult({ created: res.created, skipped: res.skipped });
    } catch (e: any) { alert(e.message || "Linking failed"); }
    finally { setChainLinking(false); }
  }

  const statuses     = ["All", "Open", "In Progress", "On Hold", "Closed", "Completed"];
  const userName     = (id: any) => users.find((u) => u.id === id)?.name ?? "—";
  const managerName       = (p: any) => p.manager?.name        ?? userName(p.assigned_manager_id);
  const partnerName       = (p: any) => p.partner?.name        ?? userName(p.assigned_partner_id);
  const patentEngineerName = (p: any) => p.patent_engineer?.name ?? userName(p.patent_engineer_id);
  const activeStage  = (p: any) =>
    p.stages?.find((s: any) => s.status === "In Progress")?.stage_name
    ?? p.stages?.[0]?.stage_name
    ?? "—";

  return (
    <AppLayout>
      <Head title="Cases" />
      <PageHeader eyebrow="Practice" title="Cases"
        description={`${projects.length} cases in portfolio`}
        actions={
          <>
            <AnalystRoleFilter value={roleFilter} onChange={(v) => { setRoleFilter(v); }} />
            <Button variant="outline" onClick={() =>
              downloadCSV(`cases-${new Date().toISOString().slice(0, 10)}.csv`,
                filtered.map((p) => ({
                  "Docket #":       p.docket_number ?? "",
                  "Patent Title":   p.project_name,
                  "Country":        p.patent_office_code ?? "",
                  "Case Type":      p.case_type ?? p.project_type ?? "",
                  "Filed":          fmtDate(p.filing_date),
                  "Deadline":       fmtDate(p.hard_deadline),
                  "Status":         p.status,
                  "Workflow Stage": activeStage(p),
                  "Client Manager": managerName(p),
                  "Person Responsible": patentEngineerName(p),
                }))
              )}>
              <Download className="h-4 w-4 mr-2" />Export CSV
            </Button>
            {canBulkImport && (
              <Button variant="outline" onClick={() => {
                setShowImport(true); setImportClientId(""); setImportClientSearch("");
                setImportFile(null); setImportError(""); setImportResult(null); setImportDuplicates(null);
                setImportSheets(null); setImportSelectedSheet(null);
              }}>
                <Upload className="h-4 w-4 mr-2" />Bulk Import
              </Button>
            )}
            {["super_admin", "partner"].includes(currentUserRole) && (
              <Button variant="outline" onClick={() => {
                setShowDocketImport(true); setDocketFile(null); setDocketImportPreview(null);
                setDocketResult(null); setDocketError(""); setDocketSkipTransferred(true);
              }}>
                <FileSpreadsheet className="h-4 w-4 mr-2" />DocketTrak Import
              </Button>
            )}
            {canLinkPred && (
              <Button variant="outline" onClick={openChainWizard} disabled={chainLoading}>
                {chainLoading
                  ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Detecting…</>
                  : <><GitMerge className="h-4 w-4 mr-2" />Detect Chains</>}
              </Button>
            )}
            {!isClientUser && (
              <Button className="bg-gold hover:bg-gold/90 text-black" onClick={openCreate}>
                <Plus className="h-4 w-4 mr-2" />New Case
              </Button>
            )}
          </>
        }
      />

      {/* Bulk Import Modal */}
      {showImport && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-lg p-6 m-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display text-lg font-semibold">Bulk Import Cases</h2>
              <button onClick={() => setShowImport(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>

            {importResult ? (
              /* ── Result ── */
              <div className="space-y-4">
                <div className="text-center py-2">
                  <CheckCircle className="h-10 w-10 text-green-500 mx-auto mb-2" />
                  <div className="font-semibold">
                    {importResult.imported} case{importResult.imported !== 1 ? "s" : ""} imported for {importResult.client}
                  </div>
                  {importResult.skipped > 0 && (
                    <div className="text-xs text-amber-500 mt-1">{importResult.skipped} row{importResult.skipped !== 1 ? "s" : ""} skipped</div>
                  )}
                </div>
                {importResult.errors.length > 0 && (
                  <div className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-xs text-amber-600 space-y-1 max-h-40 overflow-y-auto">
                    {importResult.errors.map((e, i) => <div key={i}>{e}</div>)}
                  </div>
                )}
                {importResult.dockets.length > 0 && (
                  <div className="rounded-lg border border-border p-3 max-h-40 overflow-y-auto">
                    <div className="text-[10px] uppercase tracking-wider text-muted-foreground mb-1.5">Created dockets</div>
                    <div className="flex flex-wrap gap-1.5">
                      {importResult.dockets.map((d, i) => (
                        <span key={i} className="font-mono text-xs px-1.5 py-0.5 rounded bg-gold/10 text-gold border border-gold/30">{d}</span>
                      ))}
                    </div>
                  </div>
                )}
                <Button className="w-full" variant="outline"
                  onClick={() => { setShowImport(false); loadProjects(roleFilter); }}>
                  Close
                </Button>
              </div>
            ) : importDuplicates ? (
              /* ── Duplicate confirmation ── */
              <div className="space-y-4">
                <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4">
                  <div className="flex items-start gap-3">
                    <AlertCircle className="h-5 w-5 text-amber-500 flex-shrink-0 mt-0.5" />
                    <div>
                      <p className="text-sm font-semibold text-amber-700">
                        {importDuplicates.length} duplicate {importDuplicates.length === 1 ? "case" : "cases"} detected
                      </p>
                      <p className="text-xs text-amber-600 mt-0.5">
                        These UINs already exist. Choose how to proceed:
                      </p>
                    </div>
                  </div>
                </div>
                <div className="rounded-lg border border-border max-h-48 overflow-y-auto">
                  <table className="w-full text-xs">
                    <thead className="bg-muted/40 sticky top-0">
                      <tr>
                        <th className="text-left px-3 py-2 text-muted-foreground font-medium">Row</th>
                        <th className="text-left px-3 py-2 text-muted-foreground font-medium">UIN</th>
                        <th className="text-left px-3 py-2 text-muted-foreground font-medium">Reason</th>
                      </tr>
                    </thead>
                    <tbody>
                      {importDuplicates.map((d, i) => (
                        <tr key={i} className="border-t border-border">
                          <td className="px-3 py-2 font-mono text-muted-foreground">{d.line}</td>
                          <td className="px-3 py-2 font-mono font-semibold">{d.uin}</td>
                          <td className="px-3 py-2 text-amber-600">{d.reason}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                {importError && <p className="text-xs text-red-500">{importError}</p>}
                <div className="flex gap-2">
                  <Button className="flex-1 bg-gold hover:bg-gold/90 text-black"
                    disabled={importing}
                    onClick={async () => {
                      if (!importClientId || !importFile) return;
                      setImporting(true); setImportError("");
                      try {
                        const res = await api.importProjects(Number(importClientId), importFile, true, importSelectedSheet ?? undefined) as any;
                        setImportDuplicates(null); setImportResult(res);
                      } catch (e: any) { setImportError(e?.message || "Import failed."); }
                      finally { setImporting(false); }
                    }}>
                    {importing ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Importing…</> : "Skip duplicates"}
                  </Button>
                  <Button variant="outline" className="flex-1"
                    disabled={importing}
                    onClick={async () => {
                      if (!importClientId || !importFile) return;
                      setImporting(true); setImportError("");
                      try {
                        const res = await api.importProjects(Number(importClientId), importFile, false, importSelectedSheet ?? undefined) as any;
                        setImportDuplicates(null); setImportResult(res);
                      } catch (e: any) { setImportError(e?.message || "Import failed."); }
                      finally { setImporting(false); }
                    }}>
                    {importing ? <Loader2 className="h-4 w-4 animate-spin" /> : "Import all anyway"}
                  </Button>
                </div>
                <button className="text-xs text-muted-foreground underline w-full text-center"
                  onClick={() => setImportDuplicates(null)}>
                  ← Back
                </button>
              </div>
            ) : (
              /* ── Upload form ── */
              <div className="space-y-4">
                <div>
                  <label className="block text-xs font-medium text-muted-foreground uppercase tracking-wide mb-1.5">
                    1. Select Client (import is limited to one client)
                  </label>
                  <input type="text" placeholder="Filter by client code or name…" value={importClientSearch}
                    onChange={(e) => setImportClientSearch(e.target.value)}
                    className="w-full h-8 rounded-md border border-border bg-background px-3 text-xs focus:outline-none focus:ring-1 focus:ring-gold mb-1.5" />
                  <select value={importClientId} onChange={(e) => setImportClientId(e.target.value)}
                    className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold">
                    <option value="">Choose client…</option>
                    {clients.filter((c: any) => {
                      const q = importClientSearch.toLowerCase();
                      return !q || (c.client_code ?? "").toLowerCase().includes(q) || (c.company_name ?? c.legal_name ?? "").toLowerCase().includes(q);
                    }).map((c: any) => (
                      <option key={c.id} value={c.id}>{c.client_code} — {c.company_name ?? c.legal_name}</option>
                    ))}
                  </select>
                </div>

                <div>
                  <label className="block text-xs font-medium text-muted-foreground uppercase tracking-wide mb-1.5">
                    2. Fill the Excel template
                  </label>
                  <a href="/api/projects/import-template"
                    className="flex items-center gap-2 rounded-lg border border-border px-3 py-2.5 text-sm hover:border-gold/50 hover:bg-muted/30 transition-colors">
                    <FileSpreadsheet className="h-4 w-4 text-green-500" />
                    <span className="flex-1">Download template (.xlsx)</span>
                    <Download className="h-3.5 w-3.5 text-muted-foreground" />
                  </a>
                  <p className="text-[10px] text-muted-foreground mt-1">
                    Dropdown columns are built in (case type, office, service, urgency, status). Dates use YYYY-MM-DD. See the Reference sheet for code descriptions.
                  </p>
                </div>

                <div>
                  <label className="block text-xs font-medium text-muted-foreground uppercase tracking-wide mb-1.5">
                    3. Upload the filled sheet
                  </label>
                  <input type="file" accept=".xlsx,.xls,.csv"
                    onChange={async (e) => {
                      const f = e.target.files?.[0] ?? null;
                      setImportFile(f);
                      setImportSheets(null); setImportSelectedSheet(null); setImportError("");
                      if (!f) return;
                      const ext = f.name.split(".").pop()?.toLowerCase();
                      if (ext === "csv") return; // CSV has no sheets to pick
                      setInspecting(true);
                      try {
                        const res = await api.inspectImportSheets(f);
                        if (res.sheets.length === 1) {
                          // Single data sheet — skip picker, auto-select
                          setImportSelectedSheet(res.sheets[0].name);
                        } else {
                          setImportSheets(res.sheets);
                        }
                      } catch {
                        // Inspection failed — fallback to auto-detect
                      } finally { setInspecting(false); }
                    }}
                    className="w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-gold/15 file:text-gold file:px-3 file:py-1.5 file:text-xs file:font-medium hover:file:bg-gold/25 file:cursor-pointer" />

                  {/* Sheet picker — shown when file has multiple sheets */}
                  {inspecting && (
                    <div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                      <Loader2 className="h-3.5 w-3.5 animate-spin" /> Reading sheets…
                    </div>
                  )}
                  {importSheets && !inspecting && (
                    <div className="mt-3 rounded-lg border border-border overflow-hidden">
                      <div className="bg-muted/40 px-3 py-2 text-[10px] uppercase tracking-wider text-muted-foreground font-medium">
                        This file has multiple sheets — select the one to import:
                      </div>
                      {importSheets.map((s) => (
                        <button
                          key={s.name}
                          type="button"
                          onClick={() => setImportSelectedSheet(s.name)}
                          className={`w-full flex items-center justify-between px-3 py-2.5 text-sm border-t border-border transition-colors ${
                            importSelectedSheet === s.name
                              ? "bg-gold/10 text-gold"
                              : "hover:bg-muted/40 text-foreground"
                          }`}
                        >
                          <div className="flex items-center gap-2">
                            <FileSpreadsheet className="h-3.5 w-3.5 shrink-0" />
                            <span className="font-medium">{s.name}</span>
                            {!s.is_data_sheet && <span className="text-[10px] text-muted-foreground">(empty)</span>}
                          </div>
                          <span className="text-xs text-muted-foreground font-mono">
                            {s.rows > 0 ? `${s.rows} row${s.rows !== 1 ? "s" : ""}` : "—"}
                          </span>
                        </button>
                      ))}
                    </div>
                  )}
                  {importSelectedSheet && !importSheets && (
                    <p className="mt-1.5 text-xs text-muted-foreground">
                      Sheet: <span className="font-medium text-gold">{importSelectedSheet}</span>
                    </p>
                  )}
                </div>

                {importError && <p className="text-xs text-red-500">{importError}</p>}

                <div className="flex gap-2">
                  <Button className="bg-gold hover:bg-gold/90 text-black flex-1"
                    disabled={!importClientId || !importFile || importing || inspecting || (!!importSheets && !importSelectedSheet)}
                    onClick={async () => {
                      if (!importClientId || !importFile) return;
                      setImporting(true); setImportError("");
                      try {
                        const res = await api.importProjects(Number(importClientId), importFile, undefined, importSelectedSheet ?? undefined) as any;
                        if (res.requires_confirmation) {
                          setImportDuplicates(res.duplicates);
                        } else {
                          setImportResult(res);
                        }
                      } catch (e: any) {
                        setImportError(e?.message || "Import failed.");
                      } finally { setImporting(false); }
                    }}>
                    {importing ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Checking…</>
                      : inspecting ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Reading file…</>
                      : (importSheets && !importSelectedSheet) ? "Select a sheet above"
                      : <><Upload className="h-4 w-4 mr-2" />Import Cases</>}
                  </Button>
                  <Button variant="outline" onClick={() => setShowImport(false)}>Cancel</Button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── DocketTrak Import Modal ──────────────────────────────────────────── */}
      {showDocketImport && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl p-6 m-4 max-h-[90vh] overflow-y-auto">
            <div className="flex items-center justify-between mb-5">
              <div>
                <h2 className="font-display text-lg font-semibold">Import from DocketTrak</h2>
                <p className="text-xs text-muted-foreground mt-0.5">Upload the DocketTrak Excel export (.xlsx) to bulk-create cases.</p>
              </div>
              <button onClick={() => setShowDocketImport(false)}><X className="h-5 w-5 text-muted-foreground" /></button>
            </div>

            {docketResult ? (
              <div className="space-y-4">
                <div className="flex items-center gap-3 p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/20">
                  <CheckCircle className="h-6 w-6 text-emerald-500 flex-shrink-0" />
                  <div>
                    <p className="font-semibold text-sm">Import complete</p>
                    <p className="text-xs text-muted-foreground mt-0.5">
                      {docketResult.imported} case{docketResult.imported !== 1 ? "s" : ""} imported,{" "}
                      {docketResult.skipped} skipped.
                    </p>
                  </div>
                </div>
                {docketResult.errors.length > 0 && (
                  <div className="rounded-lg border border-destructive/20 bg-destructive/5 p-3 max-h-40 overflow-y-auto">
                    <p className="text-xs font-semibold text-destructive mb-1">Errors ({docketResult.errors.length})</p>
                    {docketResult.errors.map((e, i) => (
                      <p key={i} className="text-xs text-destructive font-mono">{e}</p>
                    ))}
                  </div>
                )}
                <div className="flex gap-2">
                  <Button className="flex-1" onClick={() => { setShowDocketImport(false); loadProjects(roleFilter); }}>
                    <CheckCircle className="h-4 w-4 mr-2" />Done — View Cases
                  </Button>
                  <Button variant="outline" onClick={() => {
                    setDocketFile(null); setDocketImportPreview(null); setDocketResult(null); setDocketError("");
                  }}>Import Another</Button>
                </div>
              </div>
            ) : docketImportPreview ? (
              <div className="space-y-4">
                <div className="grid grid-cols-3 gap-3">
                  {([
                    { label: "Total rows", value: docketImportPreview.total, color: "text-foreground" },
                    { label: "Importable", value: docketImportPreview.importable, color: "text-emerald-500" },
                    { label: "Skipped/empty", value: docketImportPreview.skipped, color: "text-muted-foreground" },
                    { label: "Abandoned", value: docketImportPreview.abandoned, color: "text-orange-400" },
                    { label: "Granted", value: docketImportPreview.granted, color: "text-blue-400" },
                    { label: "Known clients", value: docketImportPreview.known_clients, color: "text-emerald-400" },
                  ] as const).map((s) => (
                    <div key={s.label} className="rounded-lg border border-border p-3 text-center">
                      <p className={`text-2xl font-bold font-mono ${s.color}`}>{s.value}</p>
                      <p className="text-xs text-muted-foreground mt-0.5">{s.label}</p>
                    </div>
                  ))}
                </div>

                {docketImportPreview.unknown_clients?.length > 0 && (
                  <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3">
                    <p className="text-xs font-semibold text-amber-400 mb-1">
                      {docketImportPreview.unknown_clients.length} clients not found in system — will be auto-created
                    </p>
                    <div className="max-h-28 overflow-y-auto space-y-0.5">
                      {docketImportPreview.unknown_clients.map((c: string) => (
                        <p key={c} className="text-xs text-muted-foreground font-mono">{c}</p>
                      ))}
                    </div>
                  </div>
                )}

                {docketImportPreview.docket_conflicts?.length > 0 && (
                  <div className="rounded-lg border border-destructive/20 bg-destructive/5 p-3">
                    <p className="text-xs font-semibold text-destructive mb-1">
                      {docketImportPreview.docket_conflicts.length} docket conflicts (will be skipped)
                    </p>
                    <div className="max-h-20 overflow-y-auto">
                      {docketImportPreview.docket_conflicts.map((d: string) => (
                        <p key={d} className="text-xs font-mono text-muted-foreground">{d}</p>
                      ))}
                    </div>
                  </div>
                )}

                <div className="flex items-center gap-2 p-3 rounded-lg border border-border">
                  <input
                    type="checkbox" id="skipTransferred" checked={docketSkipTransferred}
                    onChange={(e) => setDocketSkipTransferred(e.target.checked)}
                    className="h-4 w-4 accent-gold"
                  />
                  <label htmlFor="skipTransferred" className="text-sm cursor-pointer">
                    Skip cases where client changed attorney (recommended)
                  </label>
                </div>

                <div className="flex items-center gap-2 p-3 rounded-lg border border-border">
                  <input
                    type="checkbox" id="skipUnknown" checked={docketSkipUnknown}
                    onChange={(e) => setDocketSkipUnknown(e.target.checked)}
                    className="h-4 w-4 accent-gold"
                  />
                  <label htmlFor="skipUnknown" className="text-sm cursor-pointer">
                    Skip cases where client is not found in system
                  </label>
                </div>

                {docketMappingFile && (
                  <div className="flex items-center gap-2 p-3 rounded-lg border border-green-500/40 bg-green-500/5 text-xs text-green-400">
                    <svg className="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Mapping file: <span className="font-medium text-green-300 ml-1">{docketMappingFile.name}</span>
                  </div>
                )}

                {docketError && <p className="text-xs text-red-500">{docketError}</p>}

                <div className="flex gap-2">
                  <Button className="bg-gold hover:bg-gold/90 text-black flex-1"
                    disabled={docketImporting}
                    onClick={async () => {
                      if (!docketFile) return;
                      setDocketImporting(true); setDocketError("");
                      try {
                        const res = await api.docketImport(docketFile, {
                          skip_conflicts: true,
                          skip_transferred: docketSkipTransferred,
                          skip_unknown_clients: docketSkipUnknown,
                          mapping_file: docketMappingFile,
                        });
                        setDocketResult(res);
                      } catch (e: any) {
                        setDocketError(e?.message || "Import failed.");
                      } finally { setDocketImporting(false); }
                    }}>
                    {docketImporting ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Importing…</> : <><Upload className="h-4 w-4 mr-2" />Confirm Import</>}
                  </Button>
                  <Button variant="outline" onClick={() => { setDocketImportPreview(null); setDocketFile(null); }}>Back</Button>
                </div>
              </div>
            ) : (
              <div className="space-y-4">
                {/* ── DocketTrak file ── */}
                <div>
                  <p className="text-xs font-semibold text-muted-foreground mb-2 uppercase tracking-wide">Step 1 — DocketTrak export</p>
                  <div className="border-2 border-dashed border-border rounded-lg p-6 text-center hover:border-gold/50 transition-colors">
                    <FileSpreadsheet className="h-9 w-9 mx-auto text-muted-foreground mb-2" />
                    <p className="text-sm font-medium mb-1">Select DocketTrak Excel export</p>
                    <p className="text-xs text-muted-foreground mb-3">The .xlsx exported from DocketTrak (up to 3,000 rows)</p>
                    <input
                      type="file" accept=".xlsx,.xls" className="hidden" id="docket-file-input"
                      onChange={(e) => setDocketFile(e.target.files?.[0] ?? null)}
                    />
                    <label htmlFor="docket-file-input">
                      <Button variant="outline" size="sm" asChild>
                        <span className="cursor-pointer"><Upload className="h-3.5 w-3.5 mr-1.5" />Choose file</span>
                      </Button>
                    </label>
                    {docketFile && (
                      <p className="mt-2 text-xs text-emerald-400 font-mono">{docketFile.name}</p>
                    )}
                  </div>
                </div>

                {/* ── Mapping file ── */}
                <div>
                  <p className="text-xs font-semibold text-muted-foreground mb-2 uppercase tracking-wide">Step 2 — Client mapping file <span className="normal-case font-normal">(optional)</span></p>
                  <div className={`border-2 border-dashed rounded-lg p-4 text-center transition-colors ${docketMappingFile ? 'border-emerald-500/40 bg-emerald-500/5' : 'border-border hover:border-blue-400/50'}`}>
                    <p className="text-xs text-muted-foreground mb-2">
                      Upload the <span className="font-mono text-foreground">ClientMapping.xlsx</span> with column B filled in to resolve unmatched clients
                    </p>
                    <input
                      type="file" accept=".xlsx,.xls" className="hidden" id="docket-mapping-input"
                      onChange={(e) => setDocketMappingFile(e.target.files?.[0] ?? null)}
                    />
                    <label htmlFor="docket-mapping-input">
                      <Button variant="outline" size="sm" asChild>
                        <span className="cursor-pointer"><Upload className="h-3.5 w-3.5 mr-1.5" />{docketMappingFile ? 'Change mapping file' : 'Choose mapping file'}</span>
                      </Button>
                    </label>
                    {docketMappingFile && (
                      <p className="mt-1.5 text-xs text-emerald-400 font-mono">{docketMappingFile.name}</p>
                    )}
                  </div>
                </div>

                {docketError && <p className="text-xs text-red-500">{docketError}</p>}

                <div className="flex gap-2">
                  <Button className="bg-gold hover:bg-gold/90 text-black flex-1"
                    disabled={!docketFile || docketPreviewing}
                    onClick={async () => {
                      if (!docketFile) return;
                      setDocketPreviewing(true); setDocketError("");
                      try {
                        const res = await api.docketImportPreview(docketFile, docketMappingFile);
                        setDocketImportPreview(res);
                      } catch (e: any) {
                        setDocketError(e?.message || "Preview failed.");
                      } finally { setDocketPreviewing(false); }
                    }}>
                    {docketPreviewing ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Analysing…</> : "Analyse File"}
                  </Button>
                  <Button variant="outline" onClick={() => { setShowDocketImport(false); setDocketMappingFile(null); }}>Cancel</Button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── Case Form Modal ─────────────────────────────────────────────────── */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-3xl max-h-[92vh] flex flex-col">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <div>
                <h2 className="font-display text-lg font-semibold">
                  {editProj ? "Edit Case" : "New Case"}
                </h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  {editProj
                    ? <>Docket: <span className="font-mono text-gold font-semibold">{editProj.docket_number ?? editProj.project_code}</span></>
                    : form.record_mode === "existing"
                      ? <>Legacy matter: enter the existing case ID once. It will be saved as both project code and UIN.</>
                      : <>Docket preview: <span className="font-mono text-gold font-semibold">{docketPreview}</span></>}
                </p>
              </div>
              <button onClick={() => setShowModal(false)}>
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>

            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
              {saveErr && (
                <div className="flex items-center gap-2 p-3 rounded-md bg-destructive/10 border border-destructive/20 text-xs text-destructive">
                  <AlertCircle className="h-4 w-4 flex-shrink-0" />{saveErr}
                </div>
              )}

              {/* ── 1. Case Identification ───────────────────────────── */}
              {!editProj && (
                <Section title="Record Type">
                  <div className={`grid gap-3 ${featLegacyCase ? "grid-cols-2" : "grid-cols-1"}`}>
                    {([
                      { key: "new",      label: "New Case",             hint: "Generate project code and UIN automatically.", always: true },
                      { key: "existing", label: "Existing / Legacy Case", hint: "Enter the existing case ID once.",           always: false },
                    ] as const).filter((o) => o.always || featLegacyCase).map((option) => (
                      <button
                        key={option.key}
                        type="button"
                        onClick={() => sf("record_mode", option.key)}
                        className={`rounded-lg border-2 px-4 py-3 text-left transition-all ${
                          form.record_mode === option.key
                            ? "border-gold bg-gold/10 text-foreground"
                            : "border-border text-muted-foreground hover:border-gold/40"
                        }`}
                      >
                        <div className="text-sm font-medium">{option.label}</div>
                        <div className="mt-1 text-xs">{option.hint}</div>
                      </button>
                    ))}
                  </div>
                  {form.record_mode === "existing" && (
                    <div>
                      <Lbl req>Existing Case ID / UIN / Docket</Lbl>
                      <input
                        value={form.project_code || form.docket_number}
                        onChange={(e) => {
                          const value = e.target.value.toUpperCase();
                          sf("project_code", value);
                          sf("docket_number", value);
                          const client = clients.find((item) => String(item.id) === form.client_id);
                          const clientCode = client?.client_code?.toUpperCase() ?? "";
                          if (clientCode && value.startsWith(clientCode) && value.length === clientCode.length + 8) {
                            sf("patent_office_code", value.slice(clientCode.length + 3, clientCode.length + 5));
                            sf("service_code", value.slice(-3));
                          }
                        }}
                        className={ic}
                        placeholder="e.g. A97M001INFER"
                      />
                    </div>
                  )}
                </Section>
              )}

              <Section title="Case Identification">
                <div className="grid grid-cols-2 gap-3">
                  <div className="col-span-2">
                    <Lbl req>Patent Title / Case Name</Lbl>
                    <input value={form.project_name}
                      onChange={(e) => sf("project_name", e.target.value)}
                      placeholder="e.g. Compact Lithium Cell with Enhanced Electrolyte"
                      className={ic} />
                  </div>
                  <div>
                    <Lbl req>Client Code</Lbl>
                    <Combobox
                      value={form.client_id}
                      options={clientOptions}
                      onSelect={(v) => {
                        sf("client_id", v);
                        const cl = clients.find((c) => String(c.id) === v);
                        if (cl?.account_manager_id) sf("assigned_manager_id", String(cl.account_manager_id));
                      }}
                      placeholder="Search client code or name…"
                    />
                  </div>
                  <div>
                    <Lbl>Case Type</Lbl>
                    <select value={form.case_type} onChange={(e) => sf("case_type", e.target.value)} className={ic}>
                      {CASE_TYPES.map((t) => <option key={t}>{t}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>Circle</Lbl>
                    <div className="flex gap-2 mt-0.5">
                      {["A", "B"].map((c) => (
                        <button
                          key={c}
                          type="button"
                          onClick={() => sf("circle", form.circle === c ? "" : c)}
                          className={`flex-1 py-1.5 rounded-md text-sm font-semibold border transition-colors ${
                            form.circle === c
                              ? c === "A" ? "bg-blue-600 text-white border-blue-600" : "bg-violet-600 text-white border-violet-600"
                              : "border-border text-muted-foreground hover:bg-muted/40"
                          }`}
                        >
                          Circle {c}
                        </button>
                      ))}
                    </div>
                  </div>
                  <div>
                    <Lbl>Application Number</Lbl>
                    <input value={form.application_number}
                      onChange={(e) => sf("application_number", e.target.value)}
                      placeholder="e.g. 202341001234"
                      className={ic} />
                  </div>
                  {editProj && (
                    <div>
                      <Lbl>Status</Lbl>
                      <select value={form.status} onChange={(e) => {
                        const s = e.target.value;
                        sf("status", s);
                        if (s === "Granted") sf("patent_granted", true);
                        else if (form.patent_granted) sf("patent_granted", false);
                      }} className={ic}>
                        {STATUSES.map((s) => <option key={s}>{s}</option>)}
                      </select>
                    </div>
                  )}
                </div>
              </Section>

              {/* ── 2. IP Details ────────────────────────────────────── */}
              <Section title="IP Details">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <Lbl req>Patent Office (Country)</Lbl>
                    <CodeCombobox
                      value={form.patent_office_code}
                      baseOptions={officeOptions}
                      codeType="office"
                      onSelect={(v) => sf("patent_office_code", v)}
                      placeholder="Search office…"
                      locked={featLockCodeDropdown}
                    />
                  </div>
                  <div>
                    <Lbl req>Service Code</Lbl>
                    <CodeCombobox
                      value={form.service_code}
                      baseOptions={serviceOptions}
                      codeType="service"
                      onSelect={(v) => sf("service_code", v)}
                      placeholder="Search service code…"
                      locked={featLockCodeDropdown}
                    />
                  </div>
                  <div>
                    <Lbl>Inventor Name</Lbl>
                    <input value={form.invention_title}
                      onChange={(e) => sf("invention_title", e.target.value)}
                      placeholder="e.g. Rajesh Kumar, Priya Sharma"
                      className={ic} />
                  </div>
                  <div>
                    <Lbl>Technology Field</Lbl>
                    <input value={form.technology_field}
                      onChange={(e) => sf("technology_field", e.target.value)}
                      placeholder="e.g. Biotechnology, Semiconductors"
                      className={ic} />
                  </div>
                </div>
              </Section>

              {/* ── 3. Dates & Status ────────────────────────────────── */}
              <Section title="Dates & Status">
                <div className="grid grid-cols-3 gap-3">
                  <div>
                    <Lbl>Filing Date</Lbl>
                    <input type="date" value={form.filing_date}
                      onChange={(e) => sf("filing_date", e.target.value)} className={ic} />
                  </div>
                  <div className="flex items-end pb-1">
                    <label className="flex items-center gap-2 cursor-pointer select-none">
                      <input type="checkbox" checked={form.patent_granted}
                        onChange={(e) => {
                          const checked = e.target.checked;
                          sf("patent_granted", checked);
                          if (checked && form.status !== "Granted") sf("status", "Granted");
                          else if (!checked && form.status === "Granted") sf("status", "Open");
                        }}
                        className="h-4 w-4 rounded border-border accent-green-600" />
                      <span className="text-xs font-medium text-foreground">Patent Granted</span>
                    </label>
                  </div>
                  <div>
                    <Lbl>Target Filing Date</Lbl>
                    <input type="date" value={form.target_filing_date}
                      onChange={(e) => sf("target_filing_date", e.target.value)} className={ic} />
                  </div>
                  <div>
                    <Lbl>Hard Deadline</Lbl>
                    <input type="date" value={form.hard_deadline}
                      onChange={(e) => sf("hard_deadline", e.target.value)} className={ic} />
                  </div>
                  <div>
                    <Lbl>Urgency</Lbl>
                    <select value={form.urgency} onChange={(e) => sf("urgency", e.target.value)} className={ic}>
                      {URGENCIES.map((u) => <option key={u}>{u}</option>)}
                    </select>
                  </div>
                  <div>
                    <Lbl>IDF Received Date</Lbl>
                    <input type="date" value={form.idf_received_date}
                      onChange={(e) => sf("idf_received_date", e.target.value)} className={ic} />
                  </div>
                  <div>
                    <Lbl>Advance Payment Received</Lbl>
                    <input type="date" value={form.advance_payment_date}
                      onChange={(e) => sf("advance_payment_date", e.target.value)} className={ic} />
                  </div>
                  <div>
                    <Lbl>Partial Payment Date</Lbl>
                    <input type="date" value={form.partial_payment_date}
                      onChange={(e) => sf("partial_payment_date", e.target.value)} className={ic} />
                  </div>
                  <div>
                    <Lbl>Full Payment Received</Lbl>
                    <input type="date" value={form.full_payment_date}
                      onChange={(e) => sf("full_payment_date", e.target.value)} className={ic} />
                  </div>
                </div>
              </Section>

              {/* ── 4. Team Assignment ───────────────────────────────── */}
              <Section title="Team Assignment">
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <Lbl>Attorney (Partner)</Lbl>
                    <Combobox
                      value={form.assigned_partner_id}
                      options={staffOptions}
                      onSelect={(v) => sf("assigned_partner_id", v)}
                      placeholder="Select attorney…"
                    />
                  </div>
                  <div>
                    <Lbl>Client Manager</Lbl>
                    <Combobox
                      value={form.assigned_manager_id}
                      options={staffOptions}
                      onSelect={(v) => sf("assigned_manager_id", v)}
                      placeholder="Select client manager…"
                    />
                  </div>
                  <div>
                    <Lbl>Secondary Client Manager</Lbl>
                    <Combobox
                      value={form.secondary_manager_id}
                      options={staffOptions}
                      onSelect={(v) => sf("secondary_manager_id", v)}
                      placeholder="Select secondary manager…"
                    />
                  </div>
                  <div>
                    <Lbl>Patent Engineer</Lbl>
                    <Combobox
                      value={form.patent_engineer_id}
                      options={staffOptions}
                      onSelect={(v) => sf("patent_engineer_id", v)}
                      placeholder="Select patent engineer…"
                    />
                  </div>
                </div>
              </Section>

              {/* ── 5. Notes ─────────────────────────────────────────── */}
              <Section title="Notes" open={false}>
                <textarea
                  value={form.notes}
                  onChange={(e) => sf("notes", e.target.value)}
                  rows={4} className={tc}
                  placeholder="Internal notes, client instructions, special requirements…"
                />
              </Section>
            </div>

            <div className="flex gap-2 px-6 py-4 border-t border-border flex-shrink-0 justify-end">
              <Button variant="outline" onClick={() => setShowModal(false)}>Cancel</Button>
              <Button className="bg-gold hover:bg-gold/90 text-black min-w-[140px]" onClick={handleSave} disabled={saving}>
                {saving ? <><Loader2 className="h-4 w-4 animate-spin mr-2" />Saving…</> : editProj ? "Save Changes" : "Create Case"}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* ── Stage Picker Portal ────────────────────────────────────────────── */}
      {/* ── Workflow Stage Picker Portal ───────────────────────────────────── */}
      {stageMenu && createPortal(
        <>
          <div className="fixed inset-0 z-[9998]" onMouseDown={() => setStageMenu(null)} />
          <div
            className="z-[9999] bg-white rounded-lg shadow-xl border border-border py-1"
            style={(() => {
              const MENU_H = 300;
              const { rect } = stageMenu;
              const spaceBelow = window.innerHeight - rect.bottom;
              const openUp = spaceBelow < MENU_H && rect.top > spaceBelow;
              return openUp
                ? { position: "fixed" as const, bottom: window.innerHeight - rect.top + 4, left: rect.left, minWidth: Math.max(rect.width, 240) }
                : { position: "fixed" as const, top: rect.bottom + 4, left: rect.left, minWidth: Math.max(rect.width, 240) };
            })()}
            onMouseDown={(e) => e.stopPropagation()}
          >
            <div className="px-3 py-1.5 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider border-b border-border bg-muted/30">
              Set Workflow Stage
            </div>
            <div className="px-2 pt-2 pb-1 border-b border-border">
              <input
                autoFocus
                type="text"
                placeholder="Search stages…"
                value={pickerSearch}
                onChange={(e) => setPickerSearch(e.target.value)}
                className="w-full text-[11px] px-2 py-1 rounded border border-border bg-white outline-none focus:border-blue-400 placeholder:text-gray-400"
                onMouseDown={(e) => e.stopPropagation()}
              />
            </div>
            <div className="overflow-y-auto" style={{ maxHeight: 236 }}>
              {stageMenu.stages.filter((s) => s.toLowerCase().includes(pickerSearch.toLowerCase())).map((s) => {
                const currentProject = projects.find((p) => p.id === stageMenu.projectId);
                const activeStage = currentProject?.stages?.find((st: any) => st.status === "In Progress")?.stage_name;
                const isCurrent = activeStage === s;
                return (
                  <button
                    key={s}
                    className={`w-full text-left px-3 py-2 text-[12px] flex items-center gap-2 hover:bg-blue-50 transition-colors ${isCurrent ? "bg-blue-50 font-medium text-blue-700" : "text-gray-700"}`}
                    onMouseDown={async (e) => {
                      e.preventDefault();
                      const pid = stageMenu.projectId;
                      setStageMenu(null);
                      try { await api.updateProjectStage(pid, s); } catch {}
                      setProjects((prev) => prev.map((p) => {
                        if (p.id !== pid) return p;
                        const stageNames = stagesForProject(p);
                        const existing = p.stages && p.stages.length > 0
                          ? p.stages
                          : stageNames.map((name: string, i: number) => ({ stage_name: name, status: "Pending", sequence_order: i }));
                        const targetIdx = stageNames.indexOf(s);
                        return {
                          ...p,
                          stages: stageNames.map((name: string, i: number) => ({
                            ...(existing.find((st: any) => st.stage_name === name) ?? { stage_name: name, sequence_order: i }),
                            status: i < targetIdx ? "Completed" : i === targetIdx ? "In Progress" : "Pending",
                          })),
                        };
                      }));
                    }}
                  >
                    {s}
                    {isCurrent && <span className="ml-auto text-blue-500">✓</span>}
                  </button>
                );
              })}
            </div>
          </div>
        </>,
        document.body
      )}

      {/* ── Status Picker Portal ───────────────────────────────────────────── */}
      {statusMenu && createPortal(
        <>
          <div className="fixed inset-0 z-[9998]" onMouseDown={() => setStatusMenu(null)} />
          <div
            className="z-[9999] bg-white rounded-lg shadow-xl border border-border py-1"
            style={(() => {
              const MENU_H = 220;
              const { rect } = statusMenu;
              const spaceBelow = window.innerHeight - rect.bottom;
              const openUp = spaceBelow < MENU_H && rect.top > spaceBelow;
              return openUp
                ? { position: "fixed" as const, bottom: window.innerHeight - rect.top + 4, left: rect.left, minWidth: Math.max(rect.width, 160) }
                : { position: "fixed" as const, top: rect.bottom + 4, left: rect.left, minWidth: Math.max(rect.width, 160) };
            })()}
            onMouseDown={(e) => e.stopPropagation()}
          >
            <div className="px-3 py-1.5 text-[10px] font-semibold text-muted-foreground uppercase tracking-wider border-b border-border bg-muted/30">
              Set Status
            </div>
            <div className="overflow-y-auto" style={{ maxHeight: 188 }}>
              {STATUSES.map((s) => {
                const currentProject = projects.find((p) => p.id === statusMenu.projectId);
                const isCurrent = currentProject?.status === s;
                return (
                  <button
                    key={s}
                    className={`w-full text-left px-3 py-2 text-[12px] flex items-center gap-2 hover:bg-blue-50 transition-colors ${isCurrent ? "bg-blue-50 font-medium text-blue-700" : "text-gray-700"}`}
                    onMouseDown={async (e) => {
                      e.preventDefault();
                      const pid = statusMenu.projectId;
                      setStatusMenu(null);
                      const updates: any = { status: s };
                      if (s === "Granted") updates.patent_granted = true;
                      else updates.patent_granted = false;
                      try { await api.updateProject(pid, updates); } catch {}
                      setProjects((prev) => prev.map((p) => p.id === pid
                        ? { ...p, status: s, patent_granted: s === "Granted" }
                        : p));
                    }}
                  >
                    {s}
                    {isCurrent && <span className="ml-auto text-blue-500">✓</span>}
                  </button>
                );
              })}
            </div>
          </div>
        </>,
        document.body
      )}

      {/* ── Delete Confirm ─────────────────────────────────────────────────── */}
      {delTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
            <div className="flex items-center gap-3 mb-4">
              <div className="h-10 w-10 rounded-full bg-destructive/10 flex items-center justify-center flex-shrink-0">
                <Trash2 className="h-5 w-5 text-destructive" />
              </div>
              <div>
                <h3 className="font-semibold">Delete Case</h3>
                <p className="text-sm text-muted-foreground mt-0.5">
                  Delete <strong>{delTarget.project_name}</strong> ({delTarget.docket_number ?? delTarget.project_code})?
                  All stages and tasks will be removed.
                </p>
              </div>
            </div>
            <div className="flex gap-2 justify-end">
              <Button variant="outline" onClick={() => setDelTarget(null)}>Cancel</Button>
              <Button variant="destructive" onClick={handleDelete} disabled={deleting}>
                {deleting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Delete"}
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* ── Elevate Service Modal ─────────────────────────────────────────── */}
      {elevateModal && (() => {
        const svc = (elevateModal.project.service_code
          ?? elevateModal.project.docket_number?.slice(-3)
          ?? "").toUpperCase();
        return (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
            <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-sm p-6 m-4">
              <div className="flex items-center gap-3 mb-4">
                <div className="h-10 w-10 rounded-full bg-amber-500/10 flex items-center justify-center flex-shrink-0">
                  <ArrowUpCircle className="h-5 w-5 text-amber-500" />
                </div>
                <div>
                  <h3 className="font-semibold">Change Service</h3>
                  <p className="text-sm text-muted-foreground mt-0.5">
                    <span className="font-mono text-xs">{elevateModal.project.docket_number}</span>
                    {svc ? <> — currently <strong>{svc}</strong></> : " — no service code set"}
                  </p>
                </div>
              </div>
              {elevateErr && <p className="text-sm text-destructive mb-3">{elevateErr}</p>}
              <div className="space-y-3">
                <div>
                  <label className="text-xs font-medium text-muted-foreground mb-1 block">Move to service</label>
                  <select
                    value={elevateToService}
                    onChange={(e) => setElevateToService(e.target.value)}
                    className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                  >
                    <option value="">Select service…</option>
                    {SERVICE_CODES.map((s) => (
                      <option key={s.code} value={s.code}>{s.label}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="text-xs font-medium text-muted-foreground mb-1 block">Note (optional)</label>
                  <textarea
                    value={elevateNote}
                    onChange={(e) => setElevateNote(e.target.value)}
                    rows={2}
                    placeholder="Reason for change…"
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-gold"
                  />
                </div>
              </div>
              <div className="flex gap-2 justify-end mt-4">
                <Button variant="outline" onClick={() => setElevateModal(null)}>Cancel</Button>
                <Button
                  className="bg-amber-500 hover:bg-amber-600 text-black"
                  onClick={handleElevate}
                  disabled={elevateSaving || !elevateToService}
                >
                  {elevateSaving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                  Confirm
                </Button>
              </div>
            </div>
          </div>
        );
      })()}

      {/* ── Link Predecessor Modal ────────────────────────────────────────── */}
      {linkModal && (() => {
        const clientId = linkModal.project.client_id;
        const candidateProjects = projects.filter(
          (p) => p.id !== linkModal.project.id && p.client_id === clientId
        );
        const filtered = linkSearch.trim()
          ? candidateProjects.filter((p) =>
              (p.docket_number ?? "").toLowerCase().includes(linkSearch.toLowerCase()) ||
              (p.project_type ?? "").toLowerCase().includes(linkSearch.toLowerCase()) ||
              (p.service_code ?? "").toLowerCase().includes(linkSearch.toLowerCase())
            )
          : candidateProjects;
        return (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
            <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md p-6 m-4">
              <div className="flex items-center gap-3 mb-4">
                <div className="h-10 w-10 rounded-full bg-teal-500/10 flex items-center justify-center flex-shrink-0">
                  <Link2 className="h-5 w-5 text-teal-500" />
                </div>
                <div>
                  <h3 className="font-semibold">Link Predecessor</h3>
                  <p className="text-sm text-muted-foreground mt-0.5">
                    Choose the predecessor case for <span className="font-mono text-xs">{linkModal.project.docket_number}</span>
                  </p>
                </div>
              </div>
              {linkErr && <p className="text-sm text-destructive mb-3">{linkErr}</p>}
              <div className="space-y-3">
                <input
                  type="text"
                  placeholder="Search by docket or service code…"
                  value={linkSearch}
                  onChange={(e) => setLinkSearch(e.target.value)}
                  className="w-full h-9 rounded-md border border-border bg-background px-3 text-sm focus:outline-none focus:ring-1 focus:ring-gold"
                />
                <div className="max-h-48 overflow-y-auto rounded-md border border-border divide-y divide-border">
                  {filtered.length === 0 ? (
                    <p className="px-3 py-4 text-sm text-muted-foreground text-center">No cases found for this client.</p>
                  ) : filtered.map((p) => (
                    <button
                      key={p.id}
                      onClick={() => setLinkPredecessorId(p.id === linkPredecessorId ? null : p.id)}
                      className={`w-full text-left px-3 py-2.5 flex items-center justify-between hover:bg-muted/50 transition-colors ${linkPredecessorId === p.id ? "bg-teal-500/10" : ""}`}
                    >
                      <div>
                        <span className="font-mono text-xs text-gold">{p.docket_number}</span>
                        <span className="ml-2 text-xs text-muted-foreground">{p.service_code} · {p.project_type}</span>
                      </div>
                      {linkPredecessorId === p.id && <CheckCircle className="h-4 w-4 text-teal-500 shrink-0" />}
                    </button>
                  ))}
                </div>
                <div>
                  <label className="text-xs font-medium text-muted-foreground mb-1 block">Note (optional)</label>
                  <textarea
                    value={linkNote}
                    onChange={(e) => setLinkNote(e.target.value)}
                    rows={2}
                    placeholder="Context for this link…"
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm resize-none focus:outline-none focus:ring-1 focus:ring-gold"
                  />
                </div>
              </div>
              <div className="flex gap-2 justify-end mt-4">
                <Button variant="outline" onClick={() => setLinkModal(null)}>Cancel</Button>
                <Button
                  className="bg-teal-600 hover:bg-teal-700 text-white"
                  onClick={handleLinkPredecessor}
                  disabled={linkSaving || !linkPredecessorId}
                >
                  {linkSaving ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                  Link Predecessor
                </Button>
              </div>
            </div>
          </div>
        );
      })()}

      {/* ── Chain Detection Wizard ───────────────────────────────────────── */}
      {chainWizard !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-2xl m-4 flex flex-col max-h-[90vh]">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <div className="flex items-center gap-3">
                <div className="h-9 w-9 rounded-full bg-teal-500/10 flex items-center justify-center">
                  <GitMerge className="h-5 w-5 text-teal-500" />
                </div>
                <div>
                  <h3 className="font-semibold">Detect & Link Case Chains</h3>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    {chainWizard.total === 0
                      ? "No unlinked chains found — all existing cases are already linked."
                      : `${chainWizard.total} potential chain${chainWizard.total !== 1 ? "s" : ""} found. Review and confirm.`}
                  </p>
                </div>
              </div>
              <button onClick={() => setChainWizard(null)} className="p-1.5 rounded hover:bg-muted/50">
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Body */}
            <div className="flex-1 overflow-y-auto px-6 py-4 space-y-4">
              {chainResult ? (
                <div className="flex flex-col items-center justify-center py-12 text-center">
                  <CheckCircle className="h-12 w-12 text-green-500 mb-3" />
                  <p className="text-lg font-semibold">Chains linked successfully</p>
                  <p className="text-sm text-muted-foreground mt-1">
                    {chainResult.created} pair{chainResult.created !== 1 ? "s" : ""} linked
                    {chainResult.skipped ? ` · ${chainResult.skipped} skipped (already linked)` : ""}
                  </p>
                  <p className="text-xs text-muted-foreground mt-3">History tabs in the detail panel will now show the service chain.</p>
                </div>
              ) : chainWizard.total === 0 ? (
                <div className="flex flex-col items-center justify-center py-16 text-center">
                  <CheckCircle className="h-12 w-12 text-green-500 mb-3 opacity-50" />
                  <p className="text-muted-foreground text-sm">All existing cases with matching docket prefixes are already linked.</p>
                </div>
              ) : (
                chainWizard.chains.map((chain: any) => (
                  <div key={chain.prefix} className="rounded-lg border border-border p-4 space-y-3">
                    {/* Chain header */}
                    <div className="flex items-center gap-2 flex-wrap">
                      {(chain.members as any[]).map((m: any, i: number) => (
                        <div key={m.id} className="flex items-center gap-1.5">
                          {i > 0 && <span className="text-muted-foreground text-xs">→</span>}
                          <span className={`text-[10px] font-semibold px-2 py-0.5 rounded-full border ${
                            i === chain.members.length - 1 ? "border-gold text-gold bg-gold/10" : "border-border text-muted-foreground bg-muted/40"
                          }`}>{m.service_code}</span>
                          <span className="font-mono text-xs text-foreground">{m.docket}</span>
                        </div>
                      ))}
                    </div>

                    {/* Pair checkboxes */}
                    <div className="space-y-2">
                      {(chain.pairs as any[]).map((pair: any) => {
                        const key = `${pair.predecessor_id}-${pair.successor_id}`;
                        const checked = chainSelected.has(key);
                        return (
                          <label key={key} className="flex items-center gap-3 cursor-pointer group">
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => {
                                setChainSelected((prev) => {
                                  const next = new Set(prev);
                                  if (next.has(key)) next.delete(key); else next.add(key);
                                  return next;
                                });
                              }}
                              className="h-4 w-4 rounded border-border accent-teal-500"
                            />
                            <span className="text-xs text-muted-foreground group-hover:text-foreground transition-colors">
                              Link{" "}
                              <span className="font-mono text-gold font-semibold">{pair.predecessor_docket}</span>
                              <span className="mx-1 text-[10px]">({pair.predecessor_service})</span>
                              →{" "}
                              <span className="font-mono text-gold font-semibold">{pair.successor_docket}</span>
                              <span className="mx-1 text-[10px]">({pair.successor_service})</span>
                            </span>
                          </label>
                        );
                      })}
                    </div>
                  </div>
                ))
              )}
            </div>

            {/* Footer */}
            {!chainResult && chainWizard.total > 0 && (
              <div className="flex items-center justify-between px-6 py-4 border-t border-border flex-shrink-0">
                <span className="text-xs text-muted-foreground">
                  {chainSelected.size} pair{chainSelected.size !== 1 ? "s" : ""} selected
                </span>
                <div className="flex gap-2">
                  <Button variant="outline" onClick={() => setChainWizard(null)}>Cancel</Button>
                  <Button
                    className="bg-teal-600 hover:bg-teal-700 text-white"
                    onClick={handleBulkLink}
                    disabled={chainLinking || chainSelected.size === 0}
                  >
                    {chainLinking ? <Loader2 className="h-4 w-4 animate-spin mr-2" /> : null}
                    Link {chainSelected.size} Pair{chainSelected.size !== 1 ? "s" : ""}
                  </Button>
                </div>
              </div>
            )}
            {(chainResult || chainWizard.total === 0) && (
              <div className="flex justify-end px-6 py-4 border-t border-border flex-shrink-0">
                <Button variant="outline" onClick={() => setChainWizard(null)}>Close</Button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* ── KPI Cards ─────────────────────────────────────────────────────── */}
      <div className="px-8 pt-6 grid gap-4 grid-cols-2 md:grid-cols-4">
        {KPI_DEFS.map((kpi) => (
          <button
            key={kpi.key}
            onClick={() => setKpiModal(kpi)}
            className="rounded-xl border border-border bg-card p-5 text-left transition-all hover:shadow-md hover:border-gold/40 cursor-pointer"
          >
            <div className="text-xs font-medium uppercase tracking-wider text-muted-foreground">{kpi.label}</div>
            <div className={`mt-3 font-display text-3xl font-semibold tracking-tight ${kpi.color}`}>
              {stats[kpi.key] ?? 0}
            </div>
            <div className="mt-1 text-xs text-muted-foreground">Click to view</div>
          </button>
        ))}
      </div>

      {kpiModal && <ProjectKpiModal kpi={kpiModal} onClose={() => setKpiModal(null)} onOpenDetail={openDetail} />}

      {/* ── Table ─────────────────────────────────────────────────────────── */}
      <div className="px-8 py-6 space-y-4">
        <div className="flex flex-wrap items-center gap-3">
          <div className="relative max-w-sm flex-1">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search docket, title, application #, client…"
              value={search} onChange={(e) => setSearch(e.target.value)} className="pl-9" />
          </div>
          <div className="flex items-center gap-1.5 flex-wrap">
            {statuses.map((s) => (
              <button key={s} onClick={() => setFilter(s)}
                className={`px-3 h-8 text-xs rounded-md border transition-colors
                  ${filterStatus === s ? "bg-gold text-black border-gold font-semibold" : "border-border text-muted-foreground hover:text-foreground"}`}>
                {s}
              </button>
            ))}
          </div>
        </div>

        {loading ? (
          <div className="flex items-center justify-center py-20">
            <Loader2 className="h-8 w-8 animate-spin text-gold" />
          </div>
        ) : (
          <Card className="border-border">
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full text-sm min-w-[1100px]">
                  <thead className="bg-muted/40 text-xs uppercase tracking-wider text-muted-foreground">
                    <tr>
                      {!isClientUser && (
                        <th className="px-4 py-3 w-8">
                          <input type="checkbox" className="rounded border-border"
                            checked={paginated.length > 0 && paginated.every(p => selectedIds.includes(p.id))}
                            onChange={(e) => setSelectedIds(e.target.checked ? paginated.map(p => p.id) : [])} />
                        </th>
                      )}
                      <th className="px-4 py-3 text-left">Case ID</th>
                      <th className="px-4 py-3 text-left">Patent Title</th>
                      {!isClientUser && <th className="px-4 py-3 text-left">Circle</th>}
                      <th className="px-4 py-3 text-left">Country</th>
                      <th className="px-4 py-3 text-left">Filed</th>
                      <th className="px-4 py-3 text-left">Status</th>
                      <th className="px-4 py-3 text-left">Workflow Stage</th>
                      <th className="px-4 py-3 text-left">Deadline</th>
                      <th className="px-4 py-3 text-left">Client Manager</th>
                      <th className="px-4 py-3 text-left">Person Responsible</th>
                      {!isClientUser && <th className="px-4 py-3 text-left">Actions</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {paginated.map((p) => {
                      const stage = activeStage(p);
                      const isOverdue = p.hard_deadline && new Date(p.hard_deadline) < new Date();
                      return (
                        <tr key={p.id} className={`border-t border-border hover:bg-muted/30 ${selectedIds.includes(p.id) ? "bg-gold/5" : ""}`}>
                          {!isClientUser && (
                            <td className="px-4 py-3 w-8">
                              <input type="checkbox" className="rounded border-border"
                                checked={selectedIds.includes(p.id)}
                                onChange={(e) => setSelectedIds(prev => e.target.checked ? [...prev, p.id] : prev.filter(id => id !== p.id))} />
                            </td>
                          )}
                          <td className="px-4 py-3">
                            <Link href={`/projects/${p.id}`} className="block hover:opacity-75 transition-opacity">
                              <div className="font-mono text-xs text-gold font-semibold underline decoration-dotted underline-offset-2">
                                {p.docket_number ?? p.project_code ?? "—"}
                              </div>
                              {p.docket_number && p.project_code && p.project_code !== p.docket_number && (
                                <div className="text-[10px] text-muted-foreground font-mono">{p.project_code}</div>
                              )}
                              {(p as any).docket_trak_ref && (p as any).docket_trak_ref !== p.docket_number && (
                                <div className="text-[10px] text-muted-foreground/50 font-mono">DT: {(p as any).docket_trak_ref}</div>
                              )}
                            </Link>
                          </td>
                          <td className="px-4 py-3 max-w-[200px]">
                            <div className="font-medium truncate">{p.project_name}</div>
                            {p.invention_title && (
                              <div className="text-xs text-muted-foreground truncate">{p.invention_title}</div>
                            )}
                          </td>
                          {!isClientUser && (
                            <td className="px-4 py-3">
                              <button
                                title="Toggle circle (A / B)"
                                onClick={async (e) => {
                                  e.stopPropagation();
                                  const next = p.circle === "A" ? "B" : p.circle === "B" ? null : "A";
                                  setProjects((prev) => prev.map((x) => x.id === p.id ? { ...x, circle: next } : x));
                                  try { await api.updateProject(p.id, { circle: next } as any); } catch {}
                                }}
                                className={`text-[11px] font-bold w-7 h-7 rounded-full border-2 flex items-center justify-center transition-colors ${
                                  p.circle === "A" ? "bg-blue-600 text-white border-blue-600 hover:bg-blue-700" :
                                  p.circle === "B" ? "bg-violet-600 text-white border-violet-600 hover:bg-violet-700" :
                                  "border-dashed border-border text-muted-foreground hover:border-blue-400 hover:text-blue-500"
                                }`}
                              >
                                {p.circle ?? "—"}
                              </button>
                            </td>
                          )}
                          <td className="px-4 py-3">
                            {p.patent_office_code
                              ? <Badge variant="outline" className="text-[10px] font-mono">{p.patent_office_code}</Badge>
                              : <span className="text-muted-foreground text-xs">—</span>}
                          </td>
                          <td className="px-4 py-3 text-xs text-muted-foreground font-mono whitespace-nowrap">
                            {fmtDate(p.filing_date)}
                          </td>
                          <td className="px-4 py-3">
                            {isClientUser ? (
                              <Badge variant={statusColor(p.status)} className="text-[10px] whitespace-nowrap">
                                {p.status}
                              </Badge>
                            ) : (
                              <button
                                title="Click to change status"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
                                  setStatusMenu({ projectId: p.id, rect });
                                }}
                              >
                                <Badge variant={statusColor(p.status)} className="text-[10px] whitespace-nowrap cursor-pointer hover:opacity-80 transition-opacity">
                                  {p.status}
                                </Badge>
                              </button>
                            )}
                          </td>
                          <td className="px-4 py-3">
                            {(() => {
                              const isLocked = ['Granted', 'Refused', 'Abandoned'].includes(p.status);
                              if (isClientUser || isLocked) {
                                return (
                                  <span className={`text-xs ${isLocked ? 'text-muted-foreground/40 select-none' : ''}`}>
                                    {isLocked ? '—' : <Badge variant="secondary" className="text-[10px] whitespace-nowrap">{stage}</Badge>}
                                  </span>
                                );
                              }
                              return (
                                <button
                                  title="Click to change stage"
                                  onClick={(e) => {
                                    e.stopPropagation();
                                    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
                                    const stages = stagesForProject(p);
                                    setStageMenu({ projectId: p.id, stages, rect });
                                  }}
                                >
                                  <Badge variant="secondary" className="text-[10px] whitespace-nowrap cursor-pointer hover:bg-gold/20 hover:text-gold transition-colors">
                                    {stage}
                                  </Badge>
                                </button>
                              );
                            })()}
                          </td>
                          <td className={`px-4 py-3 text-xs font-mono whitespace-nowrap ${isOverdue ? "text-destructive font-semibold" : "text-muted-foreground"}`}>
                            {fmtDate(p.hard_deadline)}
                          </td>
                          <td className="px-4 py-3 text-xs text-muted-foreground whitespace-nowrap">
                            {managerName(p)}
                          </td>
                          <td className="px-4 py-3 text-xs text-muted-foreground whitespace-nowrap">
                            {patentEngineerName(p)}
                          </td>
                          {!isClientUser && (
                            <td className="px-4 py-3">
                              <div className="flex gap-1">
                                <Button size="sm" variant="outline" className="h-7 w-7 p-0 text-blue-500 border-blue-500/30 hover:bg-blue-500/10" title="Raise Invoice"
                                  onClick={() => {
                                    const isIndian = p.client?.gst_type !== "Export" && (p.client?.nationality ?? "india").toLowerCase() === "india";
                                    if (isIndian) router.visit(`/financial?india=invoice&project_id=${p.id}`);
                                    else alert("International (USD) invoicing for foreign clients is not yet available. Please raise manually in Financial Suite.");
                                  }}>
                                  <FileText className="h-3 w-3" />
                                </Button>
                                <Button size="sm" variant="outline" className="h-7 w-7 p-0 text-violet-500 border-violet-500/30 hover:bg-violet-500/10" title="Raise Quotation"
                                  onClick={() => {
                                    const isIndian = p.client?.gst_type !== "Export" && (p.client?.nationality ?? "india").toLowerCase() === "india";
                                    if (isIndian) router.visit(`/financial?india=quote&project_id=${p.id}`);
                                    else alert("International (USD) quotations for foreign clients are not yet available.");
                                  }}>
                                  <Scroll className="h-3 w-3" />
                                </Button>
                                <Button size="sm" variant="outline" className="h-7 w-7 p-0" title="Edit" onClick={() => openEdit(p)}>
                                  <Pencil className="h-3 w-3" />
                                </Button>
                                {canElevate && (
                                  <Button size="sm" variant="outline"
                                    className="h-7 w-7 p-0 text-amber-500 border-amber-500/30 hover:bg-amber-500/10"
                                    title="Change Service"
                                    onClick={() => { setElevateModal({ project: p }); setElevateToService(""); setElevateNote(""); setElevateErr(""); }}>
                                    <ArrowUpCircle className="h-3 w-3" />
                                  </Button>
                                )}
                                {canLinkPred && featLinkPred && (
                                  <Button size="sm" variant="outline"
                                    className="h-7 w-7 p-0 text-teal-500 border-teal-500/30 hover:bg-teal-500/10"
                                    title="Link Predecessor"
                                    onClick={() => { setLinkModal({ project: p }); setLinkSearch(""); setLinkPredecessorId(null); setLinkNote(""); setLinkErr(""); }}>
                                    <Link2 className="h-3 w-3" />
                                  </Button>
                                )}
                                <Button size="sm" variant="outline" title="Delete"
                                  className="h-7 w-7 p-0 text-destructive border-destructive/30 hover:bg-destructive/10"
                                  onClick={() => setDelTarget(p)}>
                                  <Trash2 className="h-3 w-3" />
                                </Button>
                              </div>
                            </td>
                          )}
                        </tr>
                      );
                    })}
                    {paginated.length === 0 && (
                      <tr>
                        <td colSpan={10} className="px-4 py-12 text-center text-muted-foreground">
                          {projects.length === 0 ? "No cases yet. Create your first case above." : "No cases match your search."}
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
                {/* Pagination bar */}
                {filtered.length > 0 && (
                  <div className="flex items-center justify-between px-4 py-3 border-t border-border text-xs text-muted-foreground">
                    <span>Showing {Math.min((page-1)*perPage+1, filtered.length)}–{Math.min(page*perPage, filtered.length)} of {filtered.length}</span>
                    <div className="flex items-center gap-3">
                      <span>Rows per page:</span>
                      {[10,25,50].map(n=>(
                        <button key={n} onClick={()=>{setPerPage(n);setPage(1);}}
                          className={`px-2 py-0.5 rounded border text-xs transition-colors ${perPage===n?"border-gold text-gold bg-gold/10":"border-border hover:border-gold/40"}`}>
                          {n}
                        </button>
                      ))}
                      <div className="flex items-center gap-1 ml-2">
                        <button disabled={page===1} onClick={()=>setPage(p=>p-1)}
                          className="px-2 py-0.5 rounded border border-border disabled:opacity-40 hover:bg-muted/40">‹</button>
                        {Array.from({length:Math.min(5,totalPages)},(_,i)=>{
                          const pg = totalPages<=5 ? i+1 : page<=3 ? i+1 : page>=totalPages-2 ? totalPages-4+i : page-2+i;
                          return <button key={pg} onClick={()=>setPage(pg)}
                            className={`px-2 py-0.5 rounded border text-xs ${pg===page?"border-gold bg-gold/10 text-gold":"border-border hover:bg-muted/40"}`}>{pg}</button>;
                        })}
                        <button disabled={page===totalPages} onClick={()=>setPage(p=>p+1)}
                          className="px-2 py-0.5 rounded border border-border disabled:opacity-40 hover:bg-muted/40">›</button>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {/* ── Raise Invoice / Quotation Modal ────────────────────────────────── */}
      {raiseModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div className="bg-background border border-border rounded-xl shadow-2xl w-full max-w-md flex flex-col">
            <div className="flex items-center justify-between px-6 py-4 border-b border-border">
              <div>
                <h2 className="font-display text-base font-semibold">
                  {raiseModal.type === "invoice" ? "Raise Invoice" : "Raise Quotation"}
                </h2>
                <p className="text-xs text-muted-foreground mt-0.5">
                  Case: <span className="font-mono text-gold font-semibold">{raiseModal.project.docket_number ?? raiseModal.project.project_code}</span>
                  {" · "}
                  {raiseModal.project.client?.legal_name ?? raiseModal.project.client?.company_name ?? `Client #${raiseModal.project.client_id}`}
                </p>
              </div>
              <button onClick={() => setRaiseModal(null)}>
                <X className="h-5 w-5 text-muted-foreground" />
              </button>
            </div>
            <div className="px-6 py-4 space-y-3">
              {raiseErr && (
                <div className="flex items-center gap-2 p-3 rounded-md bg-destructive/10 border border-destructive/20 text-xs text-destructive">
                  <AlertCircle className="h-3.5 w-3.5 flex-shrink-0" />{raiseErr}
                </div>
              )}
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Description / Service <span className="text-destructive">*</span></label>
                <input
                  value={raiseForm.description}
                  onChange={(e) => setRaiseForm((f) => ({ ...f, description: e.target.value }))}
                  placeholder="e.g. Patent Filing Service"
                  className={ic}
                />
              </div>
              <div>
                <label className="block text-xs text-muted-foreground mb-1">
                  {raiseModal.type === "invoice" ? "Amount (INR, excl. GST) *" : "Fee Amount (excl. GST) *"}
                </label>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={raiseForm.amount}
                  onChange={(e) => setRaiseForm((f) => ({ ...f, amount: e.target.value }))}
                  placeholder="0.00"
                  className={ic}
                />
              </div>
              {raiseModal.type === "invoice" && (
                <div>
                  <label className="block text-xs text-muted-foreground mb-1">Due Date</label>
                  <input
                    type="date"
                    value={raiseForm.due_date}
                    onChange={(e) => setRaiseForm((f) => ({ ...f, due_date: e.target.value }))}
                    className={ic}
                  />
                </div>
              )}
              <div>
                <label className="block text-xs text-muted-foreground mb-1">Notes</label>
                <textarea
                  value={raiseForm.notes}
                  onChange={(e) => setRaiseForm((f) => ({ ...f, notes: e.target.value }))}
                  rows={2}
                  placeholder="Optional notes…"
                  className={tc}
                />
              </div>
            </div>
            <div className="flex gap-2 px-6 py-4 border-t border-border">
              <Button
                className="flex-1 bg-gold hover:bg-gold/90 text-black"
                disabled={raiseSaving || !raiseForm.description.trim() || !raiseForm.amount}
                onClick={async () => {
                  if (!raiseForm.description.trim() || !raiseForm.amount) return;
                  setRaiseSaving(true); setRaiseErr("");
                  try {
                    const { project } = raiseModal;
                    const amount = parseFloat(raiseForm.amount);
                    if (raiseModal.type === "invoice") {
                      await api.createInvoice({
                        client_id: project.client_id,
                        project_id: project.id,
                        due_date: raiseForm.due_date || null,
                        notes: raiseForm.notes || null,
                        currency: "INR",
                        items: [{ description: raiseForm.description, quantity: 1, unit_rate: amount, amount, tax_rate: 18 }],
                      } as any);
                    } else {
                      const validUntil = new Date();
                      validUntil.setDate(validUntil.getDate() + 30);
                      await api.createQuotation({
                        client_id: project.client_id,
                        project_id: project.id,
                        total_amount: amount,
                        fee_structure: "Fixed Fee",
                        valid_until: validUntil.toISOString().split("T")[0],
                        currency: "INR",
                      } as any);
                    }
                    setRaiseModal(null);
                  } catch (e: any) {
                    setRaiseErr(e.message || "Failed to create. Please try again.");
                  } finally {
                    setRaiseSaving(false);
                  }
                }}
              >
                {raiseSaving ? <><Loader2 className="h-4 w-4 mr-2 animate-spin" />Saving…</> : raiseModal.type === "invoice" ? "Create Invoice" : "Create Quotation"}
              </Button>
              <Button variant="outline" onClick={() => setRaiseModal(null)}>Cancel</Button>
            </div>
          </div>
        </div>
      )}

      {/* Floating bulk action bar */}
      {!isClientUser && selectedIds.length > 0 && (
        <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 px-5 py-3 rounded-xl bg-background border border-gold/40 shadow-2xl backdrop-blur">
          <span className="text-sm font-medium text-gold">{selectedIds.length} selected</span>
          <div className="w-px h-5 bg-border" />
          <select className="h-8 rounded-md border border-border bg-background px-2 text-xs focus:outline-none focus:ring-1 focus:ring-gold"
            value={bulkAction} onChange={(e) => setBulkAction(e.target.value)}>
            <option value="">Choose action…</option>
            <option value="change_status:Active">Set Active</option>
            <option value="change_status:On Hold">Set On Hold</option>
            <option value="change_status:Completed">Set Completed</option>
            <option value="change_stage:Drafting">Move → Drafting</option>
            <option value="change_stage:Filing">Move → Filing</option>
            <option value="change_stage:Examination">Move → Examination</option>
            <option value="notify">Notify Managers</option>
            <option value="delete">Delete</option>
          </select>
          <Button size="sm" className="h-8 bg-gold hover:bg-gold/90 text-black text-xs"
            disabled={!bulkAction}
            onClick={async () => {
              if (!bulkAction) return;
              const [action, value] = bulkAction.split(":");
              const body: any = { entity: "projects", ids: selectedIds, action };
              if (action === "change_status") body.status = value;
              if (action === "change_stage") body.stage = value;
              if (action === "delete" && !confirm(`Delete ${selectedIds.length} case(s)? This cannot be undone.`)) return;
              try {
                await api.bulkExecute(body);
                setSelectedIds([]);
                setBulkAction("");
                loadProjects(roleFilter);
              } catch (e: any) { alert(e.message ?? "Bulk action failed."); }
            }}>Apply</Button>
          <button className="text-muted-foreground hover:text-foreground text-xs" onClick={() => { setSelectedIds([]); setBulkAction(""); }}>
            <X className="h-4 w-4" />
          </button>
        </div>
      )}

      {/* ── Project Detail Slide-over ───────────────────────────────────────── */}
      {detailId !== null && createPortal(
        <>
          {/* Backdrop */}
          <div className="fixed inset-0 bg-black/50 z-40" onClick={() => setDetailId(null)} />
          {/* Panel */}
          <div className="fixed right-0 top-0 h-full w-full max-w-3xl bg-background border-l border-border z-50 flex flex-col shadow-2xl">
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b border-border flex-shrink-0">
              <div>
                <p className="text-xs text-muted-foreground font-mono">
                  {detailData?.project?.docket_number ?? "Loading…"}
                </p>
                <h2 className="text-base font-semibold truncate max-w-[500px]">
                  {detailData?.project?.project_name ?? ""}
                </h2>
                {detailData?.project && (
                  <p className="text-xs text-muted-foreground mt-0.5">
                    {detailData.project.client?.company_name ?? detailData.project.client?.legal_name ?? ""}
                    {detailData.project.patent_office_code ? ` · ${detailData.project.patent_office_code}` : ""}
                    {detailData.project.service_code ? ` · ${detailData.project.service_code}` : ""}
                  </p>
                )}
              </div>
              <button onClick={() => setDetailId(null)} className="p-1.5 rounded hover:bg-muted/50">
                <X className="h-4 w-4" />
              </button>
            </div>

            {/* Tabs */}
            <div className="flex border-b border-border flex-shrink-0 px-6">
              {(["stages", "tasks", "invoices", "ledger"] as const).map((t) => (
                <button key={t} onClick={() => setDetailTab(t)}
                  className={`px-4 py-2.5 text-xs font-medium capitalize border-b-2 transition-colors ${
                    detailTab === t
                      ? "border-primary text-primary"
                      : "border-transparent text-muted-foreground hover:text-foreground"
                  }`}>
                  {t === "stages" ? "Pipeline" : t === "invoices" ? "Invoices" : t === "ledger" ? "Ledger" : "Tasks"}
                </button>
              ))}
            </div>

            {/* Body */}
            <div className="flex-1 overflow-y-auto">
              {detailLoading ? (
                <div className="flex items-center justify-center h-40">
                  <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                </div>
              ) : !detailData ? (
                <div className="flex items-center justify-center h-40 text-muted-foreground text-sm">
                  Failed to load details.
                </div>
              ) : (
                <>
                  {/* ── Pipeline tab ─────────────────────────────────────── */}
                  {detailTab === "stages" && (
                    <div className="p-6 space-y-4">
                      <div className="flex items-center justify-between">
                        <p className="text-xs text-muted-foreground">
                          Total working days across stages: <span className="font-semibold text-foreground">{detailData.total_stage_days ?? 0}</span>
                        </p>
                        {detailData.project?.filing_date && (
                          <p className="text-xs text-muted-foreground">
                            Filed: <span className="font-mono font-semibold text-foreground">{fmtDate(detailData.project.filing_date)}</span>
                          </p>
                        )}
                      </div>
                      <table className="w-full text-xs">
                        <thead>
                          <tr className="text-left border-b border-border text-muted-foreground">
                            <th className="pb-2 font-medium">Stage</th>
                            <th className="pb-2 font-medium">Status</th>
                            <th className="pb-2 font-medium">Start</th>
                            <th className="pb-2 font-medium">End</th>
                            <th className="pb-2 font-medium text-right">Working Days</th>
                          </tr>
                        </thead>
                        <tbody>
                          {detailData.stages.map((s: any) => (
                            <tr key={s.id} className="border-b border-border/50 hover:bg-muted/20">
                              <td className="py-2.5 pr-3 font-medium">{s.stage_name}</td>
                              <td className="py-2.5 pr-3">
                                <span className={`inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                  s.status === "Completed" ? "bg-green-100 text-green-700" :
                                  s.status === "In Progress" ? "bg-blue-100 text-blue-700" :
                                  "bg-muted text-muted-foreground"
                                }`}>{s.status}</span>
                              </td>
                              <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(s.actual_start_at)}</td>
                              <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(s.actual_end_at)}</td>
                              <td className="py-2.5 text-right font-semibold">
                                {s.working_days != null ? s.working_days : "—"}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}

                  {/* ── Tasks tab ────────────────────────────────────────── */}
                  {detailTab === "tasks" && (
                    <div className="p-6 space-y-4">
                      {detailData.tasks.length === 0 ? (
                        <p className="text-muted-foreground text-sm text-center py-10">No tasks for this case.</p>
                      ) : (
                        <table className="w-full text-xs">
                          <thead>
                            <tr className="text-left border-b border-border text-muted-foreground">
                              <th className="pb-2 font-medium">Task</th>
                              <th className="pb-2 font-medium">Assignee</th>
                              <th className="pb-2 font-medium">Status</th>
                              <th className="pb-2 font-medium">Start</th>
                              <th className="pb-2 font-medium">Completed</th>
                              <th className="pb-2 font-medium text-right">W.Days</th>
                            </tr>
                          </thead>
                          <tbody>
                            {detailData.tasks.map((t: any) => (
                              <tr key={t.id} className="border-b border-border/50 hover:bg-muted/20">
                                <td className="py-2.5 pr-3 font-medium max-w-[180px] truncate">{t.title}</td>
                                <td className="py-2.5 pr-3 text-muted-foreground">{t.assignee?.name ?? "—"}</td>
                                <td className="py-2.5 pr-3">
                                  <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                    t.status === "Completed" ? "bg-green-100 text-green-700" :
                                    t.status === "In Progress" ? "bg-blue-100 text-blue-700" :
                                    t.status === "Blocked" ? "bg-red-100 text-red-700" :
                                    "bg-muted text-muted-foreground"
                                  }`}>{t.status}</span>
                                </td>
                                <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(t.created_at)}</td>
                                <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(t.completed_at)}</td>
                                <td className="py-2.5 text-right font-semibold">{t.working_days != null ? t.working_days : "—"}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      )}
                    </div>
                  )}

                  {/* ── Invoices tab ─────────────────────────────────────── */}
                  {detailTab === "invoices" && (
                    <div className="p-6 space-y-4">
                      {/* Summary cards */}
                      <div className="grid grid-cols-3 gap-3">
                        {[
                          { label: "Total Invoiced", value: detailData.invoice_summary.total_invoiced },
                          { label: "Received", value: detailData.invoice_summary.total_received },
                          { label: "Pending", value: detailData.invoice_summary.total_pending },
                        ].map((c) => (
                          <div key={c.label} className="rounded-lg border border-border bg-muted/30 p-3">
                            <p className="text-[10px] text-muted-foreground">{c.label}</p>
                            <p className="text-sm font-semibold font-mono mt-1">
                              ₹{Number(c.value).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                            </p>
                          </div>
                        ))}
                      </div>
                      {detailData.invoices.length === 0 ? (
                        <p className="text-muted-foreground text-sm text-center py-8">No invoices for this case.</p>
                      ) : (
                        <table className="w-full text-xs">
                          <thead>
                            <tr className="text-left border-b border-border text-muted-foreground">
                              <th className="pb-2 font-medium">Invoice #</th>
                              <th className="pb-2 font-medium">Status</th>
                              <th className="pb-2 font-medium">Date</th>
                              <th className="pb-2 font-medium text-right">Total</th>
                              <th className="pb-2 font-medium text-right">Balance</th>
                            </tr>
                          </thead>
                          <tbody>
                            {detailData.invoices.map((inv: any) => (
                              <tr key={inv.id} className="border-b border-border/50 hover:bg-muted/20">
                                <td className="py-2.5 pr-3 font-mono text-gold font-semibold">{inv.invoice_code}</td>
                                <td className="py-2.5 pr-3">
                                  <span className={`text-[10px] px-1.5 py-0.5 rounded font-medium ${
                                    inv.status === "Paid" ? "bg-green-100 text-green-700" :
                                    inv.status === "Overdue" ? "bg-red-100 text-red-700" :
                                    inv.status === "Sent" || inv.status === "Viewed" ? "bg-blue-100 text-blue-700" :
                                    inv.status === "Partially Paid" ? "bg-amber-100 text-amber-700" :
                                    "bg-muted text-muted-foreground"
                                  }`}>{inv.status}</span>
                                </td>
                                <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(inv.created_at)}</td>
                                <td className="py-2.5 pr-3 text-right font-mono">
                                  {inv.currency === "INR" ? "₹" : inv.currency + " "}
                                  {Number(inv.total_amount).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                                </td>
                                <td className="py-2.5 text-right font-mono text-muted-foreground">
                                  {Number(inv.balance_due) > 0
                                    ? `₹${Number(inv.balance_due).toLocaleString("en-IN", { maximumFractionDigits: 0 })}`
                                    : <span className="text-green-600">Cleared</span>}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      )}
                    </div>
                  )}

                  {/* ── Ledger tab ───────────────────────────────────────── */}
                  {detailTab === "ledger" && (
                    <div className="p-6 space-y-4">
                      {detailData.ledger.length === 0 ? (
                        <p className="text-muted-foreground text-sm text-center py-8">No ledger entries for this case.</p>
                      ) : (
                        <table className="w-full text-xs">
                          <thead>
                            <tr className="text-left border-b border-border text-muted-foreground">
                              <th className="pb-2 font-medium">Date</th>
                              <th className="pb-2 font-medium">Type</th>
                              <th className="pb-2 font-medium">Reference</th>
                              <th className="pb-2 font-medium text-right">Debit</th>
                              <th className="pb-2 font-medium text-right">Credit</th>
                              <th className="pb-2 font-medium text-right">Balance</th>
                            </tr>
                          </thead>
                          <tbody>
                            {detailData.ledger.map((l: any) => (
                              <tr key={l.id} className="border-b border-border/50 hover:bg-muted/20">
                                <td className="py-2.5 pr-3 font-mono text-muted-foreground">{fmtDate(l.created_at)}</td>
                                <td className="py-2.5 pr-3 capitalize text-muted-foreground">{l.document_type}</td>
                                <td className="py-2.5 pr-3 font-mono text-gold text-[10px]">{l.document_reference}</td>
                                <td className="py-2.5 pr-3 text-right font-mono">
                                  {Number(l.debit) > 0 ? `₹${Number(l.debit).toLocaleString("en-IN", { maximumFractionDigits: 0 })}` : "—"}
                                </td>
                                <td className="py-2.5 pr-3 text-right font-mono text-green-600">
                                  {Number(l.credit) > 0 ? `₹${Number(l.credit).toLocaleString("en-IN", { maximumFractionDigits: 0 })}` : "—"}
                                </td>
                                <td className="py-2.5 text-right font-mono font-semibold">
                                  ₹{Number(l.balance).toLocaleString("en-IN", { maximumFractionDigits: 0 })}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      )}
                    </div>
                  )}
                </>
              )}
            </div>
          </div>
        </>,
        document.body
      )}
    </AppLayout>
  );
}
