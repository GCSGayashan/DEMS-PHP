CREATE TABLE IF NOT EXISTS officer_edit_request (
  id CHAR(36) PRIMARY KEY,
  officer_id CHAR(36) NOT NULL,
  request_level ENUM('DISTRICT','NATIONAL') NOT NULL,
  district_location_id CHAR(36) NULL,
  officer_version BIGINT NOT NULL,
  before_json JSON NOT NULL,
  proposed_json JSON NOT NULL,
  workflow_status ENUM('SUBMITTED','RETURNED','APPROVED','REJECTED') NOT NULL DEFAULT 'SUBMITTED',
  submitted_by CHAR(36) NOT NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by CHAR(36) NULL,
  reviewed_at TIMESTAMP NULL,
  review_remarks VARCHAR(1000) NULL,
  approved_by CHAR(36) NULL,
  approved_at TIMESTAMP NULL,
  returned_by CHAR(36) NULL,
  returned_at TIMESTAMP NULL,
  return_reason VARCHAR(1000) NULL,
  rejected_by CHAR(36) NULL,
  rejected_at TIMESTAMP NULL,
  rejection_reason VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_officer_edit_request_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_officer_edit_request_district FOREIGN KEY(district_location_id) REFERENCES location(id),
  CONSTRAINT fk_officer_edit_request_submitter FOREIGN KEY(submitted_by) REFERENCES system_user(id),
  CONSTRAINT fk_officer_edit_request_reviewer FOREIGN KEY(reviewed_by) REFERENCES system_user(id),
  CONSTRAINT fk_officer_edit_request_approver FOREIGN KEY(approved_by) REFERENCES system_user(id),
  CONSTRAINT fk_officer_edit_request_returner FOREIGN KEY(returned_by) REFERENCES system_user(id),
  CONSTRAINT fk_officer_edit_request_rejecter FOREIGN KEY(rejected_by) REFERENCES system_user(id),
  KEY idx_officer_edit_request_queue(workflow_status,request_level,district_location_id,submitted_at),
  KEY idx_officer_edit_request_officer(officer_id,workflow_status,request_level),
  KEY idx_officer_edit_request_submitter(submitted_by,workflow_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO application_permission(id,permission_key,module_code,description,protected_permission,active) VALUES
(UUID(),'officer.edit-request','HR','Submit approved Officer profile changes for approval',1,1),
(UUID(),'officer.edit-approve','HR','Review and approve Officer profile edit requests',1,1);

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key='officer.edit-request' AND p.active=1
WHERE r.role_code IN('DISTRICT_SUBJECT_OFFICER','NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN');

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key='officer.edit-approve' AND p.active=1
WHERE r.role_code IN('DISTRICT_ADMIN','NATIONAL_ADMIN');
