#!/usr/bin/env python3
"""Send an operational alert to the system admins (super_admin) and directors (partner).

Used by ipflow_monitor.sh. Deliberately independent of the Laravel app so it still works when PHP is broken:
recipients come straight from Postgres, mail goes through the same SMTP relay the app uses (credentials read from
the app's .env, never printed), and an in-app notification row is inserted for every recipient.

Usage: ipflow_alert.py "<subject>" "<body>"
"""
import os
import smtplib
import subprocess
import sys
from email.message import EmailMessage

ENV_FILE = "/var/www/mypl-cms/backend/.env"
ROLES = ("super_admin", "partner")


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
           "-U", env["DB_USERNAME"], "-d", env["DB_DATABASE"], "-At", "-v", "ON_ERROR_STOP=1", "-f", "-"]
    for k, v in (variables or {}).items():
        cmd += ["-v", f"{k}={v}"]
    res = subprocess.run(cmd, input=sql, capture_output=True, text=True,
                         env={**os.environ, "PGPASSWORD": env["DB_PASSWORD"]}, timeout=20)
    if res.returncode != 0:
        raise RuntimeError(res.stderr.strip()[:300])
    return res.stdout


def main():
    if len(sys.argv) < 3:
        sys.exit("usage: ipflow_alert.py SUBJECT BODY")
    subject, body = sys.argv[1][:240], sys.argv[2][:2000]
    env = load_env(ENV_FILE)

    roles = ",".join("'%s'" % r for r in ROLES)
    rows = psql(env, f"select id || '|' || email from users where role in ({roles}) and status = 'Active' and email not like '%@example.invalid';")
    recipients = [r.split("|", 1) for r in rows.splitlines() if "|" in r]
    only = os.environ.get("IPFLOW_ALERT_ONLY", "").strip().lower()  # testing: restrict to one address
    if only:
        recipients = [r for r in recipients if r[1].lower() == only]
    if not recipients:
        sys.exit("no recipients found")

    errors = []

    # 1) In-app notifications (values passed as psql variables, so they are quoted safely).
    try:
        ids = ",".join(r[0] for r in recipients)
        psql(env,
             "insert into ip_notifications (user_id, type, title, description, meta, created_at, updated_at) "
             "select id, 'system_alert', :'t', :'d', '{\"source\":\"ipflow-monitor\"}', now(), now() "
             f"from users where id in ({ids});",
             {"t": subject, "d": body})
    except Exception as exc:  # keep going: email may still work
        errors.append(f"notification: {exc}")

    # 2) Email through the app's SMTP relay.
    try:
        port = int(env.get("MAIL_PORT", "587"))
        with smtplib.SMTP(env["MAIL_HOST"], port, timeout=20) as smtp:
            smtp.ehlo()
            if env.get("MAIL_SCHEME", "tls") != "smtps":
                smtp.starttls()
                smtp.ehlo()
            smtp.login(env["MAIL_USERNAME"], env["MAIL_PASSWORD"])
            for _, email in recipients:
                msg = EmailMessage()
                msg["From"] = f'{env.get("MAIL_FROM_NAME", "IPFlow Monitor")} <{env["MAIL_FROM_ADDRESS"]}>'
                msg["To"] = email
                msg["Subject"] = f"[IPFlow alert] {subject}"
                msg.set_content(body + "\n\n-- IPFlow monitor on " + os.uname().nodename)
                smtp.send_message(msg)
    except Exception as exc:
        errors.append(f"email: {exc}")

    if errors:
        print("; ".join(errors), file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
