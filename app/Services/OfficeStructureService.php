<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\NumberService;
use PDO;
use RuntimeException;
use Throwable;

/** Establishes the controlled HEAD/DISTRICT/ASC Office structure without touching Officers. */
final class OfficeStructureService
{
    public function __construct(private readonly PDO $pdo) {}

    public function inspect(): array
    {
        $counts=[];
        foreach (['HEAD_OFFICE','DISTRICT_OFFICE','ASC_OFFICE'] as $key) {
            $s=$this->pdo->prepare('SELECT COUNT(*) FROM office o JOIN office_type ot ON ot.id=o.office_type_id WHERE ot.system_key=?');
            $s->execute([$key]);$counts[$key]=(int)$s->fetchColumn();
        }
        $missing=$this->missingLocationOffices();
        return [
            'office_count'=>(int)$this->pdo->query('SELECT COUNT(*) FROM office')->fetchColumn(),
            'head_offices'=>$counts['HEAD_OFFICE'],
            'district_offices'=>$counts['DISTRICT_OFFICE'],
            'asc_offices'=>$counts['ASC_OFFICE'],
            'missing_district_offices'=>count(array_filter($missing,fn($r)=>$r['office_type_key']==='DISTRICT_OFFICE')),
            'missing_asc_offices'=>count(array_filter($missing,fn($r)=>$r['office_type_key']==='ASC_OFFICE')),
            'would_create'=>count($missing),
            'number_next_value'=>(int)$this->pdo->query("SELECT next_value FROM number_category WHERE category_key='OFFICE'")->fetchColumn(),
        ];
    }

    public function execute(): array
    {
        $before=$this->inspect();
        if ($before['head_offices'] !== 1) {
            throw new RuntimeException('Office structure requires exactly one existing Head Office; found '.$before['head_offices'].'.');
        }
        $created=[];$errors=[];
        foreach ($this->missingLocationOffices() as $row) {
            try {
                $created[]=$this->createLocationOffice($row);
            } catch (Throwable $e) {
                $errors[]=['location_id'=>$row['location_id'],'message'=>$e->getMessage()];
            }
        }
        $after=$this->inspect();
        return compact('before','after','created','errors');
    }

    private function missingLocationOffices(): array
    {
        return $this->pdo->query(
            "SELECT l.id location_id,l.dad_number location_dad,l.name_en location_name,
                    l.name_si location_name_si,l.name_ta location_name_ta,l.effective_from,
                    CASE lt.system_key WHEN 'DISTRICT' THEN 'DISTRICT_OFFICE' ELSE 'ASC_OFFICE' END office_type_key
             FROM location l JOIN location_type lt ON lt.id=l.location_type_id
             LEFT JOIN office o ON o.linked_location_id=l.id
             WHERE lt.system_key IN ('DISTRICT','ASC') AND l.approval_status='APPROVED' AND o.id IS NULL
             ORDER BY FIELD(lt.system_key,'DISTRICT','ASC'),l.dad_number,l.id"
        )->fetchAll();
    }

    private function createLocationOffice(array $row): array
    {
        $this->pdo->beginTransaction();
        try {
            $check=$this->pdo->prepare('SELECT id,dad_number FROM office WHERE linked_location_id=? FOR UPDATE');
            $check->execute([$row['location_id']]);
            if ($existing=$check->fetch()) {
                $this->pdo->commit();
                return ['id'=>$existing['id'],'dad_number'=>$existing['dad_number'],'created'=>false];
            }
            $type=$this->pdo->prepare('SELECT id FROM office_type WHERE system_key=? AND active=1 FOR UPDATE');
            $type->execute([$row['office_type_key']]);$typeId=$type->fetchColumn();
            if (!$typeId) throw new RuntimeException('Required Office Type is missing: '.$row['office_type_key']);
            $dad=NumberService::nextUsing($this->pdo,'OFFICE');$id=(string)$this->pdo->query('SELECT UUID()')->fetchColumn();
            $district=$row['office_type_key']==='DISTRICT_OFFICE';
            $suffix=$district?' District Office':' Agrarian Service Center Office';
            $short=$district?'District Office':'ASC Office';
            $stmt=$this->pdo->prepare("INSERT INTO office
              (id,dad_number,office_type_id,name_en,name_si,name_ta,short_name,linked_location_id,description,effective_from,requested_status,operational_status,approval_status,created_at)
              VALUES(?,?,?,?,?,?,?,?,?,?,'ACTIVE','ACTIVE','APPROVED',NOW())");
            $stmt->execute([$id,$dad,$typeId,$row['location_name'].$suffix,null,null,$row['location_name'].' '.$short,$row['location_id'],'System-established operational Office linked to '.$row['location_dad'].'.',$row['effective_from']]);
            $this->pdo->commit();
            return ['id'=>$id,'dad_number'=>$dad,'location_id'=>$row['location_id'],'type'=>$row['office_type_key'],'created'=>true];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
