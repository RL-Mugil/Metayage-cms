const IST = "Asia/Kolkata";
const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/;
const HAS_ZONE = /(Z|[+-]\d{2}:?\d{2})$/i;

/**
 * Parses a server datetime into a Date, or null for plain dates / time-only values.
 * - ISO strings with a zone ("...Z", "+05:30") are exact instants.
 * - Raw database timestamps ("2026-10-13 07:02:50") are stored in UTC, so they are read as UTC.
 */
function toInstant(raw: string): Date | null {
  const s = raw.trim();
  if (DATE_ONLY.test(s) || !/\d{4}-\d{2}-\d{2}[T ]\d/.test(s)) return null;
  const iso = s.replace(" ", "T");
  const date = new Date(HAS_ZONE.test(iso) || s.includes("T") ? iso : `${iso}Z`);
  return isNaN(date.getTime()) ? null : date;
}

function istParts(date: Date): Record<string, string> {
  const out: Record<string, string> = {};
  for (const p of new Intl.DateTimeFormat("en-GB", {
    timeZone: IST, year: "numeric", month: "2-digit", day: "2-digit",
  }).formatToParts(date)) out[p.type] = p.value;
  return out;
}

/** Returns DD-MM-YYYY (in IST for datetimes) from any ISO / YYYY-MM-DD string. Returns "—" for empty. */
export function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const instant = toInstant(d as string);
  if (instant) {
    const p = istParts(instant);
    return `${p.day}-${p.month}-${p.year}`;
  }
  const part = (d as string).split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d as string;
  return `${day}-${m}-${y}`;
}

/** Returns HH:MM:SS AM/PM IST from an ISO datetime or time string. Returns "—" for empty. */
export function fmtTime(d: string | null | undefined): string {
  if (!d) return "—";
  const raw = typeof d === "string" ? d : String(d);
  // Time-only values (attendance etc.) are already IST wall-clock times; datetimes convert to IST.
  const date = toInstant(raw) ?? new Date(raw.includes("T") || raw.includes(" ") ? raw.replace(" ", "T") : `1970-01-01T${raw}+05:30`);
  if (isNaN(date.getTime())) return raw;
  return (
    date.toLocaleTimeString("en-US", {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: true,
      timeZone: IST,
    }) + " IST"
  );
}

/** Returns DD-MM-YYYY HH:MM:SS AM/PM IST */
export function fmtDateTime(d: string | null | undefined): string {
  if (!d) return "—";
  return `${fmtDate(d)} ${fmtTime(d)}`;
}
