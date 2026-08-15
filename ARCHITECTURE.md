# DEMS PHP Architecture

## Request flow

```text
Apache
  -> public/index.php
  -> Router
  -> Controller
  -> Core services / PDO
  -> MySQL
  -> View
  -> Bootstrap layout
```

## Core services

- `Auth`: internal session authentication + effective permissions
- `NumberService`: enterprise DAD number allocation using row locking
- `WorkflowService`: maker-checker submit/approve operations
- `ScopeService`: effective-dated EXACT / INCLUDE_CHILDREN / NATIONAL checks
- `Audit`: security/business audit event writer
- `Csrf`: state-changing request protection
- `Database`: single PDO connection factory

## Main tables

### Numbering
- `number_category`
- `number_allocation`

### Location / Organization
- `location_type`
- `location`
- `location_relationship`
- `office_type`
- `office`

### Minimal HR
- `hr_title`
- `appointment_nature`
- `designation`
- `officer_class`
- `designation_allowed_class`
- `officer_status`
- `civil_status`
- `officer`

### Access management
- `system_user`
- `application_role`
- `application_permission`
- `application_role_permission`
- `user_account_role`
- `user_account_scope`
- `provisioning_failure`
- `audit_event`

## Business role matrix

| Role | Create | Edit | Verify | Reject | Approve | Process | View |
|---|---:|---:|---:|---:|---:|---:|---:|
| SYSTEM_ADMIN | Y | Y | Y | Y | Y | Y | Y |
| NATIONAL_ADMIN | N | N | N | Y | Y | Y | Y |
| NATIONAL_SUBJECT_OFFICER | Y | Y | Y | Y | N | N | Y |
| NATIONAL_VIEWER | N | N | N | N | N | N | Y |
| DISTRICT_ADMIN | N | N | N | Y | Y | Y | Y |
| DISTRICT_SUBJECT_OFFICER | Y | Y | Y | Y | N | N | Y |
| DISTRICT_VIEWER | N | N | N | N | N | N | Y |
| ASC_ADMIN | N | N | N | Y | Y | Y | Y |
| ASC_SUBJECT_OFFICER | Y | Y | Y | Y | N | N | Y |
| ASC_VIEWER | N | N | N | N | N | N | Y |
| ARPA_OFFICER | Y | Y | Y | Y | N | N | Y |
| FARMER | Own | Workflow limited | N | N | N | N | Own |

## Scope rules

- `SYSTEM` / `NATIONAL`: National scope
- `DISTRICT`: one or more District scopes, typically `INCLUDE_CHILDREN`
- `ASC`: one or more ASC scopes, typically `INCLUDE_CHILDREN`
- `ARPA`: one or more ARPA Division scopes
- `FARMER`: no administrative scope; future own-record link
- `CUSTOM`: scope according to approved custom security design

Scope rows are attached to an approved role assignment and use separate effective periods.

## Maker-checker

The initial PHP port enforces maker-checker for:

- Offices
- Officers
- HR supporting masters
- Custom roles
- User account requests
- User role assignments
- User scope assignments

A creator cannot approve their own record.
