<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\{ArpaAppointmentIssuePresentation,ArpaWorkflowQueuePolicy,UserAccessManagementService,UserAccountRequestService};
use RuntimeException;

final class DataTableRegistry
{
    private const LOCATION_TYPES = [
        'PROVINCE', 'DISTRICT', 'DS_DIVISION', 'ASC', 'AI_RANGE',
        'MAHAWELI_DIVISION', 'ARPA_DIVISION', 'GN_DIVISION',
    ];

    private const HR_MASTERS = [
        'titles' => ['table' => 'hr_title', 'label' => 'Title'],
        'appointment-natures' => ['table' => 'appointment_nature', 'label' => 'Appointment Nature'],
        'designations' => ['table' => 'designation', 'label' => 'Designation'],
        'classes' => ['table' => 'officer_class', 'label' => 'Class'],
        'officer-statuses' => ['table' => 'officer_status', 'label' => 'Officer Status'],
        'civil-statuses' => ['table' => 'civil_status', 'label' => 'Civil Status'],
    ];

    public static function definition(string $key, array $input = []): array
    {
        return match ($key) {
            'locations' => self::locations($input),
            'location-types' => self::locationTypes(),
            'location-hierarchy' => self::locationHierarchy(),
            'offices' => self::offices(),
            'officers' => self::officers(),
            'hr-masters' => self::hrMasters($input),
            'users' => self::users(),
            'historical-users' => self::historicalUsers(),
            'account-requests' => self::accountRequests(),
            'roles' => self::roles(),
            'permissions' => self::permissions(),
            'role-assignments' => self::roleAssignments(),
            'scope-assignments' => self::scopeAssignments(),
            'provisioning-failures' => self::provisioningFailures(),
            'security-history' => self::securityHistory(),
            'arpa-division-appointments' => self::arpaDivisionAppointments(),
            'arpa-new-appointments' => self::applyArpaAscContext(self::arpaNewAppointments(), $input, 'r.asc_location_id'),
            'arpa-submitted-appointments' => self::applyArpaAscContext(self::arpaSubmittedAppointments(), $input, 'r.asc_location_id'),
            'arpa-approval-verification' => self::applyArpaAscContext(self::arpaApprovalVerification(), $input, 'r.asc_location_id'),
            'arpa-open-appointments' => self::applyArpaAscContext(self::arpaOpenAppointments(), $input, 'a.asc_location_id'),
            'arpa-historical-appointments' => self::applyArpaAscContext(self::arpaHistoricalAppointments(), $input, 'a.asc_location_id'),
            'arpa-new-appointments-summary' => self::arpaAppointmentAscSummary('new', $input),
            'arpa-submitted-appointments-summary' => self::arpaAppointmentAscSummary('submitted', $input),
            'arpa-approval-verification-summary' => self::arpaAppointmentAscSummary('approval', $input),
            'arpa-open-appointments-summary' => self::arpaAppointmentAscSummary('open', $input),
            'arpa-historical-appointments-summary' => self::arpaAppointmentAscSummary('history', $input),
            'arpa-new-appointments-district-summary' => self::arpaAppointmentDistrictSummary('new'),
            'arpa-submitted-appointments-district-summary' => self::arpaAppointmentDistrictSummary('submitted'),
            'arpa-approval-verification-district-summary' => self::arpaAppointmentDistrictSummary('approval'),
            'arpa-open-appointments-district-summary' => self::arpaAppointmentDistrictSummary('open'),
            'arpa-historical-appointments-district-summary' => self::arpaAppointmentDistrictSummary('history'),
            'arpa-vacant-divisions' => self::arpaVacantDivisions(),
            'arpa-appointment-issues' => self::arpaAppointmentIssues(),
            'arpa-appointment-corrections' => self::arpaAppointmentCorrections(),
            'arpa-subject-assignments' => self::arpaSubjectAssignments(),
            'arpa-pending-actions' => self::arpaPendingActions(),
            'arpa-officer-division-history' => self::arpaOfficerDivisionHistory($input),
            'arpa-officer-subject-history' => self::arpaOfficerSubjectHistory($input),
            'arpa-service-permanency-history' => self::arpaServicePermanencyHistory($input),
            'arpa-officer-workflow-history' => self::arpaOfficerWorkflowHistory($input),
            'legacy-arpa-special-review' => self::legacyArpaReview('SPECIAL_ASC'),
            'legacy-arpa-special-groups' => self::legacyArpaSpecialGroups(),
            'legacy-arpa-missing-location-review' => self::legacyArpaReview('MISSING_ARPA_LOCATION'),
            'legacy-arpa-current-conflicts' => self::legacyArpaReview('CURRENT_CONFLICT'),
            'legacy-arpa-appointment-preview' => self::legacyArpaAppointmentPreview(),
            'subjects' => self::subjects(),
            default => throw new RuntimeException('Unknown DataTable.'),
        };
    }

    public static function viewModel(
        string $key,
        array $context = [],
        array $filterOptions = [],
        array $initialFilters = []
    ): array {
        $definition = self::definition($key, $context);
        $query = $context === [] ? '' : '?' . http_build_query($context);
        $filters = [];
        foreach ($definition['filters'] ?? [] as $name => $filter) {
            if (empty($filter['ui'])) {
                continue;
            }
            $ui = $filter['ui'];
            $ui['name'] = $name;
            $ui['type'] = $ui['type'] ?? 'select';
            $ui['value'] = $initialFilters[$name] ?? ($ui['value'] ?? '');
            if ($ui['type'] === 'select') {
                $ui['options'] = $filterOptions[$name] ?? ($ui['options'] ?? []);
            }
            $filters[] = $ui;
        }

        return [
            'id' => 'dems-' . str_replace('_', '-', $key) . '-table',
            'endpoint' => url('api/datatables/' . $key) . $query,
            'exportEndpoint' => !empty($definition['export']) ? url('exports/' . $key) . $query : null,
            'columns' => array_map(static fn(array $column): array => [
                'data' => $column['key'],
                'label' => $column['label'],
                'orderable' => !empty($column['sort']),
                'className' => $column['className'] ?? '',
            ], $definition['columns']),
            'defaultOrder' => [
                [(int)($definition['defaultOrder'][0] ?? 0), strtolower((string)($definition['defaultOrder'][1] ?? 'asc'))],
            ],
            'filters' => $filters,
            'emptyMessage'=>$definition['emptyMessage']??'No records found for the selected filters.',
        ];
    }

    private static function locations(array $input): array
    {
        $scopeType = strtoupper(trim((string)($input['scope_type'] ?? '')));
        if ($scopeType !== '' && !in_array($scopeType, self::LOCATION_TYPES, true)) {
            throw new RuntimeException('Unknown Location Type.');
        }
        $geo = self::geo();
        $hierarchy = self::locationTypeHierarchy($scopeType);
        $baseWhere = [];
        $baseParams = $geo['params'];
        if ($scopeType !== '') {
            $baseWhere[] = 'lt.system_key=?';
            $baseParams[] = $scopeType;
        }

        $filters = [
            'location_type' => [
                'column' => 'lt.system_key', 'allowed' => self::LOCATION_TYPES,
                'ui' => $scopeType === '' ? ['label' => 'Location Type'] : null,
            ],
            'operational_status' => [
                'column' => 'l.operational_status', 'allowed' => ['ACTIVE', 'INACTIVE'],
                'ui' => ['label' => 'Operational Status', 'options' => ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']],
            ],
            'approval_status' => [
                'column' => 'l.approval_status', 'allowed' => self::workflowStatuses(),
                'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()],
            ],
        ];

        return [
            'permission' => 'location.view', 'export' => true, 'filename' => 'locations',
            'with' => self::locationHierarchyWith($geo['with'], $hierarchy['relationshipTypes']),
            'from' => 'location l ' . $geo['joinLocation'] . ' JOIN location_type lt ON lt.id=l.location_type_id' . $hierarchy['joins'],
            'select' => array_merge(
                ['l.id', 'l.dad_number', 'l.official_code', 'l.name_en', 'l.name_si', 'l.name_ta', 'lt.name_en AS type_name', 'lt.system_key AS type_key', 'l.effective_from', 'l.operational_status', 'l.approval_status', 'l.created_by'],
                $hierarchy['select']
            ),
            'count' => 'l.id', 'baseWhere' => $baseWhere, 'baseParams' => $baseParams,
            'searchable' => array_merge(
                ['l.dad_number', 'l.official_code', 'l.name_en', 'l.name_si', 'l.name_ta'],
                $scopeType === '' ? ['lt.name_en'] : $hierarchy['searchable']
            ),
            'filters' => $filters,
            'columns' => $scopeType === '' ? [
                self::col('DAD Number', 'dad_number', 'l.dad_number', fn($r) => DataTableFormat::text($r['dad_number'])),
                self::col('Official Code', 'official_code', 'l.official_code', fn($r) => DataTableFormat::text($r['official_code'])),
                self::col('Name', 'name_en', 'l.name_en', fn($r) => DataTableFormat::text($r['name_en'])),
                self::col('Type', 'type_name', 'lt.name_en', fn($r) => DataTableFormat::text($r['type_name'])),
                self::col('Start Date', 'effective_from', 'l.effective_from', fn($r) => DataTableFormat::date($r['effective_from'])),
                self::col('Status', 'operational_status', 'l.operational_status', fn($r) => DataTableFormat::badge($r['operational_status'])),
                self::col('Approval', 'approval_status', 'l.approval_status', fn($r) => DataTableFormat::badge($r['approval_status'])),
                self::actionColumn(fn($r)=>self::locationActions($r)),
            ] : array_merge(
                [
                    self::col('DAD Number', 'dad_number', 'l.dad_number', fn($r) => DataTableFormat::text($r['dad_number'])),
                    self::col('Official Code', 'official_code', 'l.official_code', fn($r) => DataTableFormat::text($r['official_code'])),
                ],
                $hierarchy['columns'],
                [
                    self::col('Start Date', 'effective_from', 'l.effective_from', fn($r) => DataTableFormat::date($r['effective_from'])),
                    self::col('Status', 'operational_status', 'l.operational_status', fn($r) => DataTableFormat::badge($r['operational_status'])),
                    self::col('Approval', 'approval_status', 'l.approval_status', fn($r) => DataTableFormat::badge($r['approval_status'])),
                    self::actionColumn(fn($r)=>self::locationActions($r)),
                ]
            ),
            'defaultOrder' => [0, 'ASC'],
        ];
    }

    /**
     * Type-specific lists follow the approved, effective hierarchy using one
     * set-based join chain. Ambiguous relationship versions are left blank
     * instead of multiplying a location row or choosing an arbitrary parent.
     */
    private static function locationTypeHierarchy(string $scopeType): array
    {
        if ($scopeType === '') {
            return ['joins' => '', 'select' => [], 'searchable' => [], 'columns' => [], 'defaultOrder' => 0, 'relationshipTypes' => []];
        }

        $provinceJoin = " LEFT JOIN current_location_relationship province_district_rel ON province_district_rel.child_location_id=district_location.id AND province_district_rel.relationship_type='PROVINCE_DISTRICT'"
            . " LEFT JOIN location province_location ON province_location.id=province_district_rel.parent_location_id";
        $districtForDs = " LEFT JOIN current_location_relationship district_ds_rel ON district_ds_rel.child_location_id=l.id AND district_ds_rel.relationship_type='DISTRICT_DS_DIVISION'"
            . " LEFT JOIN location district_location ON district_location.id=district_ds_rel.parent_location_id" . $provinceJoin;
        $districtForAsc = " LEFT JOIN current_location_relationship district_asc_rel ON district_asc_rel.child_location_id=l.id AND district_asc_rel.relationship_type='DISTRICT_ASC'"
            . " LEFT JOIN location district_location ON district_location.id=district_asc_rel.parent_location_id" . $provinceJoin;
        $arpaChain = " LEFT JOIN current_location_relationship asc_arpa_rel ON asc_arpa_rel.child_location_id=l.id AND asc_arpa_rel.relationship_type='ASC_ARPA_DIVISION'"
            . " LEFT JOIN location asc_location ON asc_location.id=asc_arpa_rel.parent_location_id"
            . " LEFT JOIN current_location_relationship district_asc_rel ON district_asc_rel.child_location_id=asc_location.id AND district_asc_rel.relationship_type='DISTRICT_ASC'"
            . " LEFT JOIN location district_location ON district_location.id=district_asc_rel.parent_location_id" . $provinceJoin;
        $gnChain = " LEFT JOIN current_location_relationship arpa_gn_rel ON arpa_gn_rel.child_location_id=l.id AND arpa_gn_rel.relationship_type='ARPA_GN_DIVISION'"
            . " LEFT JOIN location arpa_location ON arpa_location.id=arpa_gn_rel.parent_location_id"
            . " LEFT JOIN current_location_relationship asc_arpa_rel ON asc_arpa_rel.child_location_id=arpa_location.id AND asc_arpa_rel.relationship_type='ASC_ARPA_DIVISION'"
            . " LEFT JOIN location asc_location ON asc_location.id=asc_arpa_rel.parent_location_id"
            . " LEFT JOIN current_location_relationship district_asc_rel ON district_asc_rel.child_location_id=asc_location.id AND district_asc_rel.relationship_type='DISTRICT_ASC'"
            . " LEFT JOIN location district_location ON district_location.id=district_asc_rel.parent_location_id" . $provinceJoin;

        $definition = match ($scopeType) {
            'PROVINCE' => ['', [], 'Province', []],
            'DISTRICT' => [
                " LEFT JOIN current_location_relationship province_district_rel ON province_district_rel.child_location_id=l.id AND province_district_rel.relationship_type='PROVINCE_DISTRICT'"
                    . " LEFT JOIN location province_location ON province_location.id=province_district_rel.parent_location_id",
                [['Province', 'province_name', 'province_location.name_en']],
                'District',
                ['PROVINCE_DISTRICT'],
            ],
            'DS_DIVISION' => [$districtForDs, [
                ['Province', 'province_name', 'province_location.name_en'],
                ['District', 'district_name', 'district_location.name_en'],
            ], 'DS Division', ['PROVINCE_DISTRICT','DISTRICT_DS_DIVISION']],
            'ASC' => [$districtForAsc, [
                ['Province', 'province_name', 'province_location.name_en'],
                ['District', 'district_name', 'district_location.name_en'],
            ], 'Agrarian Service Center', ['PROVINCE_DISTRICT','DISTRICT_ASC']],
            'AI_RANGE' => ['', [
                ['Province', 'province_name', 'NULL'],
                ['District', 'district_name', 'NULL'],
            ], 'AI Range', []],
            'MAHAWELI_DIVISION' => ['', [
                ['Province', 'province_name', 'NULL'],
                ['District', 'district_name', 'NULL'],
            ], 'Mahaweli Division', []],
            'ARPA_DIVISION' => [$arpaChain, [
                ['Province', 'province_name', 'province_location.name_en'],
                ['District', 'district_name', 'district_location.name_en'],
                ['Agrarian Service Center', 'asc_name', 'asc_location.name_en'],
            ], 'ARPA Division', ['PROVINCE_DISTRICT','DISTRICT_ASC','ASC_ARPA_DIVISION']],
            'GN_DIVISION' => [$gnChain, [
                ['Province', 'province_name', 'province_location.name_en'],
                ['District', 'district_name', 'district_location.name_en'],
            ], 'GN Division', ['PROVINCE_DISTRICT','DISTRICT_ASC','ASC_ARPA_DIVISION','ARPA_GN_DIVISION']],
            default => throw new RuntimeException('Unknown Location Type.'),
        };

        [$joins, $ancestors, $locationLabel, $relationshipTypes] = $definition;
        $select = [];
        $searchable = [];
        $columns = [];
        $sortParts = [];
        foreach ($ancestors as [$label, $key, $expression]) {
            $select[] = $expression . ' AS ' . $key;
            if ($expression !== 'NULL') {
                $searchable[] = $expression;
                $sortParts[] = "COALESCE({$expression},'')";
            }
            $columns[] = self::col($label, $key, $expression === 'NULL' ? 'l.name_en' : $expression, fn($r) => DataTableFormat::text($r[$key] ?? null));
        }
        $sortParts[] = 'l.name_en';
        $locationSort = count($sortParts) === 1 ? 'l.name_en' : 'CONCAT_WS(CHAR(31),' . implode(',', $sortParts) . ')';
        $columns[] = self::col($locationLabel, 'name_en', $locationSort, fn($r) => DataTableFormat::text($r['name_en']));

        return [
            'joins' => $joins,
            'select' => $select,
            'searchable' => $searchable,
            'columns' => $columns,
            'defaultOrder' => count($columns) + 1,
            'relationshipTypes' => $relationshipTypes,
        ];
    }

    private static function locationHierarchyWith(string $baseWith, array $relationshipTypes): string
    {
        if ($relationshipTypes === []) {
            return $baseWith;
        }
        $allowed = ['PROVINCE_DISTRICT','DISTRICT_DS_DIVISION','DISTRICT_ASC','ASC_ARPA_DIVISION','ARPA_GN_DIVISION'];
        if (array_diff($relationshipTypes, $allowed) !== []) {
            throw new RuntimeException('Unknown Location Relationship Type.');
        }
        $typeList = "'" . implode("','", $relationshipTypes) . "'";
        $cte = "current_location_relationship AS ("
            . "SELECT relationship_type,child_location_id,"
            . "CASE WHEN COUNT(DISTINCT parent_location_id)=1 THEN MIN(parent_location_id) END parent_location_id"
            . " FROM location_relationship WHERE relationship_type IN ({$typeList})"
            . " AND active=1 AND approval_status='APPROVED'"
            . " AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())"
            . " GROUP BY relationship_type,child_location_id)";
        return $baseWith === '' ? 'WITH ' . $cte . ' ' : rtrim($baseWith) . ', ' . $cte . ' ';
    }

    private static function locationTypes(): array
    {
        return [
            'permission' => 'location.view', 'export' => true, 'filename' => 'location-types',
            'from' => 'location_type lt',
            'select' => ['lt.id', 'lt.dad_number', 'lt.system_key', 'lt.name_en', 'lt.name_si', 'lt.name_ta', 'lt.display_order', 'lt.active', 'lt.effective_from', 'lt.approval_status'],
            'count' => 'lt.id',
            'searchable' => ['lt.dad_number', 'lt.system_key', 'lt.name_en', 'lt.name_si', 'lt.name_ta'],
            'filters' => [
                'active' => ['column' => 'lt.active', 'allowed' => ['0', '1'], 'ui' => ['label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Inactive']]],
                'approval_status' => ['column' => 'lt.approval_status', 'allowed' => self::workflowStatuses(), 'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()]],
            ],
            'columns' => [
                self::col('DAD Number', 'dad_number', 'lt.dad_number', fn($r) => DataTableFormat::text($r['dad_number'])),
                self::col('System Key', 'system_key', 'lt.system_key', fn($r) => '<code>' . e($r['system_key']) . '</code>'),
                self::col('English Name', 'name_en', 'lt.name_en', fn($r) => DataTableFormat::text($r['name_en'])),
                self::col('Display Order', 'display_order', 'lt.display_order'),
                self::col('Status', 'active', 'lt.active', fn($r) => DataTableFormat::badge($r['active'] ? 'ACTIVE' : 'INACTIVE'), fn($r) => $r['active'] ? 'ACTIVE' : 'INACTIVE'),
            ],
            'defaultOrder' => [3, 'ASC'],
        ];
    }

    private static function locationHierarchy(): array
    {
        $geo = self::geo();
        $restrictedJoin=$geo['with']!==''?' JOIN visible_locations vp ON vp.id=p.id JOIN visible_locations vc ON vc.id=c.id':'';
        return [
            'permission' => 'location.view', 'export' => true, 'filename' => 'location-hierarchy',
            'with' => $geo['with'],
            'from' => 'location_relationship lr JOIN location p ON p.id=lr.parent_location_id JOIN location c ON c.id=lr.child_location_id ' . $restrictedJoin,
            'select' => ['lr.id', 'p.dad_number AS parent_number', 'p.name_en AS parent_name', 'c.dad_number AS child_number', 'c.name_en AS child_name', 'lr.relationship_type', 'lr.effective_from', 'lr.effective_to', 'lr.approval_status', 'lr.active'],
            'count' => 'lr.id', 'baseParams' => $geo['params'],
            'searchable' => ['p.dad_number', 'p.name_en', 'c.dad_number', 'c.name_en', 'lr.relationship_type'],
            'filters' => [
                'relationship_type' => ['column' => 'lr.relationship_type', 'pattern' => '/^[A-Z0-9_:-]{1,80}$/', 'ui' => ['label' => 'Relationship Type']],
                'active' => ['column' => 'lr.active', 'allowed' => ['0', '1'], 'ui' => ['label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Inactive']]],
                'approval_status' => ['column' => 'lr.approval_status', 'allowed' => self::workflowStatuses(), 'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()]],
            ],
            'columns' => [
                self::col('Parent', 'parent_name', 'p.name_en', fn($r) => DataTableFormat::text($r['parent_number'] . ' · ' . $r['parent_name']), fn($r) => $r['parent_number'] . ' - ' . $r['parent_name']),
                self::col('Child', 'child_name', 'c.name_en', fn($r) => DataTableFormat::text($r['child_number'] . ' · ' . $r['child_name']), fn($r) => $r['child_number'] . ' - ' . $r['child_name']),
                self::col('Relationship', 'relationship_type', 'lr.relationship_type', fn($r) => DataTableFormat::text($r['relationship_type'])),
                self::col('Start Date', 'effective_from', 'lr.effective_from', fn($r) => DataTableFormat::date($r['effective_from'])),
                self::col('End Date', 'effective_to', 'lr.effective_to', fn($r) => DataTableFormat::date($r['effective_to'], 'Current')),
            ],
            'defaultOrder' => [0, 'ASC'],
        ];
    }

    private static function offices(): array
    {
        $user=Auth::user();$restricted=$user&&ScopeService::requiresGeographicRestriction((string)$user['id']);$ids=$restricted?array_column(ScopeService::scopedOffices((string)$user['id']),'id'):[];
        return [
            'permission' => 'office.view', 'export' => true, 'filename' => 'offices',
            'from' => 'office o JOIN office_type ot ON ot.id=o.office_type_id LEFT JOIN location l ON l.id=o.linked_location_id',
            'select' => ['o.id', 'o.dad_number', 'o.name_en', 'o.name_si', 'o.name_ta', 'o.short_name', 'ot.id AS office_type_id', 'ot.name_en AS type_name', 'ot.office_level', 'l.name_en AS location_name', 'o.effective_from', 'o.operational_status', 'o.approval_status', 'o.created_by', 'o.created_at',"(SELECT COUNT(DISTINCT oa.officer_id) FROM officer_office_assignment oa WHERE oa.office_id=o.id AND oa.active=1 AND oa.approval_status='APPROVED' AND oa.effective_from<=CURRENT_DATE() AND (oa.effective_to IS NULL OR oa.effective_to>=CURRENT_DATE())) current_officer_count"],
            'count' => 'o.id', 'baseWhere'=>$restricted?($ids?['o.id IN ('.implode(',',array_fill(0,count($ids),'?')).')']:['1=0']):[], 'baseParams' => $restricted?$ids:[],
            'searchable' => ['o.dad_number', 'o.name_en', 'o.name_si', 'o.name_ta', 'o.short_name', 'ot.name_en', 'ot.office_level', 'l.name_en'],
            'filters' => [
                'office_type' => ['column' => 'o.office_type_id', 'pattern' => self::uuidPattern(), 'ui' => ['label' => 'Office Type']],
                'operational_status' => ['column' => 'o.operational_status', 'allowed' => ['ACTIVE', 'INACTIVE'], 'ui' => ['label' => 'Operational Status', 'options' => ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']]],
                'approval_status' => ['column' => 'o.approval_status', 'allowed' => self::workflowStatuses(), 'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()]],
            ],
            'columns' => [
                self::col('DAD Number', 'dad_number', 'o.dad_number', fn($r) => DataTableFormat::text($r['dad_number'])),
                self::col('Office Name', 'name_en', 'o.name_en', fn($r) => DataTableFormat::text($r['name_en'])),
                self::col('Office Type', 'type_name', 'ot.name_en', fn($r) => DataTableFormat::text($r['type_name'])),
                self::col('Linked Location', 'location_name', 'l.name_en', fn($r) => DataTableFormat::text($r['location_name'], 'National')),
                self::col('Current Officers','current_officer_count',null,fn($r)=>DataTableFormat::text((string)$r['current_officer_count'])),
                self::col('Start Date', 'effective_from', 'o.effective_from', fn($r) => DataTableFormat::date($r['effective_from'])),
                self::col('Status', 'operational_status', 'o.operational_status', fn($r) => DataTableFormat::badge($r['operational_status'])),
                self::col('Approval', 'approval_status', 'o.approval_status', fn($r) => DataTableFormat::badge($r['approval_status'])),
                self::actionColumn(fn($r) => self::officeActions($r)),
            ],
            'defaultOrder' => [0, 'ASC'],
        ];
    }

    private static function officers(): array
    {
        $user=Auth::user();$access=$user?ScopeService::currentOfficerAccess((string)$user['id'],'o.id'):['with'=>'','params'=>[],'where'=>['1=0']];
        $divisionAssignments="(SELECT GROUP_CONCAT(DISTINCT CONCAT(REPLACE(da.appointment_type,'_',' '),' - ',da.arpa_name_snapshot) ORDER BY da.appointment_type,da.arpa_name_snapshot SEPARATOR '; ') FROM arpa_division_appointment da JOIN arpa_division_appointment_request dr ON dr.id=da.request_id LEFT JOIN arpa_division_appointment_closure dc ON dc.appointment_id=da.id ".($access['with']!==''?'JOIN scoped_locations dva ON dva.id=da.asc_location_id ':'')."WHERE da.officer_id=o.id AND da.legacy_history_only=0 AND ((da.record_origin='NATIVE' AND dr.workflow_status='NATIONAL_APPROVED') OR (da.record_origin='LEGACY_IMPORT' AND dr.workflow_status IN('DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED'))) AND da.effective_from<=CURRENT_DATE() AND (dc.effective_to IS NULL OR dc.effective_to>=CURRENT_DATE()))";
        $subjectAssignments="(SELECT GROUP_CONCAT(DISTINCT sa.subject_name_snapshot ORDER BY sa.subject_name_snapshot SEPARATOR '; ') FROM arpa_subject_assignment sa JOIN arpa_subject_assignment_request sr ON sr.id=sa.request_id LEFT JOIN arpa_subject_assignment_closure sc ON sc.assignment_id=sa.id ".($access['with']!==''?'JOIN scoped_locations sva ON sva.id=sa.asc_location_id ':'')."WHERE sa.officer_id=o.id AND sa.legacy_history_only=0 AND ((sa.record_origin='NATIVE' AND sr.workflow_status='NATIONAL_APPROVED') OR (sa.record_origin='LEGACY_IMPORT' AND sr.workflow_status IN('DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED'))) AND sa.effective_from<=CURRENT_DATE() AND (sc.effective_to IS NULL OR sc.effective_to>=CURRENT_DATE()))";
        $officeRelationships="(SELECT GROUP_CONCAT(DISTINCT CONCAT(ofc.dad_number,' - ',ofc.name_en) ORDER BY ofc.name_en SEPARATOR '; ') FROM officer_office_assignment oa JOIN office ofc ON ofc.id=oa.office_id ".($access['with']!==''?'JOIN scoped_offices sofc ON sofc.id=ofc.id ':'')."WHERE oa.officer_id=o.id AND oa.active=1 AND oa.approval_status='APPROVED' AND oa.effective_from<=CURRENT_DATE() AND (oa.effective_to IS NULL OR oa.effective_to>=CURRENT_DATE()))";
        return [
            'permission' => 'officer.view', 'export' => true, 'filename' => 'officers',
            'with' => $access['with'],
            'from' => 'officer o LEFT JOIN designation d ON d.id=o.primary_designation_id LEFT JOIN officer_class c ON c.id=o.class_id LEFT JOIN officer_status os ON os.id=o.officer_status_id LEFT JOIN office ofc ON ofc.id=o.primary_office_id',
            'select' => ['o.id', 'o.dad_number', 'o.nic', 'o.name_with_initials', 'o.full_name_en', 'o.full_name_si', 'o.full_name_ta', 'o.primary_mobile', 'o.alternative_mobile', 'o.personal_email', 'o.official_email', 'o.gender', 'o.primary_designation_id', 'o.class_id', 'o.officer_status_id', 'o.primary_office_id', 'd.name_en AS designation_name', 'c.name_en AS class_name', 'os.name_en AS officer_status_name', 'ofc.name_en AS office_name', 'o.arpa_service_permanency','o.initial_appointment_date', 'o.operational_status', 'o.approval_status', 'o.photograph_path', 'o.created_by', 'o.created_at',"{$officeRelationships} current_offices","TRIM(BOTH '; ' FROM CONCAT_WS('; ',{$divisionAssignments},{$subjectAssignments})) current_assignments"],
            'count' => 'o.id', 'baseWhere'=>$access['where'], 'baseParams' => $access['params'],
            'searchable' => ['o.dad_number', 'o.nic', 'o.name_with_initials', 'o.full_name_en', 'o.full_name_si', 'o.full_name_ta', 'o.primary_mobile', 'o.alternative_mobile', 'o.personal_email', 'o.official_email', 'd.name_en', 'c.name_en', 'os.name_en', 'ofc.name_en'],
            'filters' => [
                'dad_number'=>['column'=>'o.dad_number','operator'=>'LIKE','ui'=>['label'=>'DAD Officer Number','type'=>'text','placeholder'=>'Search DAD number']],
                'nic'=>['column'=>'o.nic','operator'=>'LIKE','ui'=>['label'=>'NIC','type'=>'text','placeholder'=>'Search NIC']],
                'name'=>['column'=>'o.name_with_initials','operator'=>'LIKE','ui'=>['label'=>'Name','type'=>'text','placeholder'=>'Search name']],
                'designation' => ['column' => 'o.primary_designation_id', 'pattern' => self::uuidPattern(), 'ui' => ['label' => 'Designation']],
                'class' => ['column' => 'o.class_id', 'pattern' => self::uuidPattern(), 'ui' => ['label' => 'Class']],
                'officer_status' => ['column' => 'o.officer_status_id', 'pattern' => self::uuidPattern(), 'ui' => ['label' => 'Officer Status']],
                'service_permanency' => ['column'=>'o.arpa_service_permanency','allowed'=>['PERMANENT_IN_SERVICE','NOT_PERMANENT_IN_SERVICE'],'ui'=>['label'=>'Service Permanency','options'=>['PERMANENT_IN_SERVICE'=>'Permanent In Service','NOT_PERMANENT_IN_SERVICE'=>'Not Permanent In Service']]],
                'gender' => ['column' => 'o.gender', 'allowed' => ['MALE', 'FEMALE'], 'ui' => ['label' => 'Gender', 'options' => ['MALE' => 'Male', 'FEMALE' => 'Female']]],
                'office' => ['pattern' => self::uuidPattern(),'build'=>fn($value)=>["EXISTS(SELECT 1 FROM officer_office_assignment foa WHERE foa.officer_id=o.id AND foa.office_id=? AND foa.active=1 AND foa.approval_status='APPROVED' AND foa.effective_from<=CURRENT_DATE() AND (foa.effective_to IS NULL OR foa.effective_to>=CURRENT_DATE()))",[$value]], 'ui' => $access['with']===''?['label' => 'Office']:null],
                'operational_status' => ['column' => 'o.operational_status', 'allowed' => ['ACTIVE', 'INACTIVE'], 'ui' => ['label' => 'Operational Status', 'options' => ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']]],
                'approval_status' => ['column' => 'o.approval_status', 'allowed' => self::workflowStatuses(), 'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()]],
            ],
            'columns' => [
                self::col('DAD Officer Number', 'dad_number', 'o.dad_number', fn($r) => DataTableFormat::text($r['dad_number'])),
                self::col('Name with Initials', 'name_with_initials', 'o.name_with_initials', fn($r) => DataTableFormat::text($r['name_with_initials'])),
                self::col('NIC', 'nic', 'o.nic', fn($r) => DataTableFormat::text($r['nic'])),
                self::col('Class', 'class_name', 'c.name_en', fn($r) => DataTableFormat::text($r['class_name'])),
                self::col('Service Permanency','arpa_service_permanency','o.arpa_service_permanency',fn($r)=>DataTableFormat::enumText($r['arpa_service_permanency'],'Not set'),fn($r)=>DataTableFormat::enumLabel($r['arpa_service_permanency'],'Not set')),
                self::col('First Appointment Date','initial_appointment_date','o.initial_appointment_date',fn($r)=>DataTableFormat::date($r['initial_appointment_date'])),
                self::col('Current Offices','current_offices',null,fn($r)=>DataTableFormat::text($r['current_offices'],'None')),
                self::col('Current Assignments','current_assignments',null,fn($r)=>DataTableFormat::text($r['current_assignments'],'None')),
                self::col('Officer Status', 'officer_status_name', 'os.name_en', fn($r) => DataTableFormat::badge($r['officer_status_name']?:$r['operational_status'])),
                self::actionColumn(fn($r) => self::officerActions($r)),
            ],
            'defaultOrder' => [0, 'ASC'],
            'emptyMessage'=>$access['with']!==''?(ScopeService::scopeProfile((string)$user['id'])['level']==='ASC'?'No officers currently have an approved assignment to this Agrarian Service Center.':'No officers are available for your current access.'):'No records found for the selected filters.',
        ];
    }

    private static function arpaDivisionAppointments(): array
    {
        $geo = self::geo('a.asc_location_id');
        $status = "CASE WHEN c.effective_to IS NOT NULL AND c.effective_to<CURRENT_DATE() THEN 'ENDED' WHEN a.effective_from>CURRENT_DATE() THEN 'SCHEDULED' ELSE 'ACTIVE' END";
        return [
            'permission' => 'arpa.appointment.view', 'export' => true, 'filename' => 'arpa-division-appointments',
            'with' => $geo['with'],
            'from' => 'arpa_division_appointment a JOIN arpa_division_appointment_request req ON req.id=a.request_id JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_division_appointment_closure c ON c.appointment_id=a.id LEFT JOIN arpa_appointment_end_reason er ON er.id=c.end_reason_id ' . $geo['joinExpression'],
            'select' => ['a.id','a.officer_id','o.dad_number AS officer_number','o.name_with_initials AS officer_name','o.nic','o.arpa_service_permanency','a.appointment_type','a.record_origin','req.workflow_status','a.approved_at','a.province_location_id_snapshot','a.district_location_id_snapshot','a.asc_location_id','a.arpa_division_location_id','a.province_name_snapshot','a.district_name_snapshot','a.asc_name_snapshot','a.arpa_name_snapshot','a.effective_from','c.effective_to','er.name_en AS end_reason',"{$status} AS operational_status"],
            'count' => 'a.id', 'baseParams' => $geo['params'],
            'searchable' => ['o.dad_number','o.name_with_initials','o.nic','a.province_name_snapshot','a.district_name_snapshot','a.asc_name_snapshot','a.arpa_name_snapshot','a.appointment_type'],
            'filters' => [
                'province' => ['column'=>'a.province_location_id_snapshot','pattern'=>self::uuidPattern(),'ui'=>['label'=>'Province']],
                'district' => ['column'=>'a.district_location_id_snapshot','pattern'=>self::uuidPattern(),'ui'=>['label'=>'District']],
                'asc' => ['column'=>'a.asc_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ASC']],
                'arpa_division' => ['column'=>'a.arpa_division_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ARPA Division']],
                'officer' => ['column'=>'a.officer_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'Officer']],
                'service_permanency' => ['column'=>'a.service_permanency_snapshot','allowed'=>['PERMANENT_IN_SERVICE','NOT_PERMANENT_IN_SERVICE'],'ui'=>['label'=>'Service Permanency','options'=>['PERMANENT_IN_SERVICE'=>'Permanent In Service','NOT_PERMANENT_IN_SERVICE'=>'Not Permanent In Service']]],
                'appointment_type' => ['column'=>'a.appointment_type','allowed'=>['PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY'],'ui'=>['label'=>'Appointment Type','options'=>['PERMANENT'=>'Permanent','ACTING'=>'Acting','DUTY_COVERING'=>'Duty Covering','ATTEND_TO_DUTY'=>'Attend to the Duty']]],
                'status' => ['allowed'=>['ACTIVE','SCHEDULED','ENDED'],'build'=>fn($v)=>["{$status}=?",[$v]],'ui'=>['label'=>'Status','options'=>['ACTIVE'=>'Active','SCHEDULED'=>'Scheduled','ENDED'=>'Ended']]],
                'date_from' => ['column'=>'a.effective_from','operator'=>'>=','date'=>true,'ui'=>['label'=>'Start Date From','type'=>'date']],
                'date_to' => ['column'=>'a.effective_from','operator'=>'<=','date'=>true,'ui'=>['label'=>'Start Date To','type'=>'date']],
            ],
            'columns' => [
                self::col('Officer Number','officer_number','o.dad_number',fn($r)=>'<a href="'.e(url('hr/officers/'.$r['officer_id'])).'">'.e($r['officer_number']).'</a>'),
                self::col('Officer','officer_name','o.name_with_initials',fn($r)=>DataTableFormat::text($r['officer_name'])),
                self::col('NIC','nic','o.nic',fn($r)=>DataTableFormat::text($r['nic'])),
                self::col('Service Permanency','arpa_service_permanency','o.arpa_service_permanency',fn($r)=>DataTableFormat::badge($r['arpa_service_permanency']),fn($r)=>DataTableFormat::enumLabel($r['arpa_service_permanency'],'Unknown')),
                self::col('Type','appointment_type','a.appointment_type',fn($r)=>DataTableFormat::badge($r['appointment_type'])),
                self::col('ASC','asc_name_snapshot','a.asc_name_snapshot',fn($r)=>DataTableFormat::text($r['asc_name_snapshot'])),
                self::col('ARPA Division','arpa_name_snapshot','a.arpa_name_snapshot',fn($r)=>DataTableFormat::text($r['arpa_name_snapshot'])),
                self::col('Start Date','effective_from','a.effective_from',fn($r)=>DataTableFormat::date($r['effective_from'])),
                self::col('End Date','effective_to','c.effective_to',fn($r)=>DataTableFormat::date($r['effective_to'],'Current')),
                self::col('Status','operational_status',$status,fn($r)=>DataTableFormat::badge($r['operational_status'])),
                self::col('Workflow','workflow_status','req.workflow_status',fn($r)=>DataTableFormat::badge($r['workflow_status'])),
                self::col('Approved At','approved_at','a.approved_at',fn($r)=>DataTableFormat::dateTime($r['approved_at'])),
                self::actionColumn(fn($r)=>self::arpaDivisionActions($r)),
            ],
            'defaultOrder'=>[5,'DESC'],
        ];
    }

    private static function arpaSubjectAssignments(): array
    {
        $geo = self::geo('a.asc_location_id');
        $status = "CASE WHEN c.effective_to IS NOT NULL AND c.effective_to<CURRENT_DATE() THEN 'ENDED' WHEN a.effective_from>CURRENT_DATE() THEN 'SCHEDULED' ELSE 'ACTIVE' END";
        return [
            'permission'=>'arpa.appointment.view','export'=>true,'filename'=>'arpa-subject-assignments','with'=>$geo['with'],
            'from'=>'arpa_subject_assignment a JOIN officer o ON o.id=a.officer_id LEFT JOIN arpa_subject_assignment_closure c ON c.assignment_id=a.id ' . $geo['joinExpression'],
            'select'=>['a.id','a.officer_id','o.dad_number AS officer_number','o.name_with_initials AS officer_name','a.subject_kind_snapshot','a.subject_name_snapshot','a.officer_exclusive_snapshot','a.province_location_id_snapshot','a.district_location_id_snapshot','a.asc_location_id','a.province_name_snapshot','a.district_name_snapshot','a.asc_name_snapshot','a.effective_from','c.effective_to',"{$status} AS operational_status"],
            'count'=>'a.id','baseParams'=>$geo['params'],
            'searchable'=>['o.dad_number','o.name_with_initials','a.subject_name_snapshot','a.subject_kind_snapshot','a.asc_name_snapshot'],
            'filters'=>[
                'province'=>['column'=>'a.province_location_id_snapshot','pattern'=>self::uuidPattern(),'ui'=>['label'=>'Province']],
                'district'=>['column'=>'a.district_location_id_snapshot','pattern'=>self::uuidPattern(),'ui'=>['label'=>'District']],
                'asc'=>['column'=>'a.asc_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ASC']],
                'officer'=>['column'=>'a.officer_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'Officer']],
                'subject_kind'=>['column'=>'a.subject_kind_snapshot','allowed'=>['NORMAL','AGRARIAN_BANK','SALES_SHOP','SITHAMU'],'ui'=>['label'=>'Subject Type','options'=>['NORMAL'=>'Normal Subject','AGRARIAN_BANK'=>'Agrarian Bank','SALES_SHOP'=>'Sales Shop','SITHAMU'=>'Sithamu']]],
                'status'=>['allowed'=>['ACTIVE','SCHEDULED','ENDED'],'build'=>fn($v)=>["{$status}=?",[$v]],'ui'=>['label'=>'Status','options'=>['ACTIVE'=>'Active','SCHEDULED'=>'Scheduled','ENDED'=>'Ended']]],
                'date_from'=>['column'=>'a.effective_from','operator'=>'>=','date'=>true,'ui'=>['label'=>'Start Date From','type'=>'date']],
                'date_to'=>['column'=>'a.effective_from','operator'=>'<=','date'=>true,'ui'=>['label'=>'Start Date To','type'=>'date']],
            ],
            'columns'=>[
                self::col('Officer Number','officer_number','o.dad_number',fn($r)=>DataTableFormat::text($r['officer_number'])),
                self::col('Officer','officer_name','o.name_with_initials',fn($r)=>DataTableFormat::text($r['officer_name'])),
                self::col('Subject / Function','subject_name_snapshot','a.subject_name_snapshot',fn($r)=>DataTableFormat::text($r['subject_name_snapshot'])),
                self::col('Type','subject_kind_snapshot','a.subject_kind_snapshot',fn($r)=>DataTableFormat::badge($r['subject_kind_snapshot'])),
                self::col('ASC','asc_name_snapshot','a.asc_name_snapshot',fn($r)=>DataTableFormat::text($r['asc_name_snapshot'])),
                self::col('Start Date','effective_from','a.effective_from',fn($r)=>DataTableFormat::date($r['effective_from'])),
                self::col('End Date','effective_to','c.effective_to',fn($r)=>DataTableFormat::date($r['effective_to'],'Current')),
                self::col('Status','operational_status',$status,fn($r)=>DataTableFormat::badge($r['operational_status'])),
                self::actionColumn(fn($r)=>self::arpaSubjectActions($r)),
            ],
            'defaultOrder'=>[5,'DESC'],
        ];
    }

    private static function arpaPendingActions(): array
    {
        $geo=self::geo('q.asc_location_id');
        $from="(SELECT CONCAT('D:',r.id) row_key,'division' entity,r.id,r.request_type,o.dad_number officer_number,o.name_with_initials officer_name,r.asc_location_id,r.arpa_division_location_id,l.name_en context_name,r.appointment_type detail,r.requested_effective_from effective_from,r.workflow_status,r.created_by,r.created_at FROM arpa_division_appointment_request r JOIN officer o ON o.id=r.officer_id LEFT JOIN location l ON l.id=r.arpa_division_location_id WHERE r.legacy_history_only=0 AND r.workflow_status NOT IN ('NATIONAL_APPROVED','REJECTED') UNION ALL SELECT CONCAT('S:',r.id),'subject',r.id,r.request_type,o.dad_number,o.name_with_initials,r.asc_location_id,NULL,CONCAT(al.name_en,' / ',s.name_en),s.subject_kind,r.requested_effective_from,r.workflow_status,r.created_by,r.created_at FROM arpa_subject_assignment_request r JOIN officer o ON o.id=r.officer_id LEFT JOIN subject_master s ON s.id=r.subject_id LEFT JOIN location al ON al.id=r.asc_location_id WHERE r.legacy_history_only=0 AND r.workflow_status NOT IN ('NATIONAL_APPROVED','REJECTED')) q";
        return [
            'permission'=>'arpa.appointment.view','export'=>false,'with'=>$geo['with'],'from'=>$from.' '.$geo['joinExpression'],
            'select'=>['q.row_key','q.entity','q.id','q.request_type','q.officer_number','q.officer_name','q.context_name','q.detail','q.effective_from','q.workflow_status','q.created_by','q.created_at'],
            'count'=>'q.row_key','baseParams'=>$geo['params'],'searchable'=>['q.officer_number','q.officer_name','q.context_name','q.detail','q.workflow_status'],
            'filters'=>['status'=>['column'=>'q.workflow_status','allowed'=>['CREATED','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','RETURNED'],'ui'=>['label'=>'Workflow Status','options'=>['CREATED'=>'Created','SUBMITTED'=>'Submitted','ASC_VERIFIED'=>'ASC Verified','ASC_APPROVED'=>'ASC Approved','DISTRICT_VERIFIED'=>'District Verified','DISTRICT_APPROVED'=>'District Approved','NATIONAL_VERIFIED'=>'National Verified','RETURNED'=>'Returned']]]],
            'columns'=>[
                self::col('Officer Number','officer_number','q.officer_number',fn($r)=>DataTableFormat::text($r['officer_number'])),
                self::col('Officer','officer_name','q.officer_name',fn($r)=>DataTableFormat::text($r['officer_name'])),
                self::col('Request','request_type','q.request_type',fn($r)=>DataTableFormat::text(ucwords(strtolower(str_replace('_',' ',$r['request_type']))))),
                self::col('Location','context_name','q.context_name',fn($r)=>DataTableFormat::text($r['context_name'])),
                self::col('Detail','detail','q.detail',fn($r)=>DataTableFormat::text($r['detail'])),
                self::col('Start Date','effective_from','q.effective_from',fn($r)=>DataTableFormat::date($r['effective_from'])),
                self::col('Workflow','workflow_status','q.workflow_status',fn($r)=>DataTableFormat::badge($r['workflow_status'])),
                self::actionColumn(fn($r)=>self::arpaWorkflowActions($r)),
            ],'defaultOrder'=>[6,'ASC'],
        ];
    }

    private static function applyArpaAscContext(array $definition,array $input,string $column):array
    {
        $ascId=trim((string)($input['asc_id']??''));

        if($ascId==='')return $definition;

        if(preg_match(self::uuidPattern(),$ascId)!==1){
            $definition['baseWhere'][]='1=0';
            return $definition;
        }

        $definition['baseWhere'][]=$column.'=?';
        $definition['baseParams'][]=$ascId;

        if(isset($definition['filters']['asc'])){
            $definition['filters']['asc']['ui']=null;
        }

        $definition['filename']=($definition['filename']??'arpa-appointments').'-asc';

        return $definition;
    }

    private static function arpaAppointmentSummarySource(string $page):array
    {
        return match($page){
            'new'=>[
                self::arpaNewAppointments(),
                'r.asc_location_id',
                'r.appointment_type',
                'new',
                'Total Records',
            ],
            'submitted'=>[
                self::arpaSubmittedAppointments(),
                'r.asc_location_id',
                'r.appointment_type',
                'submitted',
                'Total Records',
            ],
            'approval'=>[
                self::arpaApprovalVerification(),
                'r.asc_location_id',
                'r.appointment_type',
                'approval',
                'Total Records',
            ],
            'open'=>[
                self::arpaOpenAppointments(),
                'a.asc_location_id',
                'a.appointment_type',
                'open',
                'Total Open',
            ],
            'history'=>[
                self::arpaHistoricalAppointments(),
                'a.asc_location_id',
                'a.appointment_type',
                'history',
                'Total Historical',
            ],
            default=>throw new RuntimeException(
                'Unknown ARPA appointment summary page.'
            ),
        };
    }

    private static function arpaDistrictAscs(string $districtId):array
    {
        $stmt=Database::pdo()->prepare(
            "SELECT DISTINCT
                    asc_l.id,
                    asc_l.dad_number,
                    asc_l.name_en
             FROM location_relationship lr
             JOIN location asc_l
               ON asc_l.id=lr.child_location_id
             JOIN location_type asc_t
               ON asc_t.id=asc_l.location_type_id
              AND asc_t.system_key='ASC'
             WHERE lr.parent_location_id=?
               AND lr.active=1
               AND lr.approval_status='APPROVED'
               AND lr.effective_from<=CURRENT_DATE()
               AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
               AND asc_l.approval_status='APPROVED'
               AND asc_l.operational_status='ACTIVE'
             ORDER BY asc_l.name_en"
        );

        $stmt->execute([$districtId]);

        return $stmt->fetchAll();
    }

    private static function arpaAppointmentAscSummary(
        string $page,
        array $input=[]
    ):array
    {
        $userId=(string)(Auth::user()['id']??'');

        [
            $detail,
            $ascColumn,
            $typeColumn,
            $route,
            $totalLabel
        ]=self::arpaAppointmentSummarySource($page);

        $profile=ScopeService::scopeProfile($userId);
        $level=(string)($profile['level']??'');
        $districtId=trim((string)($input['district_id']??''));

        $ascs=[];
        $allowedContext=false;
        $recordRoutePrefix='';

        if($level==='DISTRICT' && $districtId===''){
            $ascs=ScopeService::scopedLocations($userId,'ASC');
            $allowedContext=true;
            $recordRoutePrefix='hr/arpa-appointments/'.$route.'/asc/';
        }elseif(
            $level==='NATIONAL'
            && preg_match(self::uuidPattern(),$districtId)===1
        ){
            $districtIds=array_values(array_map(
                fn(array $row)=>(string)$row['id'],
                ScopeService::scopedLocations($userId,'DISTRICT')
            ));

            if(in_array($districtId,$districtIds,true)){
                $ascs=self::arpaDistrictAscs($districtId);
                $allowedContext=true;
                $recordRoutePrefix=
                    'hr/arpa-appointments/'.
                    $route.
                    '/district/'.
                    $districtId.
                    '/asc/';
            }
        }

        $ascIds=array_values(array_map(
            fn(array $row)=>(string)$row['id'],
            $ascs
        ));

        $detailWhere=array_values($detail['baseWhere']??[]);
        $detailWhereSql=$detailWhere===[]?
            '':
            ' WHERE '.implode(' AND ',$detailWhere);

        $detailCount=(string)$detail['count'];

        $recordCounts="(
            SELECT
                {$ascColumn} asc_id,
                COUNT(DISTINCT CASE WHEN {$typeColumn}='PERMANENT'
                    THEN {$detailCount} END) permanent_count,
                COUNT(DISTINCT CASE WHEN {$typeColumn}='ACTING'
                    THEN {$detailCount} END) acting_count,
                COUNT(DISTINCT CASE WHEN {$typeColumn}='DUTY_COVERING'
                    THEN {$detailCount} END) duty_covering_count,
                COUNT(DISTINCT CASE WHEN {$typeColumn}='ATTEND_TO_DUTY'
                    THEN {$detailCount} END) attend_to_duty_count,
                COUNT(DISTINCT {$detailCount}) total_count
            FROM {$detail['from']}
            {$detailWhereSql}
            GROUP BY {$ascColumn}
        ) record_counts";

        $divisionCounts="(
            SELECT
                rel.parent_location_id asc_id,
                COUNT(DISTINCT arpa.id) total_divisions
            FROM location_relationship rel
            JOIN location arpa
              ON arpa.id=rel.child_location_id
            JOIN location_type arpa_t
              ON arpa_t.id=arpa.location_type_id
             AND arpa_t.system_key='ARPA_DIVISION'
            WHERE rel.relationship_type='ASC_ARPA_DIVISION'
              AND rel.active=1
              AND rel.approval_status='APPROVED'
              AND rel.effective_from<=CURRENT_DATE()
              AND (rel.effective_to IS NULL OR rel.effective_to>=CURRENT_DATE())
              AND arpa.approval_status='APPROVED'
              AND arpa.operational_status='ACTIVE'
              AND arpa.effective_from<=CURRENT_DATE()
              AND (arpa.effective_to IS NULL OR arpa.effective_to>=CURRENT_DATE())
            GROUP BY rel.parent_location_id
        ) division_counts";

        $vacantCounts="(
            SELECT
                vacant.asc_location_id asc_id,
                COUNT(*) vacant_divisions
            FROM ".
            \App\Services\ArpaAppointmentReadService::vacantDivisionSource().
            " vacant
            GROUP BY vacant.asc_location_id
        ) vacant_counts";

        $scopeWhere=$ascIds===[]?
            '1=0':
            'summary_asc.id IN ('.
                implode(',',array_fill(0,count($ascIds),'?')).
            ')';

        $detailAuthorize=$detail['authorize']??null;

        return [
            'permission'=>$detail['permission'],
            'authorize'=>fn():bool=>
                $allowedContext
                && ($detailAuthorize===null || $detailAuthorize()),
            'export'=>false,
            'filename'=>'arpa-'.$page.'-appointments-summary',
            'with'=>$detail['with']??'',
            'from'=>"location summary_asc
                     LEFT JOIN {$recordCounts}
                       ON record_counts.asc_id=summary_asc.id
                     LEFT JOIN {$divisionCounts}
                       ON division_counts.asc_id=summary_asc.id
                     LEFT JOIN {$vacantCounts}
                       ON vacant_counts.asc_id=summary_asc.id",
            'select'=>[
                'summary_asc.id asc_id',
                'summary_asc.dad_number asc_dad',
                'summary_asc.name_en asc_name',
                'COALESCE(record_counts.permanent_count,0) permanent_count',
                'COALESCE(record_counts.acting_count,0) acting_count',
                'COALESCE(record_counts.duty_covering_count,0) duty_covering_count',
                'COALESCE(record_counts.attend_to_duty_count,0) attend_to_duty_count',
                'COALESCE(record_counts.total_count,0) total_count',
                'COALESCE(division_counts.total_divisions,0) total_divisions',
                'COALESCE(vacant_counts.vacant_divisions,0) vacant_divisions',
            ],
            'count'=>'summary_asc.id',
            'baseWhere'=>[$scopeWhere],
            'baseParams'=>array_merge(
                array_values($detail['baseParams']??[]),
                $ascIds
            ),
            'searchable'=>[
                'summary_asc.dad_number',
                'summary_asc.name_en',
            ],
            'filters'=>[],
            'columns'=>[
                self::col(
                    'ASC',
                    'asc_name',
                    'summary_asc.name_en',
                    fn($r)=>
                        '<div class="fw-semibold">'.
                        e($r['asc_name']).
                        '</div><div class="text-muted small">'.
                        e($r['asc_dad']).
                        '</div>'
                ),
                self::col(
                    'Permanent',
                    'permanent_count',
                    'COALESCE(record_counts.permanent_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['permanent_count'])
                ),
                self::col(
                    'Acting',
                    'acting_count',
                    'COALESCE(record_counts.acting_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['acting_count'])
                ),
                self::col(
                    'Duty Covering',
                    'duty_covering_count',
                    'COALESCE(record_counts.duty_covering_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['duty_covering_count'])
                ),
                self::col(
                    'Attend to Duty',
                    'attend_to_duty_count',
                    'COALESCE(record_counts.attend_to_duty_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['attend_to_duty_count'])
                ),
                self::col(
                    $totalLabel,
                    'total_count',
                    'COALESCE(record_counts.total_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['total_count'])
                ),
                self::col(
                    'Total ARPA Divisions',
                    'total_divisions',
                    'COALESCE(division_counts.total_divisions,0)',
                    fn($r)=>DataTableFormat::text((string)$r['total_divisions'])
                ),
                self::col(
                    'Vacant Divisions',
                    'vacant_divisions',
                    'COALESCE(vacant_counts.vacant_divisions,0)',
                    fn($r)=>DataTableFormat::text((string)$r['vacant_divisions'])
                ),
                self::actionColumn(
                    fn($r)=>
                        '<a class="btn btn-sm btn-outline-primary" href="'.
                        e(url($recordRoutePrefix.$r['asc_id'])).
                        '"><i class="bi bi-list-ul"></i> View Records</a>'
                ),
            ],
            'defaultOrder'=>[0,'ASC'],
            'emptyMessage'=>'No Agrarian Service Centers are available for your current access.',
        ];
    }

    private static function arpaAppointmentDistrictSummary(
        string $page
    ):array
    {
        $userId=(string)(Auth::user()['id']??'');

        [
            $detail,
            $ascColumn,
            $typeColumn,
            $route,
            $totalLabel
        ]=self::arpaAppointmentSummarySource($page);

        $profile=ScopeService::scopeProfile($userId);
        $isNational=($profile['level']??'')==='NATIONAL';

        $districts=$isNational
            ? ScopeService::scopedLocations($userId,'DISTRICT')
            : [];

        $districtIds=array_values(array_map(
            fn(array $row)=>(string)$row['id'],
            $districts
        ));

        $detailWhere=array_values($detail['baseWhere']??[]);

        $recordWhere=array_merge($detailWhere,[
            "district_rel.active=1",
            "district_rel.approval_status='APPROVED'",
            "district_rel.effective_from<=CURRENT_DATE()",
            "(district_rel.effective_to IS NULL
                OR district_rel.effective_to>=CURRENT_DATE())",
        ]);

        $recordWhereSql=' WHERE '.implode(' AND ',$recordWhere);
        $detailCount=(string)$detail['count'];

        $recordCounts="(
            SELECT
                district_rel.parent_location_id district_id,
                COUNT(DISTINCT CASE WHEN {$typeColumn}='PERMANENT'
                    THEN {$detailCount} END) permanent_count,
                COUNT(DISTINCT CASE WHEN {$typeColumn}='ACTING'
                    THEN {$detailCount} END) acting_count,
                COUNT(DISTINCT CASE WHEN {$typeColumn}='DUTY_COVERING'
                    THEN {$detailCount} END) duty_covering_count,
                COUNT(DISTINCT CASE WHEN {$typeColumn}='ATTEND_TO_DUTY'
                    THEN {$detailCount} END) attend_to_duty_count,
                COUNT(DISTINCT {$detailCount}) total_count
            FROM {$detail['from']}
            JOIN location_relationship district_rel
              ON district_rel.child_location_id={$ascColumn}
            JOIN location district_record
              ON district_record.id=district_rel.parent_location_id
            JOIN location_type district_type
              ON district_type.id=district_record.location_type_id
             AND district_type.system_key='DISTRICT'
            {$recordWhereSql}
            GROUP BY district_rel.parent_location_id
        ) record_counts";

        $inventoryCounts="(
            SELECT
                district_rel.parent_location_id district_id,
                COUNT(DISTINCT asc_l.id) total_ascs,
                COUNT(
                    DISTINCT CASE
                        WHEN arpa_t.system_key='ARPA_DIVISION'
                        THEN arpa.id
                    END
                ) total_divisions
            FROM location_relationship district_rel
            JOIN location asc_l
              ON asc_l.id=district_rel.child_location_id
            JOIN location_type asc_t
              ON asc_t.id=asc_l.location_type_id
             AND asc_t.system_key='ASC'
            LEFT JOIN location_relationship arpa_rel
              ON arpa_rel.parent_location_id=asc_l.id
             AND arpa_rel.relationship_type='ASC_ARPA_DIVISION'
             AND arpa_rel.active=1
             AND arpa_rel.approval_status='APPROVED'
             AND arpa_rel.effective_from<=CURRENT_DATE()
             AND (
                    arpa_rel.effective_to IS NULL
                    OR arpa_rel.effective_to>=CURRENT_DATE()
                 )
            LEFT JOIN location arpa
              ON arpa.id=arpa_rel.child_location_id
             AND arpa.approval_status='APPROVED'
             AND arpa.operational_status='ACTIVE'
             AND arpa.effective_from<=CURRENT_DATE()
             AND (
                    arpa.effective_to IS NULL
                    OR arpa.effective_to>=CURRENT_DATE()
                 )
            LEFT JOIN location_type arpa_t
              ON arpa_t.id=arpa.location_type_id
            WHERE district_rel.active=1
              AND district_rel.approval_status='APPROVED'
              AND district_rel.effective_from<=CURRENT_DATE()
              AND (
                    district_rel.effective_to IS NULL
                    OR district_rel.effective_to>=CURRENT_DATE()
                  )
              AND asc_l.approval_status='APPROVED'
              AND asc_l.operational_status='ACTIVE'
            GROUP BY district_rel.parent_location_id
        ) inventory_counts";

        $vacantCounts="(
            SELECT
                district_rel.parent_location_id district_id,
                COUNT(*) vacant_divisions
            FROM ".
            \App\Services\ArpaAppointmentReadService::vacantDivisionSource().
            " vacant
            JOIN location_relationship district_rel
              ON district_rel.child_location_id=vacant.asc_location_id
            JOIN location district_record
              ON district_record.id=district_rel.parent_location_id
            JOIN location_type district_type
              ON district_type.id=district_record.location_type_id
             AND district_type.system_key='DISTRICT'
            WHERE district_rel.active=1
              AND district_rel.approval_status='APPROVED'
              AND district_rel.effective_from<=CURRENT_DATE()
              AND (
                    district_rel.effective_to IS NULL
                    OR district_rel.effective_to>=CURRENT_DATE()
                  )
            GROUP BY district_rel.parent_location_id
        ) vacant_counts";

        $scopeWhere=$districtIds===[]?
            '1=0':
            'summary_district.id IN ('.
                implode(',',array_fill(0,count($districtIds),'?')).
            ')';

        $detailAuthorize=$detail['authorize']??null;

        return [
            'permission'=>$detail['permission'],
            'authorize'=>fn():bool=>
                $isNational
                && ($detailAuthorize===null || $detailAuthorize()),
            'export'=>false,
            'filename'=>'arpa-'.$page.'-district-summary',
            'with'=>$detail['with']??'',
            'from'=>"location summary_district
                     LEFT JOIN {$recordCounts}
                       ON record_counts.district_id=summary_district.id
                     LEFT JOIN {$inventoryCounts}
                       ON inventory_counts.district_id=summary_district.id
                     LEFT JOIN {$vacantCounts}
                       ON vacant_counts.district_id=summary_district.id",
            'select'=>[
                'summary_district.id district_id',
                'summary_district.dad_number district_dad',
                'summary_district.name_en district_name',
                'COALESCE(record_counts.permanent_count,0) permanent_count',
                'COALESCE(record_counts.acting_count,0) acting_count',
                'COALESCE(record_counts.duty_covering_count,0) duty_covering_count',
                'COALESCE(record_counts.attend_to_duty_count,0) attend_to_duty_count',
                'COALESCE(record_counts.total_count,0) total_count',
                'COALESCE(inventory_counts.total_ascs,0) total_ascs',
                'COALESCE(inventory_counts.total_divisions,0) total_divisions',
                'COALESCE(vacant_counts.vacant_divisions,0) vacant_divisions',
            ],
            'count'=>'summary_district.id',
            'baseWhere'=>[$scopeWhere],
            'baseParams'=>array_merge(
                array_values($detail['baseParams']??[]),
                $districtIds
            ),
            'searchable'=>[
                'summary_district.dad_number',
                'summary_district.name_en',
            ],
            'filters'=>[],
            'columns'=>[
                self::col(
                    'District',
                    'district_name',
                    'summary_district.name_en',
                    fn($r)=>
                        '<div class="fw-semibold">'.
                        e($r['district_name']).
                        '</div><div class="text-muted small">'.
                        e($r['district_dad']).
                        '</div>'
                ),
                self::col(
                    'Permanent',
                    'permanent_count',
                    'COALESCE(record_counts.permanent_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['permanent_count'])
                ),
                self::col(
                    'Acting',
                    'acting_count',
                    'COALESCE(record_counts.acting_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['acting_count'])
                ),
                self::col(
                    'Duty Covering',
                    'duty_covering_count',
                    'COALESCE(record_counts.duty_covering_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['duty_covering_count'])
                ),
                self::col(
                    'Attend to Duty',
                    'attend_to_duty_count',
                    'COALESCE(record_counts.attend_to_duty_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['attend_to_duty_count'])
                ),
                self::col(
                    $totalLabel,
                    'total_count',
                    'COALESCE(record_counts.total_count,0)',
                    fn($r)=>DataTableFormat::text((string)$r['total_count'])
                ),
                self::col(
                    'Total ASCs',
                    'total_ascs',
                    'COALESCE(inventory_counts.total_ascs,0)',
                    fn($r)=>DataTableFormat::text((string)$r['total_ascs'])
                ),
                self::col(
                    'Total ARPA Divisions',
                    'total_divisions',
                    'COALESCE(inventory_counts.total_divisions,0)',
                    fn($r)=>DataTableFormat::text((string)$r['total_divisions'])
                ),
                self::col(
                    'Vacant Divisions',
                    'vacant_divisions',
                    'COALESCE(vacant_counts.vacant_divisions,0)',
                    fn($r)=>DataTableFormat::text((string)$r['vacant_divisions'])
                ),
                self::actionColumn(
                    fn($r)=>
                        '<a class="btn btn-sm btn-outline-primary" href="'.
                        e(url(
                            'hr/arpa-appointments/'.
                            $route.
                            '/district/'.
                            $r['district_id']
                        )).
                        '"><i class="bi bi-diagram-3"></i> View ASCs</a>'
                ),
            ],
            'defaultOrder'=>[0,'ASC'],
            'emptyMessage'=>'No active Districts are available.',
        ];
    }
    private static function arpaNewAppointments():array
    {
        $definition=self::arpaRequestList();
        $definition['filename']='arpa-new-appointments';
        $definition['baseWhere'][]="r.workflow_status IN('CREATED','RETURNED')";
        $definition['baseWhere'][]='r.created_by=?';
        $definition['baseParams'][]=(string)(Auth::user()['id']??'');
        $definition['authorize']=fn():bool=>Auth::can('arpa.appointment.create');
        return $definition;
    }

    private static function arpaSubmittedAppointments():array
    {
        $userId=(string)(Auth::user()['id']??'');$policy=new ArpaWorkflowQueuePolicy(Database::pdo());$access=$policy->requestAccess($userId);
        $definition=self::arpaRequestList($access);
        $definition['filename']='arpa-submitted-appointments';
        $definition['baseWhere'][]=$access['where'];
        $definition['authorize']=fn():bool=>$policy->canUseWorkflowQueues($userId);
        return $definition;
    }

    private static function arpaApprovalVerification():array
    {
        $userId=(string)(Auth::user()['id']??'');$policy=new ArpaWorkflowQueuePolicy(Database::pdo());$access=$policy->completedAccess($userId);
        return [
            'permission'=>'arpa.appointment.view','authorize'=>fn():bool=>$policy->canUseWorkflowQueues($userId),'export'=>true,'filename'=>'arpa-completed-workflow-actions',
            'with'=>$access['with'],
            'from'=>'arpa_appointment_workflow_action w JOIN arpa_division_appointment_request r ON r.id=w.request_id JOIN officer o ON o.id=r.officer_id LEFT JOIN location asc_l ON asc_l.id=r.asc_location_id LEFT JOIN location arpa ON arpa.id=r.arpa_division_location_id JOIN system_user actor ON actor.id=w.user_id',
            'select'=>['w.id','r.id request_id','r.officer_id','o.dad_number officer_number','o.name_with_initials officer_name','o.nic','asc_l.name_en asc_name','arpa.name_en arpa_name','r.appointment_type','r.requested_effective_from effective_from','w.action','w.stage','w.new_status resulting_status','COALESCE(actor.display_name,actor.username) action_officer','w.action_at','w.timestamp_provenance','r.workflow_status current_workflow_status'],
            'count'=>'w.id','baseWhere'=>['w.user_id=?','r.legacy_history_only=0',$access['where']],
            'baseParams'=>array_merge($access['params'],[$userId]),
            'searchable'=>['r.id','o.dad_number','o.name_with_initials','o.nic','asc_l.name_en','arpa.name_en','r.appointment_type','w.action','w.stage','w.new_status','r.workflow_status'],
            'filters'=>[
                'appointment_type'=>['column'=>'r.appointment_type','allowed'=>['PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY'],'ui'=>['label'=>'Appointment Type','options'=>['PERMANENT'=>'Permanent','ACTING'=>'Acting','DUTY_COVERING'=>'Duty Covering','ATTEND_TO_DUTY'=>'Attend to the Duty']]],
                'workflow_status'=>['column'=>'r.workflow_status','allowed'=>['ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','NATIONAL_APPROVED','RETURNED','REJECTED'],'ui'=>['label'=>'Current Workflow Stage','options'=>['ASC_VERIFIED'=>'ASC Verified','ASC_APPROVED'=>'ASC Approved','DISTRICT_VERIFIED'=>'District Verified','DISTRICT_APPROVED'=>'District Approved','NATIONAL_VERIFIED'=>'National Verified','NATIONAL_APPROVED'=>'National Approved','RETURNED'=>'Returned','REJECTED'=>'Rejected']]],
            ],
            'columns'=>[
                self::col('Reference','request_id','r.id',fn($r)=>'<code>'.e(substr($r['request_id'],0,8)).'</code>'),
                self::col('Officer Number','officer_number','o.dad_number',fn($r)=>'<a href="'.e(url('hr/officers/'.$r['officer_id'])).'">'.e($r['officer_number']).'</a>'),
                self::col('Officer','officer_name','o.name_with_initials',fn($r)=>DataTableFormat::text($r['officer_name'])),
                self::col('NIC','nic','o.nic',fn($r)=>DataTableFormat::text($r['nic'])),
                self::col('ASC','asc_name','asc_l.name_en',fn($r)=>DataTableFormat::text($r['asc_name'])),
                self::col('ARPA Division','arpa_name','arpa.name_en',fn($r)=>DataTableFormat::text($r['arpa_name'])),
                self::col('Type','appointment_type','r.appointment_type',fn($r)=>DataTableFormat::badge($r['appointment_type'])),
                self::col('Start Date','effective_from','r.requested_effective_from',fn($r)=>DataTableFormat::date($r['effective_from'])),
                self::col('Action','action','w.action',fn($r)=>DataTableFormat::badge($r['stage'].' '.$r['action'])),
                self::col('Result','resulting_status','w.new_status',fn($r)=>DataTableFormat::badge($r['resulting_status'])),
                self::col('Action Officer','action_officer','actor.username',fn($r)=>DataTableFormat::text($r['action_officer'])),
                self::col('Action At','action_at','w.action_at',fn($r)=>$r['action_at']?DataTableFormat::dateTime($r['action_at']):'<span class="text-muted">Unavailable from legacy source</span>'),
                self::col('Current Stage','current_workflow_status','r.workflow_status',fn($r)=>DataTableFormat::badge($r['current_workflow_status'])),
                self::actionColumn(fn($r)=>'<a class="btn btn-sm btn-outline-primary" href="'.e(url('hr/arpa-appointments/requests/division/'.$r['request_id'])).'">View</a>'),
            ],'defaultOrder'=>[11,'DESC'],
        ];
    }

    private static function arpaRequestList(?array $queueAccess=null):array
    {
        $geo=$queueAccess===null?self::geo('r.asc_location_id'):['with'=>$queueAccess['with'],'params'=>$queueAccess['params'],'joinExpression'=>''];
        $responsible="CASE r.workflow_status WHEN 'CREATED' THEN 'ASC DATA ENTRY' WHEN 'RETURNED' THEN 'ASC DATA ENTRY' WHEN 'SUBMITTED' THEN 'ASC VERIFICATION' WHEN 'ASC_VERIFIED' THEN 'ASC APPROVAL' WHEN 'ASC_APPROVED' THEN 'DISTRICT VERIFICATION' WHEN 'DISTRICT_VERIFIED' THEN 'DISTRICT APPROVAL' WHEN 'DISTRICT_APPROVED' THEN 'NATIONAL VERIFICATION' WHEN 'NATIONAL_VERIFIED' THEN 'NATIONAL APPROVAL' ELSE 'COMPLETED' END";
        return [
            'permission'=>'arpa.appointment.view','export'=>true,'filename'=>'arpa-appointment-requests','with'=>$geo['with'],
            'from'=>'arpa_division_appointment_request r JOIN officer o ON o.id=r.officer_id LEFT JOIN location asc_l ON asc_l.id=r.asc_location_id LEFT JOIN location arpa ON arpa.id=r.arpa_division_location_id LEFT JOIN system_user submitter ON submitter.id=(SELECT wa.user_id FROM arpa_appointment_workflow_action wa WHERE wa.request_id=r.id AND wa.action=\'SUBMIT\' ORDER BY wa.id DESC LIMIT 1) LEFT JOIN arpa_appointment_workflow_action returned_event ON returned_event.id=(SELECT MAX(re.id) FROM arpa_appointment_workflow_action re WHERE re.request_id=r.id AND re.action IN(\'RETURN_FOR_CORRECTION\',\'REJECT\')) LEFT JOIN system_user returner ON returner.id=returned_event.user_id '.$geo['joinExpression'],
            'select'=>['r.id','r.officer_id','r.request_type','o.dad_number officer_number','o.name_with_initials officer_name','o.nic','r.asc_location_id','asc_l.name_en asc_name','r.arpa_division_location_id','arpa.name_en arpa_name','r.appointment_type','r.requested_effective_from effective_from','r.workflow_status','r.created_by','r.created_at','submitter.username submitted_by',"(SELECT wa.action_at FROM arpa_appointment_workflow_action wa WHERE wa.request_id=r.id AND wa.action='SUBMIT' ORDER BY wa.id DESC LIMIT 1) submitted_at","{$responsible} responsible_level","COALESCE(NULLIF(returner.display_name,''),returner.username) returned_by",'returned_event.stage returned_level','returned_event.action_at returned_at','returned_event.timestamp_provenance returned_timestamp_provenance','returned_event.comments return_reason'],
            'count'=>'r.id','baseWhere'=>['r.legacy_history_only=0',"r.record_origin='NATIVE'"],'baseParams'=>$geo['params'],'searchable'=>['o.dad_number','o.name_with_initials','o.nic','asc_l.name_en','arpa.name_en','r.appointment_type','r.workflow_status'],
            'filters'=>[
                'province'=>['build'=>fn($v)=>['EXISTS(SELECT 1 FROM location_relationship pa JOIN location_relationship da ON da.parent_location_id=pa.child_location_id WHERE pa.parent_location_id=? AND da.child_location_id=r.asc_location_id)',[$v]],'pattern'=>self::uuidPattern(),'ui'=>['label'=>'Province']],
                'district'=>['build'=>fn($v)=>['EXISTS(SELECT 1 FROM location_relationship da WHERE da.parent_location_id=? AND da.child_location_id=r.asc_location_id)',[$v]],'pattern'=>self::uuidPattern(),'ui'=>['label'=>'District']],
                'asc'=>['column'=>'r.asc_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ASC']],
                'arpa_division'=>['column'=>'r.arpa_division_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ARPA Division']],
                'officer'=>['column'=>'r.officer_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'Officer']],
                'appointment_type'=>['column'=>'r.appointment_type','allowed'=>['PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY'],'ui'=>['label'=>'Appointment Type','options'=>['PERMANENT'=>'Permanent','ACTING'=>'Acting','DUTY_COVERING'=>'Duty Covering','ATTEND_TO_DUTY'=>'Attend to the Duty']]],
                'workflow_status'=>['column'=>'r.workflow_status','allowed'=>['CREATED','SUBMITTED','ASC_VERIFIED','ASC_APPROVED','DISTRICT_VERIFIED','DISTRICT_APPROVED','NATIONAL_VERIFIED','RETURNED'],'ui'=>['label'=>'Workflow Stage','options'=>['CREATED'=>'Created','SUBMITTED'=>'Submitted','ASC_VERIFIED'=>'ASC Verified','ASC_APPROVED'=>'ASC Approved','DISTRICT_VERIFIED'=>'District Verified','DISTRICT_APPROVED'=>'District Approved','NATIONAL_VERIFIED'=>'National Verified','RETURNED'=>'Returned']]],
                'date_from'=>['column'=>'r.requested_effective_from','operator'=>'>=','date'=>true,'ui'=>['label'=>'Start Date From','type'=>'date']],
                'date_to'=>['column'=>'r.requested_effective_from','operator'=>'<=','date'=>true,'ui'=>['label'=>'Start Date To','type'=>'date']],
            ],
            'columns'=>[
                self::col('Reference','id','r.id',fn($r)=>'<code>'.e(substr($r['id'],0,8)).'</code>'),
                self::col('Officer Number','officer_number','o.dad_number',fn($r)=>'<a href="'.e(url('hr/officers/'.$r['officer_id'])).'">'.e($r['officer_number']).'</a>'),
                self::col('Officer','officer_name','o.name_with_initials',fn($r)=>DataTableFormat::text($r['officer_name'])),
                self::col('NIC','nic','o.nic',fn($r)=>DataTableFormat::text($r['nic'])),
                self::col('ASC','asc_name','asc_l.name_en',fn($r)=>DataTableFormat::text($r['asc_name'])),
                self::col('ARPA Division','arpa_name','arpa.name_en',fn($r)=>DataTableFormat::text($r['arpa_name'])),
                self::col('Type','appointment_type','r.appointment_type',fn($r)=>DataTableFormat::badge($r['appointment_type'])),
                self::col('Start Date','effective_from','r.requested_effective_from',fn($r)=>DataTableFormat::date($r['effective_from'])),
                self::col('Workflow','workflow_status','r.workflow_status',fn($r)=>$r['workflow_status']==='RETURNED'?'<span class="badge bg-danger">RETURNED FOR CORRECTION</span>':DataTableFormat::badge($r['workflow_status'])),
                self::col('Submitted By','submitted_by','submitter.username',fn($r)=>DataTableFormat::text($r['submitted_by'])),
                self::col('Submitted At','submitted_at',null,fn($r)=>DataTableFormat::dateTime($r['submitted_at'])),
                self::col('Responsible Level','responsible_level',$responsible,fn($r)=>DataTableFormat::text($r['responsible_level'])),
                self::col('Returned By','returned_by','returner.username',fn($r)=>$r['workflow_status']==='RETURNED'?DataTableFormat::text($r['returned_by']):DataTableFormat::text(null)),
                self::col('Returned Level','returned_level','returned_event.stage',fn($r)=>$r['workflow_status']==='RETURNED'?DataTableFormat::text($r['returned_level']):DataTableFormat::text(null)),
                self::col('Returned At','returned_at','returned_event.action_at',fn($r)=>$r['workflow_status']==='RETURNED'?($r['returned_at']?DataTableFormat::dateTime($r['returned_at']):'<span class="text-muted">Unavailable from legacy source</span>'):DataTableFormat::text(null)),
                self::col('Correction Reason','return_reason','returned_event.comments',fn($r)=>$r['workflow_status']==='RETURNED'?DataTableFormat::text($r['return_reason']):DataTableFormat::text(null)),
                self::actionColumn(fn($r)=>self::arpaWorkflowActions($r+['entity'=>'division'])),
            ],'defaultOrder'=>[8,'ASC'],
        ];
    }

    private static function arpaOpenAppointments():array
    {
        $definition=self::arpaDivisionAppointments();$definition['filename']='arpa-open-appointments';$definition['baseWhere'][]='c.id IS NULL';$definition['baseWhere'][]='a.legacy_history_only=0';
        return $definition;
    }

    private static function arpaHistoricalAppointments():array
    {
        $definition=self::arpaDivisionAppointments();$definition['filename']='arpa-historical-appointments';$definition['baseWhere'][]='c.id IS NOT NULL';
        $definition['columns']=[
            self::col('ARPA Division','arpa_name_snapshot','a.arpa_name_snapshot',fn($r)=>DataTableFormat::text($r['arpa_name_snapshot'])),
            self::col('Officer Number','officer_number','o.dad_number',fn($r)=>'<a href="'.e(url('hr/officers/'.$r['officer_id'])).'">'.e($r['officer_number']).'</a>'),
            self::col('Officer','officer_name','o.name_with_initials',fn($r)=>DataTableFormat::text($r['officer_name'])),
            self::col('NIC','nic','o.nic',fn($r)=>DataTableFormat::text($r['nic'])),self::col('Type','appointment_type','a.appointment_type',fn($r)=>DataTableFormat::badge($r['appointment_type'])),
            self::col('Start Date','effective_from','a.effective_from',fn($r)=>DataTableFormat::date($r['effective_from'])),self::col('End Date','effective_to','c.effective_to',fn($r)=>DataTableFormat::date($r['effective_to'])),
            self::col('End Reason','end_reason','er.name_en',fn($r)=>DataTableFormat::text($r['end_reason'],'Not available')),self::col('Workflow','workflow_status','req.workflow_status',fn($r)=>DataTableFormat::badge($r['workflow_status'])),self::col('Source','record_origin','a.record_origin',fn($r)=>DataTableFormat::badge($r['record_origin'])),
            self::actionColumn(fn($r)=>self::arpaDivisionActions($r))];
        $definition['defaultOrder']=[0,'ASC'];
        return $definition;
    }

    private static function arpaVacantDivisions():array
    {
        $geo=self::geo('v.asc_location_id');
        return ['permission'=>'arpa.appointment.view','export'=>true,'filename'=>'vacant-arpa-divisions','with'=>$geo['with'],'from'=>\App\Services\ArpaAppointmentReadService::vacantDivisionSource().' v '.$geo['joinExpression'],
            'select'=>['v.id','v.dad_number','v.name_en','v.asc_location_id','v.asc_name','v.district_location_id','v.district_name','v.province_location_id','v.province_name','v.last_officer_id','v.last_officer','v.last_appointment_type','v.last_end_date','v.last_end_reason','v.vacancy_since'],'count'=>'v.id','baseParams'=>$geo['params'],
            'searchable'=>['v.dad_number','v.name_en','v.asc_name','v.district_name','v.province_name','v.last_officer'],
            'filters'=>['province'=>['column'=>'v.province_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'Province']],'district'=>['column'=>'v.district_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'District']],'asc'=>['column'=>'v.asc_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ASC']]],
            'columns'=>[self::col('Province','province_name','v.province_name',fn($r)=>DataTableFormat::text($r['province_name'])),self::col('District','district_name','v.district_name',fn($r)=>DataTableFormat::text($r['district_name'])),self::col('ASC','asc_name','v.asc_name',fn($r)=>DataTableFormat::text($r['asc_name'])),self::col('ARPA Division Number','dad_number','v.dad_number',fn($r)=>DataTableFormat::text($r['dad_number'])),self::col('ARPA Division','name_en','v.name_en',fn($r)=>DataTableFormat::text($r['name_en'])),self::col('Last Officer','last_officer','v.last_officer',fn($r)=>DataTableFormat::text($r['last_officer'],'None')),self::col('Last Type','last_appointment_type','v.last_appointment_type',fn($r)=>DataTableFormat::text($r['last_appointment_type'])),self::col('Last End','last_end_date','v.last_end_date',fn($r)=>DataTableFormat::date($r['last_end_date'])),self::col('End Reason','last_end_reason','v.last_end_reason',fn($r)=>DataTableFormat::text($r['last_end_reason'])),self::col('Vacancy Since','vacancy_since','v.vacancy_since',fn($r)=>DataTableFormat::date($r['vacancy_since'])),self::actionColumn(fn($r)=>Auth::can('arpa.appointment.create')?'<a class="btn btn-sm btn-primary" href="'.e(url('hr/arpa-appointments/new?asc_location_id='.$r['asc_location_id'].'&arpa_division_location_id='.$r['id'])).'">New Assignment</a>':'')],
            'defaultOrder'=>[2,'ASC']];
    }

    private static function arpaAppointmentIssues():array
    {
        $geo=self::geo('q.asc_location_id');$current=\App\Services\ArpaAppointmentReadService::currentActionIssuePredicate('q');$reviewed="NOT EXISTS(SELECT 1 FROM arpa_appointment_data_correction dc WHERE dc.issue_row_key=q.row_key AND dc.resolution_status='KEPT_HISTORICAL_EXCEPTION')";
        return ['permission'=>'arpa.appointment.view','export'=>true,'filename'=>'arpa-appointment-data-issues','with'=>$geo['with'],'from'=>\App\Services\ArpaAppointmentReadService::issueSource().' q '.$geo['joinExpression'],
            'select'=>['q.row_key','q.issue_type','q.severity','q.officer_id','q.officer_number','q.officer_name','q.nic','q.asc_location_id','q.asc_name','q.arpa_divisions','q.appointment_types','q.effective_periods','q.related_ids','q.origin','q.explanation','q.recommended_action'],'count'=>'q.row_key','baseParams'=>$geo['params'],
            'searchable'=>['q.issue_type','q.officer_number','q.officer_name','q.nic','q.asc_name','q.arpa_divisions','q.appointment_types','q.explanation'],
            'filters'=>['category'=>['allowed'=>['CURRENT_ACTION_REQUIRED','HISTORICAL_EXCEPTIONS','LEGACY_DATA_WARNINGS'],'build'=>fn($v)=>match($v){'CURRENT_ACTION_REQUIRED'=>["{$current} AND {$reviewed}",[]],'HISTORICAL_EXCEPTIONS'=>["q.severity='HISTORICAL_EXCEPTION' AND {$reviewed}",[]],default=>["q.severity='WARNING' AND {$reviewed}",[]]},'ui'=>['label'=>'Show','options'=>['CURRENT_ACTION_REQUIRED'=>'Needs Attention','HISTORICAL_EXCEPTIONS'=>'Historical Records','LEGACY_DATA_WARNINGS'=>'Old Data Warnings']]],'severity'=>['column'=>'q.severity','allowed'=>['ERROR','WARNING','HISTORICAL_EXCEPTION'],'ui'=>['label'=>'Status','options'=>['ERROR'=>'Needs Correction','WARNING'=>'Please Check','HISTORICAL_EXCEPTION'=>'Old Data Warning']]],'issue_type'=>['column'=>'q.issue_type','pattern'=>'/^[A-Z_]{3,80}$/','ui'=>['label'=>'Issue','options'=>array_combine($types=['DIVISION_MULTIPLE_OPEN','OFFICER_MULTIPLE_PERMANENT','OFFICER_MULTIPLE_ACTING','OFFICER_MULTIPLE_ATTEND_TO_DUTY','DEPENDENT_WITHOUT_PERMANENT','PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY','NON_PERMANENT_SERVICE_WITH_ACTING','EXCLUSIVE_FUNCTION_OVERLAP','MULTIPLE_EXCLUSIVE_FUNCTIONS','MISSING_ASC_OFFICE_ASSIGNMENT','APPOINTMENT_OUTSIDE_ASC','INVALID_DATE_RANGE','OPEN_APPOINTMENT_WITH_END_REASON','ENDED_APPOINTMENT_WITHOUT_END_REASON','FUTURE_OVERLAP_CONFLICT','LEGACY_HISTORICAL_EXCEPTION','MANUAL_REVIEW_REQUIRED'],array_map(fn($type)=>ArpaAppointmentIssuePresentation::for($type)['title'],$types))]],'asc'=>['column'=>'q.asc_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ASC']]],
            'columns'=>[self::col('Issue','issue_type','q.issue_type',fn($r)=>'<div class="fw-semibold">'.e(ArpaAppointmentIssuePresentation::for($r['issue_type'])['title']).'</div>'.DataTableFormat::badge(ArpaAppointmentIssuePresentation::severity($r['severity']))),self::col('Officer','officer_name','q.officer_name',fn($r)=>DataTableFormat::text(($r['officer_number']??'')!==''?$r['officer_number'].' - '.$r['officer_name']:$r['officer_name'])),self::col('ASC','asc_name','q.asc_name',fn($r)=>DataTableFormat::text($r['asc_name'])),self::col('ARPA Division','arpa_divisions','q.arpa_divisions',fn($r)=>DataTableFormat::text($r['arpa_divisions'])),self::col('Appointment Type','appointment_types','q.appointment_types',fn($r)=>DataTableFormat::enumText($r['appointment_types'])),self::col('Appointment Period','effective_periods','q.effective_periods',fn($r)=>DataTableFormat::text($r['effective_periods'])),self::col('What to check','recommended_action','q.recommended_action',fn($r)=>DataTableFormat::text(ArpaAppointmentIssuePresentation::for($r['issue_type'])['what_to_check'])),self::actionColumn(fn($r)=>'<a class="btn btn-sm btn-outline-primary" href="'.e(url('hr/arpa-appointments/issues/'.rawurlencode($r['row_key']))).'">Review</a>')],
            'defaultOrder'=>[0,'ASC']];
    }

    private static function arpaAppointmentCorrections():array
    {
        $geo=self::geo('c.asc_location_id');
        return ['permission'=>'arpa.appointment.view','export'=>true,'filename'=>'arpa-appointment-data-issue-corrections','with'=>$geo['with'],'from'=>'arpa_appointment_data_correction c JOIN officer o ON o.id=c.officer_id JOIN location asc_l ON asc_l.id=c.asc_location_id JOIN system_user u ON u.id=c.corrected_by LEFT JOIN arpa_division_appointment a ON a.id=c.appointment_id '.$geo['joinExpression'],
            'select'=>['c.id','c.issue_type','c.resolution_status','c.correction_action','c.correction_reason','c.corrected_at','c.officer_id','o.dad_number officer_number','o.name_with_initials officer_name','asc_l.name_en asc_name','a.arpa_name_snapshot','a.appointment_type','c.record_origin','COALESCE(NULLIF(u.display_name,\'\'),u.username) corrected_by_name'],'count'=>'c.id','baseParams'=>$geo['params'],
            'searchable'=>['c.issue_type','c.correction_action','c.correction_reason','o.dad_number','o.name_with_initials','asc_l.name_en','a.arpa_name_snapshot','a.appointment_type','u.username','u.display_name'],
            'filters'=>['resolution_status'=>['column'=>'c.resolution_status','allowed'=>['RESOLVED_BY_CORRECTION','REVIEWED_UNRESOLVED','KEPT_HISTORICAL_EXCEPTION'],'ui'=>['label'=>'Result','options'=>['RESOLVED_BY_CORRECTION'=>'Corrected','REVIEWED_UNRESOLVED'=>'Reviewed - Please Check','KEPT_HISTORICAL_EXCEPTION'=>'Kept as Historical Record']]],'asc'=>['column'=>'c.asc_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ASC']]],
            'columns'=>[self::col('Issue','issue_type','c.issue_type',fn($r)=>DataTableFormat::text(ArpaAppointmentIssuePresentation::for($r['issue_type'])['title'])),self::col('Result','resolution_status','c.resolution_status',fn($r)=>DataTableFormat::badge(ArpaAppointmentIssuePresentation::resolution($r['resolution_status']))),self::col('Officer','officer_name','o.name_with_initials',fn($r)=>DataTableFormat::text($r['officer_number'].' - '.$r['officer_name'])),self::col('ASC','asc_name','asc_l.name_en',fn($r)=>DataTableFormat::text($r['asc_name'])),self::col('ARPA Division','arpa_name_snapshot','a.arpa_name_snapshot',fn($r)=>DataTableFormat::text($r['arpa_name_snapshot'])),self::col('Action','correction_action','c.correction_action',fn($r)=>DataTableFormat::text(ArpaAppointmentIssuePresentation::action($r['correction_action']))),self::col('Reason for Correction','correction_reason','c.correction_reason',fn($r)=>DataTableFormat::text($r['correction_reason'])),self::col('Reviewed By','corrected_by_name','u.display_name',fn($r)=>DataTableFormat::text($r['corrected_by_name'])),self::col('Reviewed At','corrected_at','c.corrected_at',fn($r)=>DataTableFormat::dateTime($r['corrected_at'])),self::actionColumn(fn($r)=>'<a class="btn btn-sm btn-outline-primary" href="'.e(url('hr/arpa-appointments/issues/corrections/'.$r['id'])).'">View</a>')],
            'defaultOrder'=>[8,'DESC']];
    }

    private static function arpaOfficerDivisionHistory(array $input):array
    {
        $id=self::requiredUuid($input,'officer_id');$definition=self::arpaDivisionAppointments();$definition['export']=false;$definition['baseWhere'][]='a.officer_id=?';$definition['baseParams'][]=$id;return $definition;
    }

    private static function arpaOfficerSubjectHistory(array $input):array
    {
        $id=self::requiredUuid($input,'officer_id');$definition=self::arpaSubjectAssignments();$definition['export']=false;$definition['baseWhere'][]='a.officer_id=?';$definition['baseParams'][]=$id;return $definition;
    }

    private static function arpaServicePermanencyHistory(array $input):array
    {
        $id=self::requiredUuid($input,'officer_id');$access=self::arpaOfficerGeoAccess('h.officer_id');return [
            'permission'=>'arpa.appointment.view','export'=>false,'with'=>$access['with'],
            'from'=>'arpa_service_permanency_history h JOIN system_user u ON u.id=h.changed_by','select'=>['h.id','h.previous_status','h.new_status','h.effective_from','h.reason','u.username','h.changed_at'],'count'=>'h.id','baseWhere'=>array_merge(['h.officer_id=?'],$access['where']),'baseParams'=>array_merge($access['params'],[$id]),'searchable'=>['h.previous_status','h.new_status','h.reason','u.username'],
            'columns'=>[self::col('Start Date','effective_from','h.effective_from',fn($r)=>DataTableFormat::date($r['effective_from'])),self::col('Previous','previous_status','h.previous_status',fn($r)=>DataTableFormat::enumText($r['previous_status'])),self::col('New','new_status','h.new_status',fn($r)=>DataTableFormat::badge($r['new_status'])),self::col('Recorded By','username','u.username',fn($r)=>DataTableFormat::text($r['username'])),self::col('Reason','reason','h.reason',fn($r)=>DataTableFormat::text($r['reason']))],'defaultOrder'=>[0,'DESC'],
        ];
    }

    private static function arpaOfficerWorkflowHistory(array $input):array
    {
        $id=self::requiredUuid($input,'officer_id');$geo=self::geo('q.asc_location_id');$from="(SELECT CONCAT('D:',w.id) row_key,'DIVISION' entity,r.officer_id,r.asc_location_id,r.request_type,w.action,w.stage,w.previous_status,w.new_status,w.comments,u.username,w.action_at FROM arpa_appointment_workflow_action w JOIN arpa_division_appointment_request r ON r.id=w.request_id JOIN system_user u ON u.id=w.user_id UNION ALL SELECT CONCAT('S:',w.id),'SUBJECT',r.officer_id,r.asc_location_id,r.request_type,w.action,w.stage,w.previous_status,w.new_status,w.comments,u.username,w.action_at FROM arpa_subject_workflow_action w JOIN arpa_subject_assignment_request r ON r.id=w.request_id JOIN system_user u ON u.id=w.user_id) q";
        return ['permission'=>'arpa.appointment.view','export'=>false,'with'=>$geo['with'],'from'=>$from.' '.$geo['joinExpression'],'select'=>['q.row_key','q.entity','q.request_type','q.action','q.stage','q.previous_status','q.new_status','q.comments','q.username','q.action_at'],'count'=>'q.row_key','baseWhere'=>['q.officer_id=?'],'baseParams'=>array_merge($geo['params'],[$id]),'searchable'=>['q.entity','q.request_type','q.action','q.stage','q.previous_status','q.new_status','q.comments','q.username'],'columns'=>[self::col('Area','entity','q.entity',fn($r)=>DataTableFormat::text($r['entity'])),self::col('Request','request_type','q.request_type',fn($r)=>DataTableFormat::text($r['request_type'])),self::col('Action','action','q.action',fn($r)=>DataTableFormat::badge($r['action'])),self::col('Stage','stage','q.stage',fn($r)=>DataTableFormat::text($r['stage'])),self::col('Previous','previous_status','q.previous_status',fn($r)=>DataTableFormat::badge($r['previous_status'])),self::col('New','new_status','q.new_status',fn($r)=>DataTableFormat::badge($r['new_status'])),self::col('User','username','q.username',fn($r)=>DataTableFormat::text($r['username'])),self::col('Timestamp','action_at','q.action_at',fn($r)=>DataTableFormat::dateTime($r['action_at'])),self::col('Comments','comments','q.comments',fn($r)=>DataTableFormat::text($r['comments']))],'defaultOrder'=>[7,'DESC']];
    }

    private static function subjects():array
    {
        return ['permission'=>'subject.master.view','export'=>true,'filename'=>'central-subject-master','from'=>'subject_master s','select'=>['s.id','s.dad_number','s.system_key','s.name_en','s.name_si','s.name_ta','s.subject_kind','s.active','s.approval_status','s.effective_from','s.effective_to'],'count'=>'s.id','searchable'=>['s.dad_number','s.system_key','s.name_en','s.name_si','s.name_ta','s.subject_kind'],'filters'=>['subject_kind'=>['column'=>'s.subject_kind','allowed'=>['NORMAL','AGRARIAN_BANK','SALES_SHOP','SITHAMU'],'ui'=>['label'=>'Subject Kind','options'=>['NORMAL'=>'Normal','AGRARIAN_BANK'=>'Agrarian Bank','SALES_SHOP'=>'Sales Shop','SITHAMU'=>'Sithamu']]],'active'=>['column'=>'s.active','allowed'=>['0','1'],'ui'=>['label'=>'Status','options'=>['1'=>'Active','0'=>'Inactive']]]],'columns'=>[self::col('DAD / Business Number','dad_number','s.dad_number',fn($r)=>DataTableFormat::text($r['dad_number'],'Not assigned')),self::col('Subject','name_en','s.name_en',fn($r)=>DataTableFormat::text($r['name_en'])),self::col('System Key','system_key','s.system_key',fn($r)=>'<code>'.e($r['system_key']).'</code>'),self::col('Kind','subject_kind','s.subject_kind',fn($r)=>DataTableFormat::badge($r['subject_kind'])),self::col('Status','active','s.active',fn($r)=>DataTableFormat::badge($r['active']?'ACTIVE':'INACTIVE')),self::col('Start Date','effective_from','s.effective_from',fn($r)=>DataTableFormat::date($r['effective_from'])),self::actionColumn(fn($r)=>Auth::can('subject.master.edit')?'<a class="btn btn-sm btn-outline-primary" href="'.e(url('subjects/'.$r['id'].'/edit')).'">Edit</a>':'')],'defaultOrder'=>[1,'ASC']];
    }

    private static function legacyArpaReview(string $itemType): array
    {
        $filters=[
            'officer'=>['column'=>"CONCAT_WS(' ',o.dad_number,o.nic,o.name_with_initials,o.full_name_en)",'operator'=>'LIKE','ui'=>['label'=>'Officer','type'=>'text','placeholder'=>'DAD, NIC or name']],
            'asc'=>['column'=>"CONCAT_WS(' ',ca.dad_number,ca.name_en)",'operator'=>'LIKE','ui'=>['label'=>'ASC','type'=>'text','placeholder'=>'DAD number or name']],
            'subject_kind'=>['column'=>'i.subject_kind','allowed'=>['AGRARIAN_BANK','SALES_SHOP','SITHAMU'],'ui'=>['label'=>'Subject / Function']],
            'appointment_type'=>['column'=>'i.appointment_type','allowed'=>['PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY'],'ui'=>['label'=>'Appointment Type','options'=>['PERMANENT'=>'Permanent','ACTING'=>'Acting','DUTY_COVERING'=>'Duty Covering','ATTEND_TO_DUTY'=>'Attend to Duty']]],
            'current_classification'=>['column'=>'i.current_classification','allowed'=>['CURRENT','HISTORICAL'],'ui'=>['label'=>'Current / Historical']],
            'source_confidence'=>['column'=>'i.source_confidence','allowed'=>['EXACT','STRONG_DERIVED','CURRENT_STATE_ONLY','UNRESOLVED','MISSING'],'ui'=>['label'=>'Confidence']],
            'resolution_status'=>['allowed'=>['PENDING','CONFIRMED','UNRESOLVED_HISTORICAL','REQUIRES_FURTHER_REVIEW'],'build'=>fn($v)=>$v==='PENDING'?['r.id IS NULL',[]]:['r.resolution_status=?',[$v]],'ui'=>['label'=>'Resolution Status']],
            'source_table'=>['column'=>'i.primary_source_table','allowed'=>['tbl_officer_apoint','tbl_officer_apoint_2026'],'ui'=>['label'=>'Source']],
            'issue_type'=>['pattern'=>'/^[A-Z_]{2,80}$/','build'=>fn($v)=>['JSON_CONTAINS(i.issue_types_json,JSON_QUOTE(?))',[$v]],'ui'=>['label'=>'Issue Type','type'=>'text','placeholder'=>'e.g. DEPENDENT_WITHOUT_PERMANENT']],
        ];
        $columns=[
            self::col('Source','source_reference','i.primary_source_table',fn($r)=>'<code>'.e($r['primary_source_table'].':'.$r['primary_source_record_id']).'</code>',fn($r)=>$r['primary_source_table'].':'.$r['primary_source_record_id']),
            self::col('Officer','officer_name','o.name_with_initials',fn($r)=>'<strong>'.e($r['officer_number']).'</strong><br>'.DataTableFormat::text($r['officer_name'])),
            self::col('Issue / Type','issue_display','i.subject_kind',fn($r)=>DataTableFormat::badge($r['subject_kind']?:$r['appointment_type']).'<div class="small text-muted mt-1">'.e(implode(', ',json_decode((string)$r['issue_types_json'],true)?:[])).'</div>'),
            self::col('Effective','effective_from','i.effective_from',fn($r)=>DataTableFormat::date($r['effective_from']).($r['effective_to']?'<br><span class="small text-muted">to '.e($r['effective_to']).'</span>':'')),
            self::col('State','current_classification','i.current_classification',fn($r)=>DataTableFormat::badge($r['current_classification'])),
            self::col('Confidence','source_confidence','i.source_confidence',fn($r)=>DataTableFormat::badge($r['source_confidence'])),
            self::col('Candidate ASC','candidate_asc','ca.name_en',fn($r)=>DataTableFormat::text(trim((string)$r['candidate_asc_number'].' '.(string)$r['candidate_asc_name']),'No candidate')),
            self::col('Resolution','resolution_status','r.resolution_status',fn($r)=>DataTableFormat::badge($r['resolution_status']?:'PENDING')),
            self::actionColumn(fn($r)=>'<a class="btn btn-sm btn-outline-primary" href="'.e(url('hr/arpa-appointments/legacy-review/items/'.$r['id'])).'">Review</a>'),
        ];
        return ['permission'=>'arpa.legacy-reconciliation.view','export'=>true,'filename'=>'legacy-arpa-'.strtolower(str_replace('_','-',$itemType)),'from'=>'legacy_arpa_reconciliation_item i JOIN officer o ON o.id=i.officer_id LEFT JOIN location ca ON ca.id=i.candidate_asc_id LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id','select'=>['i.id','i.primary_source_table','i.primary_source_record_id','i.issue_types_json','i.subject_kind','i.appointment_type','i.effective_from','i.effective_to','i.current_classification','i.source_confidence','i.diagnostic_blocker','o.dad_number officer_number','o.name_with_initials officer_name','ca.dad_number candidate_asc_number','ca.name_en candidate_asc_name','r.resolution_status'],'count'=>'i.id','baseWhere'=>['i.active=1','i.item_type=?'],'baseParams'=>[$itemType],'searchable'=>['i.primary_source_table','i.primary_source_record_id','i.legacy_officer_id','o.dad_number','o.nic','o.name_with_initials','o.full_name_en','i.subject_kind','i.appointment_type','ca.dad_number','ca.name_en','i.source_confidence','r.resolution_status'],'filters'=>$filters,'columns'=>$columns,'defaultOrder'=>[4,'DESC']];
    }

    private static function legacyArpaSpecialGroups(): array
    {
        $groupKey="CONCAT(i.officer_id,'|',i.subject_kind,'|',COALESCE(i.candidate_asc_id,'NONE'))";
        return [
            'permission'=>'arpa.legacy-reconciliation.view','export'=>true,'filename'=>'legacy-arpa-current-special-groups',
            'from'=>"legacy_arpa_reconciliation_item i JOIN legacy_arpa_appointment_preview p ON p.reconciled_business_key=i.reconciled_business_key AND p.active=1 AND p.assignment_category='ASC_FUNCTION' AND p.current_classification='CURRENT' JOIN officer o ON o.id=i.officer_id LEFT JOIN location ca ON ca.id=i.candidate_asc_id LEFT JOIN office co ON co.linked_location_id=i.candidate_asc_id LEFT JOIN legacy_arpa_appointment_resolution r ON r.reconciliation_item_id=i.id",
            'select'=>['MIN(i.id) id','i.officer_id','i.subject_kind','i.candidate_asc_id','o.dad_number officer_number','o.name_with_initials officer_name','o.nic','ca.dad_number candidate_asc_number','ca.name_en candidate_asc_name','co.dad_number candidate_office_number','co.name_en candidate_office_name','COUNT(*) record_count',"SUM(r.id IS NULL) pending_count",'MIN(i.source_confidence) evidence_class'],
            'count'=>$groupKey,'groupBy'=>'i.officer_id,i.subject_kind,i.candidate_asc_id,o.dad_number,o.name_with_initials,o.nic,ca.dad_number,ca.name_en,co.dad_number,co.name_en',
            'baseWhere'=>["i.active=1","i.item_type='SPECIAL_ASC'"],
            'searchable'=>['o.dad_number','o.name_with_initials','o.full_name_en','o.nic','i.subject_kind','ca.dad_number','ca.name_en','co.dad_number','co.name_en'],
            'filters'=>[
                'subject_kind'=>['column'=>'i.subject_kind','allowed'=>['AGRARIAN_BANK','SALES_SHOP','SITHAMU'],'ui'=>['label'=>'Subject / Function']],
                'source_confidence'=>['column'=>'i.source_confidence','allowed'=>['STRONG_DERIVED','CURRENT_STATE_ONLY','UNRESOLVED','MISSING'],'ui'=>['label'=>'Evidence Class']],
                'resolution_status'=>['allowed'=>['PENDING','CONFIRMED'],'build'=>fn($v)=>$v==='PENDING'?['r.id IS NULL',[]]:["r.resolution_status='CONFIRMED'",[]],'ui'=>['label'=>'Decision Status']],
                'candidate_status'=>['allowed'=>['HAS','NONE'],'build'=>fn($v)=>[$v==='HAS'?'i.candidate_asc_id IS NOT NULL':'i.candidate_asc_id IS NULL',[]],'ui'=>['label'=>'ASC Candidate','options'=>['HAS'=>'Has candidate','NONE'=>'No candidate']]],
            ],
            'columns'=>[
                self::col('Officer','officer_name','o.name_with_initials',fn($r)=>'<strong>'.e($r['officer_number']).'</strong><br>'.DataTableFormat::text($r['officer_name'])),
                self::col('NIC','nic','o.nic',fn($r)=>DataTableFormat::text($r['nic'])),
                self::col('Function','subject_kind','i.subject_kind',fn($r)=>DataTableFormat::badge($r['subject_kind'])),
                self::col('Candidate ASC','candidate_asc_name','ca.name_en',fn($r)=>DataTableFormat::text(trim((string)$r['candidate_asc_number'].' '.(string)$r['candidate_asc_name']),'No candidate')),
                self::col('ASC Office','candidate_office_name','co.name_en',fn($r)=>DataTableFormat::text(trim((string)$r['candidate_office_number'].' '.(string)$r['candidate_office_name']),'Missing')),
                self::col('Evidence','evidence_class','i.source_confidence',fn($r)=>DataTableFormat::badge($r['evidence_class'])),
                self::col('Records','record_count','record_count',fn($r)=>DataTableFormat::text((string)$r['record_count'])),
                self::col('Pending','pending_count',null,fn($r)=>DataTableFormat::text((string)$r['pending_count'])),
                self::actionColumn(fn($r)=>'<a class="btn btn-sm btn-outline-primary" href="'.e(url('hr/arpa-appointments/legacy-review/groups?'.http_build_query(['officer_id'=>$r['officer_id'],'function'=>$r['subject_kind'],'candidate_asc_id'=>$r['candidate_asc_id']]))).'">Review Group</a>'),
            ],
            'defaultOrder'=>[0,'ASC'],
        ];
    }

    private static function legacyArpaAppointmentPreview(): array
    {
        $geo=self::geo('COALESCE(sr.selected_target_asc_id,si.candidate_asc_id,mr.selected_target_asc_id,p.asc_location_id)');
        $assignmentLabels=['ARPA_DIVISION'=>'ARPA Division','AGRARIAN_BANK'=>'Agrarian Bank','SALES_SHOP'=>'Sales Shop','SITHAMU'=>'Sithamu'];
        $effectiveBlocker="(p.effective_from IS NULL OR (p.assignment_category='ARPA_DIVISION' AND COALESCE(mr.selected_target_arpa_id,p.arpa_location_id,CASE WHEN p.location_confidence IN ('EXACT','STRONG_DERIVED') THEN JSON_UNQUOTE(JSON_EXTRACT(p.location_provenance_json,'$.target_context_id')) END) IS NULL) OR (p.assignment_category='ASC_FUNCTION' AND si.candidate_asc_id IS NULL AND LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(si.candidate_evidence_json,'$.method')),'')) LIKE '%multiple%'))";
        $baselineClass="CASE WHEN p.effective_from IS NULL THEN 'DATE_REVIEW_REQUIRED' WHEN p.effective_from>='2025-01-01' THEN 'LEGACY_PERIOD' WHEN p.effective_to IS NULL OR p.effective_to>='2025-01-01' THEN 'PRE_BASELINE_CARRIED_FORWARD' ELSE 'PRE_BASELINE_HISTORY' END";
        $status=function(array $r):string{
            if(($r['activation_decision']??'')==='PRESERVE_HISTORY_ONLY')return 'PRESERVE HISTORY ONLY';
            if((int)$r['effective_blocker']===1)return 'MANUAL REVIEW REQUIRED';
            if((int)$r['historical_exception']===1||(int)$r['current_conflict']===1)return 'MIGRATABLE HISTORICAL EXCEPTION';
            return $r['legacy_operational_approval']?'READY FOR MIGRATION':'RECONCILED';
        };
        $filters=[
            'officer_id'=>['column'=>'p.officer_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'Officer','searchable'=>true]],
            'officer'=>['column'=>"CONCAT_WS(' ',o.dad_number,o.name_with_initials,o.full_name_en,o.nic)",'operator'=>'LIKE','ui'=>['label'=>'Officer Search','type'=>'text','placeholder'=>'DAD, name or NIC']],
            'province'=>['column'=>'p.province_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'Province','searchable'=>true]],
            'district'=>['column'=>'p.district_location_id','pattern'=>self::uuidPattern(),'ui'=>['label'=>'District','searchable'=>true]],
            'asc'=>['column'=>'COALESCE(sr.selected_target_asc_id,si.candidate_asc_id,mr.selected_target_asc_id,p.asc_location_id)','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ASC','searchable'=>true]],
            'arpa_division'=>['column'=>'COALESCE(mr.selected_target_arpa_id,p.arpa_location_id)','pattern'=>self::uuidPattern(),'ui'=>['label'=>'ARPA Division','searchable'=>true]],
            'assignment_category'=>['allowed'=>array_keys($assignmentLabels),'build'=>fn($v)=>$v==='ARPA_DIVISION'?["p.assignment_category='ARPA_DIVISION'",[]]:['p.subject_kind=?',[$v]],'ui'=>['label'=>'Assignment Category','options'=>$assignmentLabels]],
            'appointment_type'=>['column'=>'p.appointment_type','allowed'=>['PERMANENT','ACTING','DUTY_COVERING','ATTEND_TO_DUTY'],'ui'=>['label'=>'Appointment Type','options'=>['PERMANENT'=>'Permanent','ACTING'=>'Acting','DUTY_COVERING'=>'Duty Covering','ATTEND_TO_DUTY'=>'Attend to the Duty']]],
            'effective_from'=>['column'=>'p.effective_from','operator'=>'>=','date'=>true,'ui'=>['label'=>'Start Date','type'=>'date']],
            'effective_to'=>['column'=>'p.effective_from','operator'=>'<=','date'=>true,'ui'=>['label'=>'Effective Through','type'=>'date']],
            'period_status'=>['allowed'=>['OPEN','ENDED'],'build'=>fn($v)=>[$v==='OPEN'?'p.effective_to IS NULL':'p.effective_to IS NOT NULL',[]],'ui'=>['label'=>'Open / Ended','options'=>['OPEN'=>'Open','ENDED'=>'Ended']]],
            'current_classification'=>['column'=>'p.current_classification','allowed'=>['CURRENT','HISTORICAL'],'ui'=>['label'=>'Current / Historical','options'=>['CURRENT'=>'Current candidate','HISTORICAL'=>'Historical / ended']]],
            'baseline_classification'=>['column'=>$baselineClass,'allowed'=>['PRE_BASELINE_CARRIED_FORWARD','LEGACY_PERIOD','PRE_BASELINE_HISTORY','DATE_REVIEW_REQUIRED'],'ui'=>['label'=>'Baseline Period','options'=>['PRE_BASELINE_CARRIED_FORWARD'=>'Pre-2025 Carried Forward','LEGACY_PERIOD'=>'2025+ Legacy','PRE_BASELINE_HISTORY'=>'Pre-2025 History','DATE_REVIEW_REQUIRED'=>'Date Review Required']]],
            'workflow_state'=>['column'=>'p.workflow_state','pattern'=>'/^[A-Z_]{2,80}$/','ui'=>['label'=>'Workflow State']],
            'source_scope'=>['column'=>'p.source_scope','allowed'=>['OLD_ONLY','2026_ONLY','BOTH'],'ui'=>['label'=>'Source','options'=>['OLD_ONLY'=>'Old source only','2026_ONLY'=>'2026 source only','BOTH'=>'Both old + 2026']]],
            'blocker'=>['allowed'=>['0','1'],'build'=>fn($v)=>[$v==='1'?$effectiveBlocker:'NOT '.$effectiveBlocker,[]],'ui'=>['label'=>'Migration Blocker','options'=>['1'=>'Has blocker','0'=>'No blocker']]],
            'historical_exception'=>['column'=>'p.historical_exception','allowed'=>['0','1'],'ui'=>['label'=>'Historical Exception','options'=>['1'=>'Has exception','0'=>'No exception']]],
            'current_conflict'=>['column'=>'p.current_conflict','allowed'=>['0','1'],'ui'=>['label'=>'Current Conflict','options'=>['1'=>'Has conflict','0'=>'No conflict']]],
            'asc_confidence'=>['column'=>'p.location_confidence','allowed'=>['EXACT','STRONG_DERIVED','CURRENT_STATE_ONLY','UNRESOLVED','MISSING','INVALID','MISSING_TARGET_MAPPING'],'ui'=>['label'=>'ASC Confidence','options'=>['EXACT'=>'Exact','STRONG_DERIVED'=>'Strong Derived','CURRENT_STATE_ONLY'=>'Current State Only','UNRESOLVED'=>'Unresolved','MISSING'=>'Missing','INVALID'=>'Invalid','MISSING_TARGET_MAPPING'=>'Missing Target Mapping']]],
            'reconciliation_status'=>['allowed'=>['PENDING','CONFIRMED','UNRESOLVED_HISTORICAL','REQUIRES_FURTHER_REVIEW'],'build'=>fn($v)=>$v==='PENDING'?['COALESCE(sr.resolution_status,mr.resolution_status,cr.resolution_status) IS NULL',[]]:['COALESCE(sr.resolution_status,mr.resolution_status,cr.resolution_status)=?',[$v]],'ui'=>['label'=>'Reconciliation Status']],
        ];
        $from="legacy_arpa_appointment_preview p JOIN officer o ON o.id=p.officer_id LEFT JOIN location a0 ON a0.id=p.asc_location_id LEFT JOIN location ar0 ON ar0.id=p.arpa_location_id LEFT JOIN legacy_arpa_reconciliation_item si ON si.reconciled_business_key=p.reconciled_business_key AND si.item_type='SPECIAL_ASC' AND si.active=1 LEFT JOIN legacy_arpa_appointment_resolution sr ON sr.reconciliation_item_id=si.id LEFT JOIN legacy_arpa_reconciliation_item mi ON mi.reconciled_business_key=p.reconciled_business_key AND mi.item_type='MISSING_ARPA_LOCATION' AND mi.active=1 LEFT JOIN legacy_arpa_appointment_resolution mr ON mr.reconciliation_item_id=mi.id LEFT JOIN legacy_arpa_reconciliation_item ci ON ci.reconciled_business_key=p.reconciled_business_key AND ci.item_type='CURRENT_CONFLICT' AND ci.active=1 LEFT JOIN legacy_arpa_appointment_resolution cr ON cr.reconciliation_item_id=ci.id LEFT JOIN location a ON a.id=COALESCE(sr.selected_target_asc_id,si.candidate_asc_id,mr.selected_target_asc_id,p.asc_location_id) LEFT JOIN location ar ON ar.id=COALESCE(mr.selected_target_arpa_id,p.arpa_location_id,CASE WHEN p.location_confidence IN ('EXACT','STRONG_DERIVED') THEN JSON_UNQUOTE(JSON_EXTRACT(p.location_provenance_json,'$.target_context_id')) END)";
        return ['permission'=>'arpa.legacy-preview.view','export'=>true,'filename'=>'legacy-arpa-appointment-preview','with'=>$geo['with'],'from'=>$from.' '.$geo['joinExpression'],'select'=>['p.*','o.dad_number officer_number','o.name_with_initials officer_name','o.nic','a.dad_number asc_number','a.name_en asc_name','ar.dad_number arpa_number','ar.name_en arpa_name','si.source_confidence candidate_evidence_class','COALESCE(sr.resolution_status,mr.resolution_status,cr.resolution_status) resolution_status','COALESCE(sr.selected_target_asc_id,si.candidate_asc_id,mr.selected_target_asc_id) confirmed_asc_id','cr.activation_decision',"{$baselineClass} baseline_classification","{$effectiveBlocker} effective_blocker"],'count'=>'p.reconciled_business_key','baseWhere'=>['p.active=1'],'baseParams'=>$geo['params'],'searchable'=>['o.dad_number','o.name_with_initials','o.full_name_en','o.nic','a.dad_number','a.name_en','ar.dad_number','ar.name_en','p.appointment_type','p.subject_kind','p.workflow_state','p.legacy_reason_text'],'filters'=>$filters,'columns'=>[
            self::col('Officer DAD Number','officer_number','o.dad_number',fn($r)=>'<strong>'.e($r['officer_number']).'</strong>'),
            self::col('Officer Name','officer_name','o.name_with_initials',fn($r)=>DataTableFormat::text($r['officer_name'])),
            self::col('NIC','nic','o.nic',fn($r)=>DataTableFormat::text($r['nic'])),
            self::col('Service Permanency','service_permanency_snapshot','p.service_permanency_snapshot',fn($r)=>DataTableFormat::enumText($r['service_permanency_snapshot'],'Unknown'),fn($r)=>DataTableFormat::enumLabel($r['service_permanency_snapshot'],'Unknown')),
            self::col('Assignment','assignment_display','p.assignment_category',fn($r)=>DataTableFormat::badge($r['assignment_category']==='ARPA_DIVISION'?$r['appointment_type']:$r['subject_kind']),fn($r)=>$r['assignment_category']==='ARPA_DIVISION'?$r['appointment_type']:$r['subject_kind']),
            self::col('ASC','asc_name','a.name_en',fn($r)=>DataTableFormat::text(trim((string)$r['asc_number'].' '.(string)$r['asc_name']),'Unresolved')),
            self::col('ARPA Division / Subject','assignment_context','ar.name_en',fn($r)=>DataTableFormat::text($r['assignment_category']==='ARPA_DIVISION'?trim((string)$r['arpa_number'].' '.(string)$r['arpa_name']):DataTableFormat::enumLabel($r['subject_kind']))),
            self::col('Start Date','effective_from','p.effective_from',fn($r)=>DataTableFormat::date($r['effective_from'])),
            self::col('End Date','effective_to','p.effective_to',fn($r)=>DataTableFormat::date($r['effective_to'],'Current')),
            self::col('Baseline Period','baseline_classification',$baselineClass,fn($r)=>DataTableFormat::badge(match($r['baseline_classification']){'PRE_BASELINE_CARRIED_FORWARD'=>'PRE-2025 CARRIED FORWARD','LEGACY_PERIOD'=>'2025+ LEGACY','PRE_BASELINE_HISTORY'=>'PRE-2025 HISTORY',default=>'DATE REVIEW REQUIRED'})),
            self::col('End Reason','legacy_reason_text','p.legacy_reason_text',fn($r)=>DataTableFormat::text($r['legacy_reason_text'],'None recorded')),
            self::col('Workflow','workflow_state','p.workflow_state',fn($r)=>DataTableFormat::badge($r['workflow_state'])),
            self::col('Current / Historical','current_classification','p.current_classification',fn($r)=>DataTableFormat::badge($r['current_classification'])),
            self::col('Source','source_scope','p.source_scope',fn($r)=>DataTableFormat::badge($r['source_scope'])),
            self::col('Migration Status','migration_status','p.diagnostic_blocker',fn($r)=>DataTableFormat::badge($status($r)),fn($r)=>$status($r)),
            self::col('Blocker / Exception','blocker_exception','p.historical_exception',fn($r)=>DataTableFormat::text(implode(', ',array_merge(json_decode((string)$r['blocker_types_json'],true)?:[],json_decode((string)$r['historical_exception_types_json'],true)?:[])),'None'),fn($r)=>implode(', ',array_merge(json_decode((string)$r['blocker_types_json'],true)?:[],json_decode((string)$r['historical_exception_types_json'],true)?:[]))),
            self::col('ASC Confidence','location_confidence','p.location_confidence',fn($r)=>($r['resolution_status']==='CONFIRMED'?'<span class="badge bg-success">CONFIRMED</span><br><span class="small text-muted">source confidence: '.e($r['location_confidence']).'</span>':DataTableFormat::badge($r['location_confidence']))),
            self::col('Source References','source_references','p.source_scope',fn($r)=>DataTableFormat::text(implode(' | ',json_decode((string)$r['source_references_json'],true)?:[])),fn($r)=>implode(' | ',json_decode((string)$r['source_references_json'],true)?:[])),
            self::actionColumn(fn($r)=>'<a class="btn btn-sm btn-outline-primary" href="'.e(url('hr/arpa-appointments/legacy-preview/'.$r['reconciled_business_key'])).'">View Details</a>'),
        ],'defaultOrder'=>[7,'DESC']];
    }

    private static function hrMasters(array $input): array
    {
        $type = strtolower(trim((string)($input['master_type'] ?? '')));
        $master = self::HR_MASTERS[$type] ?? null;
        if ($master === null) {
            throw new RuntimeException('Unknown HR master.');
        }
        $table = $master['table'];
        $select = ['m.id', 'm.dad_number', 'm.name_en', 'm.name_si', 'm.name_ta', 'm.system_key', 'm.display_order', 'm.active', 'm.effective_from', 'm.approval_status', 'm.created_by'];
        $columns = [
            self::col('DAD Number', 'dad_number', 'm.dad_number', fn($r) => DataTableFormat::text($r['dad_number'])),
            self::col('English Name', 'name_en', 'm.name_en', fn($r) => DataTableFormat::text($r['name_en'])),
            self::col('System Key', 'system_key', 'm.system_key', fn($r) => '<code>' . e($r['system_key']) . '</code>'),
        ];
        if ($type === 'appointment-natures') {
            $select[] = 'm.class_required';
            $columns[] = self::col('Class Required', 'class_required', 'm.class_required', fn($r) => DataTableFormat::yesNo($r['class_required']), fn($r) => $r['class_required'] ? 'Yes' : 'No');
        }
        if ($type === 'designations') {
            $select[] = 'm.designation_level';
            $columns[] = self::col('Level', 'designation_level', 'm.designation_level', fn($r) => DataTableFormat::text($r['designation_level']));
        }
        $displayOrderIndex = count($columns);
        array_push(
            $columns,
            self::col('Display Order', 'display_order', 'm.display_order'),
            self::col('Active', 'active', 'm.active', fn($r) => DataTableFormat::badge($r['active'] ? 'ACTIVE' : 'INACTIVE'), fn($r) => $r['active'] ? 'ACTIVE' : 'INACTIVE'),
            self::col('Start Date', 'effective_from', 'm.effective_from', fn($r) => DataTableFormat::date($r['effective_from'])),
            self::col('Approval', 'approval_status', 'm.approval_status', fn($r) => DataTableFormat::badge($r['approval_status'])),
            self::actionColumn(fn($r) => self::hrActions($type, $r))
        );

        return [
            'permission' => 'hr.master.view', 'export' => true, 'filename' => 'hr-' . $type,
            'from' => $table . ' m', 'select' => $select, 'count' => 'm.id',
            'searchable' => ['m.dad_number', 'm.name_en', 'm.name_si', 'm.name_ta', 'm.system_key'],
            'filters' => [
                'active' => ['column' => 'm.active', 'allowed' => ['0', '1'], 'ui' => ['label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Inactive']]],
                'approval_status' => ['column' => 'm.approval_status', 'allowed' => self::workflowStatuses(), 'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()]],
            ],
            'columns' => $columns, 'defaultOrder' => [$displayOrderIndex, 'ASC'],
        ];
    }

    private static function users(): array
    {
        $actor = Auth::user();
        $visibility = $actor
            ? (new UserAccessManagementService(Database::pdo()))->activeUserVisibility((string)$actor['id'])
            : ['with' => '', 'where' => '1=0', 'params' => []];
        $currentRole="uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE()) AND r.active=1 AND r.approval_status='APPROVED'";
        $effectiveRoles="(SELECT GROUP_CONCAT(r.role_name ORDER BY r.role_name,uar.effective_from,uar.id SEPARATOR '|||') FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=su.id AND {$currentRole})";
        $effectiveDates="(SELECT GROUP_CONCAT(DATE_FORMAT(uar.effective_from,'%d %b %Y') ORDER BY r.role_name,uar.effective_from,uar.id SEPARATOR '|||') FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=su.id AND {$currentRole})";
        $effectiveAssignments="(SELECT GROUP_CONCAT(CONCAT(uar.id,':::',REPLACE(r.role_name,'|||',' ')) ORDER BY r.role_name,uar.effective_from,uar.id SEPARATOR '|||') FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=su.id AND {$currentRole})";
        $effectiveScopes="(SELECT GROUP_CONCAT(DISTINCT CONCAT(uas.scope_type,' / ',uas.scope_mode,COALESCE(CONCAT(' / ',sl.dad_number),CONCAT(' / ',so.dad_number),'')) ORDER BY uas.scope_type SEPARATOR '; ') FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id LEFT JOIN location sl ON sl.id=uas.location_id LEFT JOIN office so ON so.id=uas.office_id WHERE uar.user_id=su.id AND uar.active=1 AND uar.approval_status='APPROVED' AND uar.effective_from<=CURRENT_DATE() AND (uar.effective_to IS NULL OR uar.effective_to>=CURRENT_DATE()) AND r.active=1 AND r.approval_status='APPROVED' AND uas.active=1 AND uas.approval_status='APPROVED' AND uas.effective_from<=CURRENT_DATE() AND (uas.effective_to IS NULL OR uas.effective_to>=CURRENT_DATE()))";
        $stacked=static function(mixed $value,string $empty='None'):string{$items=array_values(array_filter(explode('|||',trim((string)$value)),static fn(string $item):bool=>$item!==''));return $items===[]?'<span class="text-muted">'.e($empty).'</span>':implode('',array_map(static fn(string $item):string=>'<div class="text-nowrap">'.e($item).'</div>',$items));};
        $editActions=static function(array $row):string{
            if(!Auth::can('user.assign-role'))return '';
            $links=[];
            foreach(array_filter(explode('|||',(string)($row['effective_role_assignments']??''))) as $entry){
                [$id,$roleName]=array_pad(explode(':::',$entry,2),2,'');
                if($id===''||$roleName==='')continue;
                $links[]='<a class="dropdown-item" href="'.e(url('access-management/role-assignments/'.$id.'/effective-from/edit')).'"><i class="bi bi-calendar-event me-2"></i>'.e($roleName).'</a>';
            }
            if($links===[])return '';
            if(count($links)===1)return '<a class="btn btn-sm btn-outline-primary" href="'.e(url('access-management/role-assignments/'.explode(':::',explode('|||',(string)$row['effective_role_assignments'])[0],2)[0].'/effective-from/edit')).'"><i class="bi bi-calendar-event"></i> Edit Effective Date</a>';
            return '<div class="dropdown"><button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-calendar-event"></i> Edit Effective Date</button><ul class="dropdown-menu">'.implode('',array_map(static fn(string $link):string=>'<li>'.$link.'</li>',$links)).'</ul></div>';
        };
        return [
            'permission' => 'user.view', 'export' => true, 'filename' => 'active-user-accounts',
            'with' => $visibility['with'],
            'from' => '`system_user` su LEFT JOIN officer o ON o.id=su.officer_id LEFT JOIN designation d ON d.id=o.primary_designation_id',
            'select' => ['su.id','su.username','su.display_name','su.email','su.identity_type','su.account_status','su.approval_status','su.mfa_method','su.mfa_enrolled','su.enabled','su.password_setup_required','su.password_changed_at','su.created_at','o.dad_number AS officer_number','o.nic','o.name_with_initials','d.name_en AS designation_name',$effectiveRoles.' AS effective_roles',$effectiveDates.' AS effective_from_dates',$effectiveAssignments.' AS effective_role_assignments',$effectiveScopes.' AS effective_scopes'],
            'count' => 'su.id',
            'baseWhere'=>["su.identity_type<>'HISTORICAL'",'su.enabled=1',"su.account_status='ACTIVE'", "su.approval_status='APPROVED'",$visibility['where']],
            'baseParams' => $visibility['params'],
            'searchable' => ['su.username','su.display_name','su.email','su.identity_type', 'su.account_status', 'o.dad_number', 'o.nic', 'o.name_with_initials','d.name_en',$effectiveRoles,$effectiveDates],
            'filters' => [
                'account_status' => ['column' => 'su.account_status', 'pattern' => '/^[A-Z_]{1,50}$/', 'ui' => ['label' => 'Account Status']],
                'identity_type'=>['column'=>'su.identity_type','allowed'=>['STAFF','FARMER'],'ui'=>['label'=>'Identity Type','options'=>['STAFF'=>'Staff','FARMER'=>'Farmer']]],
                'mfa_method' => ['column' => 'su.mfa_method', 'pattern' => '/^[A-Z0-9_]{1,50}$/', 'ui' => ['label' => 'MFA Method']],
                'enabled' => ['column' => 'su.enabled', 'allowed' => ['0', '1'], 'ui' => ['label' => 'Enabled', 'options' => ['1' => 'Enabled', '0' => 'Disabled']]],
            ],
            'columns' => [
                self::col('Username', 'username', 'su.username', fn($r) => '<strong>' . e($r['username']) . '</strong>'),
                self::col('Display Name','display_name','su.display_name',fn($r)=>DataTableFormat::text($r['display_name'])),
                self::col('NIC','nic','o.nic',fn($r)=>DataTableFormat::text($r['nic'],'-')),
                self::col('Designation','designation_name','d.name_en',fn($r)=>DataTableFormat::text($r['designation_name'],'-')),
                self::col('Identity Type','identity_type','su.identity_type',fn($r)=>DataTableFormat::badge($r['identity_type'])),
                self::col('Account Status', 'account_status', 'su.account_status', fn($r) => DataTableFormat::badge($r['account_status'])),
                self::col('Enabled', 'enabled', 'su.enabled', fn($r) => DataTableFormat::badge($r['enabled'] ? 'ENABLED' : 'DISABLED'), fn($r) => $r['enabled'] ? 'ENABLED' : 'DISABLED'),
                self::col('Role(s)','effective_roles',$effectiveRoles,fn($r)=>$stacked($r['effective_roles']),fn($r)=>str_replace('|||','; ',(string)$r['effective_roles'])),
                self::col('Effective From','effective_from_dates',$effectiveDates,fn($r)=>$stacked($r['effective_from_dates'],'Not recorded'),fn($r)=>str_replace('|||','; ',(string)$r['effective_from_dates'])),
                self::col('Assigned Locations','effective_scopes',$effectiveScopes,fn($r)=>DataTableFormat::accessLocations($r['effective_scopes'])),
                self::col('Password Setup','password_setup_required','su.password_setup_required',fn($r)=>DataTableFormat::badge($r['password_setup_required']?'REQUIRED':'COMPLETE'),fn($r)=>$r['password_setup_required']?'REQUIRED':'COMPLETE'),
                self::col('Last Password Changed','password_changed_at','su.password_changed_at',fn($r)=>DataTableFormat::dateTime($r['password_changed_at'],'Not recorded')),
                self::actionColumn(function($r)use($editActions):string{
                    $actions=[];
                    $edit=$editActions($r);if($edit!=='')$actions[]=$edit;
                    if(Auth::can('user.reset-password')&&(int)$r['enabled']===1)$actions[]='<a class="btn btn-sm btn-outline-primary" href="'.e(url('access-management/users/'.$r['id'].'/reset-password')).'"><i class="bi bi-key"></i> Reset Password</a>';
                    if(Auth::can('user.block')&&(int)$r['enabled']===1)$actions[]='<a class="btn btn-sm btn-outline-danger" href="'.e(url('access-management/users/'.$r['id'].'/deactivate')).'">Deactivate</a>';
                    return '<div class="d-flex gap-1 flex-nowrap">'.implode('',$actions).'</div>';
                }),
            ],
            'defaultOrder' => [0, 'ASC'],
        ];
    }

    private static function historicalUsers(): array
    {
        $actor=Auth::user();
        $visibility=$actor
            ?(new UserAccessManagementService(Database::pdo()))->inactiveUserVisibility((string)$actor['id'])
            :['with'=>'','where'=>'1=0','params'=>[]];
        $latestRole="uar.approval_status='APPROVED' AND r.approval_status='APPROVED' AND NOT EXISTS (SELECT 1 FROM user_account_role newer WHERE newer.user_id=uar.user_id AND newer.approval_status='APPROVED' AND COALESCE(newer.effective_to,'9999-12-31')>COALESCE(uar.effective_to,'9999-12-31'))";
        $lastRoles="(SELECT GROUP_CONCAT(r.role_name ORDER BY r.role_name,uar.effective_from,uar.id SEPARATOR '|||') FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=su.id AND {$latestRole})";
        $lastDates="(SELECT GROUP_CONCAT(DATE_FORMAT(uar.effective_from,'%d %b %Y') ORDER BY r.role_name,uar.effective_from,uar.id SEPARATOR '|||') FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=su.id AND {$latestRole})";
        $lastScopes="(SELECT GROUP_CONCAT(DISTINCT COALESCE(CONCAT(l.name_en,IF(l.dad_number IS NULL,'',CONCAT(' (',l.dad_number,')'))),'National Level') ORDER BY l.name_en SEPARATOR '; ') FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id JOIN user_account_scope uas ON uas.role_assignment_id=uar.id AND uas.user_id=uar.user_id LEFT JOIN location l ON l.id=uas.location_id WHERE uar.user_id=su.id AND {$latestRole} AND uas.approval_status='APPROVED' AND NOT EXISTS (SELECT 1 FROM user_account_scope newer_scope WHERE newer_scope.role_assignment_id=uas.role_assignment_id AND newer_scope.user_id=uas.user_id AND newer_scope.approval_status='APPROVED' AND COALESCE(newer_scope.effective_to,'9999-12-31')>COALESCE(uas.effective_to,'9999-12-31')))";
        $deactivatedAt="(SELECT MAX(uoae.acted_at) FROM user_operational_access_event uoae WHERE uoae.user_id=su.id AND uoae.event_type='DEACTIVATE')";
        $stacked=static function(mixed $value,string $empty='Not recorded'):string{$items=array_values(array_filter(explode('|||',trim((string)$value)),static fn(string $item):bool=>$item!==''));return $items===[]?'<span class="text-muted">'.e($empty).'</span>':implode('',array_map(static fn(string $item):string=>'<div class="text-nowrap">'.e($item).'</div>',$items));};
        return [
            'permission'=>'user.view','export'=>true,'filename'=>'inactive-user-accounts',
            'with'=>$visibility['with'],
            'from'=>"system_user su LEFT JOIN legacy_user_reference lur ON lur.system_user_id=su.id LEFT JOIN officer o ON o.id=su.officer_id LEFT JOIN designation d ON d.id=o.primary_designation_id LEFT JOIN (SELECT lr.system_user_id,GROUP_CONCAT(DISTINCT CONCAT(c.legacy_level_key,': ',COALESCE(l.dad_number,c.legacy_location_id,'Unresolved'),' ',COALESCE(l.name_en,'')) ORDER BY c.legacy_level_key SEPARATOR ' | ') organization_context FROM legacy_user_reference lr JOIN legacy_user_organization_context c ON c.legacy_user_reference_id=lr.id LEFT JOIN location l ON l.id=c.location_id GROUP BY lr.system_user_id) ctx ON ctx.system_user_id=su.id",
            'select'=>['su.id','su.username','su.display_name','su.identity_type','su.historical_identity','su.account_status','su.approval_status','su.enabled','su.updated_at','lur.legacy_user_id','lur.legacy_username','lur.legacy_nic','lur.legacy_role_name','ctx.organization_context','o.dad_number officer_number','o.nic','o.name_with_initials officer_name','d.name_en designation_name',$lastRoles.' last_roles',$lastDates.' effective_from_dates',$lastScopes.' last_scopes',$deactivatedAt.' deactivated_at'],
            'count'=>'su.id','baseWhere'=>["su.approval_status='APPROVED'","(su.account_status='DISABLED' OR (su.enabled=0 AND su.identity_type IN ('STAFF','HISTORICAL')))",$visibility['where']],
            'baseParams'=>$visibility['params'],
            'searchable'=>['su.username','su.display_name','su.identity_type','su.account_status','lur.legacy_user_id','lur.legacy_nic','lur.legacy_role_name','ctx.organization_context','o.dad_number','o.nic','o.name_with_initials','d.name_en',$lastRoles,$lastScopes,$lastDates],
            'filters'=>[
                'identity_type'=>['column'=>'su.identity_type','allowed'=>['STAFF','FARMER','HISTORICAL'],'ui'=>['label'=>'Identity Type','options'=>['STAFF'=>'Staff','FARMER'=>'Farmer','HISTORICAL'=>'Historical']]],
                'account_status'=>['column'=>'su.account_status','pattern'=>'/^[A-Z_]{1,50}$/','ui'=>['label'=>'Account Status']],
                'organization'=>['column'=>'ctx.organization_context','operator'=>'LIKE','ui'=>['label'=>'Organization Context','type'=>'text','placeholder'=>'ASC, District, location']],
            ],
            'columns'=>[
                self::col('Username','username','su.username',fn($r)=>'<strong>'.e($r['username']).'</strong>'),
                self::col('Display Name','display_name','su.display_name',fn($r)=>DataTableFormat::text($r['display_name'])),
                self::col('NIC','nic','o.nic',fn($r)=>DataTableFormat::text($r['nic'],'-')),
                self::col('Designation','designation_name','d.name_en',fn($r)=>DataTableFormat::text($r['designation_name'],'-')),
                self::col('Identity Type','identity_type','su.identity_type',fn($r)=>DataTableFormat::badge($r['identity_type'])),
                self::col('Account Status','account_status','su.account_status',fn($r)=>DataTableFormat::badge($r['account_status'])),
                self::col('Enabled','enabled','su.enabled',fn($r)=>DataTableFormat::badge($r['enabled']?'ENABLED':'DISABLED'),fn($r)=>$r['enabled']?'ENABLED':'DISABLED'),
                self::col('Last Role(s)','last_roles',$lastRoles,fn($r)=>$stacked($r['last_roles']?:$r['legacy_role_name'],'Not recorded'),fn($r)=>str_replace('|||','; ',(string)($r['last_roles']?:$r['legacy_role_name']))),
                self::col('Last Assigned Location(s)','last_scopes',$lastScopes,fn($r)=>DataTableFormat::text($r['last_scopes']?:$r['organization_context'],'Not recorded')),
                self::col('Effective From','effective_from_dates',$lastDates,fn($r)=>$stacked($r['effective_from_dates']),fn($r)=>str_replace('|||','; ',(string)$r['effective_from_dates'])),
                self::col('Deactivated / Updated','deactivated_at',$deactivatedAt,fn($r)=>DataTableFormat::dateTime($r['deactivated_at']?:$r['updated_at'],'Not recorded'),fn($r)=>$r['deactivated_at']?:$r['updated_at']),
                self::actionColumn(fn($r)=>Auth::can('user.activate')&&(int)$r['historical_identity']===1?'<a class="btn btn-sm btn-primary" href="'.e(url('access-management/users/'.$r['id'].'/activate')).'">'.((string)$r['identity_type']==='HISTORICAL'?'Activate':'Reactivate').'</a>':''),
            ],'defaultOrder'=>[1,'ASC'],
        ];
    }

    private static function accountRequests(): array
    {
        $actor=Auth::user();
        $visibility=$actor
            ?(new UserAccountRequestService(Database::pdo()))->pendingVisibility((string)$actor['id'])
            :['with'=>'','where'=>'1=0','params'=>[]];
        $initialRole="(SELECT GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR '; ') FROM user_account_role uar JOIN application_role r ON r.id=uar.role_id WHERE uar.user_id=su.id AND uar.approval_status IN('SUBMITTED','APPROVED'))";
        $initialLocation="(SELECT GROUP_CONCAT(DISTINCT COALESCE(CONCAT(l.dad_number,' - ',l.name_en),'National Level') ORDER BY l.name_en SEPARATOR '; ') FROM user_account_scope uas LEFT JOIN location l ON l.id=uas.location_id WHERE uas.user_id=su.id AND uas.approval_status IN('SUBMITTED','APPROVED'))";
        $initialDate="(SELECT MIN(uar.effective_from) FROM user_account_role uar WHERE uar.user_id=su.id AND uar.approval_status IN('SUBMITTED','APPROVED'))";
        $source="CASE WHEN su.identity_source='MANUAL_NO_OFFICER' THEN 'User Not Yet Registered as Officer' ELSE 'Existing Approved Officer' END";
        return [
            'permission' => 'user.view', 'export' => true, 'filename' => 'account-requests',
            'with'=>$visibility['with'],
            'from' => '`system_user` su LEFT JOIN officer o ON o.id=su.officer_id LEFT JOIN designation d ON d.id=o.primary_designation_id',
            'select' => ['su.id','su.username','su.display_name','su.identity_type','su.identity_source','su.account_status','su.approval_status','su.requested_at','su.created_at','su.requested_by','o.nic','o.name_with_initials','d.name_en designation_name',$source.' account_source',$initialRole.' initial_role',$initialLocation.' initial_location',$initialDate.' effective_from'],
            'count' => 'su.id',
            'baseWhere' => ["(su.approval_status<>'APPROVED' OR su.account_status<>'ACTIVE')",$visibility['where']],
            'baseParams'=>$visibility['params'],
            'searchable' => ['su.username','su.display_name','su.identity_type','su.account_status','su.approval_status','o.nic','o.name_with_initials','d.name_en',$source,$initialRole,$initialLocation],
            'filters' => [
                'account_status' => ['column' => 'su.account_status', 'pattern' => '/^[A-Z_]{1,50}$/', 'ui' => ['label' => 'Account Status']],
                'approval_status' => ['column' => 'su.approval_status', 'allowed' => self::workflowStatuses(), 'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()]],
            ],
            'columns' => [
                self::col('Username', 'username', 'su.username', fn($r) => DataTableFormat::text($r['username'])),
                self::col('Name','display_name','su.display_name',fn($r)=>DataTableFormat::text($r['display_name']?:$r['name_with_initials'],'Not recorded')),
                self::col('NIC','nic','o.nic',fn($r)=>DataTableFormat::text($r['nic'],'-')),
                self::col('Designation','designation_name','d.name_en',fn($r)=>DataTableFormat::text($r['designation_name'],'-')),
                self::col('Account Source','account_source',$source,fn($r)=>DataTableFormat::text($r['account_source'])),
                self::col('Initial Role','initial_role',$initialRole,fn($r)=>DataTableFormat::text($r['initial_role'],'Not assigned')),
                self::col('Assigned Location','initial_location',$initialLocation,fn($r)=>DataTableFormat::text($r['initial_location'],'Not assigned')),
                self::col('Effective From','effective_from',$initialDate,fn($r)=>DataTableFormat::date($r['effective_from'],'Not assigned')),
                self::col('Account Status', 'account_status', 'su.account_status', fn($r) => DataTableFormat::badge($r['account_status'])),
                self::col('Approval', 'approval_status', 'su.approval_status', fn($r) => DataTableFormat::badge($r['approval_status'])),
                self::col('Requested At', 'requested_at', 'su.requested_at', fn($r) => DataTableFormat::dateTime($r['requested_at'] ?: $r['created_at']), fn($r) => substr((string)($r['requested_at'] ?: $r['created_at']), 0, 16)),
                self::actionColumn(fn($r) => self::accountRequestActions($r)),
            ],
            'defaultOrder' => [10, 'DESC'],
        ];
    }

    private static function roles(): array
    {
        return [
            'permission' => 'role.manage', 'export' => true, 'filename' => 'roles',
            'from' => 'application_role r',
            'select' => ['r.id', 'r.role_name', 'r.role_code', 'r.role_level', 'r.protected_role', 'r.assignable', 'r.legacy', 'r.approval_status', 'r.active', 'r.created_by'],
            'count' => 'r.id',
            'searchable' => ['r.role_name', 'r.role_code', 'r.role_level', 'r.description'],
            'filters' => [
                'role_level' => ['column' => 'r.role_level', 'allowed' => ['SYSTEM', 'NATIONAL', 'DISTRICT', 'ASC', 'ARPA', 'FARMER', 'CUSTOM', 'LEGACY'], 'ui' => ['label' => 'Role Level', 'options' => ['SYSTEM' => 'System', 'NATIONAL' => 'National', 'DISTRICT' => 'District', 'ASC' => 'ASC', 'ARPA' => 'ARPA', 'FARMER' => 'Farmer', 'CUSTOM' => 'Custom', 'LEGACY' => 'Legacy']]],
                'protected' => ['column' => 'r.protected_role', 'allowed' => ['0', '1'], 'ui' => ['label' => 'Role Type', 'options' => ['1' => 'Protected', '0' => 'Custom']]],
                'active' => ['column' => 'r.active', 'allowed' => ['0', '1'], 'ui' => ['label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Inactive']]],
                'legacy' => ['column' => 'r.legacy', 'allowed' => ['0', '1'], 'ui' => ['label' => 'Generation', 'options' => ['0' => 'Current', '1' => 'Legacy']]],
                'approval_status' => ['column' => 'r.approval_status', 'allowed' => self::workflowStatuses(), 'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()]],
            ],
            'columns' => [
                self::col('Role Name', 'role_name', 'r.role_name', fn($r) => DataTableFormat::text($r['role_name'])),
                self::col('System Key', 'role_code', 'r.role_code', fn($r) => '<code>' . e($r['role_code']) . '</code>'),
                self::col('Role Level', 'role_level', 'r.role_level', fn($r) => DataTableFormat::text($r['role_level'])),
                self::col('Protected', 'protected_role', 'r.protected_role', fn($r) => $r['protected_role'] ? 'Protected' : 'Custom', fn($r) => $r['protected_role'] ? 'Protected' : 'Custom'),
                self::col('Assignable', 'assignable', 'r.assignable', fn($r) => DataTableFormat::yesNo($r['assignable']), fn($r) => $r['assignable'] ? 'Yes' : 'No'),
                self::col('Legacy', 'legacy', 'r.legacy', fn($r) => $r['legacy'] ? DataTableFormat::badge('LEGACY') : '—', fn($r) => $r['legacy'] ? 'LEGACY' : ''),
                self::col('Approval', 'approval_status', 'r.approval_status', fn($r) => DataTableFormat::badge($r['approval_status'])),
                self::col('Status', 'active', 'r.active', fn($r) => DataTableFormat::badge($r['active'] ? 'ACTIVE' : 'INACTIVE'), fn($r) => $r['active'] ? 'ACTIVE' : 'INACTIVE'),
                self::actionColumn(fn($r) => self::roleActions($r)),
            ],
            'defaultOrder' => [0, 'ASC'],
        ];
    }

    private static function permissions(): array
    {
        return [
            'permission' => 'permission.view', 'export' => true, 'filename' => 'permissions',
            'from' => 'application_permission p LEFT JOIN application_role_permission rp ON rp.permission_id=p.id',
            'select' => ['p.id', 'p.permission_key', 'p.module_code', 'p.description', 'p.protected_permission', 'p.active', 'COUNT(rp.role_id) AS role_count'],
            'count' => 'p.id', 'groupBy' => 'p.id',
            'searchable' => ['p.permission_key', 'p.module_code', 'p.description'],
            'filters' => [
                'module' => ['column' => 'p.module_code', 'pattern' => '/^[A-Z0-9_]{1,80}$/', 'ui' => ['label' => 'Module']],
                'protected' => ['column' => 'p.protected_permission', 'allowed' => ['0', '1'], 'ui' => ['label' => 'Permission Type', 'options' => ['1' => 'Protected', '0' => 'Custom']]],
                'active' => ['column' => 'p.active', 'allowed' => ['0', '1'], 'ui' => ['label' => 'Status', 'options' => ['1' => 'Active', '0' => 'Inactive']]],
            ],
            'columns' => [
                self::col('Permission Key', 'permission_key', 'p.permission_key', fn($r) => '<code>' . e($r['permission_key']) . '</code>'),
                self::col('Module', 'module_code', 'p.module_code', fn($r) => DataTableFormat::text($r['module_code'])),
                self::col('Description', 'description', 'p.description', fn($r) => DataTableFormat::text($r['description'])),
                self::col('Protected', 'protected_permission', 'p.protected_permission', fn($r) => DataTableFormat::yesNo($r['protected_permission']), fn($r) => $r['protected_permission'] ? 'Yes' : 'No'),
                self::col('Assigned Roles', 'role_count', 'role_count'),
            ],
            'defaultOrder' => [1, 'ASC'],
        ];
    }

    private static function roleAssignments(): array
    {
        $actor=(string)(Auth::user()['id']??'');
        $ids=$actor===''?[]:(new UserAccessManagementService(Database::pdo()))->manageableRoleAssignmentIds($actor);
        $visibility=$ids!==[]?'uar.id IN ('.implode(',',array_fill(0,count($ids),'?')).')':'1=0';
        return [
            'permission' => 'user.assign-role', 'export' => true, 'filename' => 'role-assignments',
            'from' => "user_account_role uar JOIN `system_user` su ON su.id=uar.user_id JOIN application_role r ON r.id=uar.role_id LEFT JOIN (SELECT uas.role_assignment_id,GROUP_CONCAT(DISTINCT COALESCE(CONCAT(l.dad_number,' - ',l.name_en),CONCAT(o.dad_number,' - ',o.name_en),'National') ORDER BY l.name_en,o.name_en SEPARATOR '; ') assigned_locations FROM user_account_scope uas LEFT JOIN location l ON l.id=uas.location_id LEFT JOIN office o ON o.id=uas.office_id GROUP BY uas.role_assignment_id) sx ON sx.role_assignment_id=uar.id",
            'select' => ['uar.id', 'uar.user_id', 'uar.role_id', 'su.username','su.display_name', 'r.role_name', 'r.role_code','r.role_level','sx.assigned_locations', 'uar.effective_from', 'uar.effective_to', 'uar.approval_status', 'uar.active', 'uar.created_by', 'uar.created_at'],
            'count' => 'uar.id','baseWhere'=>[$visibility],'baseParams'=>$ids,
            'searchable' => ['su.username','su.display_name', 'r.role_name', 'r.role_code','sx.assigned_locations', 'uar.approval_status'],
            'filters' => [
                'role' => ['column' => 'uar.role_id', 'pattern' => self::uuidPattern(), 'ui' => ['label' => 'Role']],
                'approval_status' => ['column' => 'uar.approval_status', 'allowed' => self::workflowStatuses(), 'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()]],
                'effective_status' => ['allowed' => ['ACTIVE', 'EXPIRED', 'FUTURE', 'INACTIVE'], 'build' => fn($value) => self::effectiveClause('uar', $value), 'ui' => ['label' => 'Effective Status', 'options' => ['ACTIVE' => 'Currently Active', 'EXPIRED' => 'Expired', 'FUTURE' => 'Future', 'INACTIVE' => 'Inactive']]],
            ],
            'columns' => [
                self::col('Name', 'display_name', 'su.display_name', fn($r) => DataTableFormat::text($r['display_name'])),
                self::col('Username', 'username', 'su.username', fn($r) => DataTableFormat::text($r['username'])),
                self::col('Role', 'role_name', 'r.role_name', fn($r) => DataTableFormat::text($r['role_name']), fn($r) => $r['role_name']),
                self::col('Assigned Location', 'assigned_locations', 'sx.assigned_locations', fn($r) => DataTableFormat::text($r['assigned_locations'],'National / Not set')),
                self::col('Start Date', 'effective_from', 'uar.effective_from', fn($r) => DataTableFormat::date($r['effective_from'])),
                self::col('End Date', 'effective_to', 'uar.effective_to', fn($r) => DataTableFormat::date($r['effective_to'], 'Current')),
                self::col('Approval', 'approval_status', 'uar.approval_status', fn($r) => DataTableFormat::badge($r['approval_status'])),
                self::col('Active', 'active', 'uar.active', fn($r) => DataTableFormat::badge($r['active'] ? 'ACTIVE' : 'INACTIVE'), fn($r) => $r['active'] ? 'ACTIVE' : 'INACTIVE'),
                self::actionColumn(fn($r) => self::roleAssignmentActions($r)),
            ],
            'defaultOrder' => [2, 'ASC'],
        ];
    }

    private static function scopeAssignments(): array
    {
        $actor=(string)(Auth::user()['id']??'');
        $ids=$actor===''?[]:(new UserAccessManagementService(Database::pdo()))->manageableScopeAssignmentIds($actor);
        $visibility=$ids!==[]?'uas.id IN ('.implode(',',array_fill(0,count($ids),'?')).')':'1=0';
        return [
            'permission' => 'user.assign-scope', 'export' => true, 'filename' => 'scope-assignments',
            'from' => 'user_account_scope uas JOIN `system_user` su ON su.id=uas.user_id LEFT JOIN location l ON l.id=uas.location_id LEFT JOIN user_account_role uar ON uar.id=uas.role_assignment_id LEFT JOIN application_role r ON r.id=uar.role_id',
            'select' => ['uas.id', 'uas.user_id', 'su.username', 'r.role_name', 'uas.scope_type', 'uas.scope_mode', 'l.id AS location_id', 'l.dad_number AS location_number', 'l.name_en AS location_name', 'uas.effective_from', 'uas.effective_to', 'uas.approval_status', 'uas.active', 'uas.created_by', 'uas.created_at'],
            'count' => 'uas.id','baseWhere'=>[$visibility],'baseParams'=>$ids,
            'searchable' => ['su.username', 'r.role_name', 'uas.scope_type', 'uas.scope_mode', 'l.dad_number', 'l.name_en'],
            'filters' => [
                'scope_type' => ['column' => 'uas.scope_type', 'pattern' => '/^[A-Z0-9_]{1,50}$/', 'ui' => ['label' => 'Location Type']],
                'location' => ['column' => "CONCAT_WS(' ',l.dad_number,l.name_en)", 'operator' => 'LIKE', 'ui' => ['label' => 'Location', 'type' => 'text', 'placeholder' => 'DAD number or name']],
                'approval_status' => ['column' => 'uas.approval_status', 'allowed' => self::workflowStatuses(), 'ui' => ['label' => 'Approval Status', 'options' => self::workflowOptions()]],
                'effective_status' => ['allowed' => ['ACTIVE', 'EXPIRED', 'FUTURE', 'INACTIVE'], 'build' => fn($value) => self::effectiveClause('uas', $value), 'ui' => ['label' => 'Effective Status', 'options' => ['ACTIVE' => 'Currently Active', 'EXPIRED' => 'Expired', 'FUTURE' => 'Future', 'INACTIVE' => 'Inactive']]],
            ],
            'columns' => [
                self::col('User', 'username', 'su.username', fn($r) => DataTableFormat::text($r['username'])),
                self::col('Role', 'role_name', 'r.role_name', fn($r) => DataTableFormat::text($r['role_name'])),
                self::col('Location Type', 'scope_type', 'uas.scope_type', fn($r) => DataTableFormat::scopeType($r['scope_type'])),
                self::col('Assigned Location', 'location_name', 'l.name_en', fn($r) => DataTableFormat::text($r['location_name'] ? $r['location_number'] . ' · ' . $r['location_name'] : '', 'National Level'), fn($r) => $r['location_name'] ? $r['location_number'] . ' - ' . $r['location_name'] : 'National Level'),
                self::col('Access', 'scope_mode', 'uas.scope_mode', fn($r) => DataTableFormat::scopeMode($r['scope_mode'])),
                self::col('Start Date', 'effective_from', 'uas.effective_from', fn($r) => DataTableFormat::date($r['effective_from'])),
                self::col('End Date', 'effective_to', 'uas.effective_to', fn($r) => DataTableFormat::date($r['effective_to'], 'Current')),
                self::col('Approval', 'approval_status', 'uas.approval_status', fn($r) => DataTableFormat::badge($r['approval_status'])),
                self::actionColumn(fn($r) => self::scopeActions($r)),
            ],
            'defaultOrder' => [5, 'DESC'],
        ];
    }

    private static function provisioningFailures(): array
    {
        return [
            'permission' => 'user.retry-provisioning', 'export' => true, 'filename' => 'provisioning-failures',
            'from' => 'provisioning_failure pf',
            'select' => ['pf.id', 'pf.username', 'pf.failure_category', 'pf.sanitized_message', 'pf.attempt_count', 'pf.last_attempt_at'],
            'count' => 'pf.id',
            'searchable' => ['pf.username', 'pf.failure_category', 'pf.sanitized_message'],
            'filters' => [
                'category' => ['column' => 'pf.failure_category', 'pattern' => '/^[A-Za-z0-9_.:-]{1,100}$/', 'ui' => ['label' => 'Category']],
            ],
            'columns' => [
                self::col('Username', 'username', 'pf.username', fn($r) => DataTableFormat::text($r['username'])),
                self::col('Category', 'failure_category', 'pf.failure_category', fn($r) => DataTableFormat::text($r['failure_category'])),
                self::col('Message', 'sanitized_message', 'pf.sanitized_message', fn($r) => DataTableFormat::text($r['sanitized_message'])),
                self::col('Attempts', 'attempt_count', 'pf.attempt_count'),
                self::col('Last Attempt', 'last_attempt_at', 'pf.last_attempt_at', fn($r) => DataTableFormat::dateTime($r['last_attempt_at'])),
            ],
            'defaultOrder' => [4, 'DESC'],
        ];
    }

    private static function securityHistory(): array
    {
        return [
            'permission' => 'user.view-security-history', 'export' => true, 'filename' => 'security-history',
            'from' => 'audit_event ae LEFT JOIN `system_user` su ON su.id=ae.actor_user_id',
            'select' => ['ae.id', 'ae.created_at', 'ae.actor_user_id', 'su.username AS actor_username', 'ae.action_key', 'ae.target_type', 'ae.target_id', 'ae.severity', 'ae.source_ip'],
            'count' => 'ae.id',
            'searchable' => ['su.username', 'ae.action_key', 'ae.target_type', 'ae.target_id', 'ae.severity', 'ae.source_ip'],
            'filters' => [
                'event_type' => ['column' => 'ae.action_key', 'pattern' => '/^[A-Za-z0-9_.:-]{1,150}$/', 'ui' => ['label' => 'Event Type']],
                'actor' => ['column' => 'su.username', 'operator' => 'LIKE', 'ui' => ['label' => 'User', 'type' => 'text', 'placeholder' => 'Username']],
                'date_from' => ['column' => 'ae.created_at', 'operator' => '>=', 'date' => true, 'ui' => ['label' => 'From Date', 'type' => 'date']],
                'date_to' => ['build' => function ($value) {
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    return $date && $date->format('Y-m-d') === $value ? ['ae.created_at < DATE_ADD(?, INTERVAL 1 DAY)', [$value]] : null;
                }, 'ui' => ['label' => 'To Date', 'type' => 'date']],
            ],
            'columns' => [
                self::col('Time', 'created_at', 'ae.created_at', fn($r) => DataTableFormat::dateTime($r['created_at'])),
                self::col('Actor', 'actor_username', 'su.username', fn($r) => DataTableFormat::text($r['actor_username'], 'SYSTEM')),
                self::col('Action', 'action_key', 'ae.action_key', fn($r) => '<code>' . e($r['action_key']) . '</code>'),
                self::col('Target', 'target', 'ae.target_type', fn($r) => DataTableFormat::text(trim($r['target_type'] . ' ' . (string)$r['target_id'])), fn($r) => trim($r['target_type'] . ' ' . (string)$r['target_id'])),
                self::col('Severity', 'severity', 'ae.severity', fn($r) => DataTableFormat::badge($r['severity'])),
                self::col('Source IP', 'source_ip', 'ae.source_ip', fn($r) => DataTableFormat::text($r['source_ip'])),
            ],
            'defaultOrder' => [0, 'DESC'],
        ];
    }

    private static function col(
        string $label,
        string $key,
        ?string $sort = null,
        ?callable $format = null,
        ?callable $exportFormat = null
    ): array {
        $column = ['label' => $label, 'key' => $key, 'sort' => $sort];
        if ($format !== null) {
            $column['format'] = $format;
        }
        if ($exportFormat !== null) {
            $column['exportFormat'] = $exportFormat;
        }
        return $column;
    }

    private static function actionColumn(callable $formatter): array
    {
        return [
            'label' => 'Actions', 'key' => 'actions', 'sort' => null, 'format' => $formatter,
            'export' => false, 'className' => 'text-nowrap',
        ];
    }

    private static function geo(?string $expression = null): array
    {
        $user = Auth::user();
        $restricted = $user !== null && ScopeService::requiresGeographicRestriction((string)$user['id']);
        if (!$restricted) {
            return ['with' => '', 'params' => [], 'joinLocation' => '', 'joinExpression' => ''];
        }
        return [
            'with' => ScopeService::visibleLocationsCte((string)$user['id']),
            'params' => ScopeService::visibleLocationParams((string)$user['id']),
            'joinLocation' => 'JOIN visible_locations vl ON vl.id=l.id',
            'joinExpression' => $expression !== null ? 'JOIN visible_locations vl ON vl.id=' . $expression : '',
        ];
    }

    private static function arpaOfficerGeoAccess(string $officerExpression):array
    {
        $user=Auth::user();$restricted=$user!==null&&ScopeService::requiresGeographicRestriction((string)$user['id']);
        if(!$restricted)return ['with'=>'','params'=>[],'where'=>[]];
        return ['with'=>ScopeService::visibleLocationsCte((string)$user['id']),'params'=>ScopeService::visibleLocationParams((string)$user['id']),'where'=>["EXISTS (SELECT 1 FROM arpa_division_appointment da JOIN visible_locations vl1 ON vl1.id=da.asc_location_id WHERE da.officer_id={$officerExpression} UNION ALL SELECT 1 FROM arpa_subject_assignment sa JOIN visible_locations vl2 ON vl2.id=sa.asc_location_id WHERE sa.officer_id={$officerExpression})"]];
    }

    private static function effectiveClause(string $alias, string $value): ?array
    {
        return match ($value) {
            'ACTIVE' => ["{$alias}.active=1 AND {$alias}.effective_from<=CURRENT_DATE() AND ({$alias}.effective_to IS NULL OR {$alias}.effective_to>=CURRENT_DATE())", []],
            'EXPIRED' => ["{$alias}.effective_to<CURRENT_DATE()", []],
            'FUTURE' => ["{$alias}.effective_from>CURRENT_DATE()", []],
            'INACTIVE' => ["{$alias}.active=0", []],
            default => null,
        };
    }

    private static function locationActions(array $row): string
    {
        $actions=Auth::can('location.view')?'<a class="btn btn-sm btn-outline-primary me-1" href="'.e(url('locations/'.$row['id'])).'">View</a>':'';
        if($row['approval_status']==='DRAFT'&&Auth::can('location.submit')){
            $actions.=DataTableFormat::actionForm('locations/'.$row['id'].'/submit','Submit','btn-outline-primary');
        }elseif($row['approval_status']==='SUBMITTED'&&Auth::can('location.approve')&&!self::isMaker($row['created_by'])){
            $actions.=DataTableFormat::actionForm('locations/'.$row['id'].'/approve','Approve','btn-success');
        }
        return $actions;
    }

    private static function officeActions(array $row): string
    {
        $actions=Auth::can('office.view')?'<a class="btn btn-sm btn-outline-primary me-1" href="'.e(url('offices/'.$row['id'])).'">View</a>':'';
        if ($row['approval_status'] === 'DRAFT' && Auth::can('office.submit')) {
            return $actions.DataTableFormat::actionForm('offices/' . $row['id'] . '/submit', 'Submit', 'btn-outline-primary');
        }
        if ($row['approval_status'] === 'SUBMITTED' && Auth::can('office.approve') && !self::isMaker($row['created_by'])) {
            return $actions.DataTableFormat::actionForm('offices/' . $row['id'] . '/approve', 'Approve', 'btn-success');
        }
        return $actions;
    }

    private static function officerActions(array $row): string
    {
        $actions = Auth::can('officer.view')?'<a class="btn btn-sm btn-outline-primary me-1" href="'.e(url('hr/officers/'.$row['id'])).'">View</a>':'';
        if (!empty($row['photograph_path']) && Auth::can('officer.view-photo')) {
            $actions .= '<a class="btn btn-sm btn-outline-secondary me-1" target="_blank" rel="noopener" href="' . e(url('hr/officers/' . $row['id'] . '/photo')) . '">Photo</a>';
        }
        if ($row['approval_status'] === 'DRAFT' && Auth::can('officer.submit')) {
            $actions .= DataTableFormat::actionForm('hr/officers/' . $row['id'] . '/submit', 'Submit', 'btn-outline-primary');
        } elseif ($row['approval_status'] === 'SUBMITTED' && Auth::can('officer.approve') && !self::isMaker($row['created_by'])) {
            $actions .= DataTableFormat::actionForm('hr/officers/' . $row['id'] . '/approve', 'Approve', 'btn-success');
        }
        return $actions;
    }

    private static function arpaDivisionActions(array $row): string
    {
        $actions='<a class="btn btn-sm btn-outline-dark me-1" href="'.e(url('hr/arpa-appointments/divisions/'.$row['id'])).'">View</a><a class="btn btn-sm btn-outline-secondary me-1" href="'.e(url('hr/officers/'.$row['officer_id'])).'">Profile</a>';
        if ($row['operational_status'] !== 'ENDED' && Auth::can('arpa.appointment.end')) {
            $actions.='<a class="btn btn-sm btn-outline-danger me-1" href="'.e(url('hr/arpa-appointments/divisions/'.$row['id'].'/end')).'">End</a>';
        }
        if ($row['appointment_type']==='PERMANENT' && $row['operational_status']!=='ENDED' && Auth::can('arpa.appointment.transfer')) {
            $actions.='<a class="btn btn-sm btn-outline-primary" href="'.e(url('hr/arpa-appointments/divisions/'.$row['id'].'/transfer')).'">Transfer</a>';
        }
        return $actions;
    }

    private static function arpaSubjectActions(array $row): string
    {
        $actions='<a class="btn btn-sm btn-outline-secondary me-1" href="'.e(url('hr/arpa-appointments/officers/'.$row['officer_id'])).'">Profile</a>';
        if ($row['operational_status']!=='ENDED' && Auth::can('arpa.subject.end')) {
            $actions.='<a class="btn btn-sm btn-outline-danger" href="'.e(url('hr/arpa-appointments/subjects/'.$row['id'].'/end')).'">End</a>';
        }
        return $actions;
    }

    private static function arpaWorkflowActions(array $row): string
    {
        $actions='<a class="btn btn-sm btn-outline-dark me-1" href="'.e(url('hr/arpa-appointments/requests/'.$row['entity'].'/'.$row['id'])).'">Review</a>';
        $ownDraft=$row['workflow_status']==='CREATED'&&(Auth::user()['id']??null)===$row['created_by'];
        $ascCorrection=$row['workflow_status']==='RETURNED'&&Auth::can('arpa.appointment.asc-verify');
        if($ownDraft||$ascCorrection){
            $permission=$row['entity']==='subject'?'arpa.subject.create':'arpa.appointment.edit';
            if(Auth::can($permission))$actions.='<a class="btn btn-sm btn-outline-secondary me-1" href="'.e(url('hr/arpa-appointments/requests/'.$row['entity'].'/'.$row['id'].'/edit')).'">Edit</a>';
        }
        if($ascCorrection&&Auth::can('arpa.appointment.submit'))$actions.=DataTableFormat::actionForm('hr/arpa-appointments/workflow/'.$row['entity'].'/'.$row['id'].'/submit?stage=CREATOR','Resubmit','btn-primary');
        return $actions;
    }

    private static function hrActions(string $type, array $row): string
    {
        if ($row['approval_status'] === 'DRAFT' && Auth::can('hr.master.edit')) {
            return DataTableFormat::actionForm('hr/' . $type . '/' . $row['id'] . '/submit', 'Submit', 'btn-outline-primary');
        }
        if ($row['approval_status'] === 'SUBMITTED' && Auth::can('hr.master.approve') && !self::isMaker($row['created_by'])) {
            return DataTableFormat::actionForm('hr/' . $type . '/' . $row['id'] . '/approve', 'Approve', 'btn-success');
        }
        return '';
    }

    private static function accountRequestActions(array $row): string
    {
        if ($row['approval_status'] === 'DRAFT' && Auth::can('user.submit')) {
            return DataTableFormat::actionForm('access-management/users/' . $row['id'] . '/submit', 'Submit', 'btn-outline-primary');
        }
        if ($row['approval_status'] === 'SUBMITTED' && Auth::can('user.approve') && !self::isMaker($row['requested_by'])) {
            return DataTableFormat::actionForm('access-management/users/' . $row['id'] . '/approve', 'Approve', 'btn-success');
        }
        return '';
    }

    private static function roleActions(array $row): string
    {
        if (!$row['protected_role'] && $row['approval_status'] === 'DRAFT' && Auth::can('role.manage')) {
            return DataTableFormat::actionForm('access-management/roles/' . $row['id'] . '/submit', 'Submit', 'btn-outline-primary');
        }
        if (!$row['protected_role'] && $row['approval_status'] === 'SUBMITTED' && Auth::can('role.manage') && !self::isMaker($row['created_by'])) {
            return DataTableFormat::actionForm('access-management/roles/' . $row['id'] . '/approve', 'Approve', 'btn-success');
        }
        return '';
    }

    private static function roleAssignmentActions(array $row): string
    {
        if ($row['approval_status'] === 'DRAFT' && Auth::can('user.assign-role')) {
            return DataTableFormat::actionForm('access-management/role-assignments/' . $row['id'] . '/submit', 'Submit', 'btn-outline-primary');
        }
        if ($row['approval_status'] === 'SUBMITTED' && Auth::can('user.assign-role') && !self::isMaker($row['created_by'])) {
            return DataTableFormat::actionForm('access-management/role-assignments/' . $row['id'] . '/approve', 'Approve', 'btn-success');
        }
        if ($row['approval_status'] === 'APPROVED' && (int)$row['active'] === 1) {
            $actions=Auth::can('user.assign-role')?'<a class="btn btn-sm btn-outline-primary me-1" href="'.e(url('access-management/role-assignments?replace='.$row['id'])).'">Transfer / Change</a>':'';
            if(Auth::can('user.revoke-role'))$actions.='<a class="btn btn-sm btn-outline-danger" href="'.e(url('access-management/role-assignments/'.$row['id'].'/end')).'">End</a>';
            return $actions;
        }
        return '';
    }

    private static function scopeActions(array $row): string
    {
        if ($row['approval_status'] === 'DRAFT' && Auth::can('user.assign-scope')) {
            return DataTableFormat::actionForm('access-management/scope-assignments/' . $row['id'] . '/submit', 'Submit', 'btn-outline-primary');
        }
        if ($row['approval_status'] === 'SUBMITTED' && Auth::can('user.assign-scope') && !self::isMaker($row['created_by'])) {
            return DataTableFormat::actionForm('access-management/scope-assignments/' . $row['id'] . '/approve', 'Approve', 'btn-success');
        }
        return '';
    }

    private static function isMaker(mixed $createdBy): bool
    {
        return $createdBy !== null && (string)$createdBy === (string)(Auth::user()['id'] ?? '');
    }

    private static function workflowStatuses(): array
    {
        return ['DRAFT', 'SUBMITTED', 'APPROVED', 'RETURNED', 'WITHDRAWN'];
    }

    private static function workflowOptions(): array
    {
        return ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'APPROVED' => 'Approved', 'RETURNED' => 'Returned', 'WITHDRAWN' => 'Withdrawn'];
    }

    private static function uuidPattern(): string
    {
        return '/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[1-5a-fA-F0-9][a-fA-F0-9]{3}-[89abABa-fA-F0-9][a-fA-F0-9]{3}-[a-fA-F0-9]{12}$/';
    }

    private static function requiredUuid(array $input,string $key):string
    {
        $value=trim((string)($input[$key]??''));
        if(preg_match(self::uuidPattern(),$value)!==1)throw new RuntimeException('A valid '.$key.' is required.');
        return $value;
    }
}
