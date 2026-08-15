START TRANSACTION;

ALTER TABLE legacy_officer_identity_consolidation
  ADD COLUMN resolution_note TEXT NULL AFTER removed_officer_snapshot_json;

-- Both source rows have the same structurally valid NIC, DOB, gender and
-- normalized initials. Once represented by one Officer the verified NIC is
-- no longer a uniqueness conflict. The later-updated source alias is INACTIVE,
-- which is the safe current master status for this historical identity.
UPDATE officer o
JOIN legacy_officer_identity_consolidation c ON c.primary_officer_id=o.id
JOIN officer_status s ON s.system_key='INACTIVE' AND s.active=1
SET o.nic='197522100292',
    o.nic_normalized='197522100292',
    o.nic_match_key='197522100292',
    o.officer_status_id=s.id,
    o.operational_status='INACTIVE',
    o.updated_at=NOW(),
    o.version=o.version+1,
    c.resolution_note='Legacy IDs 6290 and 7154 are one verified identity. Preserved Officer/DAD 70045-0006247; retired DAD 70045-0006400 remains allocated and non-reusable. INACTIVE chosen from the later-updated 7154 master row (2026-04-21).'
WHERE c.source_system='AGRARIANADMIN_HR'
  AND c.source_table='tbl_officer'
  AND c.primary_legacy_officer_id='6290'
  AND c.alias_legacy_officer_id='7154';

COMMIT;
