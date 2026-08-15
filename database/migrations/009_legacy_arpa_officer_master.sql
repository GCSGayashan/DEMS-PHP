ALTER TABLE officer
  MODIFY COLUMN title_id CHAR(36) NULL,
  MODIFY COLUMN full_name_si VARCHAR(255) NULL,
  MODIFY COLUMN full_name_ta VARCHAR(255) NULL,
  MODIFY COLUMN expected_retirement_date DATE NULL,
  MODIFY COLUMN gender ENUM('MALE','FEMALE') NULL,
  MODIFY COLUMN permanent_address TEXT NULL,
  MODIFY COLUMN temporary_address TEXT NULL,
  MODIFY COLUMN alternative_mobile VARCHAR(20) NULL,
  MODIFY COLUMN initial_appointment_date DATE NULL,
  MODIFY COLUMN appointment_nature_id CHAR(36) NULL,
  MODIFY COLUMN primary_designation_id CHAR(36) NULL,
  MODIFY COLUMN primary_office_id CHAR(36) NULL;

CREATE TABLE IF NOT EXISTS legacy_officer_migration_run (
  id CHAR(36) PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL,
  source_table VARCHAR(80) NOT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  status VARCHAR(40) NOT NULL,
  batch_size INT NOT NULL DEFAULT 500,
  source_appointment_row_count INT NOT NULL DEFAULT 0,
  distinct_source_officer_count INT NOT NULL DEFAULT 0,
  matched_source_master_count INT NOT NULL DEFAULT 0,
  existing_reference_count INT NOT NULL DEFAULT 0,
  would_create_count INT NOT NULL DEFAULT 0,
  would_update_count INT NOT NULL DEFAULT 0,
  created_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  skipped_count INT NOT NULL DEFAULT 0,
  warning_count INT NOT NULL DEFAULT 0,
  error_count INT NOT NULL DEFAULT 0,
  report_path VARCHAR(500) NULL,
  summary_json JSON NULL,
  zero_write_verification_json JSON NULL,
  INDEX idx_legacy_officer_run_started(source_system, started_at),
  INDEX idx_legacy_officer_run_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_officer_reference (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL,
  source_table VARCHAR(80) NOT NULL,
  legacy_officer_id VARCHAR(100) NOT NULL,
  officer_id CHAR(36) NOT NULL,
  legacy_nic VARCHAR(100) NULL,
  legacy_designation_id VARCHAR(100) NULL,
  legacy_designation_name VARCHAR(500) NULL,
  legacy_officer_status VARCHAR(50) NULL,
  legacy_payload_json JSON NOT NULL,
  migration_run_id CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_officer_ref_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_legacy_officer_ref_run FOREIGN KEY(migration_run_id) REFERENCES legacy_officer_migration_run(id),
  UNIQUE KEY uq_legacy_officer_source(source_system, source_table, legacy_officer_id),
  UNIQUE KEY uq_legacy_officer_target(officer_id),
  INDEX idx_legacy_officer_nic(source_system, legacy_nic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legacy_officer_migration_issue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  migration_run_id CHAR(36) NOT NULL,
  legacy_officer_id VARCHAR(100) NULL,
  issue_type VARCHAR(80) NOT NULL,
  severity VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  source_payload_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_officer_issue_run FOREIGN KEY(migration_run_id) REFERENCES legacy_officer_migration_run(id),
  INDEX idx_legacy_officer_issue_run(migration_run_id, severity),
  INDEX idx_legacy_officer_issue_type(issue_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
