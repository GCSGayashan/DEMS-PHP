-- Office Assignment is the authoritative, effective-dated organizational context for Officers.
-- ARPA appointments and subject assignments remain separate duty/function records.

ALTER TABLE office
  ADD UNIQUE KEY uq_office_linked_location (linked_location_id);

CREATE TABLE officer_office_assignment (
  id CHAR(36) PRIMARY KEY,
  officer_id CHAR(36) NOT NULL,
  office_id CHAR(36) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  approval_status ENUM('DRAFT','SUBMITTED','APPROVED','RETURNED','REJECTED') NOT NULL DEFAULT 'DRAFT',
  reason VARCHAR(500) NOT NULL,
  official_reference VARCHAR(255) NULL,
  remarks TEXT NULL,
  created_by CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_by CHAR(36) NULL,
  submitted_at TIMESTAMP NULL,
  approved_by CHAR(36) NULL,
  approved_at TIMESTAMP NULL,
  ended_by CHAR(36) NULL,
  ended_at TIMESTAMP NULL,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_ooa_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_ooa_office FOREIGN KEY(office_id) REFERENCES office(id),
  CONSTRAINT fk_ooa_created_by FOREIGN KEY(created_by) REFERENCES system_user(id),
  CONSTRAINT fk_ooa_submitted_by FOREIGN KEY(submitted_by) REFERENCES system_user(id),
  CONSTRAINT fk_ooa_approved_by FOREIGN KEY(approved_by) REFERENCES system_user(id),
  CONSTRAINT fk_ooa_ended_by FOREIGN KEY(ended_by) REFERENCES system_user(id),
  INDEX idx_ooa_officer_current(officer_id,approval_status,active,effective_from,effective_to),
  INDEX idx_ooa_office_current(office_id,approval_status,active,effective_from,effective_to),
  INDEX idx_ooa_primary(officer_id,is_primary,approval_status,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE officer_office_assignment_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  assignment_id CHAR(36) NOT NULL,
  action_key VARCHAR(80) NOT NULL,
  previous_state_json JSON NULL,
  new_state_json JSON NOT NULL,
  reason TEXT NULL,
  actor_user_id CHAR(36) NULL,
  action_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ooa_audit_assignment FOREIGN KEY(assignment_id) REFERENCES officer_office_assignment(id),
  CONSTRAINT fk_ooa_audit_actor FOREIGN KEY(actor_user_id) REFERENCES system_user(id),
  INDEX idx_ooa_audit_assignment(assignment_id,action_at),
  INDEX idx_ooa_audit_actor(actor_user_id,action_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO application_permission
  (id,permission_key,module_code,description,protected_permission,active)
VALUES
  (UUID(),'officer.office-assignment.view','HR_OFFICER','View Officer Office assignments',1,1),
  (UUID(),'officer.office-assignment.create','HR_OFFICER','Create Officer Office assignment drafts',1,1),
  (UUID(),'officer.office-assignment.submit','HR_OFFICER','Submit Officer Office assignments',1,1),
  (UUID(),'officer.office-assignment.approve','HR_OFFICER','Approve Officer Office assignments',1,1),
  (UUID(),'officer.office-assignment.end','HR_OFFICER','End approved Officer Office assignments',1,1),
  (UUID(),'officer.office-assignment.set-primary','HR_OFFICER','Set the current primary Office assignment',1,1);

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
  ON p.permission_key='officer.office-assignment.view'
WHERE r.role_code IN (
  'SYSTEM_ADMIN','HR_ADMIN','HR_APPROVER','HR_VIEWER',
  'NATIONAL_ADMIN','NATIONAL_SUBJECT_OFFICER','NATIONAL_VIEWER',
  'DISTRICT_ADMIN','DISTRICT_SUBJECT_OFFICER','DISTRICT_VIEWER',
  'ASC_ADMIN','ASC_SUBJECT_OFFICER','ASC_VIEWER'
);

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
  ON p.permission_key IN (
    'officer.office-assignment.create','officer.office-assignment.submit',
    'officer.office-assignment.end','officer.office-assignment.set-primary'
  )
WHERE r.role_code IN ('SYSTEM_ADMIN','HR_ADMIN','NATIONAL_ADMIN','DISTRICT_ADMIN','ASC_ADMIN');

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
  ON p.permission_key='officer.office-assignment.approve'
WHERE r.role_code IN ('SYSTEM_ADMIN','HR_APPROVER','NATIONAL_ADMIN','DISTRICT_ADMIN','ASC_ADMIN');

-- Existing seeded Head Office predates the allocation ledger; preserve the number and make it auditable.
INSERT IGNORE INTO number_allocation(category_id,allocated_number,allocated_at)
SELECT nc.id,o.dad_number,o.created_at
FROM office o JOIN office_type ot ON ot.id=o.office_type_id AND ot.system_key='HEAD_OFFICE'
JOIN number_category nc ON nc.category_key='OFFICE';
