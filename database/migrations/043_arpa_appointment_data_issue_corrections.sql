-- Direct, scoped ARPA appointment data-quality corrections.
-- These records are append-only and do not participate in appointment workflow.

INSERT IGNORE INTO application_permission
  (id,permission_key,module_code,description,protected_permission,active)
VALUES
  (UUID(),'arpa.appointment.data-issue.correct','ARPA_APPOINTMENT','Correct an evidence-backed ARPA appointment data issue inside an exact ASC scope',1,1);

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key='arpa.appointment.data-issue.correct'
WHERE r.role_code='ASC_SUBJECT_OFFICER';

ALTER TABLE arpa_division_appointment_closure
  MODIFY approval_timestamp_provenance ENUM(
    'NATIVE_RECORDED',
    'LEGACY_SOURCE_RECORDED',
    'UNAVAILABLE_FROM_LEGACY_SOURCE',
    'DATA_CORRECTION_RECORDED'
  ) NOT NULL DEFAULT 'NATIVE_RECORDED',
  ADD COLUMN closure_source ENUM('WORKFLOW','DATA_ISSUE_CORRECTION') NOT NULL DEFAULT 'WORKFLOW' AFTER closure_kind,
  ADD COLUMN data_correction_id CHAR(36) NULL AFTER closure_source,
  ADD INDEX idx_arpa_closure_data_correction(data_correction_id);

ALTER TABLE arpa_division_appointment_closure
  DROP CHECK chk_arpa_closure_native_provenance,
  DROP CHECK chk_arpa_division_closure_native_fields;

ALTER TABLE arpa_division_appointment_closure
  ADD CONSTRAINT chk_arpa_closure_native_provenance CHECK(
    (record_origin='NATIVE' AND closure_source='WORKFLOW' AND approved_by IS NOT NULL AND approved_at IS NOT NULL AND approval_timestamp_provenance='NATIVE_RECORDED')
    OR
    (record_origin='NATIVE' AND closure_source='DATA_ISSUE_CORRECTION' AND approved_by IS NULL AND approved_at IS NULL AND approval_timestamp_provenance='DATA_CORRECTION_RECORDED')
    OR
    (record_origin='LEGACY_IMPORT' AND approval_timestamp_provenance IN('LEGACY_SOURCE_RECORDED','UNAVAILABLE_FROM_LEGACY_SOURCE'))
  ),
  ADD CONSTRAINT chk_arpa_division_closure_native_fields CHECK(
    record_origin='LEGACY_IMPORT'
    OR closure_source='DATA_ISSUE_CORRECTION'
    OR (approved_by IS NOT NULL AND end_reason_id IS NOT NULL)
  );

CREATE TABLE arpa_appointment_data_correction (
  id CHAR(36) PRIMARY KEY,
  issue_row_key VARCHAR(255) NOT NULL,
  issue_type VARCHAR(100) NOT NULL,
  officer_id CHAR(36) NOT NULL,
  appointment_id CHAR(36) NULL,
  request_id CHAR(36) NULL,
  related_appointment_ids_json JSON NOT NULL,
  asc_location_id CHAR(36) NOT NULL,
  corrected_by CHAR(36) NOT NULL,
  corrected_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  correction_action VARCHAR(80) NOT NULL,
  resolution_status ENUM('RESOLVED_BY_CORRECTION','REVIEWED_UNRESOLVED','KEPT_HISTORICAL_EXCEPTION') NOT NULL,
  correction_reason VARCHAR(500) NOT NULL,
  remarks TEXT NULL,
  evidence_reference VARCHAR(500) NULL,
  before_json JSON NOT NULL,
  after_json JSON NOT NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'APPOINTMENT_DATA_ISSUE',
  record_origin ENUM('NATIVE','LEGACY_IMPORT','MIXED') NOT NULL,
  legacy_source_references_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_data_correction_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_arpa_data_correction_appointment FOREIGN KEY(appointment_id) REFERENCES arpa_division_appointment(id),
  CONSTRAINT fk_arpa_data_correction_request FOREIGN KEY(request_id) REFERENCES arpa_division_appointment_request(id),
  CONSTRAINT fk_arpa_data_correction_asc FOREIGN KEY(asc_location_id) REFERENCES location(id),
  CONSTRAINT fk_arpa_data_correction_user FOREIGN KEY(corrected_by) REFERENCES system_user(id),
  CONSTRAINT chk_arpa_data_correction_source CHECK(source='APPOINTMENT_DATA_ISSUE'),
  CONSTRAINT chk_arpa_data_correction_action CHECK(correction_action IN(
    'MARK_HISTORICAL_ONLY','SET_EFFECTIVE_TO','CORRECT_APPOINTMENT_TYPE',
    'CORRECT_ARPA_DIVISION','CORRECT_EFFECTIVE_FROM','CORRECT_END_REASON',
    'SELECT_CURRENT_RECORD','KEEP_AS_HISTORICAL_EXCEPTION'
  )),
  INDEX idx_arpa_data_correction_issue(issue_row_key,corrected_at),
  INDEX idx_arpa_data_correction_officer(officer_id,corrected_at),
  INDEX idx_arpa_data_correction_appointment(appointment_id,corrected_at),
  INDEX idx_arpa_data_correction_asc(asc_location_id,corrected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
