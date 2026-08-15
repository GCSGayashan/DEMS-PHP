START TRANSACTION;

ALTER TABLE `system_user`
  MODIFY COLUMN identity_type ENUM('STAFF','FARMER','HISTORICAL') NOT NULL DEFAULT 'STAFF',
  ADD COLUMN display_name VARCHAR(255) NULL AFTER username,
  ADD COLUMN email VARCHAR(255) NULL AFTER display_name,
  ADD COLUMN email_normalized VARCHAR(255) NULL AFTER email,
  ADD COLUMN mobile VARCHAR(30) NULL AFTER email_normalized,
  ADD COLUMN historical_identity TINYINT(1) NOT NULL DEFAULT 0 AFTER mobile,
  ADD COLUMN identity_source VARCHAR(80) NULL AFTER historical_identity,
  ADD INDEX idx_system_user_email_normalized(email_normalized),
  ADD INDEX idx_system_user_historical(historical_identity,enabled,account_status);

CREATE TABLE legacy_user_migration_run (
  id CHAR(36) PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL,
  source_table VARCHAR(80) NOT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  status VARCHAR(40) NOT NULL,
  dry_run TINYINT(1) NOT NULL,
  batch_size INT NOT NULL DEFAULT 500,
  source_user_count INT NOT NULL DEFAULT 0,
  existing_target_user_count INT NOT NULL DEFAULT 0,
  existing_reference_count INT NOT NULL DEFAULT 0,
  matched_existing_count INT NOT NULL DEFAULT 0,
  would_create_count INT NOT NULL DEFAULT 0,
  created_count INT NOT NULL DEFAULT 0,
  mapping_created_count INT NOT NULL DEFAULT 0,
  manual_review_count INT NOT NULL DEFAULT 0,
  invalid_source_count INT NOT NULL DEFAULT 0,
  warning_count INT NOT NULL DEFAULT 0,
  error_count INT NOT NULL DEFAULT 0,
  report_path VARCHAR(500) NULL,
  summary_json JSON NULL,
  zero_write_verification_json JSON NULL,
  INDEX idx_legacy_user_run_started(source_system,started_at),
  INDEX idx_legacy_user_run_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_user_reference (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL,
  source_table VARCHAR(80) NOT NULL,
  legacy_user_id VARCHAR(100) NOT NULL,
  system_user_id CHAR(36) NOT NULL,
  match_method VARCHAR(50) NOT NULL,
  legacy_username VARCHAR(255) NULL,
  legacy_display_name VARCHAR(1000) NOT NULL,
  legacy_nic VARCHAR(100) NULL,
  legacy_email VARCHAR(500) NULL,
  legacy_phone VARCHAR(100) NULL,
  legacy_status VARCHAR(50) NOT NULL,
  legacy_role_id VARCHAR(100) NULL,
  legacy_role_name VARCHAR(255) NULL,
  legacy_user_level_id VARCHAR(100) NULL,
  legacy_user_level_name VARCHAR(255) NULL,
  legacy_created_location_id VARCHAR(100) NULL,
  legacy_created_at DATETIME NULL,
  legacy_updated_at DATETIME NULL,
  legacy_payload_json JSON NOT NULL,
  migration_run_id CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_user_ref_user FOREIGN KEY(system_user_id) REFERENCES `system_user`(id),
  CONSTRAINT fk_legacy_user_ref_run FOREIGN KEY(migration_run_id) REFERENCES legacy_user_migration_run(id),
  UNIQUE KEY uq_legacy_user_source(source_system,source_table,legacy_user_id),
  INDEX idx_legacy_user_target(system_user_id),
  INDEX idx_legacy_user_username(source_system,legacy_username),
  INDEX idx_legacy_user_nic(source_system,legacy_nic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_user_organization_context (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  legacy_user_reference_id BIGINT NOT NULL,
  source_table VARCHAR(80) NOT NULL,
  legacy_mapping_id VARCHAR(100) NOT NULL,
  legacy_level_key VARCHAR(50) NOT NULL,
  legacy_location_id VARCHAR(100) NULL,
  location_id CHAR(36) NULL,
  legacy_status VARCHAR(50) NOT NULL,
  legacy_payload_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_user_context_ref FOREIGN KEY(legacy_user_reference_id) REFERENCES legacy_user_reference(id),
  CONSTRAINT fk_legacy_user_context_location FOREIGN KEY(location_id) REFERENCES location(id),
  UNIQUE KEY uq_legacy_user_context_source(source_table,legacy_mapping_id),
  INDEX idx_legacy_user_context_ref(legacy_user_reference_id),
  INDEX idx_legacy_user_context_location(location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historical application-access metadata only. Deliberately has no foreign
-- key to subject_master, permissions, roles, assignments, or scopes.
CREATE TABLE legacy_user_access_metadata (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  legacy_user_reference_id BIGINT NOT NULL,
  source_table VARCHAR(80) NOT NULL,
  legacy_mapping_id VARCHAR(100) NOT NULL,
  legacy_subject_id VARCHAR(100) NOT NULL,
  legacy_subject_name VARCHAR(255) NULL,
  legacy_subject_variable VARCHAR(100) NULL,
  legacy_status VARCHAR(50) NOT NULL,
  legacy_payload_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_user_access_ref FOREIGN KEY(legacy_user_reference_id) REFERENCES legacy_user_reference(id),
  UNIQUE KEY uq_legacy_user_access_source(source_table,legacy_mapping_id),
  INDEX idx_legacy_user_access_ref(legacy_user_reference_id),
  INDEX idx_legacy_user_access_subject(legacy_subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_user_migration_issue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  migration_run_id CHAR(36) NOT NULL,
  legacy_user_id VARCHAR(100) NULL,
  classification VARCHAR(50) NOT NULL,
  issue_type VARCHAR(80) NOT NULL,
  severity VARCHAR(20) NOT NULL,
  message TEXT NOT NULL,
  source_payload_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_user_issue_run FOREIGN KEY(migration_run_id) REFERENCES legacy_user_migration_run(id),
  INDEX idx_legacy_user_issue_run(migration_run_id,severity),
  INDEX idx_legacy_user_issue_type(issue_type),
  INDEX idx_legacy_user_issue_legacy(legacy_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
