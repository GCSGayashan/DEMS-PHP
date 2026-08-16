-- Normalized non-service-terminating reason for expiry/completion of a
-- temporary ARPA appointment period.
--
-- Used by controlled historical corrections such as the 2025 year-end
-- closure of ACTING, DUTY_COVERING and ATTEND_TO_DUTY appointments.

INSERT INTO arpa_appointment_end_reason (
    id,
    system_key,
    name_en,
    service_terminating,
    active,
    display_order
)
SELECT
    UUID(),
    'END_OF_APPOINTMENT_PERIOD',
    'End of Appointment Period',
    0,
    1,
    75
WHERE NOT EXISTS (
    SELECT 1
    FROM arpa_appointment_end_reason
    WHERE system_key='END_OF_APPOINTMENT_PERIOD'
);
