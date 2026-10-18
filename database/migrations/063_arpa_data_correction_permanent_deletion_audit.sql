-- Independent audit evidence for the narrowly controlled permanent deletion
-- of an incorrect, non-operational ARPA Appointment Data Correction decision.
-- This table intentionally has no foreign key to the correction being deleted.

CREATE TABLE arpa_appointment_data_correction_deletion_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  correction_id CHAR(36) NOT NULL,
  officer_id CHAR(36) NOT NULL,
  appointment_id CHAR(36) NULL,
  request_id CHAR(36) NULL,
  asc_location_id CHAR(36) NOT NULL,
  original_correction_action VARCHAR(80) NOT NULL,
  original_resolution_status VARCHAR(80) NOT NULL,
  correction_snapshot_json JSON NOT NULL,
  deleted_by CHAR(36) NOT NULL,
  deletion_reason VARCHAR(500) NOT NULL,
  deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_data_correction_deletion_actor
    FOREIGN KEY (deleted_by) REFERENCES system_user(id),
  CONSTRAINT chk_arpa_data_correction_deletion_reason
    CHECK (CHAR_LENGTH(TRIM(deletion_reason))>0),
  UNIQUE KEY uq_arpa_data_correction_deletion_correction (correction_id),
  KEY idx_arpa_data_correction_deletion_officer (officer_id,deleted_at),
  KEY idx_arpa_data_correction_deletion_appointment (appointment_id,deleted_at),
  KEY idx_arpa_data_correction_deletion_actor (deleted_by,deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
