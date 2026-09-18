<?php
declare(strict_types=1);

use App\Services\ArpaAppointmentDisplayPresentation;

require dirname(__DIR__).'/bootstrap.php';

final class ArpaAppointmentDisplayClassificationTest
{
    private int $assertions=0;

    public function run():int
    {
        $today='2026-09-18';
        $historicalException=$this->decorate([
            'record_origin'=>'LEGACY_IMPORT','legacy_history_only'=>1,'legacy_exception'=>1,
            'legacy_exception_codes_json'=>'["OFFICER_MULTIPLE_ACTING"]','effective_from'=>'2026-01-01',
            'effective_to'=>null,'closure_id'=>null,
        ],$today);
        $this->same('Historical Exception',$historicalException['display_status'],'an open legacy historical exception is not presented as ended');
        $this->same('Imported Record',$historicalException['display_origin'],'legacy origin has a readable label');
        $this->same(['Multiple Acting appointments'],$historicalException['exception_labels'],'multiple Acting exception has a readable label');
        $this->same(null,$historicalException['effective_to'],'classification does not fabricate an end date');

        $historical=$this->decorate([
            'record_origin'=>'LEGACY_IMPORT','legacy_history_only'=>1,'legacy_exception'=>0,
            'effective_from'=>'2025-01-01','effective_to'=>null,'closure_id'=>null,
        ],$today);
        $this->same('Historical',$historical['display_status'],'an open history-only record is presented as historical');

        $historicalEnded=$this->decorate([
            'record_origin'=>'LEGACY_IMPORT','legacy_history_only'=>1,'legacy_exception'=>1,
            'effective_from'=>'2025-01-01','effective_to'=>'2025-12-31','closure_id'=>'closure-1',
        ],$today);
        $this->same('Historical Ended',$historicalEnded['display_status'],'a legacy history-only record with an actual closure is ended');

        $current=$this->decorate([
            'record_origin'=>'NATIVE','legacy_history_only'=>0,'legacy_exception'=>0,
            'effective_from'=>'2026-01-01','effective_to'=>null,'closure_id'=>null,
        ],$today);
        $this->same('Current / Open',$current['display_status'],'a normal open appointment is current');

        $ended=$this->decorate([
            'record_origin'=>'NATIVE','legacy_history_only'=>0,'legacy_exception'=>0,
            'effective_from'=>'2025-01-01','effective_to'=>'2025-12-31','closure_id'=>'closure-2',
        ],$today);
        $this->same('Ended',$ended['display_status'],'a normal appointment with a past actual closure is ended');

        $profileService=(string)file_get_contents(BASE_PATH.'/app/Services/OfficerProfileService.php');
        $timelineService=(string)file_get_contents(BASE_PATH.'/app/Services/ArpaOfficerTimelineService.php');
        $divisionTimelineService=(string)file_get_contents(BASE_PATH.'/app/Services/ArpaDivisionTimelineService.php');
        $profileView=(string)file_get_contents(BASE_PATH.'/app/Views/officers/show.php');
        $timelineView=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/timeline/officer_detail.php');
        $divisionTimelineView=(string)file_get_contents(BASE_PATH.'/app/Views/arpa_appointments/timeline/detail.php');
        $this->same(true,str_contains($profileService,'ArpaAppointmentDisplayPresentation::decorate($row)')&&str_contains($timelineService,'ArpaAppointmentDisplayPresentation::decorate($row)')&&str_contains($divisionTimelineService,'ArpaAppointmentDisplayPresentation::decorate($row)'),'Officer Profile and Appointment Timelines use the same display classifier');
        $this->same(true,str_contains($profileView,"['display_status']")&&str_contains($timelineView,"['display_status']")&&str_contains($divisionTimelineView,"['display_status']"),'all views render the shared status label');
        $this->same(true,str_contains($profileView,"['exception_labels']")&&str_contains($timelineView,"['exception_labels']")&&str_contains($divisionTimelineView,"['exception_labels']"),'all views render friendly exception labels');

        echo "ArpaAppointmentDisplayClassificationTest: {$this->assertions} assertions passed.\n";
        return 0;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decorate(array $row,string $today):array
    {
        return array_merge($row,ArpaAppointmentDisplayPresentation::decorate($row,$today));
    }

    private function same(mixed $expected,mixed $actual,string $message):void
    {
        $this->assertions++;
        if($expected!==$actual)throw new RuntimeException($message.': expected '.var_export($expected,true).', got '.var_export($actual,true));
    }
}

exit((new ArpaAppointmentDisplayClassificationTest())->run());
