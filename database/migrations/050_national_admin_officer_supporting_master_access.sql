-- National Administrators maintain only the existing Officer Supporting
-- Master module. Active Working Context permission resolution remains the
-- authorization boundary for interactive requests.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key IN (
  'hr.master.view',
  'hr.master.create',
  'hr.master.edit',
  'hr.master.approve'
)
WHERE r.role_code='NATIONAL_ADMIN';
