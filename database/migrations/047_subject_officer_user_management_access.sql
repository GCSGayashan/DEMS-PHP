-- Subject Officers may administer only the operational roles allowed by the
-- server-side UserAccessManagementService matrix and selected working context.
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
WHERE r.role_code IN ('NATIONAL_SUBJECT_OFFICER','DISTRICT_SUBJECT_OFFICER');
