<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class ArpaAppointmentFormOptionsService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array{officer_id?:string,arpa_division_location_id?:string,appointment_type?:string} $requestedSelection
     * @return array<string,mixed>
     */
    public function load(
        string $userId,
        string $ascLocationId,
        string $businessEffectiveDate,
        array $requestedSelection = []
    ): array {
        ArpaAppointmentRules::assertNativeEffectiveDate($businessEffectiveDate);

        $officers=(new ArpaAppointmentCandidateService($this->pdo))
            ->optionsForAsc($userId,$ascLocationId,$businessEffectiveDate);
        $divisions=(new ArpaAppointmentReadService($this->pdo))
            ->vacantDivisionsForAsc($userId,$ascLocationId,$businessEffectiveDate);
        $requirements=(new ArpaDivisionContinuityService($this->pdo))->requirements(
            array_map(static fn(array $row):string=>(string)$row['id'],$divisions),
            $businessEffectiveDate
        );
        foreach($divisions as &$division){
            $requirement=$requirements[(string)$division['id']]??null;
            if($requirement!==null)$division+=$requirement;
        }
        unset($division);

        $state=$this->reconcileSelections(
            $officers,
            $divisions,
            $requestedSelection,
            $businessEffectiveDate
        );
        $state['continuityIssue']=null;
        if($state['selectedDivision']!==''){
            $requirement=$requirements[$state['selectedDivision']];
            $state['continuityIssue']=(new ArpaDivisionContinuityService($this->pdo))
                ->blockingDataIssue($state['selectedDivision'],$requirement,$businessEffectiveDate);
        }
        return $state+[
            'officers'=>$officers,
            'arpaDivisions'=>$divisions,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $officers
     * @param array<int,array<string,mixed>> $divisions
     * @param array{officer_id?:string,arpa_division_location_id?:string,appointment_type?:string} $requestedSelection
     * @return array{selectedOfficer:string,selectedDivision:string,selectedAppointmentType:string,selectionMessages:array<int,string>,displayDate:string}
     */
    public function reconcileSelections(
        array $officers,
        array $divisions,
        array $requestedSelection,
        string $businessEffectiveDate
    ): array {
        $requestedOfficer=trim((string)($requestedSelection['officer_id']??''));
        $requestedDivision=trim((string)($requestedSelection['arpa_division_location_id']??''));
        $requestedType=strtoupper(trim((string)($requestedSelection['appointment_type']??'')));
        $messages=[];

        $officerById=[];
        foreach($officers as $officer)$officerById[(string)$officer['id']]=$officer;
        $divisionIds=array_fill_keys(array_map(static fn(array $row):string=>(string)$row['id'],$divisions),true);

        $selectedOfficer=$requestedOfficer!==''&&isset($officerById[$requestedOfficer])?$requestedOfficer:'';
        if($requestedOfficer!==''&&$selectedOfficer===''){
            $messages[]='The previously selected Officer is not eligible on the selected start date.';
        }

        $selectedDivision=$requestedDivision!==''&&isset($divisionIds[$requestedDivision])?$requestedDivision:'';
        if($requestedDivision!==''&&$selectedDivision===''){
            $messages[]='The previously selected ARPA Division is not vacant on the selected start date.';
        }

        $allowedTypes=$selectedOfficer===''?[]:(array)($officerById[$selectedOfficer]['allowed_appointment_types']??[]);
        $selectedType=$requestedType!==''&&in_array($requestedType,$allowedTypes,true)?$requestedType:'';
        if($requestedType!==''&&$selectedOfficer!==''&&$selectedType===''){
            $messages[]='The previously selected Appointment Type is not available for this Officer on the selected start date.';
        }

        $timestamp=strtotime($businessEffectiveDate);
        return [
            'selectedOfficer'=>$selectedOfficer,
            'selectedDivision'=>$selectedDivision,
            'selectedAppointmentType'=>$selectedType,
            'selectionMessages'=>$messages,
            'displayDate'=>$timestamp===false?$businessEffectiveDate:date('d M Y',$timestamp),
        ];
    }
}
