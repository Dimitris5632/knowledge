# Security

Knowledge stores personal data (accounts, CVs, private messages) and user-uploaded files.
This document describes **what we protect, against whom, and how**: every control points to the code that implements it.

## Reporting a vulnerability

Please **do not open a public issue**. Email the maintainers (see the GitHub profile of the repository owner)
with steps to reproduce. We aim to reply within 7 days and will credit you in the changelog if you wish.

## Threat model

| Asset | Threat | Attacker |
|---|---|---|
| Accounts | Password guessing, credential stuffing, session theft | Anonymous internet users, bots |
| Private messages, CVs | Reading other users' data (IDOR) | Logged-in members |
| The server | Uploading executable or malicious files | Logged-in members |
| Other members' browsers | Stored XSS, malicious links, phishing | Logged-in members |
| Copyright holders | Pirated books / journal articles in the library | Logged-in members |
| Member list | Account enumeration (who is a member?) | Anonymous users |

Out of scope: physical access, compromise of the hosting provider, a compromised admin account
(mitigated by TOTP, see A07).

## OWASP Top 10 (2021) mapping

### A01 · Broken Access Control
- Every protected page calls `require_login()` / `require_admin()` (`src/functions.php`).
- **Ownership is checked in the SQL query itself**, never by hiding buttons:
  messages `WHERE m.id = ? AND (sender_id = ? OR receiver_id = ?)` (`public/read_message.php`),
  deletions `WHERE id = ? AND user_id = ?` (`public/delete_document.php`, `public/delete_video.php`, `public/settings.php`).
- Documents under review are visible only to their owner and admins: `visible_documents_sql()` is used by
  library, search, profile, favourites, download and reviews.
- Admins who logged in with a PIN must re-enter their password for admin pages (`public/confirm_password.php`).
- Redirect targets are allow-listed (no open redirects): `public/favorite.php`, `public/confirm_password.php`.

### A02 · Cryptographic Failures
- Passwords, security answers, PINs and email codes: `password_hash()` (bcrypt). Device tokens: SHA-256.
- Random values from `random_bytes()` / `random_int()` (CSPRNG).
- HTTPS-only on Azure + **HSTS**; `Secure`, `HttpOnly`, `SameSite` cookies (`src/bootstrap.php`).
- Database connection over TLS (`DB_SSL=1`, `src/database.php`).
- Secrets only in environment variables, never in the repository (`.env` is git-ignored).

### A03 · Injection
- **All** queries use PDO prepared statements with `ATTR_EMULATE_PREPARES = false`.
  The few dynamic parts (table / column names) come from hard-coded allow-lists, never from input.
- Output escaping with `e()` (`htmlspecialchars`, `ENT_QUOTES`, UTF-8) in every template.
- Links in messages are rendered by `linkify()`, which escapes first and links only `http(s)` URLs.

### A04 · Insecure Design
- Copyright-risk analysis for every upload with a **human in the loop** (`analyze_document()`, `public/review_documents.php`).
- Notice-and-action reporting (EU DSA): 3 independent copyright reports automatically hide a document.
- No user enumeration: identical responses for existing / non-existing usernames and emails in login,
  registration and recovery.
- Recovery needs **both** an emailed code **and** 2 random security questions, which stay fixed per attempt (no re-rolling).

### A05 · Security Misconfiguration
- Security headers on every response: CSP, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy`, `Permissions-Policy`, HSTS.
- Only `public/` is web-reachable (root `.htaccess` for Apache, `deploy/azure/nginx.conf` for Azure).
- Errors are never displayed online (`display_errors` off on Azure, generic DB error message).
- Development helpers (email code on screen) work **only** with `APP_ENV=development` or on localhost.

### A06 · Vulnerable and Outdated Components
- No third-party PHP dependencies. One optional client library (QR code for TOTP) loaded from a pinned CDN version.
- PHP runtime patched by Azure App Service.

### A07 · Identification and Authentication Failures
- Password policy: 8+ characters, 4 character classes, **blocklist of common passwords**, must not contain the username.
- Lockout: **5 failures / 15 min per IP** and **10 failures / 15 min per account** from any IP.
- **TOTP** (RFC 6238) with replay protection (`totp_last_step`).
- Email verification before first login. Unverified accounts are deleted after 7 days.
- Session ID regenerated on login and privilege change, 30-minute idle timeout,
  **session versioning**: a password change or reset signs out all other devices and revokes every PIN.
- Device PIN: valid only together with a 256-bit device secret cookie, 5 attempts, expires after 30 days unused,
  easy PINs rejected (sequences, repeats, years).
- Cloudflare Turnstile bot check on login, registration, recovery and contact.

### A08 · Software and Data Integrity Failures
- Uploaded files: extension allow-list **and** real MIME type from file contents (`finfo`) **and** image decoding
  (`getimagesize`). Random 128-bit file names chosen by the server.
- Files stored **outside the web root** (`storage/` locally, `/home/data` on Azure) and served only by
  `download.php` / `media.php` with `nosniff` and a `sandbox` CSP: an uploaded file can never execute.
- CSRF token on every state-changing request (`check_csrf()`), including logout.

### A09 · Security Logging and Monitoring Failures
- `security_log` records logins, failures, lockouts, rejected uploads, CSRF failures, 2FA and PIN events,
  bot blocks and admin decisions, with IP and browser.
- Admin **Security Center**: 24-hour counters, 7-day chart, currently locked IPs (one-click unlock), most suspicious IPs.
- Members see their own activity and a security checklist (`public/security.php`).

### A10 · Server-Side Request Forgery
- The server only calls two fixed HTTPS endpoints (Brevo, Cloudflare). No URL from user input is ever fetched.

## Known limitations and next steps

| Limitation | Plan |
|---|---|
| CSP allows `'unsafe-inline'` scripts | Move inline scripts to files and use nonces |
| TOTP secrets stored in plain text in the database | Encrypt with a key from the environment |
| No TOTP backup codes | Add one-time recovery codes |
| Rate limits per IP depend on the proxy's `X-Forwarded-For` | Account-based limits added; consider Cloudflare in front |
| No automated security tests yet | PHPUnit + CodeQL in GitHub Actions |
