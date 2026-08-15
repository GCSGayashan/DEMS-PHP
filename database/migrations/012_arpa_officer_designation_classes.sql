START TRANSACTION;

-- Preserve the stable designation identity and enterprise number; change
-- only the approved English business/display name.
UPDATE designation
SET name_en = 'Agriculture Research and Production Assistant'
WHERE system_key = 'ARPA_OFFICER'
  AND dad_number = '72003-0000003';

-- Allocate missing Officer Class numbers using the same category row lock,
-- next_value, and number_allocation ledger used by NumberService.
SELECT id, category_code
INTO @officer_class_category_id, @officer_class_category_code
FROM number_category
WHERE category_key = 'OFFICER_CLASS'
  AND category_code = '72004'
  AND active = 1
FOR UPDATE;

SET @class_i_number = CONCAT(@officer_class_category_code, '-', LPAD((SELECT next_value FROM number_category WHERE id=@officer_class_category_id), 7, '0'));
INSERT INTO officer_class
  (id,dad_number,system_key,name_en,display_order,active,effective_from,approval_status,created_by,created_at)
SELECT UUID(),@class_i_number,'CLASS_I','Class I',10,1,CURRENT_DATE(),'APPROVED',NULL,NOW()
WHERE NOT EXISTS (SELECT 1 FROM officer_class WHERE system_key='CLASS_I');
SET @class_i_inserted = ROW_COUNT();
INSERT INTO number_allocation(category_id,allocated_number,allocated_at)
SELECT @officer_class_category_id,@class_i_number,NOW() WHERE @class_i_inserted=1;
UPDATE number_category SET next_value=next_value+@class_i_inserted WHERE id=@officer_class_category_id;

SET @class_ii_number = CONCAT(@officer_class_category_code, '-', LPAD((SELECT next_value FROM number_category WHERE id=@officer_class_category_id), 7, '0'));
INSERT INTO officer_class
  (id,dad_number,system_key,name_en,display_order,active,effective_from,approval_status,created_by,created_at)
SELECT UUID(),@class_ii_number,'CLASS_II','Class II',20,1,CURRENT_DATE(),'APPROVED',NULL,NOW()
WHERE NOT EXISTS (SELECT 1 FROM officer_class WHERE system_key='CLASS_II');
SET @class_ii_inserted = ROW_COUNT();
INSERT INTO number_allocation(category_id,allocated_number,allocated_at)
SELECT @officer_class_category_id,@class_ii_number,NOW() WHERE @class_ii_inserted=1;
UPDATE number_category SET next_value=next_value+@class_ii_inserted WHERE id=@officer_class_category_id;

SET @class_iii_number = CONCAT(@officer_class_category_code, '-', LPAD((SELECT next_value FROM number_category WHERE id=@officer_class_category_id), 7, '0'));
INSERT INTO officer_class
  (id,dad_number,system_key,name_en,display_order,active,effective_from,approval_status,created_by,created_at)
SELECT UUID(),@class_iii_number,'CLASS_III','Class III',30,1,CURRENT_DATE(),'APPROVED',NULL,NOW()
WHERE NOT EXISTS (SELECT 1 FROM officer_class WHERE system_key='CLASS_III');
SET @class_iii_inserted = ROW_COUNT();
INSERT INTO number_allocation(category_id,allocated_number,allocated_at)
SELECT @officer_class_category_id,@class_iii_number,NOW() WHERE @class_iii_inserted=1;
UPDATE number_category SET next_value=next_value+@class_iii_inserted WHERE id=@officer_class_category_id;

-- Normalize existing same-key rows without changing their IDs or DAD numbers.
UPDATE officer_class SET name_en='Class I',display_order=10,active=1,approval_status='APPROVED' WHERE system_key='CLASS_I';
UPDATE officer_class SET name_en='Class II',display_order=20,active=1,approval_status='APPROVED' WHERE system_key='CLASS_II';
UPDATE officer_class SET name_en='Class III',display_order=30,active=1,approval_status='APPROVED' WHERE system_key='CLASS_III';

COMMIT;
