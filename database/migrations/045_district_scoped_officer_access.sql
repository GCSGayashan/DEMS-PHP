-- District-scoped Officer directory access.
-- ScopeService restricts District users to their District and descendant ASC locations.

INSERT IGNORE INTO application_role_permission (role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p
  ON p.permission_key='officer.view'
 AND p.active=1
WHERE r.role_code IN (
  'DISTRICT_ADMIN',
  'DISTRICT_SUBJECT_OFFICER',
  'DISTRICT_VIEWER'
);

-- Editing is allowed for operational District roles, but not the read-only viewer.
INSERT IGNORE INTO application_role_permission (role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p
  ON p.permission_key='officer.edit'
 AND p.active=1
WHERE r.role_code IN (
  'DISTRICT_ADMIN',
  'DISTRICT_SUBJECT_OFFICER'
);