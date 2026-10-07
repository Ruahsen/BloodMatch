# Email Notifications (FR-17)

Email is a **delivery channel only**. The in-app notification is the source
event; email mirrors it. No notification semantics, privacy rules, or
authorization rules change because of email.

```
Business event
  -> NotificationService::notify()          (central choke point)
    -> INSERT in-app notification           (deduplicated, always stored first)
    -> attemptEmail()                       (only if the row was new)
      -> NotificationEmailTemplate::render() (title/body + branding/link/footer)
      -> Mailer::send()                      (SMTP or local capture)
      -> emailed_at recorded on success only
```

## Which notification types send email

Every type that passes `'email' => EMAIL_NORMAL` (or `EMAIL_CRITICAL` for
critical-urgency `match.new`) to `NotificationService::notify()`:

`match.new` (normal/critical by urgency), `match.responded`,
`match.accepted`, `match.unaccepted`, `match.withdrawn`,
`match.consent_revoked`, `match.closed`, `verification.decision`,
`account.status_changed`, `donation.confirmed`, `donation.rejected`,
`request.fulfilled`, `request.cancelled`, `request.expired`.

Password-reset mail is a separate flow (`AuthService` -> `Mailer::send`
directly) and never goes through notification templates.

## Configuration (.env)

| Variable | Required | Notes |
|---|---|---|
| `MAIL_HOST` | SMTP only | Empty = email skipped gracefully (in-app unaffected) |
| `MAIL_PORT` | SMTP only | Default `587` |
| `MAIL_USERNAME` (`MAIL_USER` legacy) | SMTP w/ auth | - |
| `MAIL_PASSWORD` (`MAIL_PASS` legacy) | SMTP w/ auth | - |
| `MAIL_FROM_ADDRESS` (`MAIL_FROM` legacy) | SMTP only | Must be a deliverable address, e.g. `noreply@yourdomain` |
| `MAIL_FROM_NAME` | No | Default `BloodMatch` |
| `MAIL_ENCRYPTION` | No | Empty (plain), `tls`, or `ssl` |
| `MAIL_TIMEOUT` | No | SMTP seconds, default `10` |
| `FRONTEND_URL` | No | Absolute base URL for email links; defaults to first `APP_CORS_ORIGINS` |
| `MAIL_CAPTURE_DIR` | Local testing | Writable dir: emails written as `.eml` files instead of sent |

Never commit real credentials. Production still needs: a real SMTP host,
valid `MAIL_FROM_ADDRESS`, and either `tls`/`ssl` as the provider requires.

## Local testing without real email

Point a dev backend at a capture directory (never production):

```powershell
$env:MAIL_CAPTURE_DIR = 'D:\...\BloodMatch\logs\mail-capture'
$env:FRONTEND_URL = 'http://localhost:5173'
D:\xampp\php\php.exe -S 127.0.0.1:8001 -t backend/public
```

Trigger a notification (e.g. create + cancel a request), then inspect the
`.eml` files: `To:` / `Subject:` headers plus `--- TEXT ---` and `--- HTML ---`
sections. A mail-catcher SMTP (Mailpit/MailHog) also works: set `MAIL_HOST`
to it and leave `MAIL_CAPTURE_DIR` empty.

## Reliability & deduplication

- **Fail-graceful:** the in-app row is inserted before any mail attempt. SMTP
  failure returns `false`, is written to the error log, and `emailed_at`
  stays `NULL`. The HTTP request still succeeds.
- **No duplicate mail:** `notify()` uses `INSERT IGNORE` on
  `UNIQUE(dedup_key, generation)`; a duplicate returns `null` and email is
  never attempted. A new generation (material change) is a new row and may
  send a new email.
- **Throttles (email leg only, in-app always created):** 5 normal emails per
  user per hour; critical `match.new` bypasses that budget but is capped at
  3 per donor per request per hour; match fan-out capped at 500 donors per
  generation. Tracked via `notifications.emailed_at` — no extra table.
- **Synchronous best-effort** (no queue): appropriate for DeMolay-Bataan
  scale; SMTP has a 10 s default timeout so requests never hang long.

## Privacy

Templates render only the notification's own title/body (already intended
for that recipient) plus chrome (branding, timestamp, links, footer). They
never add passwords, tokens, coordinates, phone numbers, documents, or any
other user's email. Bilateral contact (`matches.contact` gating + consent)
is untouched: receiving an email grants no contact access. There is no
notification-preference store yet — email is always attempted when flagged,
configured, and under throttle. A future opt-out preference is recommended
before any high-volume use, but was deliberately not added here to avoid
inventing requirements.

## Tests

- `tests/email_template.php` (PHP CLI, no DB/SMTP): 37 assertions covering
  rendering of all 14 types, deep-link map, XSS escaping, secret-leakage
  scan, capture transport, SMTP-failure `false`, env aliases.
- `tests/email_notifications.ps1` (live API + DB, registered in
  `tests/run_all.ps1`): cancel fan-out, emailed_at semantics (adapts to
  server mail config), capture-content check with `-MailCaptureDir`,
  accept-replay dedup, recipient isolation, deactivated exclusion, plus the
  PHP unit suite as E6.
