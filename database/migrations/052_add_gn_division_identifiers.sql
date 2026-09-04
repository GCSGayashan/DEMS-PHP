-- Canonical GN identifiers retained independently from location.official_code.
ALTER TABLE location
  ADD COLUMN gn_code VARCHAR(20) NULL AFTER official_code,
  ADD COLUMN gn_code_for_plr VARCHAR(11) NULL AFTER gn_code;
