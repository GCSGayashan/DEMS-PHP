-- Read-only Officer directory access for established National roles.
-- ScopeService remains responsible for enforcing effective operational scope.

INSERT IGNORE INTO application_role_permission (role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p
  ON p.permission_key='officer.view'
 AND p.active=1
WHERE r.role_code IN (
  'NATIONAL_ADMIN',
  'NATIONAL_SUBJECT_OFFICER',
  'NATIONAL_VIEWER'
);