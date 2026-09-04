<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ScopeService;
use DomainException;
use PDO;

final class LocationHierarchyLookupService
{
    private const RELATIONSHIPS = [
        'PROVINCE:DISTRICT' => 'PROVINCE_DISTRICT',
        'DISTRICT:DS_DIVISION' => 'DISTRICT_DS_DIVISION',
        'DISTRICT:ASC' => 'DISTRICT_ASC',
        'ASC:ARPA_DIVISION' => 'ASC_ARPA_DIVISION',
        'DS_DIVISION:GN_DIVISION' => 'DS_DIVISION_GN_DIVISION',
        'ARPA_DIVISION:GN_DIVISION' => 'ARPA_GN_DIVISION',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function children(string $actorId,string $parentId,string $childType,string $search='',int $limit=500): array
    {
        $childType=strtoupper(trim($childType));
        $parent=$this->currentLocation($parentId);
        $relationship=self::RELATIONSHIPS[$parent['type_key'].':'.$childType]??null;
        if($relationship===null)throw new DomainException('The selected Location hierarchy level is invalid.');
        if(!ScopeService::canAccessLocation($actorId,$parentId))throw new DomainException('The selected parent Location is outside your current authority.');

        $restricted=ScopeService::requiresGeographicRestriction($actorId);
        $with=$restricted?ScopeService::visibleLocationsCte($actorId):'';
        $visibleJoin=$restricted?' JOIN visible_locations visible_child ON visible_child.id=child.id':'';
        $params=$restricted?ScopeService::visibleLocationParams($actorId):[];
        $where='';$search=trim($search);
        if($search!==''){
            $where=" AND CONCAT_WS(' ',child.dad_number,child.official_code,child.name_en) LIKE ?";
        }
        $params=array_merge($params,[$parentId,$relationship,$childType]);
        if($search!=='')$params[]='%'.$search.'%';

        $sql=$with." SELECT DISTINCT child.id,child.dad_number,child.official_code,child.name_en,child_type.system_key type_key
            FROM location_relationship lr
            JOIN location child ON child.id=lr.child_location_id
            JOIN location_type child_type ON child_type.id=child.location_type_id
            {$visibleJoin}
            WHERE lr.parent_location_id=? AND lr.relationship_type=? AND child_type.system_key=?
              AND lr.active=1 AND lr.approval_status='APPROVED'
              AND lr.effective_from<=CURRENT_DATE() AND (lr.effective_to IS NULL OR lr.effective_to>=CURRENT_DATE())
              AND child.operational_status='ACTIVE' AND child.approval_status='APPROVED'
              AND child.effective_from<=CURRENT_DATE() AND (child.effective_to IS NULL OR child.effective_to>=CURRENT_DATE())
              {$where}
            ORDER BY child.name_en,child.dad_number
            LIMIT ".max(1,min(1000,$limit));
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array{id:string,type_key:string} */
    private function currentLocation(string $id): array
    {
        $stmt=$this->pdo->prepare("SELECT l.id,lt.system_key type_key FROM location l JOIN location_type lt ON lt.id=l.location_type_id WHERE l.id=? AND l.operational_status='ACTIVE' AND l.approval_status='APPROVED' AND l.effective_from<=CURRENT_DATE() AND (l.effective_to IS NULL OR l.effective_to>=CURRENT_DATE())");
        $stmt->execute([$id]);$row=$stmt->fetch();
        if(!$row)throw new DomainException('Select a valid current parent Location.');
        return ['id'=>(string)$row['id'],'type_key'=>(string)$row['type_key']];
    }
}
