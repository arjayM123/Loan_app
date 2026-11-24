# HIRAM Loan Management

This is a PHP and MySQL loan management app. It is **not production-ready until the external setup steps below are completed**. The repository does not include a database schema or migration, so do not point a fresh empty database at the application and expect it to install itself.

## Local setup

1. Start Apache and MySQL in XAMPP.
2. Use the existing `loan_system` database, or import a schema and data export from the database you already use. No schema export is included here.
3. Open `http://localhost/Loan_app/` and sign in.

Localhost uses the standard XAMPP database defaults in `Loan-system/config.php`. Production credentials are not stored in that file.

## Database configuration

For production, configure `LOAN_DB_HOST`, `LOAN_DB_NAME`, `LOAN_DB_USER`, and `LOAN_DB_PASS` in the hosting environment. If the host cannot set environment variables, copy `Loan-system/private_config.example.php` to `Loan-system/private_config.php` and enter the database details there. Never commit that private file. It is Git-ignored and Apache is configured to deny direct web requests to it. Storing the file outside the web root is safer; set `LOAN_PRIVATE_CONFIG_FILE` to its absolute path when the host supports environment variables.

The database password that was previously in the repository must be changed in the database hosting panel. Then update the private configuration with the new password. Removing it from the current source does not erase older Git history.

## Private ID document storage

New ID images are validated and stored outside the public web directory. By default, the app creates `loan_app_private_uploads` next to the document root. The PHP process must be able to write to that location. If the host requires another location, set `LOAN_PRIVATE_UPLOAD_DIR` to an absolute writable directory that is outside the web root. The upload route checks the current user's loan ownership or administrator role before returning a document.

The old `User_pages/uploads` directory is denied direct web access on Apache. If the server uses Nginx or another web server, configure an equivalent deny rule. Previously committed JPGs remain in Git history; keep the repository private and remove historical copies before making it public.

## Admin account

The old browser-accessible admin password reset page is gone. `setup_admin.php` now runs only from a command line and can promote an already registered account:

```text
$env:APP_ENV = 'local'
C:\xampp\php\php.exe .\setup_admin.php existing-admin-email@example.com
Remove-Item Env:APP_ENV
```

Run this in PowerShell only if you need to promote an existing local account. The browser app detects XAMPP localhost automatically.

On shared hosting without command-line access, promote the intended account through the hosting database console. Never expose an unauthenticated web-based admin reset page.

## Deployment checklist

Before deploying:

- Rotate the database password and configure the new private credentials.
- Export and back up the existing database. Confirm its tables, indexes, foreign keys, and decimal money columns; this repository has no schema/migration to create or verify them.
- Add these GitHub Actions repository secrets: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_DIR`, `LOAN_DB_HOST`, `LOAN_DB_NAME`, `LOAN_DB_USER`, and `LOAN_DB_PASS`. The workflow creates the ignored private database config from the four database secrets during deployment.
- If the default private upload folder is not writable, also add `LOAN_PRIVATE_UPLOAD_DIR` with an absolute path outside the web root.
- The workflow uses explicit FTPS with certificate verification; deploy only if the host supports it.
- Confirm PHP 8.2 or later, PDO MySQL, and Fileinfo are available on the host.
- Confirm the host serves the site over HTTPS and that the private upload directory is writable but outside the web root.
- Test a full customer application, administrator approval, payment marking/reset, customer history access, and backup restoration on a staging copy before using real loan records.

The deployment workflow runs PHP syntax checks, but it does not replace database or end-to-end testing.
