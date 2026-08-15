# DEMS PHP / Bootstrap / MySQL Edition

Standalone PHP edition of the Department Enterprise Management System (DEMS), structured similarly to the existing `agrarian.lk` custom PHP MVC application.

This project is intentionally separate from the Spring Boot/React/PostgreSQL DEMS codebase.

## Technology

- PHP 8.2+
- Apache (WAMP/LAMP)
- MySQL 8+ or compatible MariaDB
- Bootstrap 5
- Vanilla JavaScript
- PDO/MySQL
- Custom lightweight MVC/router (no Composer dependency required)

## Current included scope

### Organization / Location
- Enterprise numbering framework
- Location Types: Province, District, DS Division, ASC, AI Range, Mahaweli Division, ARPA Division, GN Division
- Location Master
- Effective-dated location hierarchy table
- Office Types: Head Office, District Office, ASC Office
- Minimal Office Master
- Draft / Submit / Approve workflow with maker-checker

### Human Resource Management - minimal Officer scope
- Officer Supporting Masters
  - Titles
  - Appointment Natures
  - Main/Sub Designations
  - Classes
  - Officer Statuses
  - Civil Statuses
- Officer Master
- Sri Lankan old/new NIC format validation
- DAD Officer number generation
- Expected retirement date = DOB + 60 years
- Primary designation, class, office and appointment nature
- Draft / Submit / Approve workflow with maker-checker

### User Management
- Internal standalone authentication for the PHP edition
- User Accounts
- User Account Requests
- Roles
- Permissions
- Custom role creation
- Role Assignments
- Geographic Scope Assignments
- Provisioning Failure register / integration boundary
- Security Audit History
- Effective-dated roles and scopes
- Protected and legacy role model
- Administrator CLI bootstrap

### Standard user levels
- SYSTEM_ADMIN
- SECURITY_ADMIN
- USER_ADMIN
- NATIONAL_ADMIN
- NATIONAL_SUBJECT_OFFICER
- NATIONAL_VIEWER
- DISTRICT_ADMIN
- DISTRICT_SUBJECT_OFFICER
- DISTRICT_VIEWER
- ASC_ADMIN
- ASC_SUBJECT_OFFICER
- ASC_VIEWER
- ARPA_OFFICER
- FARMER

ARPA Officer permissions include Create, Edit, Verify, Reject and View; no Approve or Process.

### Generic permission matrix
- data.view
- data.create
- data.edit
- data.verify
- data.reject
- data.approve
- data.process
- report.view

The application also seeds granular Location, Office, HR, Officer and User Management permissions.

## Authentication architecture

The original Java DEMS uses Keycloak. This standalone PHP edition uses internal MySQL authentication so it can run like the existing `agrarian.lk` PHP system without requiring Keycloak.

The `system_user` table retains a nullable `keycloak_subject_id` so an OIDC/Keycloak adapter can be added later without redesigning the identity tables.

Passwords are stored only with PHP `password_hash()` and verified with `password_verify()`.

## Windows / WAMP installation

Copy the folder to:

```text
D:\wamp64\www\DEMS-PHP
```

Make sure PHP has these extensions enabled:

```text
pdo
pdo_mysql
mbstring
openssl
```

Apache `mod_rewrite` should be enabled.

### 1. Create `.env`

From PowerShell:

```powershell
Set-Location "D:\wamp64\www\DEMS-PHP"
Copy-Item .env.example .env
notepad .env
```

Example configuration:

```env
APP_NAME="Department Enterprise Management System"
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost/DEMS-PHP/public
APP_TIMEZONE=Asia/Colombo

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=dems_php
DB_USERNAME=root
DB_PASSWORD=
```

### 2. Run migrations

```powershell
Set-Location "D:\wamp64\www\DEMS-PHP"
php .\bin\migrate.php
```

The migration runner creates the database automatically when the configured MySQL user has `CREATE DATABASE` permission.

### 3. Create the first administrator

```powershell
Set-Location "D:\wamp64\www\DEMS-PHP"
php .\bin\create-admin.php dems.admin "CHANGE-THIS-TO-A-STRONG-PASSWORD"
```

Use a password of at least 12 characters.

This creates/reuses the user and gives it the protected `SYSTEM_ADMIN` role.

### 4. Open DEMS

```text
http://localhost/DEMS-PHP/public
```

Login with the administrator created above.

## Recommended Apache VirtualHost

For a cleaner URL, point an Apache VirtualHost DocumentRoot directly to:

```text
D:/wamp64/www/DEMS-PHP/public
```

Then set `APP_URL` to the VirtualHost URL.

## Linux / Apache installation

Example target:

```text
/var/www/dems_php
```

```bash
cp .env.example .env
php bin/migrate.php
php bin/create-admin.php dems.admin 'CHANGE-THIS-PASSWORD'
```

Set Apache DocumentRoot to `/var/www/dems_php/public` and allow `.htaccess` overrides, or route all requests to `public/index.php` in the site config.

## Project structure

```text
DEMS-PHP/
├── app/
│   ├── Controllers/
│   ├── Core/
│   └── Views/
├── bin/
│   ├── create-admin.php
│   └── migrate.php
├── config/
├── database/
│   └── migrations/
├── public/
│   ├── assets/
│   ├── .htaccess
│   └── index.php
├── routes/
│   └── web.php
├── storage/
├── bootstrap.php
├── .env.example
└── README.md
```

## Maker-checker

Office, Officer, HR supporting master and custom Role workflows include maker-checker controls. The user who created a record cannot approve that same record.

For realistic UAT, create a second administrator/approver account and give it an appropriate role.

## Role + permission + scope model

Effective application access is designed as:

```text
Active User
+ Approved Active Role Assignment
+ Permission on Role
+ Approved Effective Scope
= Effective Access
```

`App\Core\ScopeService` contains the reusable effective-dated Location hierarchy check for `EXACT`, `INCLUDE_CHILDREN` and `NATIONAL` scope behavior.

## Legacy roles

Legacy module roles are retained for compatibility but seeded as non-assignable and marked `LEGACY`:

- HR_ADMIN
- HR_APPROVER
- HR_VIEWER
- LOCATION_ADMIN
- LOCATION_APPROVER
- LOCATION_VIEWER
- AUDITOR
- SYSTEM_USER

They are hidden from the normal Roles page unless `Show Legacy Roles` is selected.

## Important production items still requiring environment integration

The PHP edition contains the data model and UI boundary for these items but they should be completed/configured before production rollout:

- Production MFA enforcement
- Department SMS gateway OTP provider
- Email delivery / password recovery
- File/photograph upload storage hardening
- Full Keycloak/OIDC adapter, only if the PHP edition will use Keycloak
- Background schedulers for future-effective activation and expiry
- Full notification service
- Detailed Farmer profile/module and Farmer self-service ownership checks
- Full ARPA Officer HR assignment workflow
- Transfers, promotions and other deferred HR functions
- Bulk imports and legacy HR migration

## One-time legacy Location migration

The administrative CLI imports Location data only from the separately configured
AgrarianAdmin staging database. Apply target migrations first, configure the
`LEGACY_DB_*` and `LEGACY_LOCATION_EFFECTIVE_FROM` values shown in `.env.example`,
then validate without operational writes:

```powershell
php .\bin\migrate.php
php .\bin\migrate-legacy-locations.php --dry-run
```

Review the CSV/JSON files under `storage/reports` before explicitly running
`--execute`. The CLI is not exposed through web routes. Type-scoped runs support
`--type=province|district|ds|asc|arpa|gn`; their prerequisite parent Locations
must already exist in DEMS. The default batch size is 500 and can be changed with
`--batch-size=N`.

## Server-side list standard

All implemented database-backed list screens use the common Bootstrap DataTables
infrastructure with prepared server-side pagination, filtering, safe sorting, and
authorized CSV export. New modules must follow
[`docs/DATATABLE_STANDARD.md`](docs/DATATABLE_STANDARD.md) instead of loading rows
into a PHP view or writing page-specific pagination JavaScript.

## Security notes

- Do not expose the project root as the web DocumentRoot; expose only `/public` where practical.
- Keep `.env` outside source control.
- Use HTTPS in production.
- Use a dedicated MySQL account with least privileges.
- Replace development passwords before deployment.
- Do not disable maker-checker for production convenience.
- `SYSTEM_ADMIN` has all application permissions but does not automatically bypass maker-checker.
