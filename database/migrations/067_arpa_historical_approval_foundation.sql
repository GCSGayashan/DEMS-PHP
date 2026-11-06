-- Historical revalidation governs an existing appointment; it never materializes one.
-- 064-066 are reserved in development. This migration depends only on schema through 063.
CREATE TABLE arpa_appointment_historical_approval_case (
  id CHAR(36) PRIMARY KEY,
  request_id CHAR(36) NOT NULL,
  appointment_id CHAR(36) NOT NULL,
  source_correction_id CHAR(36) NOT NULL,
  officer_id CHAR(36) NOT NULL,
  asc_location_id CHAR(36) NOT NULL,
  baseline_json JSON NOT NULL,
  baseline_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  evidence_reference VARCHAR(1000) NOT NULL,
  remarks TEXT NULL,
  workflow_status ENUM('DRAFT','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED',
    'DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED','RETURNED','REJECTED') NOT NULL DEFAULT 'DRAFT',
  created_by CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  submitted_by CHAR(36) NULL,
  submitted_at TIMESTAMP NULL,
  finalized_by CHAR(36) NULL,
  finalized_at TIMESTAMP NULL,
  version BIGINT NOT NULL DEFAULT 0,
  active_appointment_id CHAR(36) GENERATED ALWAYS AS (
    CASE WHEN workflow_status NOT IN('NATIONAL_APPROVED','REJECTED') THEN appointment_id ELSE NULL END
  ) STORED,
  CONSTRAINT fk_hist_approval_request FOREIGN KEY(request_id) REFERENCES arpa_division_appointment_request(id),
  CONSTRAINT fk_hist_approval_appointment FOREIGN KEY(appointment_id) REFERENCES arpa_division_appointment(id),
  CONSTRAINT fk_hist_approval_correction FOREIGN KEY(source_correction_id) REFERENCES arpa_appointment_data_correction(id),
  CONSTRAINT fk_hist_approval_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_hist_approval_asc FOREIGN KEY(asc_location_id) REFERENCES location(id),
  CONSTRAINT fk_hist_approval_creator FOREIGN KEY(created_by) REFERENCES system_user(id),
  CONSTRAINT fk_hist_approval_updater FOREIGN KEY(updated_by) REFERENCES system_user(id),
  CONSTRAINT fk_hist_approval_submitter FOREIGN KEY(submitted_by) REFERENCES system_user(id),
  CONSTRAINT fk_hist_approval_finalizer FOREIGN KEY(finalized_by) REFERENCES system_user(id),
  CONSTRAINT chk_hist_approval_hash CHECK(baseline_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_hist_approval_evidence CHECK(CHAR_LENGTH(TRIM(reason))>0 AND CHAR_LENGTH(TRIM(evidence_reference))>0),
  CONSTRAINT chk_hist_approval_version CHECK(version>=0),
  UNIQUE KEY uq_hist_approval_registration(appointment_id,source_correction_id),
  UNIQUE KEY uq_hist_approval_active(active_appointment_id),
  KEY idx_hist_approval_queue(workflow_status,asc_location_id,created_at),
  KEY idx_hist_approval_officer(officer_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_appointment_historical_approval_event (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  approval_case_id CHAR(36) NOT NULL,
  action ENUM('REGISTER','EDIT_DRAFT','SUBMIT','VERIFY','APPROVE','RETURN_FOR_CORRECTION','REJECT') NOT NULL,
  stage ENUM('CREATOR','ASC','DISTRICT','NATIONAL') NOT NULL,
  user_id CHAR(36) NOT NULL,
  previous_status VARCHAR(50) NULL,
  new_status VARCHAR(50) NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  evidence_reference VARCHAR(1000) NOT NULL,
  before_json JSON NULL,
  after_json JSON NOT NULL,
  action_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hist_approval_event_case FOREIGN KEY(approval_case_id) REFERENCES arpa_appointment_historical_approval_case(id),
  CONSTRAINT fk_hist_approval_event_actor FOREIGN KEY(user_id) REFERENCES system_user(id),
  CONSTRAINT chk_hist_approval_event_evidence CHECK(CHAR_LENGTH(TRIM(reason))>0 AND CHAR_LENGTH(TRIM(evidence_reference))>0),
  KEY idx_hist_approval_events(approval_case_id,id),
  KEY idx_hist_approval_actor(user_id,action_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only evidence, including protection from accidental application updates.
CREATE TRIGGER trg_hist_approval_event_no_update BEFORE UPDATE ON arpa_appointment_historical_approval_event
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical approval events are append-only';
CREATE TRIGGER trg_hist_approval_event_no_delete BEFORE DELETE ON arpa_appointment_historical_approval_event
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historical approval events are append-only';
