#!/usr/bin/env python3
"""Record a server-monitor event quietly, and send a recovery notice only when it matters.

Used by ipflow_monitor.sh. Deliberately independent of the Laravel app so it still works when PHP is broken.

  ipflow_alert.py "DOWN: <what is wrong>" "<detail>"       -> records/updates an open problem in ops_incidents (NO email)
  ipflow_alert.py "RECOVERED: <what was wrong>" "<detail>" -> marks it resolved; emails ONE recovery notice, but only if the
                                                              problem had already been listed in a morning report
  ipflow_alert.py "TEST: <subject>" "<body>"                -> sends a test email straight away

The morning report itself is built and sent by the Laravel scheduler (ops:daily-report at 06:00 IST) from the same table.
Mail goes through the app's SMTP relay (credentials read from the app's .env, never printed) to ALERT_EMAIL from
/etc/ipflow-monitor.env (default below). Directors and system admins are never emailed automatically.
"""
import html
import os
import smtplib
import subprocess
import sys
from email.message import EmailMessage

ENV_FILE = os.environ.get("IPFLOW_ENV_FILE", "/var/www/mypl-cms/backend/.env")
MONITOR_ENV = "/etc/ipflow-monitor.env"
DEFAULT_ALERT_EMAIL = "mugilvannan@myipstrategy.com"

# Plain-English wording for each monitor check, keyed by the check's description in ipflow_monitor.sh.
PLAIN = {
    "IPFlow portal (login.myipstrategy.com) is not responding": (
        "The IPFlow portal is not responding",
        "The login page of the portal did not answer when the monitor tried it.",
        "Staff and clients cannot use IPFlow until it is back.",
        "Ask the developer to check the server straight away."),
    "IPFlow API health check is failing (database or Redis)": (
        "The portal's health check is failing",
        "The portal answers, but it reports that its database or its cache/queue service is not working.",
        "Pages may load slowly, show errors, or not save changes.",
        "Ask the developer to check the server today."),
    "canpatentit.com is not responding": (
        "canpatentit.com is not responding",
        "The public CanPatentIt website did not answer.",
        "Visitors cannot open the website, and enquiries cannot be sent.",
        "Ask the developer to check the web server straight away."),
    "canpatentit.com data files are missing": (
        "canpatentit.com data files are missing",
        "A file the website needs for its patent showcase could not be loaded.",
        "The patent showcase section may look empty.",
        "Ask the developer to republish the website content."),
    "PostgreSQL (app database) is not accepting connections": (
        "The main database is not accepting connections",
        "The database that stores all IPFlow data is not answering.",
        "IPFlow cannot work without it. No data is lost, but nobody can use the portal.",
        "Ask the developer to check the server straight away."),
    "Redis (cache, sessions, queue) is not answering": (
        "The cache and job queue service (Redis) is not answering",
        "The fast memory store that holds logins and the background job queue is down.",
        "People may be logged out and background jobs will wait.",
        "Ask the developer to restart it today."),
    "IPFlow background workers (Horizon/Reverb/scheduler) are not running": (
        "IPFlow's background workers are not running",
        "The programs that send emails, run imports and update Zoho in the background have stopped.",
        "Reminders, Zoho updates and other automatic work will not happen until they are restarted.",
        "Ask the developer to restart the workers today."),
    "Server memory is critically low": (
        "The server is almost out of memory",
        "The server's working memory is nearly full.",
        "Everything may slow down or stop responding.",
        "Ask the developer to check what is using the memory."),
    "Server disk is almost full": (
        "The server's storage is almost full",
        "The server is running out of disk space.",
        "New files and database backups could fail to save.",
        "Ask the developer to free up space soon."),
    "Server CPU load is very high": (
        "The server is very busy",
        "The server's processor has been working flat out for several minutes.",
        "Pages may be slow.",
        "Nothing, if it passes by itself. If it repeats, ask the developer to look into it."),
    "PHP workers are saturated (requests queuing)": (
        "The portal is struggling to keep up",
        "More people or tasks are asking for pages than the server can handle at once, so requests are queuing.",
        "Pages will feel slow, and some may time out.",
        "Nothing, if it passes by itself. If it repeats, ask the developer to look into capacity."),
    "TLS certificate for login.myipstrategy.com is expiring": (
        "The security certificate for the portal is about to expire",
        "The certificate that makes the portal's padlock work expires soon and has not renewed.",
        "Browsers will warn people away from the portal once it expires.",
        "Ask the developer to renew it this week."),
    "TLS certificate for canpatentit.com is expiring": (
        "The security certificate for canpatentit.com is about to expire",
        "The certificate that makes the website's padlock work expires soon and has not renewed.",
        "Browsers will warn visitors away from the website once it expires.",
        "Ask the developer to renew it this week."),
    "Background jobs are failing repeatedly": (
        "Background jobs are failing repeatedly",
        "Several automatic background jobs (emails, syncs, imports) have failed in the last hour.",
        "Whatever those jobs do may not have happened.",
        "Share this report with the developer today."),
    "Background job queue is backing up": (
        "The background job queue is backing up",
        "Jobs are being added faster than they are being finished.",
        "Emails and updates will arrive late.",
        "Ask the developer to look at the workers today."),
    "Database backup is stale": (
        "The database backup is out of date",
        "The newest database backup is older than it should be, or much smaller than the one before it.",
        "If something went wrong now, there may be no recent copy to restore from.",
        "Ask the developer to check the backup job today."),
}


def load_env(path):
    env = {}
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            line = line.strip().replace("\r", "")
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            env[k.strip()] = v.strip().strip('"').strip("'")
    return env


def psql(env, sql, variables=None):
    cmd = ["psql", "-h", env.get("DB_HOST", "127.0.0.1"), "-p", env.get("DB_PORT", "5432"),
           "-U", env["DB_USERNAME"], "-d", env["DB_DATABASE"], "-q", "-At", "-F", "\x1f", "-v", "ON_ERROR_STOP=1", "-f", "-"]
    for k, v in (variables or {}).items():
        cmd += ["-v", f"{k}={v}"]
    res = subprocess.run(cmd, input=sql, capture_output=True, text=True,
                         env={**os.environ, "PGPASSWORD": env["DB_PASSWORD"]}, timeout=20)
    if res.returncode != 0:
        raise RuntimeError(res.stderr.strip()[:300])
    return res.stdout


def smtp_send(env, to, subject, text, html_body=None):
    port = int(env.get("MAIL_PORT", "587"))
    with smtplib.SMTP(env["MAIL_HOST"], port, timeout=20) as smtp:
        smtp.ehlo()
        if env.get("MAIL_SCHEME", "tls") != "smtps":
            smtp.starttls()
            smtp.ehlo()
        smtp.login(env["MAIL_USERNAME"], env["MAIL_PASSWORD"])
        msg = EmailMessage()
        msg["From"] = f'{env.get("MAIL_FROM_NAME", "IPFlow Monitor")} <{env["MAIL_FROM_ADDRESS"]}>'
        msg["To"] = to
        msg["Subject"] = subject
        msg.set_content(text)
        if html_body:
            msg.add_alternative(html_body, subtype="html")
        smtp.send_message(msg)


def alert_email():
    try:
        return (load_env(MONITOR_ENV).get("ALERT_EMAIL") or DEFAULT_ALERT_EMAIL).strip()
    except OSError:
        return DEFAULT_ALERT_EMAIL


UPSERT = """
insert into ops_incidents (fingerprint, source, title, meaning, impact, action, technical, occurrences, first_seen_at, last_seen_at,
                           quiet_minutes, status, created_at, updated_at)
values (:'fp', 'monitor', :'title', :'meaning', :'impact', :'action', :'tech', 1, now(), now(), 0, 'open', now(), now())
on conflict (fingerprint) do update set
  occurrences        = case when ops_incidents.status = 'open' then ops_incidents.occurrences + 1 else 1 end,
  first_seen_at      = case when ops_incidents.status = 'open' then ops_incidents.first_seen_at else now() end,
  reported_at        = case when ops_incidents.status = 'open' then ops_incidents.reported_at else null end,
  recovery_mailed_at = case when ops_incidents.status = 'open' then ops_incidents.recovery_mailed_at else null end,
  resolved_at = null, status = 'open', last_seen_at = now(), technical = excluded.technical, updated_at = now();
"""

RESOLVE = """
update ops_incidents set status = 'resolved', resolved_at = now(), updated_at = now()
where fingerprint = :'fp' and status = 'open'
returning id, title, meaning, to_char(first_seen_at at time zone 'Asia/Kolkata', 'DD Mon HH12:MI AM'),
          to_char(now() at time zone 'Asia/Kolkata', 'DD Mon HH12:MI AM'), (reported_at is not null and recovery_mailed_at is null);
"""


def main():
    if len(sys.argv) < 3:
        sys.exit("usage: ipflow_alert.py SUBJECT BODY")
    subject, body = sys.argv[1][:240], sys.argv[2][:2000]
    env = load_env(ENV_FILE)
    to = alert_email()

    if subject.startswith("TEST:"):
        smtp_send(env, to, f"[IPFlow] {subject}", body)
        return

    kind, _, what = subject.partition(": ")
    if kind not in ("DOWN", "RECOVERED"):
        kind, what = "DOWN", subject
    fp = "monitor|" + what

    if kind == "DOWN":
        title, meaning, impact, action = PLAIN.get(what, (what, "The server monitor found a problem.", "Something on the server may not be working properly.", "Share this report with the developer."))
        psql(env, UPSERT, {"fp": fp, "title": title, "meaning": meaning, "impact": impact, "action": action, "tech": body.replace("\n", " ")[:600]})
        return  # nothing is emailed now: it appears in the next morning report

    out = psql(env, RESOLVE, {"fp": fp}).strip()
    if not out:
        return
    row = out.splitlines()[0].split("\x1f")
    _id, title, meaning, first, now_s, needs_mail = row[0], row[1], row[2], row[3], row[4], row[5] == "t"
    if not needs_mail:
        return  # it was never in a morning report, so there is nothing to "recover" from: it is listed there as fixed instead
    text = f"Good news: a problem from this morning's report has recovered.\n\n* {title} - back to normal as of {now_s}\n\nNothing for you to do. The next report arrives tomorrow at 6:00 AM.\n"
    page = ('<!doctype html><html><body style="margin:0;background:#f4f6f8;font-family:Segoe UI,Arial,sans-serif;color:#212529"><div style="max-width:620px;margin:0 auto;padding:24px 16px">'
            '<div style="background:#e8f7ef;border:1px solid #b9e4cd;color:#14633a;padding:16px 18px;border-radius:8px;font-size:16px"><strong>Good news: a problem from this morning\'s report has recovered.</strong> Nothing for you to do.</div>'
            f'<p style="margin:18px 0 0;font-size:14px;line-height:1.55"><strong>{html.escape(title)}</strong><br><span style="color:#5c646c;font-size:13px">{html.escape(meaning)} First noticed {html.escape(first)}; back to normal as of {html.escape(now_s)}.</span></p>'
            '<p style="margin:22px 0 0;color:#6b747c;font-size:12px">The next normal report arrives tomorrow at 6:00 AM.</p></div></body></html>')
    smtp_send(env, to, f"IPFlow: recovered, {title}", text, page)
    psql(env, "update ops_incidents set recovery_mailed_at = now() where id = :'id';", {"id": _id})


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:  # the monitor logs this; it must never crash the monitor itself
        print(f"ipflow_alert: {exc}", file=sys.stderr)
        sys.exit(1)
