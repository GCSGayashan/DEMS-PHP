SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS schema_migration (
  version VARCHAR(50) PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS number_category (
  id CHAR(36) PRIMARY KEY,
  category_key VARCHAR(80) NOT NULL UNIQUE,
  category_code VARCHAR(5) NOT NULL UNIQUE,
  name_en VARCHAR(150) NOT NULL,
  next_value BIGINT NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS number_allocation (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  category_id CHAR(36) NOT NULL,
  allocated_number VARCHAR(20) NOT NULL UNIQUE,
  allocated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_num_alloc_cat FOREIGN KEY(category_id) REFERENCES number_category(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location_type (
  id CHAR(36) PRIMARY KEY,
  dad_number VARCHAR(20) NOT NULL UNIQUE,
  system_key VARCHAR(80) NOT NULL UNIQUE,
  name_en VARCHAR(150) NOT NULL,
  name_si VARCHAR(255) NULL,
  name_ta VARCHAR(255) NULL,
  display_order INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location (
  id CHAR(36) PRIMARY KEY,
  dad_number VARCHAR(20) NOT NULL UNIQUE,
  location_type_id CHAR(36) NOT NULL,
  official_code VARCHAR(100) NULL,
  name_en VARCHAR(255) NOT NULL,
  name_si VARCHAR(255) NULL,
  name_ta VARCHAR(255) NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  operational_status VARCHAR(30) NOT NULL DEFAULT 'INACTIVE',
  approval_status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  created_by CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_location_type FOREIGN KEY(location_type_id) REFERENCES location_type(id),
  INDEX idx_location_type(location_type_id),
  INDEX idx_location_name(name_en),
  INDEX idx_location_code(official_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS location_relationship (
  id CHAR(36) PRIMARY KEY,
  parent_location_id CHAR(36) NOT NULL,
  child_location_id CHAR(36) NOT NULL,
  relationship_type VARCHAR(80) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_rel_parent FOREIGN KEY(parent_location_id) REFERENCES location(id),
  CONSTRAINT fk_rel_child FOREIGN KEY(child_location_id) REFERENCES location(id),
  INDEX idx_rel_parent(parent_location_id,effective_from,effective_to),
  INDEX idx_rel_child(child_location_id,effective_from,effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office_type (
  id CHAR(36) PRIMARY KEY,
  system_key VARCHAR(80) NOT NULL UNIQUE,
  name_en VARCHAR(150) NOT NULL,
  office_level VARCHAR(50) NOT NULL,
  required_location_type VARCHAR(80) NULL,
  branches_allowed TINYINT(1) NOT NULL DEFAULT 0,
  display_order INT NOT NULL DEFAULT 100,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS office (
  id CHAR(36) PRIMARY KEY,
  dad_number VARCHAR(20) NOT NULL UNIQUE,
  office_type_id CHAR(36) NOT NULL,
  name_en VARCHAR(255) NOT NULL,
  name_si VARCHAR(255) NULL,
  name_ta VARCHAR(255) NULL,
  short_name VARCHAR(100) NULL,
  linked_location_id CHAR(36) NULL,
  address TEXT NULL,
  telephone VARCHAR(50) NULL,
  additional_telephone VARCHAR(50) NULL,
  email VARCHAR(255) NULL,
  description TEXT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  requested_status VARCHAR(30) NOT NULL DEFAULT 'ACTIVE',
  operational_status VARCHAR(30) NOT NULL DEFAULT 'INACTIVE',
  approval_status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  created_by CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_office_type FOREIGN KEY(office_type_id) REFERENCES office_type(id),
  CONSTRAINT fk_office_location FOREIGN KEY(linked_location_id) REFERENCES location(id),
  UNIQUE KEY uq_office_email(email),
  INDEX idx_office_location(linked_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_title (
  id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20) NOT NULL UNIQUE,system_key VARCHAR(80) NOT NULL UNIQUE,name_en VARCHAR(150) NOT NULL,name_si VARCHAR(255) NULL,name_ta VARCHAR(255) NULL,description TEXT NULL,display_order INT NOT NULL DEFAULT 100,active TINYINT(1) NOT NULL DEFAULT 1,effective_from DATE NOT NULL,effective_to DATE NULL,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',created_by CHAR(36) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS appointment_nature (
  id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20) NOT NULL UNIQUE,system_key VARCHAR(80) NOT NULL UNIQUE,name_en VARCHAR(150) NOT NULL,name_si VARCHAR(255) NULL,name_ta VARCHAR(255) NULL,description TEXT NULL,display_order INT NOT NULL DEFAULT 100,class_required TINYINT(1) NOT NULL DEFAULT 0,active TINYINT(1) NOT NULL DEFAULT 1,effective_from DATE NOT NULL,effective_to DATE NULL,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',created_by CHAR(36) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS designation (
  id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20) NOT NULL UNIQUE,system_key VARCHAR(80) NOT NULL UNIQUE,name_en VARCHAR(150) NOT NULL,name_si VARCHAR(255) NULL,name_ta VARCHAR(255) NULL,description TEXT NULL,display_order INT NOT NULL DEFAULT 100,designation_level ENUM('MAIN','SUB') NOT NULL DEFAULT 'MAIN',parent_designation_id CHAR(36) NULL,active TINYINT(1) NOT NULL DEFAULT 1,effective_from DATE NOT NULL,effective_to DATE NULL,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',created_by CHAR(36) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,CONSTRAINT fk_desig_parent FOREIGN KEY(parent_designation_id) REFERENCES designation(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS officer_class (
  id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20) NOT NULL UNIQUE,system_key VARCHAR(80) NOT NULL UNIQUE,name_en VARCHAR(150) NOT NULL,name_si VARCHAR(255) NULL,name_ta VARCHAR(255) NULL,description TEXT NULL,display_order INT NOT NULL DEFAULT 100,active TINYINT(1) NOT NULL DEFAULT 1,effective_from DATE NOT NULL,effective_to DATE NULL,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',created_by CHAR(36) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS officer_status (
  id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20) NOT NULL UNIQUE,system_key VARCHAR(80) NOT NULL UNIQUE,name_en VARCHAR(150) NOT NULL,name_si VARCHAR(255) NULL,name_ta VARCHAR(255) NULL,description TEXT NULL,display_order INT NOT NULL DEFAULT 100,protected_status TINYINT(1) NOT NULL DEFAULT 0,active TINYINT(1) NOT NULL DEFAULT 1,effective_from DATE NOT NULL,effective_to DATE NULL,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',created_by CHAR(36) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS civil_status (
  id CHAR(36) PRIMARY KEY,dad_number VARCHAR(20) NOT NULL UNIQUE,system_key VARCHAR(80) NOT NULL UNIQUE,name_en VARCHAR(150) NOT NULL,name_si VARCHAR(255) NULL,name_ta VARCHAR(255) NULL,description TEXT NULL,display_order INT NOT NULL DEFAULT 100,active TINYINT(1) NOT NULL DEFAULT 1,effective_from DATE NOT NULL,effective_to DATE NULL,approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',created_by CHAR(36) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS designation_allowed_class (
  id CHAR(36) PRIMARY KEY,
  designation_id CHAR(36) NOT NULL,
  class_id CHAR(36) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',
  CONSTRAINT fk_dac_designation FOREIGN KEY(designation_id) REFERENCES designation(id),
  CONSTRAINT fk_dac_class FOREIGN KEY(class_id) REFERENCES officer_class(id),
  UNIQUE KEY uq_dac(designation_id,class_id,effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS officer (
  id CHAR(36) PRIMARY KEY,
  dad_number VARCHAR(20) NOT NULL UNIQUE,
  nic VARCHAR(20) NOT NULL,
  nic_normalized VARCHAR(20) NOT NULL UNIQUE,
  nic_match_key VARCHAR(64) NULL UNIQUE,
  employee_number VARCHAR(100) NULL UNIQUE,
  title_id CHAR(36) NOT NULL,
  name_with_initials VARCHAR(255) NOT NULL,
  full_name_en VARCHAR(255) NOT NULL,
  full_name_si VARCHAR(255) NOT NULL,
  full_name_ta VARCHAR(255) NOT NULL,
  date_of_birth DATE NOT NULL,
  expected_retirement_date DATE NOT NULL,
  gender ENUM('MALE','FEMALE') NOT NULL,
  civil_status_id CHAR(36) NULL,
  permanent_address TEXT NOT NULL,
  temporary_address TEXT NOT NULL,
  primary_mobile VARCHAR(20) NOT NULL,
  alternative_mobile VARCHAR(20) NOT NULL,
  personal_email VARCHAR(255) NULL,
  official_email VARCHAR(255) NULL,
  photograph_path VARCHAR(500) NULL,
  initial_appointment_date DATE NOT NULL,
  appointment_nature_id CHAR(36) NOT NULL,
  primary_designation_id CHAR(36) NOT NULL,
  class_id CHAR(36) NULL,
  officer_status_id CHAR(36) NOT NULL,
  primary_office_id CHAR(36) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  operational_status VARCHAR(30) NOT NULL DEFAULT 'INACTIVE',
  approval_status VARCHAR(30) NOT NULL DEFAULT 'DRAFT',
  created_by CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_officer_title FOREIGN KEY(title_id) REFERENCES hr_title(id),
  CONSTRAINT fk_officer_civil FOREIGN KEY(civil_status_id) REFERENCES civil_status(id),
  CONSTRAINT fk_officer_appt FOREIGN KEY(appointment_nature_id) REFERENCES appointment_nature(id),
  CONSTRAINT fk_officer_desig FOREIGN KEY(primary_designation_id) REFERENCES designation(id),
  CONSTRAINT fk_officer_class FOREIGN KEY(class_id) REFERENCES officer_class(id),
  CONSTRAINT fk_officer_status FOREIGN KEY(officer_status_id) REFERENCES officer_status(id),
  CONSTRAINT fk_officer_office FOREIGN KEY(primary_office_id) REFERENCES office(id),
  UNIQUE KEY uq_officer_personal_email(personal_email),
  UNIQUE KEY uq_officer_official_email(official_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_permission (
  id CHAR(36) PRIMARY KEY,
  permission_key VARCHAR(150) NOT NULL UNIQUE,
  module_code VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL,
  protected_permission TINYINT(1) NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_role (
  id CHAR(36) PRIMARY KEY,
  role_code VARCHAR(100) NOT NULL UNIQUE,
  role_name VARCHAR(150) NOT NULL,
  description VARCHAR(255) NULL,
  role_level ENUM('SYSTEM','NATIONAL','DISTRICT','ASC','ARPA','FARMER','CUSTOM','LEGACY') NOT NULL DEFAULT 'CUSTOM',
  protected_role TINYINT(1) NOT NULL DEFAULT 0,
  assignable TINYINT(1) NOT NULL DEFAULT 1,
  legacy TINYINT(1) NOT NULL DEFAULT 0,
  approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',
  active TINYINT(1) NOT NULL DEFAULT 1,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_role_permission (
  role_id CHAR(36) NOT NULL,
  permission_id CHAR(36) NOT NULL,
  PRIMARY KEY(role_id,permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY(role_id) REFERENCES application_role(id),
  CONSTRAINT fk_rp_perm FOREIGN KEY(permission_id) REFERENCES application_permission(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `system_user` (
  id CHAR(36) PRIMARY KEY,
  officer_id CHAR(36) NULL UNIQUE,
  farmer_id CHAR(36) NULL,
  identity_type ENUM('STAFF','FARMER') NOT NULL DEFAULT 'STAFF',
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  keycloak_subject_id VARCHAR(100) NULL UNIQUE,
  account_status VARCHAR(50) NOT NULL DEFAULT 'ACTIVE',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  mfa_method VARCHAR(50) NULL,
  mobile_verified TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_system_user_officer FOREIGN KEY(officer_id) REFERENCES officer(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_account_role (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  role_id CHAR(36) NOT NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',
  active TINYINT(1) NOT NULL DEFAULT 1,
  reason TEXT NULL,
  official_reference VARCHAR(255) NULL,
  created_by CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  submitted_by CHAR(36) NULL,
  submitted_at TIMESTAMP NULL,
  approved_by CHAR(36) NULL,
  approved_at TIMESTAMP NULL,
  CONSTRAINT fk_uar_user FOREIGN KEY(user_id) REFERENCES `system_user`(id),
  CONSTRAINT fk_uar_role FOREIGN KEY(role_id) REFERENCES application_role(id),
  INDEX idx_uar_user(user_id,effective_from,effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_account_scope (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  scope_type VARCHAR(50) NOT NULL,
  scope_mode ENUM('EXACT','INCLUDE_CHILDREN','NATIONAL') NOT NULL DEFAULT 'EXACT',
  location_id CHAR(36) NULL,
  office_id CHAR(36) NULL,
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',
  active TINYINT(1) NOT NULL DEFAULT 1,
  reason TEXT NULL,
  official_reference VARCHAR(255) NULL,
  created_by CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_uas_user FOREIGN KEY(user_id) REFERENCES `system_user`(id),
  CONSTRAINT fk_uas_location FOREIGN KEY(location_id) REFERENCES location(id),
  CONSTRAINT fk_uas_office FOREIGN KEY(office_id) REFERENCES office(id),
  INDEX idx_uas_user(user_id,effective_from,effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provisioning_failure (
  id CHAR(36) PRIMARY KEY,
  username VARCHAR(100) NOT NULL,
  failure_category VARCHAR(100) NOT NULL,
  sanitized_message VARCHAR(500) NULL,
  attempt_count INT NOT NULL DEFAULT 1,
  last_attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_event (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_user_id CHAR(36) NULL,
  action_key VARCHAR(150) NOT NULL,
  target_type VARCHAR(100) NOT NULL,
  target_id VARCHAR(100) NULL,
  details_json JSON NULL,
  severity VARCHAR(30) NOT NULL DEFAULT 'INFO',
  source_ip VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_time(created_at),
  INDEX idx_audit_actor(actor_user_id),
  CONSTRAINT fk_audit_actor FOREIGN KEY(actor_user_id) REFERENCES `system_user`(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
