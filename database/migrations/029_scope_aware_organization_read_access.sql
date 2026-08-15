-- Scoped operational roles need Organization read access. Geographic visibility is
-- enforced independently by ScopeService; this grants no mutation permission and
-- creates no user role or scope assignment.
INSERT IGNORE INTO application_role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM application_role r
JOIN application_permission p ON p.permission_key='location.view' AND p.active=1
WHERE r.role_code IN (
  'ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER',
  'DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_VIEWER',
  'NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN','NATIONAL_VIEWER'
);
