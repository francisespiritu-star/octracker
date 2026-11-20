# Deploy OCTRacker on Railway

## 1. Push this repo to GitHub
Repo: https://github.com/francisespiritu-star/octracker.git

## 2. Railway project
1. Open https://railway.app → login with GitHub
2. **New Project** → **Deploy from GitHub repo** → select `octracker`
3. Add a second service: **MySQL** (or **Database** → MySQL)

## 3. Connect the PHP app to MySQL
On the **PHP/web service** → **Variables**:
- Reference the MySQL service variables, or set:
  - `MYSQLHOST` = MySQL host
  - `MYSQLPORT` = MySQL port
  - `MYSQLDATABASE` = MySQL database name
  - `MYSQLUSER` = MySQL user
  - `MYSQLPASSWORD` = MySQL password

Railway often lets you **Variable Reference** these from the MySQL service automatically.

## 4. Public URL
On the web service → **Settings** → **Networking** → **Generate Domain**.

Open: `https://your-app.up.railway.app/login.php`

## 5. Import / seed database
Use Railway MySQL credentials with a MySQL client or one-off query to create tables and users (or import a phpMyAdmin dump from XAMPP).

Default local XAMPP login users must exist in the `users` table after import.
