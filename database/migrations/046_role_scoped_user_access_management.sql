-- Couple every persisted scope to one role assignment owned by the same user.
-- The NOT NULL/FK operations intentionally fail instead of guessing if older
-- installations contain an unmapped or cross-user scope.
ALTER TABLE user_account_role
  ADD COLUMN replaces_assignment_id CHAR(36) NULL AFTER role_id,
  ADD UNIQUE INDEX uq_uar_id_user (id,user_id),
  ADD INDEX idx_uar_effective_access (user_id,active,approval_status,effective_from,effective_to,role_id),
  ADD INDEX idx_uar_replaces_assignment (replaces_assignment_id),
  ADD CONSTRAINT fk_uar_replaces_assignment FOREIGN KEY (replaces_assignment_id) REFERENCES user_account_role(id);

ALTER TABLE user_account_scope
  DROP FOREIGN KEY fk_uas_role_assignment,
  MODIFY role_assignment_id CHAR(36) NOT NULL,
  ADD INDEX idx_uas_assignment_effective (role_assignment_id,user_id,active,approval_status,effective_from,effective_to),
  ADD CONSTRAINT fk_uas_role_assignment_user
    FOREIGN KEY (role_assignment_id,user_id)
    REFERENCES user_account_role(id,user_id);

-- National and District administrators receive only the User Management
-- permissions needed by the server-side hierarchy policy. Existing mappings
-- (especially SYSTEM_ADMIN) are preserved.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id
FROM application_role r
JOIN application_permission p ON p.permission_key IN (
  'user.view',
  'user.activate',
  'user.block',
  'user.reset-password',
  'user.assign-role',
  'user.revoke-role',
  'user.assign-scope',
  'user.revoke-scope'
)
WHERE r.role_code IN ('NATIONAL_ADMIN','DISTRICT_ADMIN');
