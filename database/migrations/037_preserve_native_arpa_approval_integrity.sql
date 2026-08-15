-- Keep native approval identity/reason rules strict after allowing truthful legacy NULLs.
ALTER TABLE arpa_division_appointment
  ADD CONSTRAINT chk_arpa_division_appointment_native_actor
    CHECK(record_origin='LEGACY_IMPORT' OR approved_by IS NOT NULL);

ALTER TABLE arpa_subject_assignment
  ADD CONSTRAINT chk_arpa_subject_assignment_native_actor
    CHECK(record_origin='LEGACY_IMPORT' OR approved_by IS NOT NULL);

ALTER TABLE arpa_officer_sub_designation_period
  ADD CONSTRAINT chk_arpa_subdesignation_period_native_actor
    CHECK(record_origin='LEGACY_IMPORT' OR approved_by IS NOT NULL);

ALTER TABLE arpa_division_appointment_closure
  ADD CONSTRAINT chk_arpa_division_closure_native_fields
    CHECK(record_origin='LEGACY_IMPORT' OR (approved_by IS NOT NULL AND end_reason_id IS NOT NULL));

ALTER TABLE arpa_subject_assignment_closure
  ADD CONSTRAINT chk_arpa_subject_closure_native_fields
    CHECK(record_origin='LEGACY_IMPORT' OR (approved_by IS NOT NULL AND end_reason_id IS NOT NULL));
