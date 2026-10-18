-- Phase 1A foundation for governed amendments of append-only ARPA
-- Appointment Data Correction decisions. This migration creates storage only;
-- it does not alter appointment workflow or operational appointment data.

CREATE TABLE arpa_appointment_correction_amendment_request (
  id CHAR(36) PRIMARY KEY,
  source_correction_id CHAR(36) NOT NULL,
  appointment_id CHAR(36) NOT NULL,
  officer_id CHAR(36) NOT NULL,
  asc_location_id CHAR(36) NOT NULL,
  proposed_action VARCHAR(80) NOT NULL,
  baseline_json JSON NOT NULL,
  baseline_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  proposed_json JSON NOT NULL,
  correction_reason VARCHAR(500) NOT NULL,
  remarks TEXT NULL,
  evidence_reference VARCHAR(500) NULL,
  workflow_status ENUM(
    'DRAFT','SUBMITTED','ASC_VERIFIED','ASC_APPROVED',
    'DISTRICT_VERIFIED','DISTRICT_APPROVED',
    'NATIONAL_VERIFIED','NATIONAL_APPROVED',
    'RETURNED','REJECTED','WITHDRAWN'
  ) NOT NULL DEFAULT 'DRAFT',
  created_by CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  submitted_by CHAR(36) NULL,
  submitted_at TIMESTAMP NULL,
  finalized_by CHAR(36) NULL,
  finalized_at TIMESTAMP NULL,
  applied_correction_id CHAR(36) NULL,
  version BIGINT NOT NULL DEFAULT 0,
  active_source_correction_id CHAR(36) GENERATED ALWAYS AS (
    CASE
      WHEN workflow_status IN(
        'DRAFT','SUBMITTED','ASC_VERIFIED','ASC_APPROVED',
        'DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','RETURNED'
      ) THEN source_correction_id
      ELSE NULL
    END
  ) STORED,
  CONSTRAINT fk_arpa_corr_amendment_source
    FOREIGN KEY (source_correction_id) REFERENCES arpa_appointment_data_correction(id),
  CONSTRAINT fk_arpa_corr_amendment_appointment
    FOREIGN KEY (appointment_id) REFERENCES arpa_division_appointment(id),
  CONSTRAINT fk_arpa_corr_amendment_officer
    FOREIGN KEY (officer_id) REFERENCES officer(id),
  CONSTRAINT fk_arpa_corr_amendment_asc
    FOREIGN KEY (asc_location_id) REFERENCES location(id),
  CONSTRAINT fk_arpa_corr_amendment_creator
    FOREIGN KEY (created_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_corr_amendment_updater
    FOREIGN KEY (updated_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_corr_amendment_submitter
    FOREIGN KEY (submitted_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_corr_amendment_finalizer
    FOREIGN KEY (finalized_by) REFERENCES system_user(id),
  CONSTRAINT fk_arpa_corr_amendment_applied
    FOREIGN KEY (applied_correction_id) REFERENCES arpa_appointment_data_correction(id),
  CONSTRAINT chk_arpa_corr_amendment_action CHECK (proposed_action IN(
    'MARK_HISTORICAL_ONLY','SET_EFFECTIVE_TO','CORRECT_APPOINTMENT_TYPE',
    'CORRECT_ARPA_DIVISION','CORRECT_EFFECTIVE_FROM','CORRECT_END_REASON',
    'SELECT_CURRENT_RECORD','KEEP_AS_HISTORICAL_EXCEPTION',
    'RESOLVE_CANONICAL_ASSIGNMENT','CLEAR_EFFECTIVE_TO','ADMIN_DATE_CORRECTION'
  )),
  CONSTRAINT chk_arpa_corr_amendment_hash
    CHECK (baseline_hash REGEXP '^[0-9A-Fa-f]{64}$'),
  UNIQUE KEY uq_arpa_corr_amendment_active (active_source_correction_id),
  UNIQUE KEY uq_arpa_corr_amendment_applied (applied_correction_id),
  KEY idx_arpa_corr_amendment_source (source_correction_id,workflow_status),
  KEY idx_arpa_corr_amendment_queue (workflow_status,asc_location_id,created_at),
  KEY idx_arpa_corr_amendment_appointment (appointment_id,created_at),
  KEY idx_arpa_corr_amendment_officer (officer_id,created_at),
  KEY idx_arpa_corr_amendment_creator (created_by,workflow_status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_appointment_correction_amendment_event (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  amendment_request_id CHAR(36) NOT NULL,
  action ENUM(
    'CREATE_DRAFT','EDIT_DRAFT','SUBMIT','VERIFY','APPROVE',
    'RETURN_FOR_CORRECTION','REJECT','WITHDRAW'
  ) NOT NULL,
  stage ENUM('CREATOR','ASC','DISTRICT','NATIONAL') NOT NULL,
  user_id CHAR(36) NOT NULL,
  comments TEXT NULL,
  previous_status VARCHAR(50) NULL,
  new_status VARCHAR(50) NOT NULL,
  before_proposal_json JSON NULL,
  after_proposal_json JSON NULL,
  action_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_corr_amendment_event_request
    FOREIGN KEY (amendment_request_id) REFERENCES arpa_appointment_correction_amendment_request(id),
  CONSTRAINT fk_arpa_corr_amendment_event_user
    FOREIGN KEY (user_id) REFERENCES system_user(id),
  KEY idx_arpa_corr_amendment_event_request (amendment_request_id,action_at,id),
  KEY idx_arpa_corr_amendment_event_user (user_id,action,action_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE arpa_appointment_data_correction
  ADD COLUMN supersedes_correction_id CHAR(36) NULL AFTER legacy_source_references_json,
  ADD COLUMN amendment_request_id CHAR(36) NULL AFTER supersedes_correction_id,
  ADD CONSTRAINT chk_arpa_data_correction_not_self_superseding
    CHECK (supersedes_correction_id IS NULL OR supersedes_correction_id<>id),
  ADD CONSTRAINT fk_arpa_data_correction_supersedes
    FOREIGN KEY (supersedes_correction_id) REFERENCES arpa_appointment_data_correction(id),
  ADD CONSTRAINT fk_arpa_data_correction_amendment
    FOREIGN KEY (amendment_request_id) REFERENCES arpa_appointment_correction_amendment_request(id),
  ADD UNIQUE KEY uq_arpa_data_correction_supersedes (supersedes_correction_id),
  ADD UNIQUE KEY uq_arpa_data_correction_amendment (amendment_request_id);
