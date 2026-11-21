# OCTRacker — Deployment & Migration Notes

**System:** OCTRacker (PHP SIS for Olivarez College Tagaytay)  
**Stack evolution:** XAMPP MySQL → Railway MySQL → Render + Supabase Postgres  
**Code repo:** https://github.com/francisespiritu-star/octracker.git  

> Do **not** put database passwords or API keys in this file or in Git. Use host Environment Variables only.

---

## 1. What the system is

- Classic **PHP** (procedural, PDO), no Laravel
- Roles: **Super Admin**, **Registrar Head** (Admin), **Registrar Staff**
- Features: users, college + SHS students, requirements, archive, reports, email notices
- Originally built for **XAMPP** (local Apache + MySQL)

---

## 2. Early product work (before the hosting chase)

| Area | Changes |
|------|---------|
| Security | Password hashing, CSRF, XSS escaping; removed debug/duplicate trees |
| Bugs / features | Archive from DB, requirements persisted, SHS wired for real (not UI-only) |
| Email UX | Add Student uses Tagaytay domain `@olivarezcollegetagaytay.edu.ph` |
| GitHub | Collaborator push to `francisespiritu-star/octracker` |

---

## 3. Hosting journey (tools tried)

### A. Hostinger

- **Used for:** Domain **`octracker.live`** (DNS; later Brevo DKIM/SPF/DMARC)
- **Not** the final long-term PHP host (paid plans)

### B. InfinityFree (free PHP + MySQL)

- **Why tried:** Free classic PHP + MySQL (closest drop-in for XAMPP-style apps)
- **Problems:** Panel flaky / outages; setup unfinished
- **Result:** Abandoned in favor of Railway

### C. Vercel / static-style hosts

- **Why skipped:** Not suitable for classic PHP + MySQL without a full rewrite

### D. Railway (Docker PHP + MySQL)

- **What worked:**
  - `Dockerfile` (`php:8.2-cli` + built-in server on `$PORT`)
  - Managed MySQL + env vars (`MYSQL_*`, `MYSQL_PUBLIC_URL`)
  - SQL import of local/XAMPP data
  - Live URL pattern: `*.up.railway.app`
- **Problems:**
  - Trial / usage credits — not free forever (Hobby ~$5/mo later)
  - Auto-deploy sometimes stuck on old commits
  - **Outbound Gmail SMTP blocked** (connection timeout on 587/465)
- **Email solution:**
  - Resend first (limited: test mode / freemail issues)
  - Switched to **Brevo** HTTP API
  - Authenticated sender domain **`octracker.live`**
  - Env vars: `BREVO_API_KEY`, `BREVO_FROM` (e.g. `noreply@octracker.live`)

### E. Render (free web) — first deploy (Oregon)

- **Why:** Free web after Railway cost / expiry concern
- **Setup:** Same GitHub repo, Docker; temporarily pointed at Railway MySQL via public URL
- **Problems:**
  - Free web **sleeps** after idle (slow cold start)
  - **No free MySQL** on Render (free DB is Postgres, with limits)
  - Oregon (US) app ↔ later Singapore DB = high latency on every page
- **Email:** Brevo continued to work via the same env vars

### F. Supabase (free Postgres) — final database

- **Why:** Free database so Railway MySQL could be shut down
- **Work required:**
  - App support for **MySQL and Postgres**
  - Docker: `pdo_pgsql` (+ keep `pdo_mysql` for local XAMPP)
  - `admin/includes/db_compat.php` (portable table/column helpers; `GROUP_CONCAT` → `STRING_AGG`)
  - Schema: `schema/postgres.sql`
  - Convert MySQL dump → Postgres: `tools/convert_mysql_dump_to_pgsql.php`
  - Import file used: `octracker-supabase.sql` (from Railway MySQL backup)
  - Guide: `SUPABASE.md`
- **Problems:**
  - Direct host `db.<project>.supabase.co:5432` uses **IPv6** → Render error: *Network is unreachable*
  - **Fix:** use **Session pooler** URI (`*.pooler.supabase.com`, user often `postgres.<project-ref>`)
  - Free projects can **pause** after ~1 week of inactivity
- **Important:** Do not commit `DATABASE_URL` with a real password to GitHub

### G. Render Singapore — final app region

- **Why:** Page navigation felt slow (Oregon ↔ Singapore round-trips)
- **Constraint:** Render **cannot change region** on an existing service
- **Action:** Create a **new** Web Service in **Singapore**, same repo + env vars
- **Result:** Noticeably faster page switches
- Old Oregon service was no longer present in the dashboard (nothing left to delete)

### H. Railway shutdown

- Deleted **octracker** web service, **MySQL**, and volume
- Changes were staged as “Removed” until purple **Deploy** applied them
- Empty Railway project afterward — do not add a new DB there for this app

---

## 4. Final architecture (current)

| Layer | Tool | Notes |
|--------|------|--------|
| App | **Render** — Singapore, Free, Docker | Sleeps when idle (~15+ min) |
| Database | **Supabase** — Postgres, Free, Singapore | Connect via **Session pooler** |
| Email | **Brevo** | Domain auth on `octracker.live` |
| Domain / DNS | **Hostinger** | DNS for Brevo; optional custom domain to Render later |
| Source | **GitHub** | Includes Postgres support |
| Local dev | **XAMPP MySQL** | Still works locally; production cloud DB is Postgres |

**Live path:** Render (Singapore) + Supabase + Brevo  
**Retired:** Railway  

---

## 5. Render environment variables (no secrets in git)

| Key | Purpose |
|-----|---------|
| `DATABASE_URL` | Supabase Session pooler `postgresql://…` |
| `BREVO_API_KEY` | Outbound email |
| `BREVO_FROM` | Verified From address / name |

Removed for production cutover: `MYSQL_PUBLIC_URL` (Railway).

---

## 6. Problems & solutions (quick reference)

| Problem | Cause | Solution |
|---------|--------|----------|
| Gmail SMTP timeout | Host blocks SMTP ports | Brevo (HTTPS API) |
| Resend / freemail limits | Provider rules | Authenticate `octracker.live` + Brevo |
| Railway not free forever | Trial / Hobby billing | Move app + DB off Railway |
| Render free MySQL | Not offered | Supabase Postgres + code migration |
| Supabase “Network is unreachable” | Direct connection IPv6 | Session pooler URI |
| Slow first open | Render free spin-down | Expected; wait or keep warm |
| Slow page-to-page | App far from DB region | New Render service in Singapore |
| Bad `DATABASE_URL` after password reset | Direct URI or user/password mangled | Pooler + `postgres.<ref>:PASSWORD@host` |
| Cannot “switch region” | Render limitation | Recreate service in new region |
| Railway still showing services after delete | Changes only staged | Click **Deploy** to apply |

---

## 7. Notable code / repo artifacts

- `config/database.php` — MySQL + Postgres URL support (`DATABASE_URL`, SSL for Postgres)
- `admin/includes/db_compat.php` — portable schema helpers
- `Dockerfile` — `pdo_mysql` + `pdo_pgsql`
- `schema/postgres.sql`
- `tools/convert_mysql_dump_to_pgsql.php`, `tools/export_railway.php`, `tools/import_mysql_dump_to_pgsql.php`
- `SUPABASE.md` — cutover steps
- Student email domain → `@olivarezcollegetagaytay.edu.ph`
- Mail helper prefers Brevo/Resend over SMTP

---

## 8. Backups to keep offline (PC)

- MySQL-era dump (e.g. `octracker-railway-backup.sql`)
- Postgres import dump (e.g. `octracker-supabase.sql`)

Regenerate Postgres SQL from a MySQL dump when needed:

```powershell
cd octracker
php tools/convert_mysql_dump_to_pgsql.php "C:\path\to\mysql-backup.sql" "C:\path\to\octracker-supabase.sql"
```

---

## 9. Status checklist

- [x] App on Render Singapore (free)
- [x] DB on Supabase Postgres (free, Session pooler)
- [x] Brevo email working with `octracker.live`
- [x] Railway services deleted
- [x] Faster navigation after Singapore move
- [ ] Optional: point custom domain `octracker.live` at Render
- [ ] Remember free-tier sleep / Supabase pause if unused

---

## 10. One-line summary

Hardened OCTRacker, left flaky free shared hosts, ran on **Railway** (SMTP → **Brevo**), moved the **app to Render** and the **DB to Supabase** (MySQL→Postgres + pooler fix), relocated Render to **Singapore** for speed, then **shut Railway down**.
