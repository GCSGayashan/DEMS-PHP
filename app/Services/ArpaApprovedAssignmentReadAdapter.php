<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{Auth,ScopeService,DataTableQuery,DataTableRequest};
use DomainException;
use PDO;
use Throwable;

/** Current Assignments ONLY. Bounded candidates, no appointment ID manifest or DDL.
 * Summary JSON contains capped geographic aggregates, never appointment identities.
 */
final class ArpaApprovedAssignmentReadAdapter
{
    public const MAX_GROUPS=5000;
    public const MAX_SUMMARY_BYTES=1048576;
    public const MAX_READ_SECONDS=60;
    public const SELECT_TIMEOUT_MS=10000;

    public static function summarySource(string $key):string
    {
        if(!in_array($key,['asc_id','district_id'],true))throw new DomainException('Invalid summary key.',422);
        return "JSON_TABLE(?, '$[*]' COLUMNS({$key} CHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PATH '$.id',permanent_count BIGINT PATH '$.permanent_count',acting_count BIGINT PATH '$.acting_count',duty_covering_count BIGINT PATH '$.duty_covering_count',attend_to_duty_count BIGINT PATH '$.attend_to_duty_count',total_count BIGINT PATH '$.total_count')) record_counts";
    }

    public static function run(PDO $pdo,array $config,callable $reader):mixed
    {
        if(!Auth::check()||Auth::activeContext(false)===null||!Auth::can('arpa.appointment.view'))throw new DomainException('ARPA assignment viewing is not authorized.',403);
        $actor=(string)Auth::user()['id'];
        $scope=ScopeService::requiresGeographicRestriction($actor)?array_column(ScopeService::scopedLocations($actor,'ASC'),'id'):null;
        $settings=$pdo->query('SELECT @@session.max_execution_time timeout_ms,@@max_allowed_packet packet_bytes,CURRENT_DATE() reporting_date')->fetch(PDO::FETCH_ASSOC);
        // Capture MySQL's business date once, never the PHP/request clock or a per-batch date.
        $config['approvalReportingDate']=$settings['reporting_date'];
        $deadline=hrtime(true)+self::MAX_READ_SECONDS*1000000000;
        try{
            $timeout=(int)$settings['timeout_ms'];$pdo->exec('SET SESSION max_execution_time='.($timeout>0?min($timeout,self::SELECT_TIMEOUT_MS):self::SELECT_TIMEOUT_MS));
            return (new ArpaAppointmentApprovalQualificationPolicy($pdo))->withCandidateQualification($scope,function(callable $qualify)use($config,$reader,$pdo,$deadline,$settings):mixed{
                $bounded=function(array $ids)use($qualify,$deadline):array{
                    self::checkDeadline($deadline);$result=$qualify($ids);self::checkDeadline($deadline);
                    foreach($result as $q)if($q['reason']==='APPROVAL_EVIDENCE_UNAVAILABLE')throw new DomainException('Approval evidence could not be verified. Retry this read.',503);
                    return $result;
                };
                unset($config['readAdapter']);
                if(isset($config['approvalSummarySource'])){
                    $source=$config['approvalSummarySource'];$source['approvalReportingDate']=$config['approvalReportingDate'];$groups=[];
                    foreach((new DataTableQuery($pdo,$source,new DataTableRequest([])))->candidateBatches() as $rows){
                        $q=$bounded(array_values(array_unique(array_column($rows,'_qualification_id'))));
                        foreach($rows as $row){
                            if(($q[$row['_qualification_id']]['classification']??null)!==ArpaAppointmentApprovalQualificationPolicy::APPROVED)continue;
                            $id=strtolower($row['group_id']); // UUID group identity follows MySQL's case-insensitive keys.
                            if(!isset($groups[$id])){
                                if(count($groups)>=self::MAX_GROUPS)throw new DomainException('Approved assignment geographic summary limit exceeded.',503);
                                $groups[$id]=['id'=>$id,'permanent_count'=>0,'acting_count'=>0,'duty_covering_count'=>0,'attend_to_duty_count'=>0,'total_count'=>0];
                            }
                            $key=strtolower($row['appointment_type']).'_count';if(isset($groups[$id][$key]))$groups[$id][$key]++;
                            $groups[$id]['total_count']++;
                        }
                    }
                    $json=json_encode(array_values($groups),JSON_THROW_ON_ERROR);
                    if(strlen($json)>min(self::MAX_SUMMARY_BYTES,intdiv((int)$settings['packet_bytes'],4)))throw new DomainException('Approved assignment geographic summary packet limit exceeded.',503);
                    $config['baseParams'][$config['approvalQualificationParameter']]=$json;
                    $config['requiresApprovalQualification']=false; // Complete qualified aggregate, still inside this snapshot.
                    unset($config['approvalSummarySource'],$config['approvalQualificationParameter']);
                }else $config['qualificationReader']=$bounded;
                self::checkDeadline($deadline);$result=$reader($config);self::checkDeadline($deadline);return $result;
            });
        }finally{
            try{$pdo->exec('SET SESSION max_execution_time='.(int)$settings['timeout_ms']);}
            catch(Throwable){throw new DomainException('Read connection cleanup failed; discard this connection.',503);}
        }
    }

    private static function checkDeadline(int $deadline):void
    {
        if(hrtime(true)>$deadline)throw new DomainException('Approved assignment read timed out. Narrow the geographic or search scope and retry.',503);
    }
}
