import { usePage } from "@inertiajs/react";

// ── Translation dictionary ────────────────────────────────────────────────────
// Keys are English; values are translations per language code.
// Only add keys that actually appear in UI; missing keys fall back to English.
const TRANSLATIONS: Record<string, Record<string, string>> = {
  // ── Navigation ──────────────────────────────────────────────────────────────
  "Dashboard":          { Tamil: "டாஷ்போர்டு", Hindi: "डैशबोर्ड", Telugu: "డాష్‌బోర్డ్" },
  "Clients":            { Tamil: "வாடிக்கையாளர்கள்", Hindi: "ग्राहक", Telugu: "క్లయింట్లు" },
  "Projects":           { Tamil: "திட்டங்கள்", Hindi: "परियोजनाएं", Telugu: "ప్రాజెక్టులు" },
  "Tasks":              { Tamil: "பணிகள்", Hindi: "कार्य", Telugu: "పనులు" },
  "Kanban Board":       { Tamil: "கான்பான் பலகை", Hindi: "कानबान बोर्ड", Telugu: "కాన్బాన్ బోర్డ్" },
  "Project Tracker":    { Tamil: "திட்ட ட்ராக்கர்", Hindi: "प्रोजेक्ट ट्रैकर", Telugu: "ప్రాజెక్ట్ ట్రాకర్" },
  "Calendar":           { Tamil: "நாட்காட்டி", Hindi: "कैलेंडर", Telugu: "కాలెండర్" },
  "Discussions":        { Tamil: "விவாதங்கள்", Hindi: "चर्चाएं", Telugu: "చర్చలు" },
  "Reminders":          { Tamil: "நினைவூட்டல்கள்", Hindi: "अनुस्मारक", Telugu: "రిమైండర్లు" },
  "Documents":          { Tamil: "ஆவணங்கள்", Hindi: "दस्तावेज़", Telugu: "పత్రాలు" },
  "Financial Suite":    { Tamil: "நிதி அமைப்பு", Hindi: "वित्तीय सेवाएं", Telugu: "ఆర్థిక విభాగం" },
  "Invoices":           { Tamil: "இன்வாய்ஸ்கள்", Hindi: "चालान", Telugu: "ఇన్వాయిస్లు" },
  "Reports":            { Tamil: "அறிக்கைகள்", Hindi: "रिपोर्ट", Telugu: "నివేదికలు" },
  "Notifications":      { Tamil: "அறிவிப்புகள்", Hindi: "सूचनाएं", Telugu: "నోటిఫికేషన్లు" },
  "Settings":           { Tamil: "அமைப்புகள்", Hindi: "सेटिंग्स", Telugu: "సెట్టింగ్‌లు" },
  "Integrations":       { Tamil: "ஒருங்கிணைப்புகள்", Hindi: "एकीकरण", Telugu: "ఇంటిగ్రేషన్లు" },
  "HRMS":               { Tamil: "மனித வளங்கள்", Hindi: "एचआरएमएस", Telugu: "HRMS" },
  "Employees":          { Tamil: "ஊழியர்கள்", Hindi: "कर्मचारी", Telugu: "ఉద్యోగులు" },
  "Attendance":         { Tamil: "வருகை", Hindi: "उपस्थिति", Telugu: "హాజరు" },
  "Leave":              { Tamil: "விடுப்பு", Hindi: "अवकाश", Telugu: "సెలవు" },
  "Payroll":            { Tamil: "ஊதியம்", Hindi: "वेतन", Telugu: "జీతం" },

  // ── Common actions ───────────────────────────────────────────────────────────
  "Save":               { Tamil: "சேமி", Hindi: "सहेजें", Telugu: "సేవ్ చేయి" },
  "Cancel":             { Tamil: "ரத்து செய்", Hindi: "रद्द करें", Telugu: "రద్దు చేయి" },
  "Delete":             { Tamil: "நீக்கு", Hindi: "हटाएं", Telugu: "తొలగించు" },
  "Add":                { Tamil: "சேர்", Hindi: "जोड़ें", Telugu: "జోడించు" },
  "Create":             { Tamil: "உருவாக்கு", Hindi: "बनाएं", Telugu: "సృష్టించు" },
  "Edit":               { Tamil: "திருத்து", Hindi: "संपादित करें", Telugu: "సవరించు" },
  "Search":             { Tamil: "தேடு", Hindi: "खोज", Telugu: "వెతుకు" },
  "Filter":             { Tamil: "வடிகட்டு", Hindi: "फ़िल्टर", Telugu: "ఫిల్టర్" },
  "Export":             { Tamil: "ஏற்றுமதி", Hindi: "निर्यात", Telugu: "ఎగుమతి" },
  "Upload":             { Tamil: "பதிவேற்று", Hindi: "अपलोड", Telugu: "అప్‌లోడ్" },
  "Download":           { Tamil: "பதிவிறக்கு", Hindi: "डाउनलोड", Telugu: "డౌన్‌లోడ్" },
  "Send":               { Tamil: "அனுப்பு", Hindi: "भेजें", Telugu: "పంపు" },
  "Submit":             { Tamil: "சமர்பி", Hindi: "जमा करें", Telugu: "సమర్పించు" },
  "Close":              { Tamil: "மூடு", Hindi: "बंद करें", Telugu: "మూసివేయి" },
  "Refresh":            { Tamil: "புதுப்பி", Hindi: "रीफ्रेश", Telugu: "రిఫ్రెష్" },
  "View":               { Tamil: "பார்", Hindi: "देखें", Telugu: "చూడు" },
  "Back":               { Tamil: "திரும்பு", Hindi: "वापस", Telugu: "వెనుకకు" },

  // ── Status labels ────────────────────────────────────────────────────────────
  "Active":             { Tamil: "செயலில்", Hindi: "सक्रिय", Telugu: "క్రియాశీలంగా" },
  "Inactive":           { Tamil: "செயலற்ற", Hindi: "निष्क्रिय", Telugu: "నిష్క్రియంగా" },
  "Pending":            { Tamil: "நிலுவையில்", Hindi: "लंबित", Telugu: "పెండింగ్‌లో" },
  "Completed":          { Tamil: "முடிந்தது", Hindi: "पूर्ण", Telugu: "పూర్తయింది" },
  "In Progress":        { Tamil: "செயல்பாட்டில்", Hindi: "प्रगति में", Telugu: "పురోగతిలో" },
  "Overdue":            { Tamil: "தாமதமான", Hindi: "समयसीमा समाप्त", Telugu: "గడువు దాటింది" },

  // ── Common labels ────────────────────────────────────────────────────────────
  "Loading…":           { Tamil: "ஏற்றுகிறது…", Hindi: "लोड हो रहा है…", Telugu: "లోడ్ అవుతోంది…" },
  "No results found":   { Tamil: "முடிவுகள் இல்லை", Hindi: "कोई परिणाम नहीं", Telugu: "ఫలితాలు లేవు" },
  "Today":              { Tamil: "இன்று", Hindi: "आज", Telugu: "ఈరోజు" },
  "This Week":          { Tamil: "இந்த வாரம்", Hindi: "इस सप्ताह", Telugu: "ఈ వారం" },
  "Upcoming":           { Tamil: "வரவிருக்கும்", Hindi: "आगामी", Telugu: "రాబోయే" },
  "Profile":            { Tamil: "சுயவிவரம்", Hindi: "प्रोफ़ाइल", Telugu: "ప్రొఫైల్" },
  "Logout":             { Tamil: "வெளியேறு", Hindi: "लॉगआउट", Telugu: "లాగ్‌అవుట్" },
  "Request Help":       { Tamil: "உதவி கேள்", Hindi: "सहायता मांगें", Telugu: "సహాయం అడగు" },
  "Send Reply":         { Tamil: "பதில் அனுப்பு", Hindi: "उत्तर भेजें", Telugu: "జవాబు పంపు" },
  "New Discussion":     { Tamil: "புதிய விவாதம்", Hindi: "नई चर्चा", Telugu: "కొత్త చర్చ" },
  "New Reminder":       { Tamil: "புதிய நினைவூட்டல்", Hindi: "नया अनुस्मारक", Telugu: "కొత్త రిమైండర్" },
};

/** Get the user's saved language from Inertia shared props. */
export function useLanguage(): string {
  const { props } = usePage() as any;
  return props.auth?.user?.language ?? "English";
}

/** Translate a key to the current user's language. Falls back to English (the key itself). */
export function useTranslation() {
  const lang = useLanguage();

  return function t(key: string): string {
    if (lang === "English" || !TRANSLATIONS[key]) return key;
    return TRANSLATIONS[key][lang] ?? key;
  };
}

/** Non-hook version — pass language explicitly. Use in callbacks/non-component code. */
export function translate(key: string, lang: string): string {
  if (lang === "English" || !TRANSLATIONS[key]) return key;
  return TRANSLATIONS[key][lang] ?? key;
}
