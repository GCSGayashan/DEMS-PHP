-- Operational User Management roles may submit account requests. Role and
-- location authority remains enforced by UserAccessManagementService.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key='user.request'
WHERE r.role_code IN (
  'ASC_SUBJECT_OFFICER',
  'ASC_ADMIN',
  'DISTRICT_SUBJECT_OFFICER',
  'DISTRICT_ADMIN',
  'NATIONAL_SUBJECT_OFFICER',
  'NATIONAL_ADMIN'
);
