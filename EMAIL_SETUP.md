# Email setup (Railway)

Railway often **blocks Gmail SMTP** (`Connection timed out` on port 587/465).
Use an **HTTPS email API** instead.

## Recommended: Resend (free tier)

1. Sign up at https://resend.com  
2. Create an API key  
3. For quick tests, Resend allows: `OCTRacker <onboarding@resend.dev>`  
   (may only send to your own signup email until you verify a domain)  
4. Railway → **octracker → Variables**:

| Name | Value |
|------|--------|
| `RESEND_API_KEY` | `re_...` |
| `RESEND_FROM` | `OCTRacker <onboarding@resend.dev>` |

5. Redeploy and test **Send Email**

You can leave the Gmail `SMTP_*` vars in place; Resend is tried first.

## Alternative: Brevo API

| Name | Value |
|------|--------|
| `BREVO_API_KEY` | your Brevo API key |
| `BREVO_FROM` | a verified sender email |

## Gmail SMTP (often fails on Railway)

`SMTP_HOST=smtp.gmail.com`, port `587` / `465` — may time out on Railway.
Keep **Open in Email App** as the demo fallback.
