START TRANSACTION;

-- Recover safely if a prior MySQL DDL run stopped part-way through this
-- migration. Migration 014 is not recorded until the complete file succeeds.
SET @ddl=IF(EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='arpa_subject_assignment_request' AND CONSTRAINT_NAME='fk_arpa_subject_req_asc'),'ALTER TABLE arpa_subject_assignment_request DROP FOREIGN KEY fk_arpa_subject_req_asc','DO 0');
PREPARE ddl_stmt FROM @ddl; EXECUTE ddl_stmt; DEALLOCATE PREPARE ddl_stmt;
SET @ddl=IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='arpa_subject_assignment_request' AND INDEX_NAME='idx_arpa_subject_req_asc'),'ALTER TABLE arpa_subject_assignment_request DROP INDEX idx_arpa_subject_req_asc','DO 0');
PREPARE ddl_stmt FROM @ddl; EXECUTE ddl_stmt; DEALLOCATE PREPARE ddl_stmt;
SET @ddl=IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='arpa_subject_assignment_request' AND COLUMN_NAME='asc_location_id'),'ALTER TABLE arpa_subject_assignment_request DROP COLUMN asc_location_id','DO 0');
PREPARE ddl_stmt FROM @ddl; EXECUTE ddl_stmt; DEALLOCATE PREPARE ddl_stmt;
DROP TABLE IF EXISTS arpa_subject_master_migration_map;
DROP TABLE IF EXISTS subject_master;

-- Central Head-Office subject catalogue. There is currently no confirmed
-- enterprise number category for subjects, so dad_number remains nullable.
CREATE TABLE subject_master (
  id CHAR(36) PRIMARY KEY,
  dad_number VARCHAR(20) NULL UNIQUE,
  system_key VARCHAR(100) NOT NULL UNIQUE,
  name_en VARCHAR(200) NOT NULL,
  name_si VARCHAR(255) NULL,
  name_ta VARCHAR(255) NULL,
  subject_kind ENUM('NORMAL','AGRARIAN_BANK','SALES_SHOP','SITHAMU') NOT NULL DEFAULT 'NORMAL',
  active TINYINT(1) NOT NULL DEFAULT 1,
  approval_status VARCHAR(30) NOT NULL DEFAULT 'APPROVED',
  effective_from DATE NOT NULL,
  effective_to DATE NULL,
  created_by CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by CHAR(36) NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  version BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT fk_subject_master_created FOREIGN KEY(created_by) REFERENCES system_user(id),
  CONSTRAINT fk_subject_master_updated FOREIGN KEY(updated_by) REFERENCES system_user(id),
  INDEX idx_subject_master_list(active,approval_status,subject_kind,name_en),
  CHECK (effective_to IS NULL OR effective_to>=effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Collapse any pre-correction development masters by stable system key, or
-- by a deterministic kind/name key when the old system_key was missing.
CREATE TABLE arpa_subject_master_migration_map (
  old_subject_id CHAR(36) PRIMARY KEY,
  central_subject_id CHAR(36) NOT NULL,
  INDEX idx_subject_master_map_central(central_subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO subject_master(id,dad_number,system_key,name_en,subject_kind,active,approval_status,effective_from,created_by,created_at)
SELECT MIN(s.id),NULL,
       COALESCE(NULLIF(UPPER(TRIM(MAX(s.system_key))),''),CONCAT('MIGRATED_',MAX(s.assignment_kind),'_',SUBSTRING(SHA2(LOWER(TRIM(MAX(s.name_en))),256),1,16))),
       MAX(s.name_en),MAX(s.assignment_kind),MAX(s.active),'APPROVED',CURRENT_DATE(),MIN(s.created_by),MIN(s.created_at)
FROM arpa_asc_subject s
GROUP BY COALESCE(NULLIF(UPPER(TRIM(s.system_key)),''),CONCAT('MIGRATED_',s.assignment_kind,'_',SUBSTRING(SHA2(LOWER(TRIM(s.name_en)),256),1,16)));

INSERT INTO arpa_subject_master_migration_map(old_subject_id,central_subject_id)
SELECT s.id,m.id
FROM arpa_asc_subject s
JOIN subject_master m ON m.system_key=COALESCE(NULLIF(UPPER(TRIM(s.system_key)),''),CONCAT('MIGRATED_',s.assignment_kind,'_',SUBSTRING(SHA2(LOWER(TRIM(s.name_en)),256),1,16)));

ALTER TABLE arpa_subject_assignment_request
  ADD COLUMN asc_location_id CHAR(36) NULL AFTER officer_id,
  ADD INDEX idx_arpa_subject_req_asc(asc_location_id,workflow_status),
  ADD CONSTRAINT fk_arpa_subject_req_asc FOREIGN KEY(asc_location_id) REFERENCES location(id);

UPDATE arpa_subject_assignment_request r
JOIN arpa_asc_subject s ON s.id=r.subject_id
SET r.asc_location_id=s.asc_location_id
WHERE r.request_type='ASSIGN';

UPDATE arpa_subject_assignment_request r
JOIN arpa_subject_assignment a ON a.id=r.source_assignment_id
SET r.asc_location_id=a.asc_location_id
WHERE r.request_type='END';

SET @ddl=IF(EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='arpa_subject_assignment_request' AND CONSTRAINT_NAME='fk_arpa_subject_req_subject'),'ALTER TABLE arpa_subject_assignment_request DROP FOREIGN KEY fk_arpa_subject_req_subject','DO 0');
PREPARE ddl_stmt FROM @ddl; EXECUTE ddl_stmt; DEALLOCATE PREPARE ddl_stmt;
SET @ddl=IF(EXISTS(SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='arpa_subject_assignment' AND CONSTRAINT_NAME='fk_arpa_subject_assignment_subject'),'ALTER TABLE arpa_subject_assignment DROP FOREIGN KEY fk_arpa_subject_assignment_subject','DO 0');
PREPARE ddl_stmt FROM @ddl; EXECUTE ddl_stmt; DEALLOCATE PREPARE ddl_stmt;

UPDATE arpa_subject_assignment_request r
JOIN arpa_subject_master_migration_map mm ON mm.old_subject_id=r.subject_id
SET r.subject_id=mm.central_subject_id;

UPDATE arpa_subject_assignment a
JOIN arpa_subject_master_migration_map mm ON mm.old_subject_id=a.subject_id
SET a.subject_id=mm.central_subject_id;

ALTER TABLE arpa_subject_assignment_request DROP FOREIGN KEY fk_arpa_subject_req_asc;
ALTER TABLE arpa_subject_assignment_request MODIFY COLUMN asc_location_id CHAR(36) NOT NULL;
ALTER TABLE arpa_subject_assignment_request
  ADD CONSTRAINT fk_arpa_subject_req_asc FOREIGN KEY(asc_location_id) REFERENCES location(id),
  ADD CONSTRAINT fk_arpa_subject_req_subject FOREIGN KEY(subject_id) REFERENCES subject_master(id);

ALTER TABLE arpa_subject_assignment
  ADD CONSTRAINT fk_arpa_subject_assignment_subject FOREIGN KEY(subject_id) REFERENCES subject_master(id);

DROP TABLE arpa_subject_master_migration_map;
DROP TABLE arpa_asc_subject;

-- Required centrally recognized functions. These are new DEMS master rows,
-- not legacy tbl_subject imports.
INSERT IGNORE INTO subject_master(id,dad_number,system_key,name_en,subject_kind,active,approval_status,effective_from,created_at) VALUES
(UUID(),NULL,'AGRARIAN_BANK','Agrarian Bank','AGRARIAN_BANK',1,'APPROVED',CURRENT_DATE(),NOW()),
(UUID(),NULL,'SALES_SHOP','Sales Shop','SALES_SHOP',1,'APPROVED',CURRENT_DATE(),NOW()),
(UUID(),NULL,'SITHAMU','Sithamu','SITHAMU',1,'APPROVED',CURRENT_DATE(),NOW());

-- Sithamu is also a formal sub-designation. Allocate it through the existing
-- DESIGNATION enterprise-number category and ledger.
SELECT id,category_code
INTO @designation_category_id,@designation_category_code
FROM number_category
WHERE category_key='DESIGNATION' AND active=1
FOR UPDATE;

SET @sithamu_designation_number=CONCAT(@designation_category_code,'-',LPAD((SELECT next_value FROM number_category WHERE id=@designation_category_id),7,'0'));
INSERT INTO designation(id,dad_number,system_key,name_en,description,display_order,designation_level,parent_designation_id,active,effective_from,approval_status,created_at)
SELECT UUID(),@sithamu_designation_number,'SITHAMU','Sithamu','Sithamu sub-designation synchronized with the central Sithamu ASC subject assignment.',40,'SUB',p.id,1,CURRENT_DATE(),'APPROVED',NOW()
FROM designation p
WHERE p.system_key='ARPA_OFFICER'
  AND NOT EXISTS(SELECT 1 FROM designation WHERE system_key='SITHAMU');
SET @sithamu_designation_inserted=ROW_COUNT();
INSERT INTO number_allocation(category_id,allocated_number,allocated_at)
SELECT @designation_category_id,@sithamu_designation_number,NOW()
WHERE @sithamu_designation_inserted=1;
UPDATE number_category SET next_value=next_value+@sithamu_designation_inserted WHERE id=@designation_category_id;

CREATE TABLE arpa_officer_sub_designation_period (
  id CHAR(36) PRIMARY KEY,
  officer_id CHAR(36) NOT NULL,
  designation_id CHAR(36) NOT NULL,
  source_subject_assignment_id CHAR(36) NOT NULL UNIQUE,
  asc_location_id CHAR(36) NOT NULL,
  designation_key_snapshot VARCHAR(80) NOT NULL,
  designation_name_snapshot VARCHAR(200) NOT NULL,
  asc_dad_snapshot VARCHAR(20) NOT NULL,
  asc_name_snapshot VARCHAR(255) NOT NULL,
  effective_from DATE NOT NULL,
  approved_by CHAR(36) NOT NULL,
  approved_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_subdesig_officer FOREIGN KEY(officer_id) REFERENCES officer(id),
  CONSTRAINT fk_arpa_subdesig_designation FOREIGN KEY(designation_id) REFERENCES designation(id),
  CONSTRAINT fk_arpa_subdesig_assignment FOREIGN KEY(source_subject_assignment_id) REFERENCES arpa_subject_assignment(id),
  CONSTRAINT fk_arpa_subdesig_asc FOREIGN KEY(asc_location_id) REFERENCES location(id),
  CONSTRAINT fk_arpa_subdesig_approved FOREIGN KEY(approved_by) REFERENCES system_user(id),
  INDEX idx_arpa_subdesig_officer(officer_id,effective_from),
  INDEX idx_arpa_subdesig_asc(asc_location_id,effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE arpa_officer_sub_designation_closure (
  id CHAR(36) PRIMARY KEY,
  sub_designation_period_id CHAR(36) NOT NULL UNIQUE,
  source_subject_assignment_closure_id CHAR(36) NOT NULL UNIQUE,
  effective_to DATE NOT NULL,
  end_reason_id CHAR(36) NOT NULL,
  approved_by CHAR(36) NOT NULL,
  approved_at TIMESTAMP NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arpa_subdesig_close_period FOREIGN KEY(sub_designation_period_id) REFERENCES arpa_officer_sub_designation_period(id),
  CONSTRAINT fk_arpa_subdesig_close_assignment FOREIGN KEY(source_subject_assignment_closure_id) REFERENCES arpa_subject_assignment_closure(id),
  CONSTRAINT fk_arpa_subdesig_close_reason FOREIGN KEY(end_reason_id) REFERENCES arpa_appointment_end_reason(id),
  CONSTRAINT fk_arpa_subdesig_close_approved FOREIGN KEY(approved_by) REFERENCES system_user(id),
  INDEX idx_arpa_subdesig_close_effective(effective_to,end_reason_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO application_permission(id,permission_key,module_code,description,protected_permission,active) VALUES
(UUID(),'subject.master.view','SUBJECT_MANAGEMENT','View the central Head Office Subject Master',1,1),
(UUID(),'subject.master.create','SUBJECT_MANAGEMENT','Create central Head Office Subject Master records',1,1),
(UUID(),'subject.master.edit','SUBJECT_MANAGEMENT','Edit or activate/deactivate central Subject Master records',1,1);

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
WHERE p.permission_key='subject.master.view'
  AND r.role_code IN('SYSTEM_ADMIN','NATIONAL_ADMIN','NATIONAL_SUBJECT_OFFICER','NATIONAL_VIEWER','HR_ADMIN','HR_APPROVER','HR_VIEWER');

INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p
WHERE p.permission_key IN('subject.master.create','subject.master.edit')
  AND r.role_code IN('SYSTEM_ADMIN','NATIONAL_ADMIN','NATIONAL_SUBJECT_OFFICER','HR_ADMIN');

COMMIT;
