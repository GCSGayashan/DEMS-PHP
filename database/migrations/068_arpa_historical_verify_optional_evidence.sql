-- Migration 067 remains unchanged. Only VERIFY events may omit review text.
-- Case-registration evidence and all other event actions remain mandatory.
-- No existing audit rows, triggers, permissions or workflow states are changed.
ALTER TABLE arpa_appointment_historical_approval_event
  DROP CHECK chk_hist_approval_event_evidence,
  ADD CONSTRAINT chk_hist_approval_event_evidence CHECK (
    action='VERIFY' OR
    (CHAR_LENGTH(TRIM(reason))>0 AND CHAR_LENGTH(TRIM(evidence_reference))>0)
  );
