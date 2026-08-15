START TRANSACTION;

CREATE TABLE legacy_arpa_reconciliation_bulk_operation (
  id CHAR(36) PRIMARY KEY,
  operation_type VARCHAR(80) NOT NULL,
  operation_status ENUM('RUNNING','COMPLETED','COMPLETED_NO_CHANGES','FAILED') NOT NULL,
  decision_reason TEXT NOT NULL,
  selection_criteria_json JSON NOT NULL,
  eligible_record_count INT NOT NULL DEFAULT 0,
  decision_record_count INT NOT NULL DEFAULT 0,
  initiated_by CHAR(36) NOT NULL,
  initiated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  error_message VARCHAR(1000) NULL,
  CONSTRAINT fk_legacy_arpa_bulk_operation_user
    FOREIGN KEY(initiated_by) REFERENCES system_user(id),
  INDEX idx_legacy_arpa_bulk_operation_type(operation_type,initiated_at),
  INDEX idx_legacy_arpa_bulk_operation_user(initiated_by,initiated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE legacy_arpa_appointment_resolution
  MODIFY COLUMN resolution_type ENUM(
    'CONFIRM_ASC',
    'CONFIRM_CANDIDATE_ASC',
    'SELECT_DIFFERENT_ASC',
    'UNRESOLVED_HISTORICAL',
    'SELECT_ARPA_DIVISION',
    'ACTIVATE_CURRENT',
    'PRESERVE_HISTORY_ONLY',
    'REQUIRES_FURTHER_REVIEW'
  ) NOT NULL,
  ADD COLUMN original_evidence_class ENUM(
    'EXACT','STRONG_DERIVED','CURRENT_STATE_ONLY','UNRESOLVED','MISSING'
  ) NULL AFTER activation_decision,
  ADD COLUMN evidence_summary TEXT NULL AFTER original_evidence_class,
  ADD COLUMN bulk_operation_id CHAR(36) NULL AFTER evidence_summary,
  ADD CONSTRAINT fk_legacy_arpa_resolution_bulk_operation
    FOREIGN KEY(bulk_operation_id) REFERENCES legacy_arpa_reconciliation_bulk_operation(id),
  ADD INDEX idx_legacy_arpa_resolution_bulk_operation(bulk_operation_id);

ALTER TABLE legacy_arpa_appointment_resolution_audit
  ADD COLUMN bulk_operation_id CHAR(36) NULL AFTER reconciliation_item_id,
  ADD CONSTRAINT fk_legacy_arpa_resolution_audit_bulk_operation
    FOREIGN KEY(bulk_operation_id) REFERENCES legacy_arpa_reconciliation_bulk_operation(id),
  ADD INDEX idx_legacy_arpa_resolution_audit_bulk_operation(bulk_operation_id);

COMMIT;
