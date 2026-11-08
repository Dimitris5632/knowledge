# Changelog

## [1.0.0] — 2026-11

### Project
- Professional layout: only `public/` is web-reachable; `src/`, `templates/`, `storage/`, `migrations/`, `deploy/`.
- Settings from `.env` locally and from environment variables on Azure.
- README, SECURITY.md (OWASP Top 10 mapping), MIT license, demo data.

### Security
- Profile photos and videos stored outside the web root (they survived no redeploy before).
- Lockout per account (10 / 15 min) in addition to per IP.
- Password change signs out all other devices (session versioning).
- No account enumeration in registration; owner of an existing email is notified instead.
- Logout requires POST + CSRF token.
- Non-PDF documents served with a sandbox CSP.
- Email codes shown on screen only in development.
- Unverified accounts removed after 7 days; wrong email can be corrected during verification.

### Features
- Email verification with 6-digit codes (Brevo), 3 security questions, device PIN login.
- Copyright-risk check for uploads, admin review queue, infringement reports, blocklist.
- Installable app (PWA) with document scanner (perspective correction, PDF).
- Greek / English interface.
- Cloudflare Turnstile bot protection.
- Live username availability and password strength meter.

### Earlier
- Library, film library, debates with votes, private messages with safe links, CVs, reviews, favourites,
  search, security log and Security Center, TOTP 2FA, chatbot, quote of the day.
