SET NAMES utf8mb4;

INSERT IGNORE INTO number_category(id,category_key,category_code,name_en,next_value,active) VALUES
(UUID(),'LOCATION_PROVINCE','70001','Province',1,1),(UUID(),'LOCATION_DISTRICT','70002','District',1,1),(UUID(),'LOCATION_DS_DIVISION','70003','DS Division',1,1),(UUID(),'LOCATION_ASC','70004','Agrarian Service Centre',1,1),(UUID(),'LOCATION_AI_RANGE','70005','AI Range',1,1),(UUID(),'LOCATION_MAHAWELI_DIVISION','70006','Mahaweli Division',1,1),(UUID(),'LOCATION_ARPA_DIVISION','70007','ARPA Division',1,1),(UUID(),'LOCATION_GN_DIVISION','70008','GN Division',1,1),
(UUID(),'OFFICER','70045','Officer',1,1),(UUID(),'OFFICE','71001','Office',1,1),(UUID(),'HR_TITLE','72001','Title',8,1),(UUID(),'APPOINTMENT_NATURE','72002','Appointment Nature',10,1),(UUID(),'DESIGNATION','72003','Designation',1,1),(UUID(),'OFFICER_CLASS','72004','Officer Class',1,1),(UUID(),'OFFICER_STATUS','72005','Officer Status',10,1),(UUID(),'CIVIL_STATUS','72006','Civil Status',6,1);

INSERT IGNORE INTO location_type(id,dad_number,system_key,name_en,display_order,active,effective_from,approval_status) VALUES
(UUID(),'70001-0000000','PROVINCE','Province',10,1,CURRENT_DATE(),'APPROVED'),
(UUID(),'70002-0000000','DISTRICT','District',20,1,CURRENT_DATE(),'APPROVED'),
(UUID(),'70003-0000000','DS_DIVISION','Divisional Secretariat Division',30,1,CURRENT_DATE(),'APPROVED'),
(UUID(),'70004-0000000','ASC','Agrarian Service Center',40,1,CURRENT_DATE(),'APPROVED'),
(UUID(),'70005-0000000','AI_RANGE','AI Range',50,1,CURRENT_DATE(),'APPROVED'),
(UUID(),'70006-0000000','MAHAWELI_DIVISION','Mahaweli Division',60,1,CURRENT_DATE(),'APPROVED'),
(UUID(),'70007-0000000','ARPA_DIVISION','ARPA Division',70,1,CURRENT_DATE(),'APPROVED'),
(UUID(),'70008-0000000','GN_DIVISION','Grama Niladhari Division',80,1,CURRENT_DATE(),'APPROVED');

INSERT IGNORE INTO office_type(id,system_key,name_en,office_level,required_location_type,branches_allowed,display_order,active) VALUES
(UUID(),'HEAD_OFFICE','Head Office','NATIONAL',NULL,1,10,1),
(UUID(),'DISTRICT_OFFICE','District Office','DISTRICT','DISTRICT',1,20,1),
(UUID(),'ASC_OFFICE','ASC Office','ASC','ASC',0,30,1);

INSERT IGNORE INTO hr_title(id,dad_number,system_key,name_en,display_order,active,effective_from,approval_status) VALUES
(UUID(),'72001-0000001','MR','Mr.',10,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72001-0000002','MRS','Mrs.',20,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72001-0000003','MISS','Miss.',30,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72001-0000004','MS','Ms.',40,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72001-0000005','DR','Dr.',50,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72001-0000006','REV','Rev.',60,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72001-0000007','OTHER','Other',70,1,CURRENT_DATE(),'APPROVED');

INSERT IGNORE INTO appointment_nature(id,dad_number,system_key,name_en,display_order,class_required,active,effective_from,approval_status) VALUES
(UUID(),'72002-0000001','PERMANENT','Permanent',10,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72002-0000002','PROBATIONARY','Probationary',20,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72002-0000003','TEMPORARY','Temporary',30,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72002-0000004','CASUAL','Casual',40,0,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72002-0000005','CONTRACT','Contract',50,0,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72002-0000006','TRAINEE','Trainee',60,0,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72002-0000007','INTERN','Intern',70,0,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72002-0000008','SECONDED','Seconded',80,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72002-0000009','ATTACHED_EXTERNAL','Attached External Officer',90,0,1,CURRENT_DATE(),'APPROVED');

INSERT IGNORE INTO officer_status(id,dad_number,system_key,name_en,display_order,protected_status,active,effective_from,approval_status) VALUES
(UUID(),'72005-0000001','ACTIVE','Active',10,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72005-0000002','INACTIVE','Inactive',20,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72005-0000003','SUSPENDED','Suspended',30,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72005-0000004','ON_SECONDMENT','On Secondment',40,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72005-0000005','TRANSFERRED_OUT','Transferred Out',50,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72005-0000006','RETIRED','Retired',60,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72005-0000007','RESIGNED','Resigned',70,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72005-0000008','TERMINATED','Terminated',80,1,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72005-0000009','DECEASED','Deceased',90,1,1,CURRENT_DATE(),'APPROVED');

INSERT IGNORE INTO civil_status(id,dad_number,system_key,name_en,display_order,active,effective_from,approval_status) VALUES
(UUID(),'72006-0000001','SINGLE','Single',10,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72006-0000002','MARRIED','Married',20,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72006-0000003','DIVORCED','Divorced',30,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72006-0000004','WIDOWED','Widowed',40,1,CURRENT_DATE(),'APPROVED'),(UUID(),'72006-0000005','SEPARATED','Separated',50,1,CURRENT_DATE(),'APPROVED');

INSERT IGNORE INTO application_permission(id,permission_key,module_code,description,protected_permission,active) VALUES
(UUID(),'data.view','DATA','View business data',1,1),(UUID(),'data.create','DATA','Create business data',1,1),(UUID(),'data.edit','DATA','Edit business data',1,1),(UUID(),'data.verify','DATA','Verify business data',1,1),(UUID(),'data.reject','DATA','Reject business data',1,1),(UUID(),'data.approve','DATA','Approve business data',1,1),(UUID(),'data.process','DATA','Process approved business data',1,1),(UUID(),'report.view','REPORT','View reports',1,1),
(UUID(),'location.view','LOCATION','View locations',1,1),(UUID(),'location.create','LOCATION','Create locations',1,1),(UUID(),'location.edit','LOCATION','Edit locations',1,1),(UUID(),'location.submit','LOCATION','Submit locations',1,1),(UUID(),'location.approve','LOCATION','Approve locations',1,1),(UUID(),'location.return','LOCATION','Return locations',1,1),(UUID(),'location.withdraw','LOCATION','Withdraw locations',1,1),
(UUID(),'office.view','OFFICE','View offices',1,1),(UUID(),'office.create','OFFICE','Create offices',1,1),(UUID(),'office.edit','OFFICE','Edit offices',1,1),(UUID(),'office.submit','OFFICE','Submit offices',1,1),(UUID(),'office.approve','OFFICE','Approve offices',1,1),(UUID(),'office.return','OFFICE','Return offices',1,1),(UUID(),'office.withdraw','OFFICE','Withdraw offices',1,1),
(UUID(),'hr.master.view','HR','View HR supporting masters',1,1),(UUID(),'hr.master.create','HR','Create HR supporting masters',1,1),(UUID(),'hr.master.edit','HR','Edit HR supporting masters',1,1),(UUID(),'hr.master.approve','HR','Approve HR supporting masters',1,1),
(UUID(),'officer.view','HR','View officers',1,1),(UUID(),'officer.create','HR','Create officers',1,1),(UUID(),'officer.edit','HR','Edit officers',1,1),(UUID(),'officer.submit','HR','Submit officers',1,1),(UUID(),'officer.approve','HR','Approve officers',1,1),(UUID(),'officer.return','HR','Return officers',1,1),(UUID(),'officer.withdraw','HR','Withdraw officers',1,1),(UUID(),'officer.view-history','HR','View officer history',1,1),(UUID(),'officer.view-photo','HR','View officer photograph',1,1),
(UUID(),'user.view','ACCESS','View user accounts',1,1),(UUID(),'user.request','ACCESS','Request user account',1,1),(UUID(),'user.edit','ACCESS','Edit user account',1,1),(UUID(),'user.submit','ACCESS','Submit user account',1,1),(UUID(),'user.approve','ACCESS','Approve user account',1,1),(UUID(),'user.return','ACCESS','Return user account',1,1),(UUID(),'user.withdraw','ACCESS','Withdraw user account request',1,1),(UUID(),'user.activate','ACCESS','Activate user',1,1),(UUID(),'user.block','ACCESS','Block user',1,1),(UUID(),'user.unblock','ACCESS','Unblock user',1,1),(UUID(),'user.suspend','ACCESS','Suspend user',1,1),(UUID(),'user.close','ACCESS','Close user',1,1),(UUID(),'user.reset-password','ACCESS','Reset password',1,1),(UUID(),'user.revoke-sessions','ACCESS','Revoke sessions',1,1),(UUID(),'user.change-username','ACCESS','Change username',1,1),(UUID(),'user.assign-role','ACCESS','Assign role',1,1),(UUID(),'user.revoke-role','ACCESS','Revoke role',1,1),(UUID(),'user.assign-scope','ACCESS','Assign scope',1,1),(UUID(),'user.revoke-scope','ACCESS','Revoke scope',1,1),(UUID(),'user.view-security-history','ACCESS','View security history',1,1),(UUID(),'user.retry-provisioning','ACCESS','Retry provisioning',1,1),(UUID(),'role.manage','ACCESS','Manage roles',1,1),(UUID(),'permission.view','ACCESS','View permissions',1,1);

INSERT IGNORE INTO application_role(id,role_code,role_name,description,role_level,protected_role,assignable,legacy,approval_status,active,effective_from) VALUES
(UUID(),'SYSTEM_ADMIN','System Administrator','Full system administration','SYSTEM',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'SECURITY_ADMIN','Security Administrator','Security administration','SYSTEM',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'USER_ADMIN','User Administrator','User administration','SYSTEM',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'NATIONAL_ADMIN','National Administrator','National approval and process','NATIONAL',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'NATIONAL_SUBJECT_OFFICER','National Subject Officer','National data entry and verification','NATIONAL',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'NATIONAL_VIEWER','National Viewer','National read-only access','NATIONAL',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'DISTRICT_ADMIN','District Administrator','District approval and process','DISTRICT',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'DISTRICT_SUBJECT_OFFICER','District Subject Officer','District data entry and verification','DISTRICT',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'DISTRICT_VIEWER','District Viewer','District read-only access','DISTRICT',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'ASC_ADMIN','ASC Administrator','ASC approval and process','ASC',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'ASC_SUBJECT_OFFICER','ASC Subject Officer','ASC data entry and verification','ASC',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'ASC_VIEWER','ASC Viewer','ASC read-only access','ASC',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'ARPA_OFFICER','ARPA Officer','ARPA data entry, verification and reject','ARPA',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'FARMER','Farmer','Farmer self service','FARMER',1,1,0,'APPROVED',1,CURRENT_DATE()),
(UUID(),'HR_ADMIN','HR Administrator','Legacy HR role','LEGACY',1,0,1,'APPROVED',1,CURRENT_DATE()),
(UUID(),'HR_APPROVER','HR Approver','Legacy HR role','LEGACY',1,0,1,'APPROVED',1,CURRENT_DATE()),
(UUID(),'HR_VIEWER','HR Viewer','Legacy HR role','LEGACY',1,0,1,'APPROVED',1,CURRENT_DATE()),
(UUID(),'LOCATION_ADMIN','Location Administrator','Legacy location role','LEGACY',1,0,1,'APPROVED',1,CURRENT_DATE()),
(UUID(),'LOCATION_APPROVER','Location Approver','Legacy location role','LEGACY',1,0,1,'APPROVED',1,CURRENT_DATE()),
(UUID(),'LOCATION_VIEWER','Location Viewer','Legacy location role','LEGACY',1,0,1,'APPROVED',1,CURRENT_DATE()),
(UUID(),'AUDITOR','Auditor','Legacy audit role','LEGACY',1,0,1,'APPROVED',1,CURRENT_DATE()),
(UUID(),'SYSTEM_USER','System User','Legacy generic user role','LEGACY',1,0,1,'APPROVED',1,CURRENT_DATE());

-- SYSTEM_ADMIN gets every permission.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r CROSS JOIN application_permission p WHERE r.role_code='SYSTEM_ADMIN' AND p.active=1;

-- Business permission matrix.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key IN ('data.view','data.reject','data.approve','data.process','report.view') WHERE r.role_code IN ('NATIONAL_ADMIN','DISTRICT_ADMIN','ASC_ADMIN');
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key IN ('data.view','data.create','data.edit','data.verify','data.reject','report.view') WHERE r.role_code IN ('NATIONAL_SUBJECT_OFFICER','DISTRICT_SUBJECT_OFFICER','ASC_SUBJECT_OFFICER','ARPA_OFFICER');
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key IN ('data.view','report.view') WHERE r.role_code IN ('NATIONAL_VIEWER','DISTRICT_VIEWER','ASC_VIEWER');
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key IN ('data.view','data.create') WHERE r.role_code='FARMER';

-- Technical access roles.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.module_code='ACCESS' WHERE r.role_code IN ('SECURITY_ADMIN','USER_ADMIN');
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key IN ('role.manage','permission.view') WHERE r.role_code='SECURITY_ADMIN';

-- Legacy module mappings retained for compatibility.
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.module_code='HR' WHERE r.role_code='HR_ADMIN';
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key IN ('officer.view','officer.approve','hr.master.view','hr.master.approve') WHERE r.role_code='HR_APPROVER';
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key IN ('officer.view','hr.master.view') WHERE r.role_code='HR_VIEWER';
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.module_code IN ('LOCATION','OFFICE') WHERE r.role_code='LOCATION_ADMIN';
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key IN ('location.view','location.approve','office.view','office.approve') WHERE r.role_code='LOCATION_APPROVER';
INSERT IGNORE INTO application_role_permission(role_id,permission_id)
SELECT r.id,p.id FROM application_role r JOIN application_permission p ON p.permission_key IN ('location.view','office.view') WHERE r.role_code='LOCATION_VIEWER';


-- Initial designations mentioned in the approved requirements. More can be added from HR Supporting Masters.
INSERT IGNORE INTO designation(id,dad_number,system_key,name_en,display_order,designation_level,active,effective_from,approval_status) VALUES
(UUID(),'72003-0000001','AGRARIAN_DEVELOPMENT_OFFICER','Agrarian Development Officer',10,'MAIN',1,CURRENT_DATE(),'APPROVED'),
(UUID(),'72003-0000002','DEVELOPMENT_OFFICER','Development Officer',20,'MAIN',1,CURRENT_DATE(),'APPROVED'),
(UUID(),'72003-0000003','ARPA_OFFICER','ARPA Officer',30,'MAIN',1,CURRENT_DATE(),'APPROVED'),
(UUID(),'72003-0000004','SERVICE_COMMISSIONER','Service Commissioner',40,'MAIN',1,CURRENT_DATE(),'APPROVED'),
(UUID(),'72003-0000005','DEVELOPMENT_COMMISSIONER','Development Commissioner',50,'MAIN',1,CURRENT_DATE(),'APPROVED'),
(UUID(),'72003-0000006','ACCOUNTANT','Accountant',60,'MAIN',1,CURRENT_DATE(),'APPROVED'),
(UUID(),'72003-0000007','INTERNAL_AUDITOR','Internal Auditor',70,'MAIN',1,CURRENT_DATE(),'APPROVED');
UPDATE number_category SET next_value=8 WHERE category_key='DESIGNATION' AND next_value<8;

-- One National Head Office gives the standalone edition a usable primary office immediately.
INSERT IGNORE INTO office(id,dad_number,office_type_id,name_en,short_name,linked_location_id,address,effective_from,requested_status,operational_status,approval_status,created_at)
SELECT UUID(),'71001-0000001',ot.id,'Department of Agrarian Development - Head Office','Head Office',NULL,NULL,CURRENT_DATE(),'ACTIVE','ACTIVE','APPROVED',NOW()
FROM office_type ot WHERE ot.system_key='HEAD_OFFICE';
UPDATE number_category SET next_value=2 WHERE category_key='OFFICE' AND next_value<2;
