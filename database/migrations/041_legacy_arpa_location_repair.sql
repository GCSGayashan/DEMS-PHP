ALTER TABLE schema_migration MODIFY version VARCHAR(100) NOT NULL;
UPDATE schema_migration
SET version = '040_allow_legacy_invalid_appointment_period_envelopes'
WHERE version = '040_allow_legacy_invalid_appointment_period_envelo';

CREATE TABLE legacy_arpa_location_repair_run (
    id CHAR(36) PRIMARY KEY,
    status ENUM('RUNNING', 'COMPLETED', 'COMPLETED_WITH_REVIEW_ITEMS', 'FAILED') NOT NULL,
    executor_user_id CHAR(36) NOT NULL,
    examined_requests INT NOT NULL DEFAULT 0,
    repaired_requests INT NOT NULL DEFAULT 0,
    repaired_appointments INT NOT NULL DEFAULT 0,
    repaired_closures INT NOT NULL DEFAULT 0,
    manual_review_count INT NOT NULL DEFAULT 0,
    summary_json JSON NULL,
    started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    error_category VARCHAR(100) NULL,
    CONSTRAINT fk_legacy_arpa_location_repair_executor
        FOREIGN KEY (executor_user_id) REFERENCES system_user(id)
);

CREATE TABLE legacy_arpa_location_repair_item (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    repair_run_id CHAR(36) NOT NULL,
    request_id CHAR(36) NOT NULL,
    appointment_id CHAR(36) NULL,
    closure_id CHAR(36) NULL,
    previous_arpa_location_id CHAR(36) NOT NULL,
    corrected_arpa_location_id CHAR(36) NOT NULL,
    previous_asc_location_id CHAR(36) NULL,
    corrected_asc_location_id CHAR(36) NOT NULL,
    legacy_arpa_id VARCHAR(100) NOT NULL,
    legacy_arpa_name VARCHAR(255) NOT NULL,
    repair_reason VARCHAR(100) NOT NULL,
    before_target_json JSON NOT NULL,
    corrected_target_json JSON NOT NULL,
    repaired_by CHAR(36) NOT NULL,
    repaired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_legacy_arpa_location_repair_item_run
        FOREIGN KEY (repair_run_id) REFERENCES legacy_arpa_location_repair_run(id),
    CONSTRAINT fk_legacy_arpa_location_repair_item_request
        FOREIGN KEY (request_id) REFERENCES arpa_division_appointment_request(id),
    CONSTRAINT fk_legacy_arpa_location_repair_item_appointment
        FOREIGN KEY (appointment_id) REFERENCES arpa_division_appointment(id),
    CONSTRAINT fk_legacy_arpa_location_repair_item_closure
        FOREIGN KEY (closure_id) REFERENCES arpa_division_appointment_closure(id),
    CONSTRAINT fk_legacy_arpa_location_repair_item_previous_arpa
        FOREIGN KEY (previous_arpa_location_id) REFERENCES location(id),
    CONSTRAINT fk_legacy_arpa_location_repair_item_corrected_arpa
        FOREIGN KEY (corrected_arpa_location_id) REFERENCES location(id),
    CONSTRAINT fk_legacy_arpa_location_repair_item_previous_asc
        FOREIGN KEY (previous_asc_location_id) REFERENCES location(id),
    CONSTRAINT fk_legacy_arpa_location_repair_item_corrected_asc
        FOREIGN KEY (corrected_asc_location_id) REFERENCES location(id),
    CONSTRAINT fk_legacy_arpa_location_repair_item_actor
        FOREIGN KEY (repaired_by) REFERENCES system_user(id),
    CONSTRAINT uq_legacy_arpa_location_repair_run_request
        UNIQUE (repair_run_id, request_id),
    INDEX idx_legacy_arpa_location_repair_request (request_id),
    INDEX idx_legacy_arpa_location_repair_appointment (appointment_id),
    INDEX idx_legacy_arpa_location_repair_corrected_arpa (corrected_arpa_location_id)
);
