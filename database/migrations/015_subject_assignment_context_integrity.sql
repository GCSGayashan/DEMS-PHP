-- Every subject transaction identifies both the central subject and the ASC
-- workplace. The context index is intentionally non-unique because multiple
-- officers may hold the same subject at the same ASC.
ALTER TABLE arpa_subject_assignment_request
  DROP FOREIGN KEY fk_arpa_subject_req_subject;
ALTER TABLE arpa_subject_assignment_request
  MODIFY COLUMN subject_id CHAR(36) NOT NULL;
ALTER TABLE arpa_subject_assignment_request
  ADD CONSTRAINT fk_arpa_subject_req_subject FOREIGN KEY(subject_id) REFERENCES subject_master(id),
  ADD INDEX idx_arpa_subject_req_context(asc_location_id,subject_id,workflow_status);

ALTER TABLE arpa_subject_assignment
  ADD INDEX idx_arpa_subject_assignment_context(asc_location_id,subject_id,effective_from);
