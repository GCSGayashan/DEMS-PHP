-- A maker-requested Office change remains separate from the approved
-- assignment until the checker approves it. The self-reference preserves
-- the authoritative assignment that the request will replace.
ALTER TABLE officer_office_assignment
  ADD COLUMN replaces_assignment_id CHAR(36) NULL AFTER workflow_scope_location_id,
  ADD COLUMN request_kind ENUM('STANDARD','ADDITIONAL','REPLACEMENT') NOT NULL DEFAULT 'STANDARD' AFTER replaces_assignment_id,
  ADD KEY idx_ooa_replaces_assignment (replaces_assignment_id,approval_status),
  ADD CONSTRAINT fk_ooa_replaces_assignment
    FOREIGN KEY (replaces_assignment_id) REFERENCES officer_office_assignment(id);
