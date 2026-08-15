-- Database-backed, temporary login throttling. Keys are SHA-256 hashes of the
-- normalized username and validated REMOTE_ADDR; no credential material is stored.
CREATE TABLE IF NOT EXISTS login_attempt_throttle (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  throttle_type ENUM('USERNAME','CLIENT_IP') NOT NULL,
  throttle_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  failed_attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  blocked_until DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_login_throttle_type_key(throttle_type,throttle_key),
  INDEX idx_login_throttle_blocked_until(blocked_until),
  INDEX idx_login_throttle_updated_at(updated_at),
  CONSTRAINT chk_login_throttle_failed_count CHECK(failed_attempt_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
