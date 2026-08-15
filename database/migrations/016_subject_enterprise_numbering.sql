START TRANSACTION;

INSERT IGNORE INTO number_category(id,category_key,category_code,name_en,next_value,active,created_at)
VALUES(UUID(),'SUBJECT','72007','Subject',1,1,NOW());

-- Lock and use the same category sequence and allocation ledger used by
-- NumberService. Values are read from next_value; no subject sequence is
-- calculated independently.
SELECT id,category_code
INTO @subject_category_id,@subject_category_code
FROM number_category
WHERE category_key='SUBJECT' AND category_code='72007' AND active=1
FOR UPDATE;

SET @subject_number=CONCAT(@subject_category_code,'-',LPAD((SELECT next_value FROM number_category WHERE id=@subject_category_id),7,'0'));
UPDATE subject_master SET dad_number=@subject_number,updated_at=NOW()
WHERE system_key='AGRARIAN_BANK' AND dad_number IS NULL;
SET @subject_allocated=ROW_COUNT();
INSERT INTO number_allocation(category_id,allocated_number,allocated_at)
SELECT @subject_category_id,@subject_number,NOW() WHERE @subject_allocated=1;
UPDATE number_category SET next_value=next_value+@subject_allocated WHERE id=@subject_category_id;

SET @subject_number=CONCAT(@subject_category_code,'-',LPAD((SELECT next_value FROM number_category WHERE id=@subject_category_id),7,'0'));
UPDATE subject_master SET dad_number=@subject_number,updated_at=NOW()
WHERE system_key='SALES_SHOP' AND dad_number IS NULL;
SET @subject_allocated=ROW_COUNT();
INSERT INTO number_allocation(category_id,allocated_number,allocated_at)
SELECT @subject_category_id,@subject_number,NOW() WHERE @subject_allocated=1;
UPDATE number_category SET next_value=next_value+@subject_allocated WHERE id=@subject_category_id;

SET @subject_number=CONCAT(@subject_category_code,'-',LPAD((SELECT next_value FROM number_category WHERE id=@subject_category_id),7,'0'));
UPDATE subject_master SET dad_number=@subject_number,updated_at=NOW()
WHERE system_key='SITHAMU' AND dad_number IS NULL;
SET @subject_allocated=ROW_COUNT();
INSERT INTO number_allocation(category_id,allocated_number,allocated_at)
SELECT @subject_category_id,@subject_number,NOW() WHERE @subject_allocated=1;
UPDATE number_category SET next_value=next_value+@subject_allocated WHERE id=@subject_category_id;

-- This also deliberately fails the migration if an unexpected pre-existing
-- Subject Master lacks an enterprise number instead of inventing one outside
-- the allocator.
ALTER TABLE subject_master MODIFY COLUMN dad_number VARCHAR(20) NOT NULL;

COMMIT;
