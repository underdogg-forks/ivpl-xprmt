# InvoicePlane Deployment Guide

## Critical: Traffic Routing Through public/index.php

All HTTP traffic **must** route through `public/index.php`. This is the only entry point that initializes the application's bootstrap system.

### Why This Matters

The bootstrap system (`bootstrap/kernel.php`) is responsible for:
- Loading environment configuration from `ipconfig.php`
- Setting critical security headers (X-Frame-Options, Content-Security-Policy, Referrer-Policy)
- Configuring error reporting based on environment (ENVIRONMENT constant)
- Defining application path constants (FCPATH, APPPATH, BASEPATH, VIEWPATH)

If traffic bypasses `public/index.php`:
- Security headers are **not sent** → clickjacking attacks are possible
- Environment configuration may not load → database connections fail
- Error reporting may expose sensitive information

### Proper Web Server Configuration

#### Apache

Enable `.htaccess` support and use the committed `.htaccess` file:

```apache
# .htaccess in the document root (repo root)
# This should route all traffic to public/index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /
    
    # Skip files and directories
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    
    # Route to public/index.php
    RewriteRule ^(.*)$ public/index.php/$1 [L]
</IfModule>
```

#### Nginx

Configure the server block to route all requests to `public/index.php`:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/invoiceplane;

    # Route all requests to public/index.php unless file/directory exists
    location / {
        if (!-e $request_filename) {
            rewrite ^(.*)$ /public/index.php?/$1 last;
        }
    }

    # Configure PHP handler for public/index.php
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

#### IIS (10 or later)

IIS ignores `.htaccess`, so the project ships a `web.config` in the project root. It needs the **URL Rewrite**
module; without it IIS answers `500.19` and does not serve the site, so a missing module fails closed.

The rules answer `404` for the private folders (`application/`, `vendor/`, `storage/`, `resources/`,
`node_modules/`, `tests/`, `uploads/archive/`, `uploads/customer_files/`, `uploads/import/`, `uploads/temp/`),
dotfiles (except `.well-known/`, which Let's Encrypt needs), the project files (`composer.json`,
`composer.lock`, `package.json`, `*.md`, `ipconfig.php`, ...) and every PHP file other than the front controller
(`public/index.php`). They are anchored at the start of the URL, so routes such as `/index.php/import` are not
affected, and they ignore case and the Windows spellings of a path (repeated slashes, trailing dots and spaces).
Do not replace them with `<requestFiltering><hiddenSegments>`: that matches a segment anywhere in the URL and would
also block `/index.php/import`.

`web.config` applies when the IIS site root is the project root. With the site root set to `public/`
(recommended, see below) the private folders are not under the site at all.

Clean URLs (`REMOVE_INDEXPHP=true` in `ipconfig.php`): `web.config` contains a routing rule that sends requests for
files and folders that do not exist to the front controller. It ships switched off, because `REMOVE_INDEXPHP=false` is
the default and every URL then carries `index.php`. Set `enabled="true"` on the rule *Route clean URLs to the front
controller* and make `public/index.php` the default document of the site (a request for `/` is a folder and is not
routed). Without that rule IIS answers `404` for `/sessions/login`.

Check it after every deployment. A refusal check only proves something when the file exists, so create a canary
first (any content) and remove it afterwards:

```bash
echo canary > uploads/archive/iis-check.txt

# every one of these must answer 404; --path-as-is stops curl from tidying the path before it is sent
curl -s -o /dev/null -w "%{http_code}\n" --path-as-is https://your-domain.com/uploads/archive/iis-check.txt
curl -s -o /dev/null -w "%{http_code}\n" --path-as-is https://your-domain.com/uploads/archive./iis-check.txt
curl -s -o /dev/null -w "%{http_code}\n" --path-as-is "https://your-domain.com/uploads//archive/iis-check.txt"
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain.com/composer.json
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain.com/vendor/autoload.php
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain.com/ipconfig.php

# and the site itself must still answer 200
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain.com/index.php/sessions/login

rm uploads/archive/iis-check.txt
```

If the second command answers `200`, the rule does not cover the Windows spelling of the folder and the archive is
exposed. If the last one is not `200`, the rules are refusing the application itself.

PDFs archived by an older version keep their predictable `<date>_Invoice_<number>.pdf` names after an upgrade; the
rule for `uploads/archive/` is the only thing protecting them.

### Document Root Setup

**Recommended**: Set the document root to the `public/` directory:

```nginx
# Nginx
root /path/to/invoiceplane/public;

# Apache (in VirtualHost)
DocumentRoot /path/to/invoiceplane/public
```

This prevents direct access to sensitive files (`ipconfig.php`, `application/`, `vendor/`, etc.) and ensures all traffic naturally routes through `public/index.php`.

## Environment Configuration

### Required Environment Variables

Set these in your web server or application configuration:

```bash
# Application environment
CI_ENV=production              # or 'development' for debugging

# Database
DB_HOSTNAME=your-db-host
DB_PORT=3306
DB_DATABASE=invoiceplane
DB_USERNAME=invoiceplane_user
DB_PASSWORD='secure_password'

# Optional: Security headers
X_FRAME_OPTIONS=SAMEORIGIN    # or DENY
ENABLE_X_CONTENT_TYPE_OPTIONS=true

# Optional: Session configuration
SESS_SAVE_PATH=/path/to/sessions  # Outside document root
SESS_COOKIE_NAME=ip_session
SESS_REGENERATE_DESTROY=true
```

These are read by `bootstrap/kernel.php` via the `env()` helper function, which loads from `ipconfig.php`.

### ipconfig.php Location

The `ipconfig.php` file **must** be in the repository root:

```
/path/to/invoiceplane/
├── ipconfig.php          ← Read by bootstrap/kernel.php
├── public/
│   └── index.php         ← Single entry point
├── application/
├── bootstrap/
│   └── kernel.php        ← Loaded first by public/index.php
└── vendor/
```

## Security Headers Verification

After deployment, verify that security headers are being sent:

```bash
curl -I https://your-domain.com/

# Should show:
# X-Frame-Options: SAMEORIGIN
# Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'
# Referrer-Policy: strict-origin-when-cross-origin
# X-Content-Type-Options: nosniff
```

If these headers are missing, check:
1. Traffic is routing through `public/index.php`
2. `ipconfig.php` is readable by the web server
3. PHP error logs for bootstrap errors

## Cron Job Configuration

The `/cron` endpoint requires a configured cron key for rate-limit protection:

```bash
# Set in ipconfig.php or environment
CRON_IP_MAX_ATTEMPTS=10
CRON_IP_WINDOW_MINUTES=15

# Set the cron key in settings (via web UI) or environment
# Then configure your system cron to call the endpoint with that key
```

Example cron job:

```bash
# Every 5 minutes, run reminders and recurring invoices
*/5 * * * * curl -s https://your-domain.com/cron/recur?cron_key=YOUR_CRON_KEY >/dev/null 2>&1
```

## Deployment Checklist

- [ ] Document root set to `public/` directory (or rewrite rules configured)
- [ ] All HTTP/HTTPS traffic routes through `public/index.php`
- [ ] `ipconfig.php` is readable by web server process
- [ ] `ENVIRONMENT` set to `production`
- [ ] Database credentials configured (DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE)
- [ ] Security headers verified with `curl -I`
- [ ] Cron endpoint configured with correct cron key
- [ ] Logs folder (`application/logs/`) is writable by web server
- [ ] Uploads folder (`uploads/`) is writable by web server

## Troubleshooting

### "No direct script access allowed" Error

This means traffic is not routing through `public/index.php`. Check:
- `.htaccess` file is in place and mod_rewrite is enabled (Apache)
- Nginx rewrite rules are correct
- Document root is not set to `public/` (if using rewrites from repo root)

### Security Headers Missing

1. Verify traffic routes through `public/index.php`
2. Check that `ipconfig.php` can be read by PHP: `php -r "readfile('ipconfig.php')" | head`
3. Check PHP error logs for bootstrap errors
4. Verify `PHP_SAPI !== 'cli'` is true (not in CLI mode)

### Database Connection Failed

1. Verify `DB_HOSTNAME`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE` in `ipconfig.php`
2. Test connection manually: `mysql -h $DB_HOSTNAME -u $DB_USERNAME -p$DB_PASSWORD $DB_DATABASE -e "SELECT 1"`
3. Check PHP error logs for connection errors
4. Verify MariaDB/MySQL is running and accessible from the web server

## References

- [Project README](../README.md) — General project information
- [CLAUDE.md](../CLAUDE.md) — Development guide with testing instructions
- [.github/workflows/php-lint.yml](../.github/workflows/php-lint.yml) — CI/CD configuration
