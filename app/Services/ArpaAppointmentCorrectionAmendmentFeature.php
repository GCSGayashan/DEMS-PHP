<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class ArpaAppointmentCorrectionAmendmentFeature
{
    public static function enabled():bool
    {
        return config('features.arpa_correction_amendments',false)===true;
    }

    public static function schemaAvailable(PDO $pdo):bool
    {
        if(!self::enabled())return false;
        $stmt=$pdo->prepare("SELECT
            (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_request') request_table,
            (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='arpa_appointment_correction_amendment_event') event_table,
            (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction' AND column_name='supersedes_correction_id') supersedes_column,
            (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='arpa_appointment_data_correction' AND column_name='amendment_request_id') amendment_column");
        $stmt->execute();$row=$stmt->fetch();
        return $row&&(int)$row['request_table']===1&&(int)$row['event_table']===1
            &&(int)$row['supersedes_column']===1&&(int)$row['amendment_column']===1;
    }
}
