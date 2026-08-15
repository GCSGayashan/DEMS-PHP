-- Legacy Officer personnel details and auditable, field-strict backfill provenance.
ALTER TABLE officer
  ADD COLUMN service_permanented_date DATE NULL AFTER arpa_service_permanency,
  ADD INDEX idx_officer_service_permanency_date(arpa_service_permanency,service_permanented_date),
  ADD INDEX idx_officer_initial_appointment(initial_appointment_date);

CREATE TABLE legacy_officer_personnel_backfill_run (
  id CHAR(36) PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL DEFAULT 'AGRARIANADMIN_HR',
  source_table VARCHAR(80) NOT NULL DEFAULT 'tbl_officer',
  mode ENUM('DRY_RUN','EXECUTE') NOT NULL,
  status ENUM('RUNNING','COMPLETED','COMPLETED_WITH_WARNINGS','FAILED') NOT NULL,
  target_officers_examined INT NOT NULL DEFAULT 0,
  mapped_officers INT NOT NULL DEFAULT 0,
  would_update_count INT NOT NULL DEFAULT 0,
  updated_count INT NOT NULL DEFAULT 0,
  warning_count INT NOT NULL DEFAULT 0,
  blocker_count INT NOT NULL DEFAULT 0,
  summary_json JSON NULL,
  report_path VARCHAR(500) NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  error_message TEXT NULL,
  INDEX idx_legacy_personnel_run_status(status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_officer_personnel_provenance (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  officer_id CHAR(36) NOT NULL,
  backfill_run_id CHAR(36) NOT NULL,
  source_system VARCHAR(80) NOT NULL DEFAULT 'AGRARIANADMIN_HR',
  source_table VARCHAR(80) NOT NULL DEFAULT 'tbl_officer',
  legacy_officer_ids_json JSON NOT NULL,
  source_rows_json JSON NOT NULL,
  resolved_fields_json JSON NOT NULL,
  conflicting_fields_json JSON NOT NULL,
  applied_fields_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_personnel_prov_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_legacy_personnel_prov_run FOREIGN KEY(backfill_run_id) REFERENCES legacy_officer_personnel_backfill_run(id),
  UNIQUE KEY uq_legacy_personnel_prov_run_officer(backfill_run_id,officer_id),
  INDEX idx_legacy_personnel_prov_officer(officer_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_officer_personnel_issue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  backfill_run_id CHAR(36) NOT NULL,
  officer_id CHAR(36) NULL,
  legacy_officer_ids_json JSON NOT NULL,
  field_name VARCHAR(80) NULL,
  issue_type VARCHAR(80) NOT NULL,
  severity ENUM('WARNING','BLOCKER') NOT NULL,
  message TEXT NOT NULL,
  evidence_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_personnel_issue_run FOREIGN KEY(backfill_run_id) REFERENCES legacy_officer_personnel_backfill_run(id),
  CONSTRAINT fk_legacy_personnel_issue_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  INDEX idx_legacy_personnel_issue_run(backfill_run_id,severity),
  INDEX idx_legacy_personnel_issue_type(issue_type,field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
