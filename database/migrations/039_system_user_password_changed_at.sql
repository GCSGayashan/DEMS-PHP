-- Track successful credential rotation without storing password material.
ALTER TABLE `system_user`
  ADD COLUMN password_changed_at TIMESTAMP NULL AFTER password_hash;
