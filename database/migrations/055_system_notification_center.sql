CREATE TABLE IF NOT EXISTS system_notification (
  id CHAR(36) NOT NULL,
  recipient_user_id CHAR(36) NOT NULL,
  notification_type ENUM('ACTION_REQUIRED','INFORMATION','WARNING') NOT NULL,
  module_code VARCHAR(80) NOT NULL,
  title VARCHAR(180) NOT NULL,
  message VARCHAR(1000) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id CHAR(36) NULL,
  workflow_stage VARCHAR(80) NULL,
  action_url VARCHAR(500) NULL,
  action_label VARCHAR(80) NULL,
  priority ENUM('NORMAL','HIGH','URGENT') NOT NULL DEFAULT 'NORMAL',
  read_at DATETIME NULL,
  action_status ENUM('PENDING','COMPLETED','RESOLVED_BY_OTHER','CANCELLED','EXPIRED') NOT NULL DEFAULT 'PENDING',
  completed_at DATETIME NULL,
  resolved_by CHAR(36) NULL,
  resolution_reason VARCHAR(500) NULL,
  dedupe_key VARCHAR(255) NOT NULL,
  required_role_assignment_id CHAR(36) NULL,
  required_scope_assignment_id CHAR(36) NULL,
  created_by CHAR(36) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  active_dedupe_key VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN action_status='PENDING' THEN dedupe_key ELSE NULL END) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_system_notification_pending (recipient_user_id,active_dedupe_key),
  KEY idx_system_notification_inbox (recipient_user_id,action_status,read_at,created_at),
  KEY idx_system_notification_entity (entity_type,entity_id,workflow_stage,action_status),
  KEY idx_system_notification_dedupe (dedupe_key),
  CONSTRAINT fk_system_notification_recipient FOREIGN KEY (recipient_user_id) REFERENCES system_user(id),
  CONSTRAINT fk_system_notification_resolver FOREIGN KEY (resolved_by) REFERENCES system_user(id),
  CONSTRAINT fk_system_notification_creator FOREIGN KEY (created_by) REFERENCES system_user(id),
  CONSTRAINT fk_system_notification_role_context FOREIGN KEY (required_role_assignment_id) REFERENCES user_account_role(id),
  CONSTRAINT fk_system_notification_scope_context FOREIGN KEY (required_scope_assignment_id) REFERENCES user_account_scope(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO application_permission(id,permission_key,module_code,description,protected_permission,active)
SELECT UUID(),'notification.view','NOTIFICATION_CENTER','View own notifications',1,1
WHERE NOT EXISTS(SELECT 1 FROM application_permission WHERE permission_key='notification.view');

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key='notification.view'
WHERE r.active=1;
