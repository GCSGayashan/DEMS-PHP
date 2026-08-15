-- Legacy Officer Master imports are record-permissive and field-strict.
-- Manual Officer Create/Edit validation remains enforced by the application.
ALTER TABLE officer
  MODIFY COLUMN nic VARCHAR(20) NULL,
  MODIFY COLUMN nic_normalized VARCHAR(20) NULL,
  MODIFY COLUMN name_with_initials VARCHAR(255) NULL,
  MODIFY COLUMN full_name_en VARCHAR(255) NULL,
  MODIFY COLUMN primary_mobile VARCHAR(20) NULL;
