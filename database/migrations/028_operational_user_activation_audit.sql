-- Selective operational activation audit. This migration deliberately creates no
-- users, role assignments, scope assignments, or credentials.
CREATE TABLE IF NOT EXISTS user_operational_access_event (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  event_type ENUM('ACTIVATE','REACTIVATE','DEACTIVATE') NOT NULL,
  previous_identity_type VARCHAR(50) NOT NULL,
  new_identity_type VARCHAR(50) NOT NULL,
  previous_account_status VARCHAR(50) NOT NULL,
  new_account_status VARCHAR(50) NOT NULL,
  previous_username VARCHAR(100) NOT NULL,
  new_username VARCHAR(100) NOT NULL,
  role_assignments_json JSON NULL,
  scope_assignments_json JSON NULL,
  reason TEXT NOT NULL,
  official_reference VARCHAR(255) NULL,
  acted_by CHAR(36) NOT NULL,
  acted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_access_event_user FOREIGN KEY(user_id) REFERENCES system_user(id),
  CONSTRAINT fk_user_access_event_actor FOREIGN KEY(acted_by) REFERENCES system_user(id),
  INDEX idx_user_access_event_target(user_id,acted_at),
  INDEX idx_user_access_event_actor(acted_by,acted_at),
  INDEX idx_user_access_event_type(event_type,acted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
