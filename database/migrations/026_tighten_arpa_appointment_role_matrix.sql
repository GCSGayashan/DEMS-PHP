-- Tighten the existing ARPA Appointment workflow roles without changing user assignments.
-- This migration is intentionally limited to ARPA workflow permissions for the nine
-- established ASC, District, and National roles.

INSERT IGNORE INTO application_permission
  (id, permission_key, module_code, description, protected_permission, active)
VALUES
  (UUID(), 'arpa.appointment.edit', 'ARPA_APPOINTMENT', 'Edit an eligible ASC-originated ARPA appointment request', 1, 1),
  (UUID(), 'arpa.appointment.district-review-edit', 'ARPA_APPOINTMENT', 'Enter or edit District ARPA appointment review information', 1, 1),
  (UUID(), 'arpa.appointment.national-review-edit', 'ARPA_APPOINTMENT', 'Enter or edit National ARPA appointment review information', 1, 1);

CREATE TABLE IF NOT EXISTS arpa_appointment_stage_review (
  id CHAR(36) PRIMARY KEY,
  entity_type ENUM('DIVISION','SUBJECT') NOT NULL,
  request_id CHAR(36) NOT NULL,
  review_stage ENUM('DISTRICT','NATIONAL') NOT NULL,
  review_information TEXT NULL,
  remarks TEXT NULL,
  updated_by CHAR(36) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_arpa_stage_review_user FOREIGN KEY (updated_by) REFERENCES system_user(id),
  UNIQUE KEY uq_arpa_stage_review_request (entity_type, request_id, review_stage),
  INDEX idx_arpa_stage_review_actor (updated_by, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arpa_appointment_stage_review_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  stage_review_id CHAR(36) NOT NULL,
  entity_type ENUM('DIVISION','SUBJECT') NOT NULL,
  request_id CHAR(36) NOT NULL,
  review_stage ENUM('DISTRICT','NATIONAL') NOT NULL,
  previous_review_json JSON NULL,
  new_review_json JSON NOT NULL,
  changed_by CHAR(36) NOT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_stage_review_audit_review FOREIGN KEY (stage_review_id) REFERENCES arpa_appointment_stage_review(id),
  CONSTRAINT fk_arpa_stage_review_audit_user FOREIGN KEY (changed_by) REFERENCES system_user(id),
  INDEX idx_arpa_stage_review_audit_request (entity_type, request_id, review_stage, changed_at),
  INDEX idx_arpa_stage_review_audit_actor (changed_by, changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remove only the ARPA workflow/assignment permission mappings addressed by this
-- matrix. Legacy preview/reconciliation and every non-ARPA permission remain intact.
DELETE rp
FROM application_role_permission rp
JOIN application_role r ON r.id = rp.role_id
JOIN application_permission p ON p.id = rp.permission_id
WHERE r.role_code IN (
  'ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER',
  'DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_VIEWER',
  'NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN','NATIONAL_VIEWER'
)
AND (
  p.permission_key LIKE 'arpa.appointment.%'
  OR p.permission_key IN ('arpa.subject.create','arpa.subject.end','arpa.subject.manage')
);

INSERT IGNORE INTO application_role_permission (role_id, permission_id)
SELECT r.id, p.id
FROM application_role r
JOIN application_permission p
  ON (r.role_code = 'ASC_SUBJECT_OFFICER' AND p.permission_key IN (
        'arpa.appointment.view','arpa.appointment.create','arpa.appointment.edit',
        'arpa.appointment.submit','arpa.appointment.asc-verify',
        'arpa.appointment.end','arpa.appointment.transfer',
        'arpa.subject.create','arpa.subject.end'
      ))
  OR (r.role_code = 'ASC_ADMIN' AND p.permission_key IN (
        'arpa.appointment.view','arpa.appointment.asc-approve',
        'arpa.appointment.return','arpa.appointment.reject'
      ))
  OR (r.role_code = 'ASC_VIEWER' AND p.permission_key = 'arpa.appointment.view')
  OR (r.role_code = 'DISTRICT_SUBJECT_OFFICER' AND p.permission_key IN (
        'arpa.appointment.view','arpa.appointment.district-review-edit',
        'arpa.appointment.district-verify','arpa.appointment.return'
      ))
  OR (r.role_code = 'DISTRICT_ADMIN' AND p.permission_key IN (
        'arpa.appointment.view','arpa.appointment.district-approve',
        'arpa.appointment.return','arpa.appointment.reject'
      ))
  OR (r.role_code = 'DISTRICT_VIEWER' AND p.permission_key = 'arpa.appointment.view')
  OR (r.role_code = 'NATIONAL_SUBJECT_OFFICER' AND p.permission_key IN (
        'arpa.appointment.view','arpa.appointment.national-review-edit',
        'arpa.appointment.national-verify','arpa.appointment.return'
      ))
  OR (r.role_code = 'NATIONAL_ADMIN' AND p.permission_key IN (
        'arpa.appointment.view','arpa.appointment.national-approve',
        'arpa.appointment.return','arpa.appointment.reject'
      ))
  OR (r.role_code = 'NATIONAL_VIEWER' AND p.permission_key = 'arpa.appointment.view')
WHERE r.role_code IN (
  'ASC_SUBJECT_OFFICER','ASC_ADMIN','ASC_VIEWER',
  'DISTRICT_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_VIEWER',
  'NATIONAL_SUBJECT_OFFICER','NATIONAL_ADMIN','NATIONAL_VIEWER'
);
