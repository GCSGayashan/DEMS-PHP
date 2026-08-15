-- One selected legacy Officer has a zero birth date. Preserve the unknown
-- historical value as NULL; normal Officer Create/Edit validation remains
-- responsible for requiring a valid DOB on manually managed records.
ALTER TABLE officer
  MODIFY COLUMN date_of_birth DATE NULL;
