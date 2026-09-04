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
            ->timelineDivisionsForAsc($userId,$ascLocationId,$businessEffectiveDate);
        $requirements=(new ArpaDivisionContinuityService($this->pdo))->requirements(
            array_map(static fn(array $row):string=>(string)$row['id'],$divisions),
            $businessEffectiveDate
        );
        foreach($divisions as &$division){
            $requirement=$requirements[(string)$division['id']]??null;
            if($requirement!==null){
                $division+=$requirement;
                $division['timeline_label']=$this->timelineLabel($requirement);
            }
        }
        unset($division);

        $state=$this->reconcileSelections(
            $officers,
            $divisions,
            $requestedSelection,
            $businessEffectiveDate
        );
        $state['continuityIssue']=null;$state['unresolvedDataIssues']=[];
        if($state['selectedDivision']!==''){
            $continuity=new ArpaDivisionContinuityService($this->pdo);
            $state['unresolvedDataIssues']=$continuity->unresolvedDataIssues($state['selectedDivision']);
            $state['continuityIssue']=$state['unresolvedDataIssues'][0]??null;
            if($state['unresolvedDataIssues']!==[]){
                foreach($divisions as &$division){
                    if((string)$division['id']!==$state['selectedDivision'])continue;
                    $division['unresolved_data_issue_count']=count($state['unresolvedDataIssues']);
                    $division['timeline_status']='UNRESOLVED_DATA_ISSUE';
                    $division['timeline_label']=$this->timelineLabel($division);
                    break;
                }
                unset($division);
            }
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
            $messages[]='The previously selected ARPA Division has no uncovered period available on the selected start date or is outside the selected ASC.';
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

    /** @param array<string,mixed> $diagnostic */
    private function timelineLabel(array $diagnostic):string
    {
        $issues=(int)($diagnostic['unresolved_data_issue_count']??0);
        if($issues>0)return $issues===1?'Data Issue - Review First':$issues.' Data Issues - Review First';
        $status=(string)($diagnostic['timeline_status']??'');
        if($status==='INVALID_PERIOD')return 'Invalid Assignment Period';
        if($status==='MULTIPLE_OPEN_ASSIGNMENTS')return 'Multiple Open Assignments';
        if($status==='OVERLAP')return 'Overlapping Assignment History';
        $start=$diagnostic['gap_start']??null;$end=$diagnostic['gap_end']??null;
        if($start!==null){
            if($start===ArpaDivisionContinuityService::BASELINE&&$end===null)return 'No History From 01 Jan 2025';
            if($start===ArpaDivisionContinuityService::BASELINE)return 'Missing: 01 Jan 2025 - '.$this->displayDate((string)$end);
            return 'Missing: '.$this->displayDate((string)$start).' - '.($end===null?'Open':$this->displayDate((string)$end));
        }
        return match($status){
            'COMPLETE'=>'Complete Timeline - No Missing Period',
            default=>'Available Period',
        };
    }

    private function displayDate(string $date):string
    {
        $timestamp=strtotime($date);return $timestamp===false?$date:date('d M Y',$timestamp);
    }
}
