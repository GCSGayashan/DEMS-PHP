<?php
declare(strict_types=1);

namespace App\Services;

final class ArpaAppointmentDisplayPresentation
{
    private const EXCEPTION_LABELS=[
        'OFFICER_MULTIPLE_ACTING'=>'Multiple Acting appointments',
    ];

    /** @param array<string,mixed> $row @return array{display_status:string,display_origin:string,exception_labels:array<int,string>} */
    public static function decorate(array $row,?string $today=null):array
    {
        $today??=date('Y-m-d');
        $historyOnly=(int)($row['legacy_history_only']??0)===1;
        $exception=(int)($row['legacy_exception']??0)===1;
        $hasClosure=!empty($row['closure_id'])&&($row['effective_to']??null)!==null;
        $sourceKind=(string)($row['source_kind']??'OPERATIONAL');

        if($sourceKind==='RESERVATION')$status='Reserved';
        elseif($hasClosure&&$historyOnly)$status='Historical Ended';
        elseif($hasClosure&&(string)$row['effective_to']<$today)$status='Ended';
        elseif($historyOnly&&$exception)$status='Historical Exception';
        elseif($historyOnly)$status='Historical';
        elseif((string)($row['effective_from']??'')>$today)$status='Scheduled';
        elseif($hasClosure)$status='Current';
        else $status='Current / Open';

        return [
            'display_status'=>$status,
            'display_origin'=>(string)($row['record_origin']??'')==='LEGACY_IMPORT'
                ?'Imported Record'
                :($sourceKind==='RESERVATION'?'Native Workflow / Reservation':'Native Appointment'),
            'exception_labels'=>$exception?self::exceptionLabels($row['legacy_exception_codes_json']??[]):[],
        ];
    }

    /** @return array<int,string> */
    public static function exceptionLabels(mixed $codes):array
    {
        if(is_string($codes))$codes=json_decode($codes,true)?:[];
        if(!is_array($codes))return [];
        $labels=[];
        foreach($codes as $code){$code=(string)$code;$labels[]=self::EXCEPTION_LABELS[$code]??ArpaAppointmentIssuePresentation::for($code)['title'];}
        return array_values(array_unique($labels));
    }
}
