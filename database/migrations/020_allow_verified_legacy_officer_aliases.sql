START TRANSACTION;

-- A legacy person may have more than one stable tbl_officer ID. Source identity
-- remains unique, while verified aliases may resolve to the same target Officer.
ALTER TABLE legacy_officer_reference
  DROP INDEX uq_legacy_officer_target,
  ADD INDEX idx_legacy_officer_target(officer_id);

COMMIT;
