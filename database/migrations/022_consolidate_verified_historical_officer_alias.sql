START TRANSACTION;

CREATE TABLE legacy_officer_identity_consolidation (
  id CHAR(36) PRIMARY KEY,
  source_system VARCHAR(80) NOT NULL,
  source_table VARCHAR(80) NOT NULL,
  primary_legacy_officer_id VARCHAR(100) NOT NULL,
  alias_legacy_officer_id VARCHAR(100) NOT NULL,
  primary_officer_id CHAR(36) NOT NULL,
  removed_duplicate_officer_id CHAR(36) NOT NULL,
  retired_dad_number VARCHAR(20) NOT NULL,
  evidence_json JSON NOT NULL,
  removed_officer_snapshot_json JSON NOT NULL,
  consolidated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_legacy_officer_consolidation_primary FOREIGN KEY(primary_officer_id) REFERENCES officer(id),
  UNIQUE KEY uq_legacy_officer_consolidation_alias(source_system,source_table,alias_legacy_officer_id),
  UNIQUE KEY uq_legacy_officer_consolidation_removed(removed_duplicate_officer_id),
  UNIQUE KEY uq_legacy_officer_consolidation_dad(retired_dad_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @primary_officer_id := (
  SELECT officer_id FROM legacy_officer_reference
  WHERE source_system='AGRARIANADMIN_HR' AND source_table='tbl_officer' AND legacy_officer_id='6290'
  LIMIT 1
);
SET @duplicate_officer_id := (
  SELECT officer_id FROM legacy_officer_reference
  WHERE source_system='AGRARIANADMIN_HR' AND source_table='tbl_officer' AND legacy_officer_id='7154'
  LIMIT 1
);

INSERT INTO legacy_officer_identity_consolidation
  (id,source_system,source_table,primary_legacy_officer_id,alias_legacy_officer_id,
   primary_officer_id,removed_duplicate_officer_id,retired_dad_number,evidence_json,removed_officer_snapshot_json)
SELECT UUID(),'AGRARIANADMIN_HR','tbl_officer','6290','7154',
       p.id,d.id,d.dad_number,
       JSON_OBJECT('match_rule','EXACT_VALID_NIC_DOB_GENDER_NORMALIZED_INITIAL_NAME',
                   'nic','197522100292','date_of_birth','1975-08-08','gender','MALE',
                   'normalized_initial_name','BADWIJERATHNA'),
       JSON_OBJECT('id',d.id,'dad_number',d.dad_number,'nic',d.nic,'nic_normalized',d.nic_normalized,
                   'nic_match_key',d.nic_match_key,'name_with_initials',d.name_with_initials,
                   'full_name_en',d.full_name_en,'date_of_birth',d.date_of_birth,'gender',d.gender,
                   'permanent_address',d.permanent_address,'primary_mobile',d.primary_mobile,
                   'alternative_mobile',d.alternative_mobile,'personal_email',d.personal_email,
                   'initial_appointment_date',d.initial_appointment_date,'primary_designation_id',d.primary_designation_id,
                   'class_id',d.class_id,'arpa_service_permanency',d.arpa_service_permanency,
                   'officer_status_id',d.officer_status_id,'effective_from',d.effective_from,
                   'operational_status',d.operational_status,'approval_status',d.approval_status,
                   'created_at',d.created_at)
FROM officer p JOIN officer d
  ON p.id=@primary_officer_id AND d.id=@duplicate_officer_id AND p.id<>d.id;

UPDATE legacy_officer_reference alias_ref
JOIN legacy_officer_reference primary_ref
  ON primary_ref.source_system=alias_ref.source_system
 AND primary_ref.source_table=alias_ref.source_table
 AND primary_ref.legacy_officer_id='6290'
SET alias_ref.officer_id=primary_ref.officer_id
WHERE alias_ref.source_system='AGRARIANADMIN_HR'
  AND alias_ref.source_table='tbl_officer'
  AND alias_ref.legacy_officer_id='7154'
  AND alias_ref.officer_id=@duplicate_officer_id;

-- The allocated DAD number is deliberately retained in number_allocation and
-- is never returned to the sequence. The full removed row is recoverable from
-- legacy_officer_identity_consolidation.
DELETE o FROM officer o
LEFT JOIN legacy_officer_reference r ON r.officer_id=o.id
WHERE o.id=@duplicate_officer_id AND r.id IS NULL;

COMMIT;
