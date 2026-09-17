ALTER TABLE arpa_division_appointment_request
  ADD COLUMN deleted_at TIMESTAMP NULL AFTER version,
  ADD COLUMN deleted_by CHAR(36) NULL AFTER deleted_at,
  ADD COLUMN delete_reason VARCHAR(500) NULL AFTER deleted_by,
  ADD KEY idx_arpa_division_request_deleted (deleted_at),
  ADD CONSTRAINT fk_arpa_division_request_deleted_by FOREIGN KEY (deleted_by) REFERENCES system_user(id);
