-- ASC operational managers receive only the permissions needed by the
-- server-side role hierarchy and active working-context policy.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key IN (
  'user.view',
  'user.activate',
  'user.block',
  'user.reset-password',
  'user.assign-role',
  'user.revoke-role',
  'user.assign-scope',
  'user.revoke-scope'
)
WHERE r.role_code IN ('ASC_SUBJECT_OFFICER','ASC_ADMIN');
