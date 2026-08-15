ALTER TABLE `system_user`
  ADD COLUMN password_setup_required TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN mfa_enrolled TINYINT(1) NOT NULL DEFAULT 0;
