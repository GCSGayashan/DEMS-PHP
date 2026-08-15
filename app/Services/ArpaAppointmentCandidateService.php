<?php
declare(strict_types=1);
namespace App\Services;

use PDO;

/** Candidate discovery is intentionally separate from the current Officer directory. */
final class ArpaAppointmentCandidateService
{
    public function __construct(private readonly PDO $pdo){}

    public function options():array
    {
        return $this->pdo->query("SELECT o.id,o.dad_number,o.name_with_initials,o.arpa_service_permanency FROM officer o JOIN designation d ON d.id=o.primary_designation_id WHERE d.system_key='ARPA_OFFICER' AND d.active=1 AND d.approval_status='APPROVED' AND o.approval_status='APPROVED' ORDER BY o.name_with_initials")->fetchAll();
    }

    public function optionsForAsc(string $userId,string $ascLocationId,string $effectiveDate):array
    {
        return (new ArpaAppointmentReadService($this->pdo))->eligibleOfficersForAsc($userId,$ascLocationId,$effectiveDate);
    }
}
