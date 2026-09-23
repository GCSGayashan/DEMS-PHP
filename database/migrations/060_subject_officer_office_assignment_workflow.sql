-- Persist the Active Working Context that governs each operational Office
-- Assignment request. The assignment row remains both the request and the
-- canonical effective-dated relationship after approval.
ALTER TABLE officer_office_assignment
  ADD COLUMN workflow_origin_role_code VARCHAR(80) NULL AFTER version,
  ADD COLUMN workflow_scope_location_id CHAR(36) NULL AFTER workflow_origin_role_code,
  ADD KEY idx_ooa_workflow_queue (approval_status,workflow_origin_role_code,workflow_scope_location_id,submitted_at),
  ADD CONSTRAINT fk_ooa_workflow_scope_location FOREIGN KEY(workflow_scope_location_id) REFERENCES location(id);

-- Reuse the existing Office Assignment creation/submission permissions.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p
  ON p.permission_key IN('officer.office-assignment.create','officer.office-assignment.submit')
 AND p.active=1
WHERE r.role_code IN('DISTRICT_SUBJECT_OFFICER','NATIONAL_SUBJECT_OFFICER');
