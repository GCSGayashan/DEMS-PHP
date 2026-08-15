-- Clarify the existing permission's single responsibility without changing its key.
UPDATE application_permission
SET description = 'Create an ASC-originated ARPA appointment request'
WHERE permission_key = 'arpa.appointment.create';
