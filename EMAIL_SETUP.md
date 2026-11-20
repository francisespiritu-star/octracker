# Email / SMTP setup (Railway)

OCTRacker sends mail through `admin/includes/mail_helper.php`.

## Railway variables (octracker service)

Add these under **Variables**:

| Name | Example |
|------|---------|
| `SMTP_HOST` | `smtp-relay.brevo.com` |
| `SMTP_PORT` | `587` |
| `SMTP_USER` | your Brevo/Gmail/SendGrid username |
| `SMTP_PASS` | your SMTP key / app password |
| `SMTP_FROM` | a verified sender, e.g. `you@gmail.com` |
| `SMTP_SECURE` | `tls` (default for 587) |

Redeploy after saving variables.

## Free provider options

### Brevo (Sendinblue)
1. Sign up at https://www.brevo.com  
2. SMTP & API → SMTP  
3. Use their host/user/key as above  

### Gmail
1. Google Account → App passwords (2FA required)  
2. `SMTP_HOST=smtp.gmail.com`  
3. `SMTP_PORT=587`  
4. `SMTP_USER=you@gmail.com`  
5. `SMTP_PASS=` the 16-character app password  
6. `SMTP_FROM=you@gmail.com`  

Without SMTP vars, **Send Email** falls back to **Open in Email App** (mailto).
