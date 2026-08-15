-- Preserve invalid legacy periods truthfully while keeping native DEMS requests strict.
ALTER TABLE arpa_division_appointment_request
  DROP CHECK arpa_division_appointment_request_chk_1,
  ADD CONSTRAINT chk_arpa_division_request_effective_period
    CHECK (
      record_origin = 'LEGACY_IMPORT'
      OR requested_effective_to IS NULL
      OR requested_effective_from IS NULL
      OR requested_effective_to >= requested_effective_from
    );

ALTER TABLE arpa_subject_assignment_request
  DROP CHECK arpa_subject_assignment_request_chk_1,
  ADD CONSTRAINT chk_arpa_subject_request_effective_period
    CHECK (
      record_origin = 'LEGACY_IMPORT'
      OR requested_effective_to IS NULL
      OR requested_effective_from IS NULL
      OR requested_effective_to >= requested_effective_from
    );
