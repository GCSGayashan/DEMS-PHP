START TRANSACTION;

CREATE TABLE legacy_arpa_reconciliation_item (
  id CHAR(36) PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL DEFAULT 'dems_legacy_hr',
  reconciled_business_key CHAR(64) NOT NULL,
  item_type ENUM('SPECIAL_ASC','MISSING_ARPA_LOCATION','CURRENT_CONFLICT') NOT NULL,
  issue_types_json JSON NOT NULL,
  source_references_json JSON NOT NULL,
  primary_source_table VARCHAR(80) NOT NULL,
  primary_source_record_id VARCHAR(100) NOT NULL,
  legacy_officer_id VARCHAR(100) NOT NULL,
  officer_id CHAR(36) NOT NULL,
  subject_kind ENUM('AGRARIAN_BANK','SALES_SHOP','SITHAMU') NULL,
  appointment_type ENUM('PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY') NULL,
  effective_from DATE NULL,
  effective_to DATE NULL,
  current_classification ENUM('CURRENT','HISTORICAL') NOT NULL,
  source_confidence ENUM('EXACT','STRONG_DERIVED','CURRENT_STATE_ONLY','UNRESOLVED','MISSING') NOT NULL,
  candidate_asc_id CHAR(36) NULL,
  candidate_arpa_id CHAR(36) NULL,
  candidate_evidence_json JSON NULL,
  context_snapshot_json JSON NOT NULL,
  diagnostic_blocker TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_legacy_arpa_review_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_legacy_arpa_review_candidate_asc FOREIGN KEY(candidate_asc_id) REFERENCES location(id),
  CONSTRAINT fk_legacy_arpa_review_candidate_arpa FOREIGN KEY(candidate_arpa_id) REFERENCES location(id),
  UNIQUE KEY uq_legacy_arpa_review_item(source_system,item_type,reconciled_business_key),
  INDEX idx_legacy_arpa_review_queue(item_type,active,diagnostic_blocker,current_classification),
  INDEX idx_legacy_arpa_review_officer(officer_id,item_type),
  INDEX idx_legacy_arpa_review_confidence(source_confidence,item_type),
  INDEX idx_legacy_arpa_review_candidate_asc(candidate_asc_id,item_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_arpa_appointment_resolution (
  id CHAR(36) PRIMARY KEY,
  reconciliation_item_id CHAR(36) NOT NULL,
  reconciled_business_key CHAR(64) NOT NULL,
  resolution_type ENUM('CONFIRM_CANDIDATE_ASC','SELECT_DIFFERENT_ASC','UNRESOLVED_HISTORICAL','SELECT_ARPA_DIVISION','ACTIVATE_CURRENT','PRESERVE_HISTORY_ONLY','REQUIRES_FURTHER_REVIEW') NOT NULL,
  resolution_status ENUM('CONFIRMED','UNRESOLVED_HISTORICAL','REQUIRES_FURTHER_REVIEW') NOT NULL,
  selected_target_location_id CHAR(36) NULL,
  selected_target_asc_id CHAR(36) NULL,
  selected_target_arpa_id CHAR(36) NULL,
  activation_decision ENUM('ACTIVATE_CURRENT','PRESERVE_HISTORY_ONLY','DO_NOT_ACTIVATE') NULL,
  supporting_reconciliation_item_id CHAR(36) NULL,
  supporting_reconciled_business_key CHAR(64) NULL,
  decision_reason TEXT NOT NULL,
  evidence_notes TEXT NULL,
  decided_by CHAR(36) NOT NULL,
  decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_legacy_arpa_resolution_item FOREIGN KEY(reconciliation_item_id) REFERENCES legacy_arpa_reconciliation_item(id),
  CONSTRAINT fk_legacy_arpa_resolution_location FOREIGN KEY(selected_target_location_id) REFERENCES location(id),
  CONSTRAINT fk_legacy_arpa_resolution_asc FOREIGN KEY(selected_target_asc_id) REFERENCES location(id),
  CONSTRAINT fk_legacy_arpa_resolution_arpa FOREIGN KEY(selected_target_arpa_id) REFERENCES location(id),
  CONSTRAINT fk_legacy_arpa_resolution_support FOREIGN KEY(supporting_reconciliation_item_id) REFERENCES legacy_arpa_reconciliation_item(id),
  CONSTRAINT fk_legacy_arpa_resolution_decider FOREIGN KEY(decided_by) REFERENCES system_user(id),
  CONSTRAINT fk_legacy_arpa_resolution_updater FOREIGN KEY(updated_by) REFERENCES system_user(id),
  UNIQUE KEY uq_legacy_arpa_resolution_item(reconciliation_item_id),
  INDEX idx_legacy_arpa_resolution_status(resolution_status,resolution_type),
  INDEX idx_legacy_arpa_resolution_decided(decided_by,decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_arpa_appointment_resolution_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  resolution_id CHAR(36) NOT NULL,
  reconciliation_item_id CHAR(36) NOT NULL,
  audit_action ENUM('CREATED','CHANGED') NOT NULL,
  previous_decision_json JSON NULL,
  new_decision_json JSON NOT NULL,
  changed_by CHAR(36) NOT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_arpa_resolution_audit_resolution FOREIGN KEY(resolution_id) REFERENCES legacy_arpa_appointment_resolution(id),
  CONSTRAINT fk_legacy_arpa_resolution_audit_item FOREIGN KEY(reconciliation_item_id) REFERENCES legacy_arpa_reconciliation_item(id),
  CONSTRAINT fk_legacy_arpa_resolution_audit_user FOREIGN KEY(changed_by) REFERENCES system_user(id),
  INDEX idx_legacy_arpa_resolution_audit_item(reconciliation_item_id,changed_at),
  INDEX idx_legacy_arpa_resolution_audit_actor(changed_by,changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_arpa_reconciliation_sync (
  singleton_id TINYINT PRIMARY KEY,
  diagnostic_generated_at DATETIME NOT NULL,
  reconciled_business_record_count INT NOT NULL,
  officer_blockers INT NOT NULL,
  workflow_blockers INT NOT NULL,
  schema_blockers INT NOT NULL,
  reconciliation_issue_rows INT NOT NULL,
  source_summary_json JSON NOT NULL,
  synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  synced_by CHAR(36) NULL,
  CONSTRAINT fk_legacy_arpa_reconciliation_sync_user FOREIGN KEY(synced_by) REFERENCES system_user(id),
  CONSTRAINT chk_legacy_arpa_reconciliation_singleton CHECK(singleton_id=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO application_permission(id,permission_key,module_code,description,protected_permission,active) VALUES
(UUID(),'arpa.legacy-reconciliation.view','ARPA_APPOINTMENT','View legacy ARPA appointment migration reconciliation workbench',1,1),
(UUID(),'arpa.legacy-reconciliation.decide','ARPA_APPOINTMENT','Record audited legacy ARPA appointment reconciliation decisions',1,1);

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
WHERE p.permission_key='arpa.legacy-reconciliation.view'
  AND r.role_code IN ('SYSTEM_ADMIN','NATIONAL_ADMIN','NATIONAL_SUBJECT_OFFICER','HR_ADMIN','HR_APPROVER','HR_VIEWER');

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
WHERE p.permission_key='arpa.legacy-reconciliation.decide'
  AND r.role_code IN ('SYSTEM_ADMIN','NATIONAL_ADMIN','HR_ADMIN','HR_APPROVER');

COMMIT;
