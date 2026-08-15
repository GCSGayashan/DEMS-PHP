ALTER TABLE user_account_scope
  ADD COLUMN role_assignment_id CHAR(36) NULL AFTER user_id,
  ADD COLUMN submitted_by CHAR(36) NULL,
  ADD COLUMN submitted_at TIMESTAMP NULL,
  ADD COLUMN approved_by CHAR(36) NULL,
  ADD COLUMN approved_at TIMESTAMP NULL,
  ADD COLUMN action_reason TEXT NULL,
  ADD CONSTRAINT fk_uas_role_assignment FOREIGN KEY(role_assignment_id) REFERENCES user_account_role(id);
