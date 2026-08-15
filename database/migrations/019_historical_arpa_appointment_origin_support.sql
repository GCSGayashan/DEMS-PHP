START TRANSACTION;

ALTER TABLE arpa_division_appointment_request
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  MODIFY COLUMN created_by CHAR(36) NULL,
  ADD COLUMN legacy_source_created_at DATETIME NULL AFTER created_at,
  ADD COLUMN origin_metadata_json JSON NULL AFTER legacy_source_created_at,
  ADD CONSTRAINT chk_arpa_request_native_creator CHECK (record_origin='LEGACY_IMPORT' OR created_by IS NOT NULL);

ALTER TABLE arpa_subject_assignment_request
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  MODIFY COLUMN created_by CHAR(36) NULL,
  ADD COLUMN legacy_source_created_at DATETIME NULL AFTER created_at,
  ADD COLUMN origin_metadata_json JSON NULL AFTER legacy_source_created_at,
  ADD CONSTRAINT chk_arpa_subject_request_native_creator CHECK (record_origin='LEGACY_IMPORT' OR created_by IS NOT NULL);

ALTER TABLE arpa_appointment_workflow_action
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  MODIFY COLUMN action_at TIMESTAMP NULL,
  ADD COLUMN timestamp_provenance ENUM('NATIVE_RECORDED','LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE') NOT NULL DEFAULT 'NATIVE_RECORDED' AFTER action_at,
  ADD COLUMN legacy_source_payload_json JSON NULL AFTER timestamp_provenance,
  ADD CONSTRAINT chk_arpa_workflow_time_provenance CHECK (
    (record_origin='NATIVE' AND action_at IS NOT NULL AND timestamp_provenance='NATIVE_RECORDED') OR
    (record_origin='LEGACY_IMPORT' AND timestamp_provenance IN ('LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE'))
  );

ALTER TABLE arpa_subject_workflow_action
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  MODIFY COLUMN action_at TIMESTAMP NULL,
  ADD COLUMN timestamp_provenance ENUM('NATIVE_RECORDED','LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE') NOT NULL DEFAULT 'NATIVE_RECORDED' AFTER action_at,
  ADD COLUMN legacy_source_payload_json JSON NULL AFTER timestamp_provenance,
  ADD CONSTRAINT chk_arpa_subject_workflow_time_provenance CHECK (
    (record_origin='NATIVE' AND action_at IS NOT NULL AND timestamp_provenance='NATIVE_RECORDED') OR
    (record_origin='LEGACY_IMPORT' AND timestamp_provenance IN ('LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE'))
  );

ALTER TABLE arpa_division_appointment
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  MODIFY COLUMN service_permanency_snapshot ENUM('PERMANENT_IN_SERVICE','NOT_PERMANENT_IN_SERVICE') NULL,
  ADD COLUMN service_permanency_source ENUM('NATIVE_CURRENT_STATUS','EXACT_PERMANENTED_DATE','CURRENT_STATE_ONLY','UNRESOLVED') NOT NULL DEFAULT 'NATIVE_CURRENT_STATUS' AFTER service_permanency_snapshot,
  MODIFY COLUMN approved_at TIMESTAMP NULL,
  ADD COLUMN approval_timestamp_provenance ENUM('NATIVE_RECORDED','LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE') NOT NULL DEFAULT 'NATIVE_RECORDED' AFTER approved_at,
  ADD COLUMN origin_metadata_json JSON NULL AFTER approval_timestamp_provenance,
  ADD CONSTRAINT chk_arpa_appointment_native_provenance CHECK (
    (record_origin='NATIVE' AND approved_at IS NOT NULL AND approval_timestamp_provenance='NATIVE_RECORDED' AND service_permanency_snapshot IS NOT NULL AND service_permanency_source='NATIVE_CURRENT_STATUS') OR
    (record_origin='LEGACY_IMPORT' AND approval_timestamp_provenance IN ('LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE') AND service_permanency_source IN ('EXACT_PERMANENTED_DATE','CURRENT_STATE_ONLY','UNRESOLVED'))
  );

ALTER TABLE arpa_subject_assignment
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  MODIFY COLUMN approved_at TIMESTAMP NULL,
  ADD COLUMN approval_timestamp_provenance ENUM('NATIVE_RECORDED','LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE') NOT NULL DEFAULT 'NATIVE_RECORDED' AFTER approved_at,
  ADD COLUMN origin_metadata_json JSON NULL AFTER approval_timestamp_provenance,
  ADD CONSTRAINT chk_arpa_subject_native_provenance CHECK (
    (record_origin='NATIVE' AND approved_at IS NOT NULL AND approval_timestamp_provenance='NATIVE_RECORDED') OR
    (record_origin='LEGACY_IMPORT' AND approval_timestamp_provenance IN ('LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE'))
  );

ALTER TABLE arpa_division_appointment_closure
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  MODIFY COLUMN approved_at TIMESTAMP NULL,
  ADD COLUMN approval_timestamp_provenance ENUM('NATIVE_RECORDED','LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE') NOT NULL DEFAULT 'NATIVE_RECORDED' AFTER approved_at,
  ADD COLUMN origin_metadata_json JSON NULL AFTER approval_timestamp_provenance,
  ADD CONSTRAINT chk_arpa_closure_native_provenance CHECK (
    (record_origin='NATIVE' AND approved_at IS NOT NULL AND approval_timestamp_provenance='NATIVE_RECORDED') OR
    (record_origin='LEGACY_IMPORT' AND approval_timestamp_provenance IN ('LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE'))
  );

ALTER TABLE arpa_subject_assignment_closure
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  MODIFY COLUMN approved_at TIMESTAMP NULL,
  ADD COLUMN approval_timestamp_provenance ENUM('NATIVE_RECORDED','LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE') NOT NULL DEFAULT 'NATIVE_RECORDED' AFTER approved_at,
  ADD COLUMN origin_metadata_json JSON NULL AFTER approval_timestamp_provenance,
  ADD CONSTRAINT chk_arpa_subject_closure_native_provenance CHECK (
    (record_origin='NATIVE' AND approved_at IS NOT NULL AND approval_timestamp_provenance='NATIVE_RECORDED') OR
    (record_origin='LEGACY_IMPORT' AND approval_timestamp_provenance IN ('LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE'))
  );

ALTER TABLE arpa_officer_sub_designation_period
  ADD COLUMN record_origin ENUM('NATIVE','LEGACY_IMPORT') NOT NULL DEFAULT 'NATIVE' AFTER id,
  MODIFY COLUMN approved_at TIMESTAMP NULL,
  ADD COLUMN approval_timestamp_provenance ENUM('NATIVE_RECORDED','LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE') NOT NULL DEFAULT 'NATIVE_RECORDED' AFTER approved_at,
  ADD COLUMN origin_metadata_json JSON NULL AFTER approval_timestamp_provenance,
  ADD CONSTRAINT chk_arpa_subdesignation_native_provenance CHECK (
    (record_origin='NATIVE' AND approved_at IS NOT NULL AND approval_timestamp_provenance='NATIVE_RECORDED') OR
    (record_origin='LEGACY_IMPORT' AND approval_timestamp_provenance IN ('LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE'))
  );

CREATE TABLE legacy_arpa_appointment_business_record (
  id CHAR(36) PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL DEFAULT 'dems_legacy_hr',
  reconciled_business_key VARCHAR(128) NOT NULL,
  reconciliation_class ENUM('EXACT_DUPLICATE','SAME_APPOINTMENT_CONTINUATION','OLD_HISTORY_ONLY','2026_ONLY','CONFLICT','AMBIGUOUS') NOT NULL,
  target_concept ENUM('ARPA_DIVISION_APPOINTMENT','AGRARIAN_BANK_SUBJECT','SALES_SHOP_SUBJECT','SITHAMU_SUBJECT') NOT NULL,
  officer_id CHAR(36) NULL,
  source_snapshot_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_arpa_business_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  UNIQUE KEY uq_legacy_arpa_business_key(source_system,reconciled_business_key),
  INDEX idx_legacy_arpa_business_officer(officer_id,target_concept)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_arpa_appointment_source_reference (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  business_record_id CHAR(36) NOT NULL,
  source_system VARCHAR(80) NOT NULL DEFAULT 'dems_legacy_hr',
  source_table VARCHAR(80) NOT NULL,
  legacy_appointment_id VARCHAR(100) NOT NULL,
  target_appointment_request_id CHAR(36) NULL,
  target_appointment_id CHAR(36) NULL,
  target_subject_request_id CHAR(36) NULL,
  target_subject_assignment_id CHAR(36) NULL,
  target_sub_designation_period_id CHAR(36) NULL,
  legacy_payload_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_arpa_source_business FOREIGN KEY(business_record_id) REFERENCES legacy_arpa_appointment_business_record(id),
  CONSTRAINT fk_legacy_arpa_source_appointment_request FOREIGN KEY(target_appointment_request_id) REFERENCES arpa_division_appointment_request(id),
  CONSTRAINT fk_legacy_arpa_source_appointment FOREIGN KEY(target_appointment_id) REFERENCES arpa_division_appointment(id),
  CONSTRAINT fk_legacy_arpa_source_subject_request FOREIGN KEY(target_subject_request_id) REFERENCES arpa_subject_assignment_request(id),
  CONSTRAINT fk_legacy_arpa_source_subject_assignment FOREIGN KEY(target_subject_assignment_id) REFERENCES arpa_subject_assignment(id),
  CONSTRAINT fk_legacy_arpa_source_subdesignation FOREIGN KEY(target_sub_designation_period_id) REFERENCES arpa_officer_sub_designation_period(id),
  UNIQUE KEY uq_legacy_arpa_source(source_system,source_table,legacy_appointment_id),
  INDEX idx_legacy_arpa_source_business(business_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
