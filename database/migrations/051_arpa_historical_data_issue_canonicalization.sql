-- Permit audited historical Data Issue resolution and reopening of its
-- canonical assignment. Existing resolution statuses and provenance tables
-- remain authoritative.

ALTER TABLE arpa_appointment_data_correction
  DROP CHECK chk_arpa_data_correction_action,
  ADD CONSTRAINT chk_arpa_data_correction_action CHECK(correction_action IN(
    'MARK_HISTORICAL_ONLY','SET_EFFECTIVE_TO','CORRECT_APPOINTMENT_TYPE',
    'CORRECT_ARPA_DIVISION','CORRECT_EFFECTIVE_FROM','CORRECT_END_REASON',
    'SELECT_CURRENT_RECORD','KEEP_AS_HISTORICAL_EXCEPTION',
    'RESOLVE_CANONICAL_ASSIGNMENT','CLEAR_EFFECTIVE_TO'
  ));
