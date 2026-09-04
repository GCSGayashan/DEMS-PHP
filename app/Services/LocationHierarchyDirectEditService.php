<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use DomainException;
use PDO;

final class LocationHierarchyDirectEditService
{
    private const CURRENT = "active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())";

    public function __construct(private readonly PDO $pdo) {}

    public function formData(array $location): array
    {
        $type=(string)$location['type_key'];$values=[];
        $direct=[
            'province_location_id'=>$this->singleParent((string)$location['id'],'PROVINCE_DISTRICT'),
            'district_location_id'=>$this->singleParent((string)$location['id'],$type==='DS_DIVISION'?'DISTRICT_DS_DIVISION':'DISTRICT_ASC'),
            'ds_division_location_id'=>$type==='GN_DIVISION'?$this->singleParent((string)$location['id'],'DS_DIVISION_GN_DIVISION'):null,
            'asc_location_id'=>$this->singleParent((string)$location['id'],'ASC_ARPA_DIVISION'),
            'arpa_division_location_id'=>$this->singleParent((string)$location['id'],'ARPA_GN_DIVISION'),
        ];

        if($type==='DISTRICT')$values['province_location_id']=$direct['province_location_id'];
        if($type==='DS_DIVISION'){
            $values['district_location_id']=$direct['district_location_id'];
            $values['province_location_id']=$this->singleParent($values['district_location_id'],'PROVINCE_DISTRICT');
        }
        if($type==='ASC'){
            $values['district_location_id']=$direct['district_location_id'];
            $values['province_location_id']=$this->singleParent($values['district_location_id'],'PROVINCE_DISTRICT');
        }
        if($type==='ARPA_DIVISION'){
            $values['asc_location_id']=$direct['asc_location_id'];
            $values['district_location_id']=$this->singleParent($values['asc_location_id'],'DISTRICT_ASC');
            $values['province_location_id']=$this->singleParent($values['district_location_id'],'PROVINCE_DISTRICT');
        }
        if($type==='GN_DIVISION'){
            $values['ds_division_location_id']=$direct['ds_division_location_id'];$values['arpa_division_location_id']=$direct['arpa_division_location_id'];
            $asc=$this->singleParent($values['arpa_division_location_id'],'ASC_ARPA_DIVISION');
            $values['asc_location_id']=$asc;
            $values['district_location_id']=$this->singleParent($asc,'DISTRICT_ASC');
            if($values['district_location_id']===null&&$values['ds_division_location_id']!==null)$values['district_location_id']=$this->singleParent($values['ds_division_location_id'],'DISTRICT_DS_DIVISION');
            $values['province_location_id']=$this->singleParent($values['district_location_id'],'PROVINCE_DISTRICT');
        }

        $fields=$this->fields($type);
        return ['fields'=>$fields,'values'=>$values,'options'=>$this->options($values)];
    }

    public function replace(string $locationId,string $locationType,array $input,string $actorId): array
    {
        if(!$this->pdo->inTransaction())throw new DomainException('Location hierarchy changes require a transaction.');
        if(!LocationDirectEditPolicy::allowed()||(string)(\App\Core\Auth::user()['id']??'')!==$actorId)throw new DomainException('Only SYSTEM_ADMIN may edit Location hierarchy directly.');
        $child=$this->pdo->prepare('SELECT lt.system_key FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE l.id=? FOR UPDATE');
        $child->execute([$locationId]);if((string)$child->fetchColumn()!==$locationType)throw new DomainException('The Location Type does not match the record being edited.');
        $desired=$this->validatedDesired($locationId,$locationType,$input);$before=[];$after=[];$changed=false;
        foreach($desired as $relationshipType=>$parentId){
            $old=$this->currentParentsForUpdate($locationId,$relationshipType);$before[$relationshipType]=$old;
            if($relationshipType!=='DS_DIVISION_GN_DIVISION'&&count($old)!==count(array_unique($old)))throw new DomainException("Duplicate active {$relationshipType} relationships must be repaired before editing.");
            if($parentId===null&&count($old)>1)throw new DomainException("Select one {$relationshipType} parent to resolve the existing ambiguous hierarchy.");
            $wanted=$parentId===null?[]:[$parentId];sort($old);sort($wanted);
            if($old!==$wanted){$this->replaceOne($locationId,$relationshipType,$parentId);$changed=true;}
            $after[$relationshipType]=$wanted;
            if($relationshipType==='DS_DIVISION_GN_DIVISION')$this->assertGnDsCardinality($locationId,$parentId===null?0:1);
        }
        if($changed)Audit::record('location.hierarchy.direct-update','LOCATION',$locationId,['before'=>$before,'after'=>$after,'actor_user_id'=>$actorId]);
        return ['before'=>$before,'after'=>$after,'changed'=>$changed];
    }

    private function validatedDesired(string $locationId,string $type,array $input): array
    {
        if(in_array($type,['PROVINCE','AI_RANGE','MAHAWELI_DIVISION'],true))return [];
        $province=$this->requiredLocation($input,'province_location_id','PROVINCE');
        if($type==='DISTRICT')return ['PROVINCE_DISTRICT'=>$province];
        $district=$this->requiredLocation($input,'district_location_id','DISTRICT');$this->assertRelationship($province,$district,'PROVINCE_DISTRICT');
        if($type==='DS_DIVISION')return ['DISTRICT_DS_DIVISION'=>$district];
        if($type==='ASC')return ['DISTRICT_ASC'=>$district];
        if($type==='ARPA_DIVISION'){
            $asc=$this->requiredLocation($input,'asc_location_id','ASC');$this->assertRelationship($district,$asc,'DISTRICT_ASC');
            return ['ASC_ARPA_DIVISION'=>$asc];
        }
        if($type==='GN_DIVISION'){
            $ds=$this->optionalLocation($input,'ds_division_location_id','DS_DIVISION');if($ds!==null)$this->assertRelationship($district,$ds,'DISTRICT_DS_DIVISION');
            $asc=$this->requiredLocation($input,'asc_location_id','ASC');$this->assertRelationship($district,$asc,'DISTRICT_ASC');
            $arpa=$this->requiredLocation($input,'arpa_division_location_id','ARPA_DIVISION');$this->assertRelationship($asc,$arpa,'ASC_ARPA_DIVISION');
            return ['DS_DIVISION_GN_DIVISION'=>$ds,'ARPA_GN_DIVISION'=>$arpa,'ASC_GN_DIVISION'=>$asc];
        }
        throw new DomainException('This Location Type does not support direct hierarchy editing.');
    }

    private function replaceOne(string $childId,string $relationshipType,?string $parentId): void
    {
        $this->pdo->prepare("UPDATE location_relationship SET active=0,effective_to=CASE WHEN effective_from<CURRENT_DATE() THEN DATE_SUB(CURRENT_DATE(),INTERVAL 1 DAY) ELSE effective_from END WHERE child_location_id=? AND relationship_type=? AND ".self::CURRENT)->execute([$childId,$relationshipType]);
        if($parentId===null)return;
        if($parentId===$childId)throw new DomainException('A Location cannot be its own parent.');
        $cycle=$this->pdo->prepare("WITH RECURSIVE descendants(id) AS (SELECT ? UNION DISTINCT SELECT lr.child_location_id FROM location_relationship lr JOIN descendants d ON d.id=lr.parent_location_id WHERE ".self::CURRENT.") SELECT COUNT(*) FROM descendants WHERE id=?");
        $cycle->execute([$childId,$parentId]);if((int)$cycle->fetchColumn()>0)throw new DomainException('The selected parent would create a Location hierarchy cycle.');
        $duplicate=$this->pdo->prepare("SELECT COUNT(*) FROM location_relationship WHERE parent_location_id=? AND child_location_id=? AND relationship_type=? AND ".self::CURRENT);
        $duplicate->execute([$parentId,$childId,$relationshipType]);if((int)$duplicate->fetchColumn()>0)throw new DomainException('This active Location relationship already exists.');
        $this->pdo->prepare("INSERT INTO location_relationship(id,parent_location_id,child_location_id,relationship_type,effective_from,approval_status,active,created_at) VALUES(UUID(),?,?,?,CURRENT_DATE(),'APPROVED',1,NOW())")->execute([$parentId,$childId,$relationshipType]);
    }

    private function currentParentsForUpdate(string $childId,string $relationshipType): array
    {
        $stmt=$this->pdo->prepare("SELECT parent_location_id FROM location_relationship WHERE child_location_id=? AND relationship_type=? AND ".self::CURRENT." ORDER BY parent_location_id FOR UPDATE");
        $stmt->execute([$childId,$relationshipType]);return array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertGnDsCardinality(string $gnId,int $expected): void
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM location_relationship WHERE child_location_id=? AND relationship_type='DS_DIVISION_GN_DIVISION' AND ".self::CURRENT);
        $stmt->execute([$gnId]);if((int)$stmt->fetchColumn()!==$expected)throw new DomainException('A GN Division can have only one current DS Division relationship.');
    }

    private function requiredLocation(array $input,string $field,string $type): string
    {
        $id=trim((string)($input[$field]??''));if($id==='')throw new DomainException($this->label($field).' is required.');$this->assertLocationType($id,$type);return $id;
    }

    private function optionalLocation(array $input,string $field,string $type): ?string
    {
        $id=trim((string)($input[$field]??''));if($id==='')return null;$this->assertLocationType($id,$type);return $id;
    }

    private function assertLocationType(string $id,string $type): void
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE l.id=? AND lt.system_key=? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' AND l.effective_from<=CURRENT_DATE() AND (l.effective_to IS NULL OR l.effective_to>=CURRENT_DATE())");
        $stmt->execute([$id,$type]);if((int)$stmt->fetchColumn()!==1)throw new DomainException("Select a valid active {$type} Location.");
    }

    private function assertRelationship(string $parentId,string $childId,string $type): void
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(DISTINCT parent_location_id) FROM location_relationship WHERE parent_location_id=? AND child_location_id=? AND relationship_type=? AND ".self::CURRENT);
        $stmt->execute([$parentId,$childId,$type]);if((int)$stmt->fetchColumn()!==1)throw new DomainException('The selected Location hierarchy combination is invalid.');
    }

    private function onlyParent(string $childId,string $relationshipType): string
    {
        $parents=$this->parentIds($childId,$relationshipType);if(count($parents)!==1)throw new DomainException("The selected Location does not have exactly one valid {$relationshipType} parent.");return $parents[0];
    }

    private function singleParent(?string $childId,string $relationshipType): ?string
    {
        if($childId===null||$childId==='')return null;$parents=$this->parentIds($childId,$relationshipType);return count($parents)===1?$parents[0]:null;
    }

    private function parentIds(string $childId,string $relationshipType): array
    {
        $stmt=$this->pdo->prepare("SELECT DISTINCT parent_location_id FROM location_relationship WHERE child_location_id=? AND relationship_type=? AND ".self::CURRENT." ORDER BY parent_location_id");
        $stmt->execute([$childId,$relationshipType]);return array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function options(array $values): array
    {
        $selected=array_values(array_unique(array_filter(array_map('strval',$values))));
        $selectedWhere=$selected!==[]?' OR l.id IN ('.implode(',',array_fill(0,count($selected),'?')).')':'';
        $stmt=$this->pdo->prepare("SELECT lt.system_key,l.id,l.dad_number,l.name_en FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE (lt.system_key='PROVINCE'{$selectedWhere}) AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' AND l.effective_from<=CURRENT_DATE() AND (l.effective_to IS NULL OR l.effective_to>=CURRENT_DATE()) ORDER BY lt.display_order,l.name_en,l.dad_number");
        $stmt->execute($selected);$options=[];foreach($stmt->fetchAll() as $row)$options[$row['system_key']][]=$row;return $options;
    }

    private function fields(string $type): array
    {
        $map=[
            'DISTRICT'=>[['province_location_id','Province','PROVINCE',true]],
            'DS_DIVISION'=>[['province_location_id','Province','PROVINCE',true],['district_location_id','District','DISTRICT',true]],
            'ASC'=>[['province_location_id','Province','PROVINCE',true],['district_location_id','District','DISTRICT',true]],
            'ARPA_DIVISION'=>[['province_location_id','Province','PROVINCE',true],['district_location_id','District','DISTRICT',true],['asc_location_id','Agrarian Service Center','ASC',true]],
            'GN_DIVISION'=>[['province_location_id','Province','PROVINCE',true],['district_location_id','District','DISTRICT',true],['ds_division_location_id','DS Division','DS_DIVISION',false],['asc_location_id','Agrarian Service Center','ASC',true],['arpa_division_location_id','ARPA Division','ARPA_DIVISION',true]],
        ];return $map[$type]??[];
    }

    private function label(string $field): string
    {
        return ['province_location_id'=>'Province','district_location_id'=>'District','ds_division_location_id'=>'DS Division','asc_location_id'=>'Agrarian Service Center','arpa_division_location_id'=>'ARPA Division'][$field]??'Parent Location';
    }
}
