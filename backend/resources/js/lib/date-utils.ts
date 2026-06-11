/** Returns DD-MM-YYYY from any ISO / YYYY-MM-DD string. Returns "—" for empty. */
export function fmtDate(d: string | null | undefined): string {
  if (!d) return "—";
  const part = (d as string).split("T")[0];
  const [y, m, day] = part.split("-");
  if (!y || !m || !day) return d as string;
  return `${day}-${m}-${y}`;
}

/** Returns HH:MM:SS AM/PM IST from an ISO datetime or time string. Returns "—" for empty. */
export function fmtTime(d: string | null | undefined): string {
  if (!d) return "—";
  const raw = typeof d === "string" ? d : String(d);
  const iso = raw.includes("T") || raw.includes(" ") ? raw.replace(" ", "T") : `1970-01-01T${raw}`;
  const date = new Date(iso);
  if (isNaN(date.getTime())) return raw;
  return (
    date.toLocaleTimeString("en-US", {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
      hour12: true,
      timeZone: "Asia/Kolkata",
    }) + " IST"
  );
}

/** Returns DD-MM-YYYY HH:MM:SS AM/PM IST */
export function fmtDateTime(d: string | null | undefined): string {
  if (!d) return "—";
  return `${fmtDate(d)} ${fmtTime(d)}`;
}
