-- Scoped operational roles may read Offices; ScopeService determines which Office rows are visible.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key='office.view'
WHERE r.role_code IN (
  'ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER',
  'DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_VIEWER',
  'NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN','NATIONAL_VIEWER'
);
