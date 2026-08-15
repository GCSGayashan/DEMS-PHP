-- Read-only Officer directory access for the three established ASC roles.
-- ScopeService enforces current operational ASC visibility independently.
INSERT IGNORE INTO application_role_permission (role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key='officer.view' AND p.active=1
WHERE r.role_code IN ('ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER');
