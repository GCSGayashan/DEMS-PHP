ALTER TABLE officer
  ADD COLUMN arpa_service_permanency ENUM('PERMANENT_IN_SERVICE','NOT_PERMANENT_IN_SERVICE') NULL AFTER class_id,
  ADD INDEX idx_officer_arpa_permanency(arpa_service_permanency,primary_designation_id);

CREATE TABLE arpa_appointment_end_reason (
  id CHAR(36) PRIMARY KEY,
  system_key VARCHAR(100) NOT NULL UNIQUE,
  name_en VARCHAR(200) NOT NULL,
  service_terminating TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 100,
  created_by CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_end_reason_created_by FOREIGN KEY(created_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_end_reason_updated_by FOREIGN KEY(updated_by) REFERENCES system_user(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO arpa_appointment_end_reason(id,system_key,name_en,service_terminating,active,display_order) VALUES
(UUID(),'TRANSFER','Transfer',0,1,10),(UUID(),'RETIREMENT','Retirement',1,1,20),(UUID(),'DEATH','Death',1,1,30),
(UUID(),'RELEASE_FROM_SERVICE','Release from the Service',1,1,40),(UUID(),'VACATION_OF_POST','Vacation of Post',1,1,50),
(UUID(),'RESIGNATION','Resignation',1,1,60),(UUID(),'TERMINATION_OF_APPOINTMENT','Termination of Appointment',1,1,70),
(UUID(),'SUSPENSION_FROM_WORK','Suspension from Work',0,1,80),(UUID(),'LEAVE_PREPARATORY_TO_RETIREMENT','Leave Preparatory to Retirement',0,1,90),
(UUID(),'MATERNITY_LEAVE','Maternity Leave',0,1,100),(UUID(),'ACCIDENT_LEAVE','Accident Leave',0,1,110),
(UUID(),'ACADEMIC_LEAVE','Academic Leave',0,1,120),(UUID(),'FOREIGN_LEAVE_WITHOUT_PAY','Foreign Leave Without Pay',0,1,130),
(UUID(),'COMPULSORY_LEAVE','Compulsory Leave',0,1,140),(UUID(),'SPECIAL_SICK_LEAVE','Special Sick Leave',0,1,150),
(UUID(),'MEDICAL_LEAVE','Medical Leave',0,1,160);

CREATE TABLE arpa_service_permanency_history (
  id CHAR(36) PRIMARY KEY,
  officer_id CHAR(36) NOT NULL,
  previous_status VARCHAR(50) NULL,
  new_status ENUM('PERMANENT_IN_SERVICE','NOT_PERMANENT_IN_SERVICE') NOT NULL,
  effective_from DATE NOT NULL,
  reason TEXT NULL,
  changed_by CHAR(36) NOT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_perm_history_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_arpa_perm_history_user FOREIGN KEY(changed_by) REFERENCES system_user(id),
  INDEX idx_arpa_perm_history(officer_id,effective_from,changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_division_appointment_request (
  id CHAR(36) PRIMARY KEY,
  request_type ENUM('APPOINTMENT','END','TRANSFER') NOT NULL,
  officer_id CHAR(36) NOT NULL,
  appointment_type ENUM('PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY') NULL,
  source_appointment_id CHAR(36) NULL,
  asc_location_id CHAR(36) NULL,
  arpa_division_location_id CHAR(36) NULL,
  requested_effective_from DATE NULL,
  requested_effective_to DATE NULL,
  end_reason_id CHAR(36) NULL,
  request_remarks TEXT NULL,
  impact_snapshot_json JSON NULL,
  location_snapshot_json JSON NULL,
  workflow_status ENUM('CREATED','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED','RETURNED','REJECTED') NOT NULL DEFAULT 'CREATED',
  created_by CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  finalized_by CHAR(36) NULL,
  finalized_at TIMESTAMP NULL,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_arpa_req_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_arpa_req_asc FOREIGN KEY(asc_location_id) REFERENCES location(id),
  CONSTRAINT fk_arpa_req_division FOREIGN KEY(arpa_division_location_id) REFERENCES location(id),
  CONSTRAINT fk_arpa_req_reason FOREIGN KEY(end_reason_id) REFERENCES arpa_appointment_end_reason(id),
  CONSTRAINT fk_arpa_req_created FOREIGN KEY(created_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_req_updated FOREIGN KEY(updated_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_req_finalized FOREIGN KEY(finalized_by) REFERENCES system_user(id),
  CHECK (requested_effective_to IS NULL OR requested_effective_from IS NULL OR requested_effective_to>=requested_effective_from),
  INDEX idx_arpa_req_workflow(workflow_status,created_at),
  INDEX idx_arpa_req_officer(officer_id,request_type,workflow_status),
  INDEX idx_arpa_req_location(arpa_division_location_id,requested_effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_division_appointment (
  id CHAR(36) PRIMARY KEY,
  request_id CHAR(36) NOT NULL UNIQUE,
  officer_id CHAR(36) NOT NULL,
  appointment_type ENUM('PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY') NOT NULL,
  service_permanency_snapshot ENUM('PERMANENT_IN_SERVICE','NOT_PERMANENT_IN_SERVICE') NOT NULL,
  province_location_id_snapshot CHAR(36) NULL,
  district_location_id_snapshot CHAR(36) NULL,
  asc_location_id CHAR(36) NOT NULL,
  arpa_division_location_id CHAR(36) NOT NULL,
  province_dad_snapshot VARCHAR(20) NULL,
  province_name_snapshot VARCHAR(255) NULL,
  district_dad_snapshot VARCHAR(20) NULL,
  district_name_snapshot VARCHAR(255) NULL,
  asc_dad_snapshot VARCHAR(20) NOT NULL,
  asc_name_snapshot VARCHAR(255) NOT NULL,
  arpa_dad_snapshot VARCHAR(20) NOT NULL,
  arpa_name_snapshot VARCHAR(255) NOT NULL,
  hierarchy_snapshot_json JSON NOT NULL,
  effective_from DATE NOT NULL,
  approved_by CHAR(36) NOT NULL,
  approved_at TIMESTAMP NOT NULL,
  letter_reference_id CHAR(36) NULL,
  letter_type VARCHAR(80) NULL,
  letter_date DATE NULL,
  letter_generation_status ENUM('NOT_REQUESTED','PENDING','GENERATED','FAILED') NOT NULL DEFAULT 'NOT_REQUESTED',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_appt_request FOREIGN KEY(request_id) REFERENCES arpa_division_appointment_request(id),
  CONSTRAINT fk_arpa_appt_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_arpa_appt_province FOREIGN KEY(province_location_id_snapshot) REFERENCES location(id),
  CONSTRAINT fk_arpa_appt_district FOREIGN KEY(district_location_id_snapshot) REFERENCES location(id),
  CONSTRAINT fk_arpa_appt_asc FOREIGN KEY(asc_location_id) REFERENCES location(id),
  CONSTRAINT fk_arpa_appt_division FOREIGN KEY(arpa_division_location_id) REFERENCES location(id),
  CONSTRAINT fk_arpa_appt_approved FOREIGN KEY(approved_by) REFERENCES system_user(id),
  INDEX idx_arpa_appt_division_overlap(arpa_division_location_id,effective_from),
  INDEX idx_arpa_appt_officer_overlap(officer_id,appointment_type,effective_from),
  INDEX idx_arpa_appt_geo(province_location_id_snapshot,district_location_id_snapshot,asc_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE arpa_division_appointment_request
  ADD CONSTRAINT fk_arpa_req_source FOREIGN KEY(source_appointment_id) REFERENCES arpa_division_appointment(id);

CREATE TABLE arpa_division_appointment_closure (
  id CHAR(36) PRIMARY KEY,
  appointment_id CHAR(36) NOT NULL UNIQUE,
  request_id CHAR(36) NOT NULL,
  effective_to DATE NOT NULL,
  end_reason_id CHAR(36) NOT NULL,
  closure_kind ENUM('DIRECT','DEPENDENT','TRANSFER') NOT NULL,
  remarks TEXT NULL,
  context_snapshot_json JSON NOT NULL,
  approved_by CHAR(36) NOT NULL,
  approved_at TIMESTAMP NOT NULL,
  letter_reference_id CHAR(36) NULL,
  letter_type VARCHAR(80) NULL,
  letter_date DATE NULL,
  letter_generation_status ENUM('NOT_REQUESTED','PENDING','GENERATED','FAILED') NOT NULL DEFAULT 'NOT_REQUESTED',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_close_appt FOREIGN KEY(appointment_id) REFERENCES arpa_division_appointment(id),
  CONSTRAINT fk_arpa_close_request FOREIGN KEY(request_id) REFERENCES arpa_division_appointment_request(id),
  CONSTRAINT fk_arpa_close_reason FOREIGN KEY(end_reason_id) REFERENCES arpa_appointment_end_reason(id),
  CONSTRAINT fk_arpa_close_approved FOREIGN KEY(approved_by) REFERENCES system_user(id),
  INDEX idx_arpa_close_effective(effective_to,end_reason_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_appointment_workflow_action (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id CHAR(36) NOT NULL,
  action ENUM('SUBMIT','VERIFY','APPROVE','RETURN_FOR_CORRECTION','REJECT') NOT NULL,
  stage ENUM('CREATOR','ASC','DISTRICT','NATIONAL') NOT NULL,
  user_id CHAR(36) NOT NULL,
  comments TEXT NULL,
  previous_status VARCHAR(50) NOT NULL,
  new_status VARCHAR(50) NOT NULL,
  action_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_workflow_request FOREIGN KEY(request_id) REFERENCES arpa_division_appointment_request(id),
  CONSTRAINT fk_arpa_workflow_user FOREIGN KEY(user_id) REFERENCES system_user(id),
  INDEX idx_arpa_workflow_history(request_id,action_at),
  INDEX idx_arpa_workflow_user_action(user_id,action,action_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_asc_subject (
  id CHAR(36) PRIMARY KEY,
  asc_location_id CHAR(36) NOT NULL,
  system_key VARCHAR(100) NULL,
  name_en VARCHAR(200) NOT NULL,
  assignment_kind ENUM('NORMAL','AGRARIAN_BANK','SALES_SHOP','SITHAMU') NOT NULL DEFAULT 'NORMAL',
  officer_exclusive TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_subject_asc FOREIGN KEY(asc_location_id) REFERENCES location(id),
  CONSTRAINT fk_arpa_subject_created FOREIGN KEY(created_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_subject_updated FOREIGN KEY(updated_by) REFERENCES system_user(id),
  UNIQUE KEY uq_arpa_subject_key(asc_location_id,system_key),
  INDEX idx_arpa_subject_list(asc_location_id,active,assignment_kind,name_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_subject_assignment_request (
  id CHAR(36) PRIMARY KEY,
  request_type ENUM('ASSIGN','END') NOT NULL,
  officer_id CHAR(36) NOT NULL,
  subject_id CHAR(36) NULL,
  source_assignment_id CHAR(36) NULL,
  requested_effective_from DATE NULL,
  requested_effective_to DATE NULL,
  end_reason_id CHAR(36) NULL,
  request_remarks TEXT NULL,
  location_snapshot_json JSON NULL,
  workflow_status ENUM('CREATED','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED','RETURNED','REJECTED') NOT NULL DEFAULT 'CREATED',
  created_by CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  finalized_by CHAR(36) NULL,
  finalized_at TIMESTAMP NULL,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_arpa_subject_req_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_arpa_subject_req_subject FOREIGN KEY(subject_id) REFERENCES arpa_asc_subject(id),
  CONSTRAINT fk_arpa_subject_req_reason FOREIGN KEY(end_reason_id) REFERENCES arpa_appointment_end_reason(id),
  CONSTRAINT fk_arpa_subject_req_created FOREIGN KEY(created_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_subject_req_updated FOREIGN KEY(updated_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_subject_req_finalized FOREIGN KEY(finalized_by) REFERENCES system_user(id),
  CHECK (requested_effective_to IS NULL OR requested_effective_from IS NULL OR requested_effective_to>=requested_effective_from),
  INDEX idx_arpa_subject_req_workflow(workflow_status,created_at),
  INDEX idx_arpa_subject_req_officer(officer_id,workflow_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_subject_assignment (
  id CHAR(36) PRIMARY KEY,
  request_id CHAR(36) NOT NULL UNIQUE,
  officer_id CHAR(36) NOT NULL,
  subject_id CHAR(36) NOT NULL,
  subject_kind_snapshot ENUM('NORMAL','AGRARIAN_BANK','SALES_SHOP','SITHAMU') NOT NULL,
  officer_exclusive_snapshot TINYINT(1) NOT NULL,
  province_location_id_snapshot CHAR(36) NULL,
  district_location_id_snapshot CHAR(36) NULL,
  asc_location_id CHAR(36) NOT NULL,
  province_name_snapshot VARCHAR(255) NULL,
  district_name_snapshot VARCHAR(255) NULL,
  asc_dad_snapshot VARCHAR(20) NOT NULL,
  asc_name_snapshot VARCHAR(255) NOT NULL,
  subject_name_snapshot VARCHAR(200) NOT NULL,
  context_snapshot_json JSON NOT NULL,
  effective_from DATE NOT NULL,
  approved_by CHAR(36) NOT NULL,
  approved_at TIMESTAMP NOT NULL,
  letter_reference_id CHAR(36) NULL,
  letter_type VARCHAR(80) NULL,
  letter_date DATE NULL,
  letter_generation_status ENUM('NOT_REQUESTED','PENDING','GENERATED','FAILED') NOT NULL DEFAULT 'NOT_REQUESTED',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_subject_assignment_request FOREIGN KEY(request_id) REFERENCES arpa_subject_assignment_request(id),
  CONSTRAINT fk_arpa_subject_assignment_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_arpa_subject_assignment_subject FOREIGN KEY(subject_id) REFERENCES arpa_asc_subject(id),
  CONSTRAINT fk_arpa_subject_assignment_asc FOREIGN KEY(asc_location_id) REFERENCES location(id),
  CONSTRAINT fk_arpa_subject_assignment_approved FOREIGN KEY(approved_by) REFERENCES system_user(id),
  INDEX idx_arpa_subject_assignment_officer(officer_id,effective_from,officer_exclusive_snapshot),
  INDEX idx_arpa_subject_assignment_subject(subject_id,effective_from),
  INDEX idx_arpa_subject_assignment_geo(province_location_id_snapshot,district_location_id_snapshot,asc_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE arpa_subject_assignment_request
  ADD CONSTRAINT fk_arpa_subject_req_source FOREIGN KEY(source_assignment_id) REFERENCES arpa_subject_assignment(id);

CREATE TABLE arpa_subject_assignment_closure (
  id CHAR(36) PRIMARY KEY,
  assignment_id CHAR(36) NOT NULL UNIQUE,
  request_id CHAR(36) NOT NULL,
  effective_to DATE NOT NULL,
  end_reason_id CHAR(36) NOT NULL,
  remarks TEXT NULL,
  context_snapshot_json JSON NOT NULL,
  approved_by CHAR(36) NOT NULL,
  approved_at TIMESTAMP NOT NULL,
  letter_reference_id CHAR(36) NULL,
  letter_type VARCHAR(80) NULL,
  letter_date DATE NULL,
  letter_generation_status ENUM('NOT_REQUESTED','PENDING','GENERATED','FAILED') NOT NULL DEFAULT 'NOT_REQUESTED',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_subject_close_assignment FOREIGN KEY(assignment_id) REFERENCES arpa_subject_assignment(id),
  CONSTRAINT fk_arpa_subject_close_request FOREIGN KEY(request_id) REFERENCES arpa_subject_assignment_request(id),
  CONSTRAINT fk_arpa_subject_close_reason FOREIGN KEY(end_reason_id) REFERENCES arpa_appointment_end_reason(id),
  CONSTRAINT fk_arpa_subject_close_approved FOREIGN KEY(approved_by) REFERENCES system_user(id),
  INDEX idx_arpa_subject_close_effective(effective_to,end_reason_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_subject_workflow_action (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id CHAR(36) NOT NULL,
  action ENUM('SUBMIT','VERIFY','APPROVE','RETURN_FOR_CORRECTION','REJECT') NOT NULL,
  stage ENUM('CREATOR','ASC','DISTRICT','NATIONAL') NOT NULL,
  user_id CHAR(36) NOT NULL,
  comments TEXT NULL,
  previous_status VARCHAR(50) NOT NULL,
  new_status VARCHAR(50) NOT NULL,
  action_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_subject_workflow_request FOREIGN KEY(request_id) REFERENCES arpa_subject_assignment_request(id),
  CONSTRAINT fk_arpa_subject_workflow_user FOREIGN KEY(user_id) REFERENCES system_user(id),
  INDEX idx_arpa_subject_workflow_history(request_id,action_at),
  INDEX idx_arpa_subject_workflow_user_action(user_id,action,action_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO application_permission(id,permission_key,module_code,description,protected_permission,active) VALUES
(UUID(),'arpa.appointment.view','ARPA_APPOINTMENT','View ARPA Officer appointments and subjects',1,1),
(UUID(),'arpa.appointment.create','ARPA_APPOINTMENT','Create and edit ARPA appointment requests',1,1),
(UUID(),'arpa.appointment.submit','ARPA_APPOINTMENT','Submit ARPA appointment requests',1,1),
(UUID(),'arpa.appointment.asc-verify','ARPA_APPOINTMENT','ASC verify ARPA appointment requests',1,1),
(UUID(),'arpa.appointment.asc-approve','ARPA_APPOINTMENT','ASC approve ARPA appointment requests',1,1),
(UUID(),'arpa.appointment.district-verify','ARPA_APPOINTMENT','District verify ARPA appointment requests',1,1),
(UUID(),'arpa.appointment.district-approve','ARPA_APPOINTMENT','District approve ARPA appointment requests',1,1),
(UUID(),'arpa.appointment.national-verify','ARPA_APPOINTMENT','National verify ARPA appointment requests',1,1),
(UUID(),'arpa.appointment.national-approve','ARPA_APPOINTMENT','National approve and activate ARPA appointment requests',1,1),
(UUID(),'arpa.appointment.return','ARPA_APPOINTMENT','Return ARPA appointment requests for correction',1,1),
(UUID(),'arpa.appointment.reject','ARPA_APPOINTMENT','Reject ARPA appointment requests',1,1),
(UUID(),'arpa.appointment.end','ARPA_APPOINTMENT','Create ARPA appointment ending requests',1,1),
(UUID(),'arpa.appointment.transfer','ARPA_APPOINTMENT','Create ARPA permanent transfer requests',1,1),
(UUID(),'arpa.appointment.manage-service','ARPA_APPOINTMENT','Maintain ARPA service permanency',1,1),
(UUID(),'arpa.subject.create','ARPA_APPOINTMENT','Create ARPA subject assignments',1,1),
(UUID(),'arpa.subject.manage','ARPA_APPOINTMENT','Maintain ASC subject/function masters',1,1),
(UUID(),'arpa.subject.end','ARPA_APPOINTMENT','End ARPA subject assignments',1,1);

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
WHERE p.module_code='ARPA_APPOINTMENT' AND p.permission_key='arpa.appointment.view'
  AND r.role_code IN ('SYSTEM_ADMIN','NATIONAL_ADMIN','NATIONAL_SUBJECT_OFFICER','NATIONAL_VIEWER','DISTRICT_ADMIN','DISTRICT_SUBJECT_OFFICER','DISTRICT_VIEWER','ASC_ADMIN','ASC_SUBJECT_OFFICER','ASC_VIEWER','HR_ADMIN','HR_APPROVER','HR_VIEWER');

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
WHERE p.permission_key IN ('arpa.appointment.create','arpa.appointment.submit','arpa.appointment.end','arpa.appointment.transfer','arpa.appointment.manage-service','arpa.subject.create','arpa.subject.manage','arpa.subject.end')
  AND r.role_code IN ('SYSTEM_ADMIN','NATIONAL_ADMIN','NATIONAL_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_SUBJECT_OFFICER','ASC_ADMIN','ASC_SUBJECT_OFFICER','HR_ADMIN');

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p WHERE
 (p.permission_key='arpa.appointment.asc-verify' AND r.role_code IN ('SYSTEM_ADMIN','ASC_SUBJECT_OFFICER')) OR
 (p.permission_key='arpa.appointment.asc-approve' AND r.role_code IN ('SYSTEM_ADMIN','ASC_ADMIN')) OR
 (p.permission_key='arpa.appointment.district-verify' AND r.role_code IN ('SYSTEM_ADMIN','DISTRICT_SUBJECT_OFFICER')) OR
 (p.permission_key='arpa.appointment.district-approve' AND r.role_code IN ('SYSTEM_ADMIN','DISTRICT_ADMIN')) OR
 (p.permission_key='arpa.appointment.national-verify' AND r.role_code IN ('SYSTEM_ADMIN','NATIONAL_SUBJECT_OFFICER')) OR
 (p.permission_key='arpa.appointment.national-approve' AND r.role_code IN ('SYSTEM_ADMIN','NATIONAL_ADMIN','HR_APPROVER')) OR
 (p.permission_key IN ('arpa.appointment.return','arpa.appointment.reject') AND r.role_code IN ('SYSTEM_ADMIN','NATIONAL_ADMIN','NATIONAL_SUBJECT_OFFICER','DISTRICT_ADMIN','DISTRICT_SUBJECT_OFFICER','ASC_ADMIN','ASC_SUBJECT_OFFICER','HR_APPROVER'));
