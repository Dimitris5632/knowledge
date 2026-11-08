# Deploy to Azure App Service

Tested setup: **App Service Linux, PHP 8.3, plan F1/B1** + **Azure Database for MySQL Flexible Server (Burstable B1ms)**.

## 1. Database (once)
1. Create a MySQL Flexible Server (B1ms, 20 GiB, public access, "Allow Azure services", your client IP).
2. Add a database `knowledgebase` (utf8mb4 / utf8mb4_unicode_ci).
3. In Cloud Shell, upload `migrations/001_initial_schema.sql` and run:
   ```bash
   mysql -h <server>.mysql.database.azure.com -u <admin> -p --ssl knowledgebase < ~/001_initial_schema.sql
   ```
4. Create a least-privilege user that sees **only** this database:
   ```sql
   CREATE USER 'kbuser'@'%' IDENTIFIED BY '<strong password>';
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES ON knowledgebase.* TO 'kbuser'@'%';
   ```
   Existing installs: run the files in `migrations/upgrades/` you have not run yet (`extras4.sql`, `extras5.sql`).

## 2. Web app (once)
1. App Service → Linux → PHP 8.3, same or nearby region.
2. **Settings → Environment variables:**

   | Name | Value |
   |---|---|
   | `DB_HOST` | `<server>.mysql.database.azure.com` |
   | `DB_NAME` | `knowledgebase` |
   | `DB_USER` / `DB_PASS` | `kbuser` / its password |
   | `DB_SSL` | `1` |
   | `TRUST_PROXY` | `1` |
   | `APP_ENV` | `production` |
   | `UPLOAD_DIR` | `/home/data/uploads` |
   | `MAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` | Brevo |
   | `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` | Cloudflare (optional) |

3. **Settings → Configuration → General settings → Startup Command:**
   ```
   sh /home/site/wwwroot/deploy/azure/startup.sh
   ```
   This points nginx at `public/`. (If it is missing, the root `index.php` redirects visitors to `/public/`, so the site still works.)
4. Same page: FTP **Disabled**, SCM/FTP Basic Auth **Off**, Minimum TLS **1.2**, HTTPS Only **On** → Save.

## 3. Deploy a new version
```bash
sh deploy/build-zip.sh          # creates build/knowledge_site.zip
```
Cloud Shell → Manage files → Upload `knowledge_site.zip` →
```bash
az webapp deploy --resource-group <group> --name <app> --src-path ~/knowledge_site.zip --type zip
```
Uploads, photos and videos live in `/home/data` and are **not** touched by a deploy.
**Always run new migrations before deploying the code that needs them.**
