-- Targeted composite indexes for server-side DataTable filters and default ordering.
-- Existing unique/FK/search indexes are intentionally retained and not duplicated.
CREATE INDEX idx_location_list_status ON location (approval_status, operational_status, location_type_id, effective_from);
CREATE INDEX idx_location_relationship_list ON location_relationship (relationship_type, approval_status, active, effective_from);
CREATE INDEX idx_office_list_status ON office (approval_status, operational_status, office_type_id, created_at);
CREATE INDEX idx_officer_list_status ON officer (approval_status, operational_status, created_at);
CREATE INDEX idx_officer_list_gender_status ON officer (gender, officer_status_id, primary_designation_id);
CREATE INDEX idx_system_user_list_status ON `system_user` (account_status, approval_status, enabled, created_at);
CREATE INDEX idx_role_list_filters ON application_role (legacy, role_level, active, approval_status, role_name);
CREATE INDEX idx_permission_list_filters ON application_permission (module_code, active, permission_key);
CREATE INDEX idx_user_role_list_filters ON user_account_role (approval_status, active, role_id, effective_from, effective_to);
CREATE INDEX idx_user_scope_list_filters ON user_account_scope (approval_status, active, scope_type, effective_from, effective_to);
CREATE INDEX idx_provisioning_failure_list ON provisioning_failure (failure_category, last_attempt_at);
CREATE INDEX idx_audit_event_list_filters ON audit_event (action_key, severity, created_at);

CREATE INDEX idx_hr_title_list ON hr_title (approval_status, active, display_order, name_en);
CREATE INDEX idx_appointment_nature_list ON appointment_nature (approval_status, active, display_order, name_en);
CREATE INDEX idx_designation_list ON designation (approval_status, active, display_order, name_en);
CREATE INDEX idx_officer_class_list ON officer_class (approval_status, active, display_order, name_en);
CREATE INDEX idx_officer_status_list ON officer_status (approval_status, active, display_order, name_en);
CREATE INDEX idx_civil_status_list ON civil_status (approval_status, active, display_order, name_en);
