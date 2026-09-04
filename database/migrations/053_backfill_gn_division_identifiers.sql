-- Controlled one-to-one GN identifier backfill from canonical legacy references.
-- Refuse to run unless the verified 14,016-row source/reference population is intact.
SET @gn_identifier_expected_source_count = 14016;

DROP TEMPORARY TABLE IF EXISTS tmp_gn_identifier_backfill_guard;
CREATE TEMPORARY TABLE tmp_gn_identifier_backfill_guard (
  blocker_count INT NOT NULL,
  CHECK (blocker_count = 0)
);

INSERT INTO tmp_gn_identifier_backfill_guard(blocker_count)
SELECT IF(
  (SELECT COUNT(*) FROM dems_legacy_hr.tbl_gnd) = @gn_identifier_expected_source_count
  AND (SELECT COUNT(*) FROM dems_legacy_hr.tbl_gnd
       WHERE NULLIF(TRIM(gnd_ocode),'') IS NOT NULL
         AND NULLIF(TRIM(gnd_code),'') IS NOT NULL) = @gn_identifier_expected_source_count
  AND (SELECT COUNT(*) FROM legacy_location_reference
       WHERE source_system = 'AGRARIANADMIN_HR'
         AND source_table = 'tbl_gnd') = @gn_identifier_expected_source_count
  AND (SELECT COUNT(DISTINCT legacy_id) FROM legacy_location_reference
       WHERE source_system = 'AGRARIANADMIN_HR'
         AND source_table = 'tbl_gnd') = @gn_identifier_expected_source_count
  AND (SELECT COUNT(DISTINCT location_id) FROM legacy_location_reference
       WHERE source_system = 'AGRARIANADMIN_HR'
         AND source_table = 'tbl_gnd') = @gn_identifier_expected_source_count
  AND (SELECT COUNT(*)
       FROM legacy_location_reference r
       JOIN dems_legacy_hr.tbl_gnd g ON g.gnd_id = CAST(r.legacy_id AS UNSIGNED)
       JOIN location l ON l.id = r.location_id
       JOIN location_type lt ON lt.id = l.location_type_id
                            AND lt.system_key = 'GN_DIVISION'
       WHERE r.source_system = 'AGRARIANADMIN_HR'
         AND r.source_table = 'tbl_gnd'
         AND r.legacy_id REGEXP '^[0-9]+$') = @gn_identifier_expected_source_count,
  0,
  1
);

DROP TEMPORARY TABLE IF EXISTS tmp_gn_identifier_backfill;
CREATE TEMPORARY TABLE tmp_gn_identifier_backfill (
  location_id CHAR(36) NOT NULL PRIMARY KEY,
  gn_code VARCHAR(20) NOT NULL,
  gn_code_for_plr VARCHAR(11) NOT NULL
);

INSERT INTO tmp_gn_identifier_backfill(location_id,gn_code,gn_code_for_plr)
SELECT r.location_id,TRIM(g.gnd_ocode),TRIM(g.gnd_code)
FROM legacy_location_reference r
JOIN dems_legacy_hr.tbl_gnd g ON g.gnd_id = CAST(r.legacy_id AS UNSIGNED)
JOIN location l ON l.id = r.location_id
JOIN location_type lt ON lt.id = l.location_type_id
                     AND lt.system_key = 'GN_DIVISION'
WHERE r.source_system = 'AGRARIANADMIN_HR'
  AND r.source_table = 'tbl_gnd'
  AND r.legacy_id REGEXP '^[0-9]+$';

UPDATE location l
JOIN tmp_gn_identifier_backfill b ON b.location_id = l.id
SET l.gn_code = CASE
      WHEN NULLIF(TRIM(l.gn_code),'') IS NULL THEN b.gn_code
      ELSE l.gn_code
    END,
    l.gn_code_for_plr = CASE
      WHEN NULLIF(TRIM(l.gn_code_for_plr),'') IS NULL THEN b.gn_code_for_plr
      ELSE l.gn_code_for_plr
    END
WHERE (NULLIF(TRIM(l.gn_code),'') IS NULL AND b.gn_code <> '')
   OR (NULLIF(TRIM(l.gn_code_for_plr),'') IS NULL AND b.gn_code_for_plr <> '');

DROP TEMPORARY TABLE tmp_gn_identifier_backfill;
DROP TEMPORARY TABLE tmp_gn_identifier_backfill_guard;
