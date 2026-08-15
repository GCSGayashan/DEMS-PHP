-- Truthful provenance for controlled current-state Office Assignment backfills.
-- Native assignment workflow remains unchanged and still records its real actors/timestamps.
ALTER TABLE officer_office_assignment
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_CURRENT_STATE_BACKFILL') NOT NULL DEFAULT 'NATIVE' AFTER active,
  ADD COLUMN source_system VARCHAR(80) NULL AFTER record_origin,
  ADD COLUMN source_table VARCHAR(80) NULL AFTER source_system,
  ADD COLUMN source_evidence_json JSON NULL AFTER source_table,
  ADD COLUMN approval_timestamp_provenance ENUM('NATIVE_ACTION','UNAVAILABLE_CURRENT_STATE_BACKFILL') NOT NULL DEFAULT 'NATIVE_ACTION' AFTER approved_at,
  ADD INDEX idx_ooa_origin(record_origin,source_system,source_table);
