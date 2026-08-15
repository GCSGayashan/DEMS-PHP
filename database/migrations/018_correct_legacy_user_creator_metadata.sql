START TRANSACTION;

-- Legacy tbl_user.created_location was populated with the creating user's
-- tbl_user.id. Preserve the value as creator metadata; it is not a location.
ALTER TABLE `legacy_user_reference`
  RENAME COLUMN `legacy_created_location_id` TO `legacy_created_by_user_id`;

COMMIT;
