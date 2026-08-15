-- Truthful final legacy ARPA appointment import support. Native workflow remains strict.
ALTER TABLE arpa_division_appointment_request
  ADD COLUMN legacy_history_only TINYINT(1) NOT NULL DEFAULT 0 AFTER workflow_status,
  ADD COLUMN legacy_exception TINYINT(1) NOT NULL DEFAULT 0 AFTER legacy_history_only,
  ADD COLUMN legacy_exception_codes_json JSON NULL AFTER legacy_exception;

ALTER TABLE arpa_subject_assignment_request
  MODIFY COLUMN asc_location_id CHAR(36) NULL,
  ADD COLUMN legacy_history_only TINYINT(1) NOT NULL DEFAULT 0 AFTER workflow_status,
  ADD COLUMN legacy_exception TINYINT(1) NOT NULL DEFAULT 0 AFTER legacy_history_only,
  ADD COLUMN legacy_exception_codes_json JSON NULL AFTER legacy_exception,
  ADD CONSTRAINT chk_arpa_subject_request_native_asc CHECK(record_origin='LEGACY_IMPORT' OR asc_location_id IS NOT NULL);

ALTER TABLE arpa_division_appointment
  MODIFY COLUMN approved_by CHAR(36) NULL,
  ADD COLUMN legacy_history_only TINYINT(1) NOT NULL DEFAULT 0 AFTER effective_from,
  ADD COLUMN legacy_exception TINYINT(1) NOT NULL DEFAULT 0 AFTER legacy_history_only,
  ADD COLUMN legacy_exception_codes_json JSON NULL AFTER legacy_exception;

ALTER TABLE arpa_subject_assignment
  MODIFY COLUMN approved_by CHAR(36) NULL,
  ADD COLUMN legacy_history_only TINYINT(1) NOT NULL DEFAULT 0 AFTER effective_from,
  ADD COLUMN legacy_exception TINYINT(1) NOT NULL DEFAULT 0 AFTER legacy_history_only,
  ADD COLUMN legacy_exception_codes_json JSON NULL AFTER legacy_exception;

ALTER TABLE arpa_division_appointment_closure
  MODIFY COLUMN end_reason_id CHAR(36) NULL,
  MODIFY COLUMN approved_by CHAR(36) NULL,
  ADD COLUMN legacy_reason_id VARCHAR(100) NULL AFTER end_reason_id,
  ADD COLUMN legacy_reason_text VARCHAR(500) NULL AFTER legacy_reason_id;

ALTER TABLE arpa_subject_assignment_closure
  MODIFY COLUMN end_reason_id CHAR(36) NULL,
  MODIFY COLUMN approved_by CHAR(36) NULL,
  ADD COLUMN legacy_reason_id VARCHAR(100) NULL AFTER end_reason_id,
  ADD COLUMN legacy_reason_text VARCHAR(500) NULL AFTER legacy_reason_id;

ALTER TABLE arpa_officer_sub_designation_period
  MODIFY COLUMN approved_by CHAR(36) NULL;

ALTER TABLE arpa_officer_sub_designation_closure
  MODIFY COLUMN end_reason_id CHAR(36) NULL,
  MODIFY COLUMN approved_by CHAR(36) NULL,
  MODIFY COLUMN approved_at TIMESTAMP NULL,
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  ADD COLUMN approval_timestamp_provenance ENUM('NATIVE_RECORDED','LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE') NOT NULL DEFAULT 'NATIVE_RECORDED' AFTER approved_at,
  ADD COLUMN origin_metadata_json JSON NULL AFTER approval_timestamp_provenance,
  ADD CONSTRAINT chk_arpa_subdesignation_closure_provenance CHECK((record_origin='NATIVE' AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND approval_timestamp_provenance='NATIVE_RECORDED') OR (record_origin='LEGACY_IMPORT' AND approval_timestamp_provenance IN('LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE')));

CREATE TABLE legacy_arpa_appointment_migration_run (
  id CHAR(36) PRIMARY KEY,
  mode ENUM('DRY_RUN','EXECUTE') NOT NULL,
  status ENUM('RUNNING','COMPLETED','COMPLETED_WITH_WARNINGS','FAILED') NOT NULL,
  reconciled_records INT NOT NULL DEFAULT 0,
  business_records_created INT NOT NULL DEFAULT 0,
  requests_created INT NOT NULL DEFAULT 0,
  operational_records_created INT NOT NULL DEFAULT 0,
  workflow_actions_created INT NOT NULL DEFAULT 0,
  source_references_created INT NOT NULL DEFAULT 0,
  warning_count INT NOT NULL DEFAULT 0,
  blocker_count INT NOT NULL DEFAULT 0,
  summary_json JSON NULL,
  report_path VARCHAR(500) NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  error_message TEXT NULL,
  INDEX idx_legacy_arpa_migration_run(status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_arpa_appointment_migration_issue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  migration_run_id CHAR(36) NOT NULL,
  reconciled_business_key CHAR(64) NOT NULL,
  issue_type VARCHAR(100) NOT NULL,
  severity ENUM('WARNING','BLOCKER') NOT NULL,
  message TEXT NOT NULL,
  evidence_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_arpa_migration_issue_run FOREIGN KEY(migration_run_id) REFERENCES legacy_arpa_appointment_migration_run(id),
  INDEX idx_legacy_arpa_migration_issue_run(migration_run_id,severity),
  INDEX idx_legacy_arpa_migration_issue_type(issue_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
