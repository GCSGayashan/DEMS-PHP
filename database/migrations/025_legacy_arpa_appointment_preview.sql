START TRANSACTION;

CREATE TABLE legacy_arpa_appointment_preview (
  reconciled_business_key CHAR(64) PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL DEFAULT 'dems_legacy_hr',
  officer_id CHAR(36) NOT NULL,
  legacy_officer_id VARCHAR(100) NOT NULL,
  assignment_category ENUM('ARPA_DIVISION','ASC_FUNCTION') NOT NULL,
  appointment_type ENUM('PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY') NULL,
  subject_kind ENUM('AGRARIAN_BANK','SALES_SHOP','SITHAMU') NULL,
  service_permanency_snapshot ENUM('PERMANENT_IN_SERVICE','NOT_PERMANENT_IN_SERVICE') NULL,
  service_permanency_source ENUM('EXACT_PERMANENTED_DATE','CURRENT_STATE_ONLY','UNRESOLVED') NOT NULL,
  effective_from DATE NULL,
  effective_to DATE NULL,
  legacy_reason_id VARCHAR(100) NULL,
  legacy_reason_text VARCHAR(500) NULL,
  workflow_state VARCHAR(80) NOT NULL,
  legacy_operational_approval TINYINT(1) NOT NULL DEFAULT 0,
  current_classification ENUM('CURRENT','HISTORICAL') NOT NULL,
  reconciliation_class ENUM('EXACT_DUPLICATE','SAME_APPOINTMENT_CONTINUATION','OLD_HISTORY_ONLY','2026_ONLY') NOT NULL,
  source_scope ENUM('OLD_ONLY','2026_ONLY','BOTH') NOT NULL,
  location_confidence ENUM('EXACT','STRONG_DERIVED','CURRENT_STATE_ONLY','UNRESOLVED','MISSING','INVALID','MISSING_TARGET_MAPPING') NOT NULL,
  asc_location_id CHAR(36) NULL,
  arpa_location_id CHAR(36) NULL,
  district_location_id CHAR(36) NULL,
  province_location_id CHAR(36) NULL,
  historical_exception TINYINT(1) NOT NULL DEFAULT 0,
  historical_exception_types_json JSON NOT NULL,
  current_conflict TINYINT(1) NOT NULL DEFAULT 0,
  current_conflict_types_json JSON NOT NULL,
  diagnostic_blocker TINYINT(1) NOT NULL DEFAULT 0,
  blocker_types_json JSON NOT NULL,
  source_references_json JSON NOT NULL,
  workflow_json JSON NOT NULL,
  location_provenance_json JSON NOT NULL,
  source_provenance_json JSON NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  first_indexed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_indexed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_arpa_preview_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_legacy_arpa_preview_asc FOREIGN KEY(asc_location_id) REFERENCES location(id),
  CONSTRAINT fk_legacy_arpa_preview_arpa FOREIGN KEY(arpa_location_id) REFERENCES location(id),
  CONSTRAINT fk_legacy_arpa_preview_district FOREIGN KEY(district_location_id) REFERENCES location(id),
  CONSTRAINT fk_legacy_arpa_preview_province FOREIGN KEY(province_location_id) REFERENCES location(id),
  INDEX idx_legacy_arpa_preview_officer(officer_id,effective_from),
  INDEX idx_legacy_arpa_preview_category(assignment_category,appointment_type,subject_kind),
  INDEX idx_legacy_arpa_preview_dates(effective_from,effective_to),
  INDEX idx_legacy_arpa_preview_current(current_classification,diagnostic_blocker),
  INDEX idx_legacy_arpa_preview_location(asc_location_id,arpa_location_id),
  INDEX idx_legacy_arpa_preview_hierarchy(province_location_id,district_location_id),
  INDEX idx_legacy_arpa_preview_source(source_scope,reconciliation_class),
  INDEX idx_legacy_arpa_preview_exception(historical_exception,current_conflict)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_arpa_appointment_preview_sync (
  singleton_id TINYINT PRIMARY KEY,
  reconciled_record_count INT NOT NULL,
  diagnostic_generated_at DATETIME NOT NULL,
  source_old_count INT NOT NULL,
  source_2026_count INT NOT NULL,
  indexed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_legacy_arpa_preview_sync_singleton CHECK(singleton_id=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO application_permission(id,permission_key,module_code,description,protected_permission,active)
VALUES(UUID(),'arpa.legacy-preview.view','ARPA_APPOINTMENT','View reconciled legacy ARPA appointment preview before migration',1,1);

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
WHERE p.permission_key='arpa.legacy-preview.view'
  AND r.role_code IN ('SYSTEM_ADMIN','NATIONAL_ADMIN','NATIONAL_SUBJECT_OFFICER','HR_ADMIN','HR_APPROVER','HR_VIEWER');

COMMIT;
