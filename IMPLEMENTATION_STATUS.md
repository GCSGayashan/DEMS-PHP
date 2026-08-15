# Implementation status

## Implemented in this PHP edition

- Lightweight MVC routing/layout matching the existing Agrarian custom PHP style
- Full-width responsive Bootstrap admin shell
- MySQL migrations and seed data
- Enterprise number allocation
- Location types / location list / location create
- Effective-dated location relationships storage and hierarchy viewer
- Office master create/list + submit/approve
- HR supporting masters with separate route-synchronized tabs
- Supporting master create + submit/approve
- Minimal officer create/list + submit/approve
- Internal user authentication and CLI administrator bootstrap
- User account requests with maker-checker activation
- Protected standard role catalogue
- Hierarchical National/District/ASC/ARPA/Farmer roles
- Generic data permission matrix
- Custom role creation and permission selection
- Role assignment
- Geographic scope assignment
- Security audit history
- Legacy role visibility separation
- Effective-date scope helper

## Deliberately deferred / integration dependent

- Actual production SMS OTP gateway
- Enforced TOTP/SMS MFA challenge during login
- Full document / photograph upload lifecycle
- Production mail service
- Farmer profile table and own-record portal authorization
- Detailed ARPA Officer HR assignment screens
- Complete Location relationship editing UI and all relationship business validations
- Bulk imports/exports
- Notifications/background jobs
- Transfers/promotions/full HR service history
- Payroll, leave, attendance, disciplinary modules
- Legacy MySQL HR data migration automation

The codebase is structured so these can be added without replacing the core identity, role, permission, scope or numbering tables.
