# OCTRacker on Render + Supabase (free Postgres)

Goal: **Render** runs PHP; **Supabase** holds the database; **Railway can be shut down** after cutover.

## Limits (free)
- Supabase free DB can **pause after ~1 week** of inactivity.
- Render free web **sleeps** when idle (slow first open).

## 1. Create Supabase project
1. https://supabase.com → New project  
2. Save the DB password  
3. **Project Settings → Database → Connection string → URI**  
   Use the **Session** pooler or direct connection URI  
   Example shape: `postgresql://postgres.[ref]:[PASSWORD]@aws-0-….pooler.supabase.com:5432/postgres`

## 2. Import your MySQL data
A Postgres-ready file was generated from your Railway dump:

`C:\Users\Jensen Manalo\Downloads\octracker-supabase.sql`

1. Supabase → **SQL Editor** → New query  
2. Paste the full contents of `octracker-supabase.sql`  
3. **Run**  

To regenerate later (from a fresh MySQL dump):

```powershell
cd "C:\Users\Jensen Manalo\Downloads\octracker\octracker"
php tools/convert_mysql_dump_to_pgsql.php "C:\path\to\mysql-backup.sql" "C:\Users\Jensen Manalo\Downloads\octracker-supabase.sql"
```

## 3. Point Render at Supabase
Render → **octracker** → **Environment**:

| Key | Value |
|-----|--------|
| `DATABASE_URL` | Your Supabase `postgresql://…` URI |
| `BREVO_API_KEY` | (keep) |
| `BREVO_FROM` | (keep) |

**Remove or leave unused:** `MYSQL_PUBLIC_URL` (Railway).  
If both are set, `DATABASE_URL` is preferred when it is `postgres://`.

**Save** → **Manual Deploy** / clear cache & deploy.

## 4. Test
1. Open `https://octracker.onrender.com/login.php`  
2. Login (same users as before: e.g. `superadmin`)  
3. Check students + Send Email  

## 5. Shut down Railway
Only after login works on Render + Supabase:

1. Railway → delete/stop **MySQL** and old **web** service  
2. Keep your `.sql` backups on disk  

## Local / XAMPP
Still **MySQL** by default (`localhost`). No change required for local dev.

## Code notes
- `config/database.php` supports MySQL **and** Postgres URLs  
- `admin/includes/db_compat.php` portable schema helpers  
- Docker image includes `pdo_pgsql` for Render  
