ALTER TABLE legacy_arpa_location_repair_run ENGINE = InnoDB;
ALTER TABLE legacy_arpa_location_repair_item ENGINE = InnoDB;

ALTER TABLE legacy_arpa_location_repair_run
    ADD CONSTRAINT fk_legacy_arpa_location_repair_executor
        FOREIGN KEY (executor_user_id) REFERENCES system_user(id);

ALTER TABLE legacy_arpa_location_repair_item
    ADD CONSTRAINT fk_legacy_arpa_location_repair_item_run
        FOREIGN KEY (repair_run_id) REFERENCES legacy_arpa_location_repair_run(id),
    ADD CONSTRAINT fk_legacy_arpa_location_repair_item_request
        FOREIGN KEY (request_id) REFERENCES arpa_division_appointment_request(id),
    ADD CONSTRAINT fk_legacy_arpa_location_repair_item_appointment
        FOREIGN KEY (appointment_id) REFERENCES arpa_division_appointment(id),
    ADD CONSTRAINT fk_legacy_arpa_location_repair_item_closure
        FOREIGN KEY (closure_id) REFERENCES arpa_division_appointment_closure(id),
    ADD CONSTRAINT fk_legacy_arpa_location_repair_item_previous_arpa
        FOREIGN KEY (previous_arpa_location_id) REFERENCES location(id),
    ADD CONSTRAINT fk_legacy_arpa_location_repair_item_corrected_arpa
        FOREIGN KEY (corrected_arpa_location_id) REFERENCES location(id),
    ADD CONSTRAINT fk_legacy_arpa_location_repair_item_previous_asc
        FOREIGN KEY (previous_asc_location_id) REFERENCES location(id),
    ADD CONSTRAINT fk_legacy_arpa_location_repair_item_corrected_asc
        FOREIGN KEY (corrected_asc_location_id) REFERENCES location(id),
    ADD CONSTRAINT fk_legacy_arpa_location_repair_item_actor
        FOREIGN KEY (repaired_by) REFERENCES system_user(id);
