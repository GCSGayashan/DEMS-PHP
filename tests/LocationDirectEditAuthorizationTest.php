<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\DataTableRegistry;
use App\Core\Database;
use App\Services\LocationDirectEditPolicy;
use App\Services\LocationHierarchyDirectEditService;
use App\Services\LocationHierarchyLookupService;
use App\Services\UserContextService;

require dirname(__DIR__).'/bootstrap.php';

final class LocationDirectEditAuthorizationTest
{
    private PDO $pdo;
    private int $assertions=0;

    public function run(): int
    {
        $this->pdo=Database::pdo();
        $systemAdmin=(string)$this->pdo->query("SELECT su.id FROM system_user su JOIN user_account_role uar ON uar.user_id=su.id JOIN application_role r ON r.id=uar.role_id WHERE r.role_code='SYSTEM_ADMIN' AND uar.active=1 AND uar.approval_status='APPROVED' LIMIT 1")->fetchColumn();
        $ascUser=(string)$this->pdo->query("SELECT id FROM system_user WHERE username='asctest' AND enabled=1 AND account_status='ACTIVE' LIMIT 1")->fetchColumn();
        if($systemAdmin===''||$ascUser==='')throw new RuntimeException('SYSTEM_ADMIN and asctest fixtures are required.');

        $this->useContext($systemAdmin,'SYSTEM_ADMIN');
        $this->same(true,LocationDirectEditPolicy::allowed(),'SYSTEM_ADMIN active context may edit Locations directly');
        $this->same(true,str_contains($this->locationActions(),'/edit'),'SYSTEM_ADMIN sees the direct Edit action');
        $this->hierarchyChanges($systemAdmin,$ascUser);

        $this->useContext($ascUser,'ASC_SUBJECT_OFFICER');
        $this->same(false,LocationDirectEditPolicy::allowed(),'non-SYSTEM_ADMIN active context is rejected');
        $this->same(false,str_contains($this->locationActions(),'/edit'),'non-SYSTEM_ADMIN does not see the direct Edit action');

        $controller=(string)file_get_contents(BASE_PATH.'/app/Controllers/LocationController.php');
        $routes=(string)file_get_contents(BASE_PATH.'/routes/web.php');
        $detail=(string)file_get_contents(BASE_PATH.'/app/Views/locations/show.php');
        $form=(string)file_get_contents(BASE_PATH.'/app/Views/locations/form.php');
        $hierarchyService=(string)file_get_contents(BASE_PATH.'/app/Services/LocationHierarchyDirectEditService.php');
        $this->same(true,substr_count($controller,'$this->requireSystemAdminDirectEdit();')>=2,'GET and POST edit paths enforce the server-side SYSTEM_ADMIN policy');
        $this->same(true,str_contains($controller,"Audit::record('location.direct-update','LOCATION',\$id,['before'=>\$before,'after'=>\$after"),'direct edit records before and after values');
        $this->same(true,str_contains($routes,"/locations/{id}/edit"),'direct edit routes are registered');
        $this->same(true,str_contains($detail,'DS Division:</strong>'),'GN detail has a dedicated DS Division summary');
        $this->same(true,str_contains($detail,'Not assigned'),'GN detail identifies a missing DS Division');
        $this->same(true,str_contains($detail,'Data Issue - Multiple current DS Divisions'),'GN detail identifies multiple current DS relationships');
        $this->same(true,str_contains($detail,'GN Code for PLR'),'GN detail renders the independent PLR identifier');
        $this->same(true,str_contains($form,'name="gn_code"')&&str_contains($form,'name="gn_code_for_plr"'),'GN Add/Edit form exposes both canonical GN identifiers');
        $this->same(true,str_contains($controller,'validatedGnIdentifiers')&&str_contains($controller,'gn_code_for_plr'),'GN identifiers are validated and persisted server-side');
        $this->same(false,str_contains($hierarchyService,"'DS_DIVISION_ASC'=>"),'direct Location edit does not persist a DS-to-ASC relationship');
        $cascade=(string)file_get_contents(BASE_PATH.'/public/assets/js/location-hierarchy-cascade.js');
        $this->same(true,str_contains($cascade,'clearDescendants(select.name)')&&str_contains($cascade,'reloadBranch(select.name)'),'parent changes clear invalid descendants before reloading canonical children');

        $_SESSION=[];Auth::forgetRequestCache();
        echo "LocationDirectEditAuthorizationTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    private function hierarchyChanges(string $systemAdmin,string $ascUser): void
    {
        $active=static fn(string $alias):string=>"{$alias}.active=1 AND {$alias}.approval_status='APPROVED' AND {$alias}.effective_from<=CURRENT_DATE() AND ({$alias}.effective_to IS NULL OR {$alias}.effective_to>=CURRENT_DATE())";
        $pdCurrent=$active('pd');$ddCurrent=$active('dd');$aaCurrent=$active('aa');$daCurrent=$active('da');
        $sql="SELECT d.id district_id,MIN(pd.parent_location_id) province_id FROM location d JOIN location_type dt ON dt.id=d.location_type_id AND dt.system_key='DISTRICT' JOIN location_relationship pd ON pd.child_location_id=d.id AND pd.relationship_type='PROVINCE_DISTRICT' AND {$pdCurrent} WHERE (SELECT COUNT(DISTINCT dd.child_location_id) FROM location_relationship dd JOIN location ds ON ds.id=dd.child_location_id JOIN location_type dst ON dst.id=ds.location_type_id AND dst.system_key='DS_DIVISION' WHERE dd.parent_location_id=d.id AND dd.relationship_type='DISTRICT_DS_DIVISION' AND {$ddCurrent})>=2 AND (SELECT COUNT(DISTINCT aa.child_location_id) FROM location_relationship da JOIN location_relationship aa ON aa.parent_location_id=da.child_location_id AND aa.relationship_type='ASC_ARPA_DIVISION' AND {$aaCurrent} WHERE da.parent_location_id=d.id AND da.relationship_type='DISTRICT_ASC' AND {$daCurrent})>=2 GROUP BY d.id ORDER BY d.id LIMIT 1";
        $context=$this->pdo->query($sql)->fetch();if(!$context)throw new RuntimeException('A District with two DS and ARPA Division parents is required.');
        $district=(string)$context['district_id'];$province=(string)$context['province_id'];
        $stmt=$this->pdo->prepare("SELECT child_location_id FROM location_relationship lr WHERE parent_location_id=? AND relationship_type='DISTRICT_DS_DIVISION' AND ".$active('lr')." GROUP BY child_location_id ORDER BY child_location_id LIMIT 2");$stmt->execute([$district]);$ds=array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
        $stmt=$this->pdo->prepare("SELECT aa.child_location_id arpa_id,aa.parent_location_id asc_id FROM location_relationship da JOIN location_relationship aa ON aa.parent_location_id=da.child_location_id AND aa.relationship_type='ASC_ARPA_DIVISION' AND {$aaCurrent} WHERE da.parent_location_id=? AND da.relationship_type='DISTRICT_ASC' AND {$daCurrent} GROUP BY aa.child_location_id,aa.parent_location_id ORDER BY aa.child_location_id LIMIT 2");$stmt->execute([$district]);$arpa=$stmt->fetchAll();
        $stmt=$this->pdo->prepare("SELECT pd.parent_location_id province_id,da.parent_location_id district_id,aa.child_location_id arpa_id FROM location_relationship da JOIN location_relationship aa ON aa.parent_location_id=da.child_location_id AND aa.relationship_type='ASC_ARPA_DIVISION' AND {$aaCurrent} JOIN location_relationship pd ON pd.child_location_id=da.parent_location_id AND pd.relationship_type='PROVINCE_DISTRICT' AND {$pdCurrent} WHERE da.relationship_type='DISTRICT_ASC' AND da.parent_location_id<>? AND {$daCurrent} ORDER BY da.parent_location_id LIMIT 1");$stmt->execute([$district]);$outside=$stmt->fetch();
        if(count($ds)!==2||count($arpa)!==2||!$outside)throw new RuntimeException('Location hierarchy edit fixtures are incomplete.');

        $lookup=new LocationHierarchyLookupService($this->pdo);
        $western=(string)$this->value("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE lt.system_key='PROVINCE' AND l.name_en='Western' AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' LIMIT 1");
        $colombo=(string)$this->value("SELECT l.id FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE lt.system_key='DISTRICT' AND l.name_en='Colombo' AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' LIMIT 1");
        $westernDistricts=$lookup->children($systemAdmin,$western,'DISTRICT');
        $this->same(true,in_array($colombo,array_column($westernDistricts,'id'),true),'Western Province lookup includes Colombo District');
        $this->same(true,$this->allChildrenBelong($westernDistricts,$western,'PROVINCE_DISTRICT'),'Western Province lookup excludes Districts outside Western');
        $this->same(true,$this->allChildrenBelong($lookup->children($systemAdmin,$colombo,'DS_DIVISION'),$colombo,'DISTRICT_DS_DIVISION'),'Colombo lookup returns only Colombo DS Divisions');
        $this->same(true,$this->allChildrenBelong($lookup->children($systemAdmin,$colombo,'ASC'),$colombo,'DISTRICT_ASC'),'Colombo lookup returns only Colombo ASCs');
        $districtOptions=$lookup->children($systemAdmin,$province,'DISTRICT');
        $this->same(true,in_array($district,array_column($districtOptions,'id'),true),'Province lookup contains its approved current District');
        $this->same(true,$this->allChildrenBelong($districtOptions,$province,'PROVINCE_DISTRICT'),'Province lookup contains only related Districts');
        $dsOptions=$lookup->children($systemAdmin,$district,'DS_DIVISION');
        $this->same(true,in_array((string)$ds[0],array_column($dsOptions,'id'),true),'District lookup contains its approved current DS Divisions');
        $this->same(true,$this->allChildrenBelong($dsOptions,$district,'DISTRICT_DS_DIVISION'),'District DS lookup contains only related DS Divisions');
        $ascOptions=$lookup->children($systemAdmin,$district,'ASC');
        $this->same(true,in_array((string)$arpa[0]['asc_id'],array_column($ascOptions,'id'),true),'District lookup contains its approved current ASCs');
        $this->same(true,$this->allChildrenBelong($ascOptions,$district,'DISTRICT_ASC'),'District ASC lookup contains only related ASCs');
        $arpaOptions=$lookup->children($systemAdmin,(string)$arpa[0]['asc_id'],'ARPA_DIVISION');
        $this->same(true,in_array((string)$arpa[0]['arpa_id'],array_column($arpaOptions,'id'),true),'ASC lookup contains its approved current ARPA Divisions');
        $this->same(true,$this->allChildrenBelong($arpaOptions,(string)$arpa[0]['asc_id'],'ASC_ARPA_DIVISION'),'ASC lookup contains only related ARPA Divisions');

        $this->pdo->beginTransaction();
        try{
            $gn=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();$gnType=(string)$this->pdo->query("SELECT id FROM location_type WHERE system_key='GN_DIVISION'")->fetchColumn();
            $dad='TEST-GN-'.substr(str_replace('-','',$gn),0,11);
            $this->pdo->prepare("INSERT INTO location(id,dad_number,location_type_id,name_en,effective_from,operational_status,approval_status,version) VALUES(?,?,?,'Hierarchy Test GN',CURRENT_DATE(),'ACTIVE','APPROVED',0)")->execute([$gn,$dad,$gnType]);
            $this->relation((string)$ds[0],$gn,'DS_DIVISION_GN_DIVISION');$this->relation((string)$arpa[0]['arpa_id'],$gn,'ARPA_GN_DIVISION');$this->relation((string)$arpa[0]['asc_id'],$gn,'ASC_GN_DIVISION');
            $this->same(true,in_array($gn,array_column($lookup->children($systemAdmin,(string)$ds[0],'GN_DIVISION'),'id'),true),'DS Division lookup contains only its related GN children, including the fixture');
            $this->same(true,in_array($gn,array_column($lookup->children($systemAdmin,(string)$arpa[0]['arpa_id'],'GN_DIVISION'),'id'),true),'ARPA Division lookup contains only its related GN children, including the fixture');
            $input=['province_location_id'=>$province,'district_location_id'=>$district,'ds_division_location_id'=>$ds[1],'asc_location_id'=>$arpa[1]['asc_id'],'arpa_division_location_id'=>$arpa[1]['arpa_id']];
            $service=new LocationHierarchyDirectEditService($this->pdo);$form=$service->formData(['id'=>$gn,'type_key'=>'GN_DIVISION']);
            $this->same((string)$ds[0],(string)$form['values']['ds_division_location_id'],'existing GN DS Division is preselected');
            $this->same((string)$arpa[0]['asc_id'],(string)$form['values']['asc_location_id'],'existing GN ASC is preselected through its ARPA Division');
            $this->same((string)$arpa[0]['arpa_id'],(string)$form['values']['arpa_division_location_id'],'existing GN ARPA Division is preselected');
            $this->relation((string)$ds[1],$gn,'DS_DIVISION_GN_DIVISION');
            $result=$service->replace($gn,'GN_DIVISION',$input,$systemAdmin);
            $this->same(true,$result['changed'],'SYSTEM_ADMIN can correct multiple GN DS parents transactionally');
            $this->same((string)$ds[1],$this->currentParent($gn,'DS_DIVISION_GN_DIVISION'),'GN DS Division is changed');
            $this->same((string)$arpa[1]['arpa_id'],$this->currentParent($gn,'ARPA_GN_DIVISION'),'GN ARPA Division is changed');
            $this->same((string)$arpa[1]['asc_id'],$this->currentParent($gn,'ASC_GN_DIVISION'),'GN direct ASC hierarchy remains consistent with selected ARPA Division');
            $this->same(1,(int)$this->value("SELECT COUNT(*) FROM location_relationship WHERE child_location_id=? AND relationship_type='DS_DIVISION_GN_DIVISION' AND active=1",[$gn]),'GN change leaves one active DS relationship');
            $this->same(1,(int)$this->value("SELECT COUNT(*) FROM location_relationship WHERE child_location_id=? AND relationship_type='ARPA_GN_DIVISION' AND active=1",[$gn]),'GN change leaves one active ARPA relationship');
            $this->same(2,(int)$this->value("SELECT COUNT(*) FROM location_relationship WHERE child_location_id=? AND relationship_type='DS_DIVISION_GN_DIVISION' AND active=0 AND effective_to IS NOT NULL",[$gn]),'superseded DS relationships remain as ended history');
            $this->same(1,(int)$this->value("SELECT COUNT(*) FROM audit_event WHERE target_id=? AND action_key='location.hierarchy.direct-update'",[$gn]),'hierarchy change creates an audit record');

            $invalid=$input;$invalid['province_location_id']=$outside['province_id'];$invalid['district_location_id']=$outside['district_id'];$invalid['ds_division_location_id']='';
            $this->throws(fn()=>$service->replace($gn,'GN_DIVISION',$invalid,$systemAdmin),'cross-District ARPA hierarchy is rejected');

            $duplicate=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();$this->pdo->prepare("INSERT INTO location_relationship(id,parent_location_id,child_location_id,relationship_type,effective_from,approval_status,active) VALUES(?,?,?,'DS_DIVISION_GN_DIVISION',CURRENT_DATE(),'APPROVED',1)")->execute([$duplicate,$ds[1],$gn]);
            $duplicateCorrection=$service->replace($gn,'GN_DIVISION',$input,$systemAdmin);
            $this->same(true,$duplicateCorrection['changed'],'existing duplicate current DS relationships can be corrected explicitly');
            $this->same(1,(int)$this->value("SELECT COUNT(*) FROM location_relationship WHERE child_location_id=? AND relationship_type='DS_DIVISION_GN_DIVISION' AND active=1",[$gn]),'duplicate correction leaves exactly one current DS relationship');
            $unchanged=$service->replace($gn,'GN_DIVISION',$input,$systemAdmin);
            $this->same(false,$unchanged['changed'],'saving the same DS relationship does not create a duplicate');

            $this->useContext($ascUser,'ASC_SUBJECT_OFFICER');
            $this->throws(fn()=>$service->replace($gn,'GN_DIVISION',$input,$ascUser),'non-SYSTEM_ADMIN direct hierarchy edit is rejected');
            $this->throws(fn()=>$lookup->children($ascUser,(string)$outside['district_id'],'ASC'),'out-of-scope hierarchy parent is unavailable');
            $this->useContext($systemAdmin,'SYSTEM_ADMIN');
        }finally{
            if($this->pdo->inTransaction())$this->pdo->rollBack();
        }
    }

    private function relation(string $parent,string $child,string $type): void
    {
        $this->pdo->prepare("INSERT INTO location_relationship(id,parent_location_id,child_location_id,relationship_type,effective_from,approval_status,active) VALUES(UUID(),?,?,?,CURRENT_DATE(),'APPROVED',1)")->execute([$parent,$child,$type]);
    }

    private function allChildrenBelong(array $children,string $parent,string $relationshipType): bool
    {
        if($children===[])return false;
        foreach($children as $child){
            if((int)$this->value("SELECT COUNT(*) FROM location_relationship WHERE parent_location_id=? AND child_location_id=? AND relationship_type=? AND active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())",[$parent,$child['id'],$relationshipType])<1)return false;
        }
        return true;
    }

    private function currentParent(string $child,string $type): string
    {
        return (string)$this->value("SELECT parent_location_id FROM location_relationship WHERE child_location_id=? AND relationship_type=? AND active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())",[$child,$type]);
    }

    private function value(string $sql,array $params=[]): mixed
    {
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchColumn();
    }

    private function throws(callable $callback,string $message): void
    {
        $this->assertions++;try{$callback();}catch(DomainException){return;}throw new RuntimeException($message.': expected DomainException');
    }

    private function useContext(string $userId,string $roleCode): void
    {
        $_SESSION=['user_id'=>$userId,'authenticated_at'=>time(),'last_activity_at'=>time()];Auth::forgetRequestCache();
        $service=new UserContextService($this->pdo);
        $context=array_values(array_filter($service->availableContexts($userId),static fn(array $row):bool=>(string)$row['role_code']===$roleCode))[0]??null;
        if($context===null)throw new RuntimeException("{$roleCode} Active Working Context is required.");
        $service->select($userId,(string)$context['role_assignment_id'],$context['scope_assignment_id']===null?null:(string)$context['scope_assignment_id']);Auth::forgetRequestCache();
    }

    private function locationActions(): string
    {
        $config=DataTableRegistry::definition('locations',['scope_type'=>'GN_DIVISION']);
        $format=$config['columns'][array_key_last($config['columns'])]['format'];
        return (string)$format(['id'=>'location-test','approval_status'=>'APPROVED','created_by'=>'fixture']);
    }

    private function same(mixed $expected,mixed $actual,string $message): void
    {
        $this->assertions++;if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
}

exit((new LocationDirectEditAuthorizationTest())->run());
