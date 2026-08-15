CREATE TABLE legacy_arpa_office_backfill_run (
  id CHAR(36) PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL,
  source_table VARCHAR(80) NOT NULL,
  as_of_date DATE NOT NULL,
  status ENUM('RUNNING','COMPLETED','FAILED') NOT NULL,
  qualifying_source_rows INT NOT NULL,
  distinct_legacy_officers INT NOT NULL,
  distinct_target_officers INT NOT NULL,
  distinct_ascs INT NOT NULL,
  created_assignments INT NOT NULL DEFAULT 0,
  synchronized_primary_offices INT NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  INDEX idx_laobr_status(status,started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE officer_office_assignment
  ADD COLUMN legacy_backfill_run_id CHAR(36) NULL AFTER source_evidence_json,
  ADD CONSTRAINT fk_ooa_legacy_backfill_run FOREIGN KEY(legacy_backfill_run_id) REFERENCES legacy_arpa_office_backfill_run(id),
  ADD INDEX idx_ooa_legacy_backfill_run(legacy_backfill_run_id);
