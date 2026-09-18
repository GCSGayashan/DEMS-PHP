<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\{Audit,NicNormalizer};
use DomainException;
use PDO;
use Throwable;

final class OfficerAdminDirectEditService
{
    private const EDITABLE_FIELDS=[
        'nic','nic_normalized','nic_match_key','employee_number','title_id','name_with_initials',
        'full_name_en','full_name_si','full_name_ta','date_of_birth','expected_retirement_date',
        'gender','civil_status_id','permanent_address','temporary_address','primary_mobile',
        'alternative_mobile','personal_email','official_email','initial_appointment_date',
        'appointment_nature_id','primary_designation_id','class_id','arpa_service_permanency',
        'service_permanented_date','officer_status_id','effective_from','photograph_path',
    ];

    public function __construct(private readonly PDO $pdo){}

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function update(string $officerId,array $data,int $expectedVersion,string $actorId):array
    {
        $context=OfficerAdminDirectEditPolicy::assert($actorId);
        $unexpected=array_diff(array_keys($data),self::EDITABLE_FIELDS);
        if($unexpected!==[])throw new DomainException('The Officer update contains fields that cannot be edited directly.');

        $own=!$this->pdo->inTransaction();
        if($own)$this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare('SELECT * FROM officer WHERE id=? FOR UPDATE');$stmt->execute([$officerId]);$before=$stmt->fetch();
            if(!$before)throw new DomainException('Officer record was not found.');
            $preservedStatus=(string)$before['approval_status'];
            if(!OfficerAdminDirectEditPolicy::supportsStatus($preservedStatus))throw new DomainException('Direct administrative editing is limited to approved or submitted Officers.');
            if((int)$before['version']!==$expectedVersion)throw new DomainException('The Officer changed after this edit form was opened. Reload the profile and try again.');

            $this->validate($officerId,$data);
            $set=[];foreach(array_keys($data) as $column)$set[]=$column.'=?';
            $params=array_values($data);$params[]=$actorId;$params[]=$officerId;$params[]=$expectedVersion;$params[]=$preservedStatus;
            $update=$this->pdo->prepare('UPDATE officer SET '.implode(',',$set).',updated_by=?,updated_at=NOW(),version=version+1 WHERE id=? AND version=? AND approval_status=?');
            $update->execute($params);
            if($update->rowCount()!==1)throw new DomainException('The Officer changed while it was being updated. Reload the profile and try again.');

            $stmt=$this->pdo->prepare('SELECT * FROM officer WHERE id=?');$stmt->execute([$officerId]);$after=$stmt->fetch()?:[];
            $changes=[];$old=[];$new=[];
            foreach(array_keys($data) as $field){
                if(($before[$field]??null)===($after[$field]??null))continue;
                $old[$field]=$before[$field]??null;$new[$field]=$after[$field]??null;
                $changes[$field]=['before'=>$old[$field],'after'=>$new[$field]];
            }
            Audit::record('officer.admin-direct-edit','OFFICER',$officerId,[
                'officer_id'=>$officerId,'actor_user_id'=>$actorId,'canonical_username'=>'dems.admin',
                'active_context'=>OfficerAdminDirectEditPolicy::auditContext($context),
                'changed_fields'=>$changes,'before'=>$old,'after'=>$new,
                'previous_approval_status'=>$preservedStatus,
                'workflow_status_preserved'=>$preservedStatus,
            ]);
            if($own)$this->pdo->commit();
            return $after;
        }catch(Throwable $e){
            if($own&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $data */
    private function validate(string $officerId,array $data):void
    {
        $required=['nic','nic_normalized','nic_match_key','title_id','name_with_initials','full_name_en','date_of_birth','gender','permanent_address','initial_appointment_date','appointment_nature_id','primary_designation_id','arpa_service_permanency','officer_status_id','effective_from'];
        foreach($required as $field)if(!array_key_exists($field,$data)||trim((string)$data[$field])==='')throw new DomainException('All required Officer fields must be supplied.');

        $nic=NicNormalizer::normalize((string)$data['nic']);
        if($nic===null||!NicNormalizer::isValid($nic)||$nic!==(string)$data['nic_normalized']||NicNormalizer::matchKey($nic)!==(string)$data['nic_match_key'])throw new DomainException('NIC format is invalid.');
        $this->assertUnique($officerId,"(nic_normalized=? OR (nic_match_key IS NOT NULL AND nic_match_key=?))",[$nic,NicNormalizer::matchKey($nic)],'NIC already exists.');
        if(($data['employee_number']??null)!==null)$this->assertUnique($officerId,'employee_number=?',[(string)$data['employee_number']],'Employee number already exists.');

        $contacts=OfficerPersonnelValidator::contactNumbers($data['primary_mobile']??null,$data['alternative_mobile']??null);
        if($contacts['primary_mobile']!==($data['primary_mobile']??null)||$contacts['alternative_mobile']!==($data['alternative_mobile']??null))throw new DomainException('Officer contact numbers are not normalized correctly.');
        $permanency=OfficerPersonnelValidator::servicePermanency($data['arpa_service_permanency']??null,$data['service_permanented_date']??null);
        if($permanency['arpa_service_permanency']!==$data['arpa_service_permanency']||$permanency['service_permanented_date']!==($data['service_permanented_date']??null))throw new DomainException('Service Permanency information is invalid.');

        foreach(['date_of_birth','initial_appointment_date','effective_from'] as $field)$this->assertDate((string)$data[$field],$field);
        $retirement=(new \DateTimeImmutable((string)$data['date_of_birth']))->modify('+60 years')->format('Y-m-d');
        if((string)($data['expected_retirement_date']??'')!==$retirement)throw new DomainException('Expected retirement date is inconsistent with Date of Birth.');
        if(!in_array((string)$data['gender'],['MALE','FEMALE'],true))throw new DomainException('Gender is invalid.');

        $this->assertActiveReference('hr_title',(string)$data['title_id'],'Title is invalid.');
        $this->assertActiveReference('designation',(string)$data['primary_designation_id'],'Primary Designation is invalid.');
        $this->assertActiveReference('officer_status',(string)$data['officer_status_id'],'Officer Status is invalid.');
        if(($data['civil_status_id']??null)!==null)$this->assertActiveReference('civil_status',(string)$data['civil_status_id'],'Civil Status is invalid.');
        if(($data['class_id']??null)!==null)$this->assertActiveReference('officer_class',(string)$data['class_id'],'Class is invalid.');

        $stmt=$this->pdo->prepare('SELECT class_required FROM appointment_nature WHERE id=? AND active=1');$stmt->execute([$data['appointment_nature_id']]);$nature=$stmt->fetch();
        if(!$nature)throw new DomainException('Appointment Nature is invalid.');
        if((bool)$nature['class_required']&&($data['class_id']??null)===null)throw new DomainException('Class is required for the selected Appointment Nature.');
        if(($data['class_id']??null)!==null){
            $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM designation_allowed_class WHERE designation_id=? AND active=1');$stmt->execute([$data['primary_designation_id']]);
            if((int)$stmt->fetchColumn()>0){
                $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM designation_allowed_class WHERE designation_id=? AND class_id=? AND active=1 AND approval_status='APPROVED' AND effective_from<=CURRENT_DATE() AND (effective_to IS NULL OR effective_to>=CURRENT_DATE())");
                $stmt->execute([$data['primary_designation_id'],$data['class_id']]);if((int)$stmt->fetchColumn()===0)throw new DomainException('Selected Class is not permitted for this Designation.');
            }
        }

        $personal=$data['personal_email']??null;$official=$data['official_email']??null;
        if($personal!==null&&(!filter_var($personal,FILTER_VALIDATE_EMAIL)||strtolower((string)$personal)!==$personal))throw new DomainException('Email address format is invalid.');
        if($official!==null&&(!filter_var($official,FILTER_VALIDATE_EMAIL)||strtolower((string)$official)!==$official))throw new DomainException('Email address format is invalid.');
        if($personal!==null&&$official!==null&&$personal===$official)throw new DomainException('Personal and official email must be different.');
        foreach(array_filter([$personal,$official]) as $email)$this->assertUnique($officerId,'(LOWER(personal_email)=? OR LOWER(official_email)=?)',[$email,$email],'Email address already belongs to another officer.');
    }

    /** @param list<mixed> $params */
    private function assertUnique(string $officerId,string $where,array $params,string $message):void
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM officer WHERE id<>? AND {$where}");$stmt->execute(array_merge([$officerId],$params));if((int)$stmt->fetchColumn()>0)throw new DomainException($message);
    }
    private function assertActiveReference(string $table,string $id,string $message):void
    {
        if(preg_match('/^[a-z_]+$/',$table)!==1)throw new DomainException('Invalid Officer reference table.');
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id=? AND active=1");$stmt->execute([$id]);if((int)$stmt->fetchColumn()!==1)throw new DomainException($message);
    }
    private function assertDate(string $value,string $field):void
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if(!$date||$date->format('Y-m-d')!==$value)throw new DomainException(str_replace('_',' ',ucwords($field,'_')).' is invalid.');
    }
}
