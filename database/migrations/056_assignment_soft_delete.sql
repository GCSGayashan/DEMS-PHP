ALTER TABLE officer_office_assignment
  ADD COLUMN deleted_at TIMESTAMP NULL AFTER version,
  ADD COLUMN deleted_by CHAR(36) NULL AFTER deleted_at,
  ADD COLUMN delete_reason VARCHAR(500) NULL AFTER deleted_by,
  ADD KEY idx_ooa_deleted (deleted_at),
  ADD CONSTRAINT fk_ooa_deleted_by FOREIGN KEY (deleted_by) REFERENCES system_user(id);

ALTER TABLE user_account_role
  ADD COLUMN deleted_at TIMESTAMP NULL AFTER approved_at,
  ADD COLUMN deleted_by CHAR(36) NULL AFTER deleted_at,
  ADD COLUMN delete_reason VARCHAR(500) NULL AFTER deleted_by,
  ADD KEY idx_uar_deleted (deleted_at),
  ADD CONSTRAINT fk_uar_deleted_by FOREIGN KEY (deleted_by) REFERENCES system_user(id);

ALTER TABLE user_account_scope
  ADD COLUMN deleted_at TIMESTAMP NULL AFTER action_reason,
  ADD COLUMN deleted_by CHAR(36) NULL AFTER deleted_at,
  ADD COLUMN delete_reason VARCHAR(500) NULL AFTER deleted_by,
  ADD KEY idx_uas_deleted (deleted_at),
  ADD CONSTRAINT fk_uas_deleted_by FOREIGN KEY (deleted_by) REFERENCES system_user(id);
