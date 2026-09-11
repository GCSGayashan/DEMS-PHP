-- Bind pending Officer records to the maker's Active Working Context.
ALTER TABLE officer
  ADD COLUMN workflow_origin_role_code VARCHAR(80) NULL AFTER action_reason,
  ADD COLUMN workflow_scope_location_id CHAR(36) NULL AFTER workflow_origin_role_code,
  ADD KEY idx_officer_workflow_queue (approval_status,workflow_origin_role_code,workflow_scope_location_id),
  ADD CONSTRAINT fk_officer_workflow_scope_location FOREIGN KEY(workflow_scope_location_id) REFERENCES location(id);

-- Reuse the established Officer workflow permissions.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key IN('officer.view','officer.create','officer.edit','officer.submit') AND p.active=1
WHERE r.role_code IN('DISTRICT_SUBJECT_OFFICER','NATIONAL_SUBJECT_OFFICER');

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key IN('officer.view','officer.approve','officer.return','officer.view-history') AND p.active=1
WHERE r.role_code IN('DISTRICT_ADMIN','NATIONAL_ADMIN');

-- Subject Officers are makers, never Officer approvers.
DELETE rp
FROM application_role_permission rp
JOIN application_role r ON r.id=rp.role_id AND r.role_code IN('DISTRICT_SUBJECT_OFFICER','NATIONAL_SUBJECT_OFFICER')
JOIN application_permission p ON p.id=rp.permission_id AND p.permission_key='officer.approve';
