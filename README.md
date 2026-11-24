# HIRAM Loan Management

This is a PHP and MySQL loan management app. It is **not production-ready until the external setup steps below are completed**. The repository does not include a database schema or migration, so do not point a fresh empty database at the application and expect it to install itself.

## Local setup

1. Start Apache and MySQL in XAMPP.
2. Use the existing `loan_system` database, or import a schema and data export from the database you already use. No schema export is included here.
3. Open `http://localhost/Loan_app/` and sign in.

Localhost uses the standard XAMPP database defaults in `Loan-system/config.php`. Production database settings are also configured in `Loan-system/config.php` for the current InfinityFree deployment.

## Database configuration

The production branch of `Loan-system/config.php` contains the InfinityFree database host, name, username, and password. Get these values from the hosting control panel; the database host is not `localhost`. This puts credentials in a Git-tracked source file, so keep the repository private and restrict repository access. A database password that has appeared in Git history should be changed in the hosting panel, then updated in `config.php`; removing it from the latest version does not erase old commits.

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

- Rotate any database password exposed in Git history and update the production settings in `Loan-system/config.php`.
- Export and back up the existing database. Confirm its tables, indexes, foreign keys, and decimal money columns; this repository has no schema/migration to create or verify them.
- Configure the existing GitHub Actions secrets `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, and `FTP_DIR` for deployment. The current workflow uploads the tracked `config.php`; it does not create a separate database config file.
- Confirm PHP 8.2 or later, PDO MySQL, and Fileinfo are available on the host.
- Confirm the host serves the site over HTTPS and that the private upload directory is writable but outside the web root.
- Test a full customer application, administrator approval, payment marking/reset, customer history access, and backup restoration on a staging copy before using real loan records.

The current deployment workflow uses FTP and does not run PHP syntax checks or database/end-to-end tests.
