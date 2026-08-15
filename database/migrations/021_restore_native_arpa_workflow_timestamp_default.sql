START TRANSACTION;

-- Native workflow services intentionally omit action_at and rely on the
-- database clock. LEGACY_IMPORT tooling may explicitly insert NULL together
-- with UNAVAILABLE_FROM_LEGACY_SOURCE.
ALTER TABLE arpa_appointment_workflow_action
  MODIFY COLUMN action_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE arpa_subject_workflow_action
  MODIFY COLUMN action_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP;

COMMIT;
