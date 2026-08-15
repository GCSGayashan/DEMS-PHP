<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

/** Location hierarchy rules used when reading historical ARPA appointments. */
final class ArpaAppointmentLocationPolicy
{
    public const LOCATION_HIERARCHY_BASELINE_DATE = LocationHierarchyEffectiveDatePolicy::BASELINE_DATE;

    public static function validationDate(string $appointmentDate): string
    {
        return LocationHierarchyEffectiveDatePolicy::validationDate($appointmentDate);
    }

    public static function validationDateSql(string $appointmentDateColumn): string
    {
        return LocationHierarchyEffectiveDatePolicy::validationDateSql($appointmentDateColumn);
    }

    /**
     * The first approved relationship version is the authoritative baseline snapshot.
     * Later versions retain their own effective dates.
     */
    public static function relationshipAtSql(string $alias, string $validationDateSql): string
    {
        return LocationHierarchyEffectiveDatePolicy::relationshipAtSql($alias,$validationDateSql);
    }

    /** @return array<string,mixed> */
    public function hierarchyContext(PDO $pdo, string $arpaDivisionId, string $recordedAscId, string $appointmentDate): array
    {
        $validationDate = self::validationDate($appointmentDate);
        $parents = $this->parents($pdo, $arpaDivisionId, 'ASC_ARPA_DIVISION', $appointmentDate);
        $matching = array_values(array_filter($parents, fn(array $row): bool => (string)$row['id'] === $recordedAscId));

        return [
            'appointment_start_date' => $appointmentDate,
            'location_validation_date' => $validationDate,
            'is_old_appointment' => $appointmentDate < self::LOCATION_HIERARCHY_BASELINE_DATE,
            'recorded_asc_id' => $recordedAscId,
            'arpa_division_id' => $arpaDivisionId,
            'correct_ascs' => $parents,
            'matches' => $matching !== [],
            'result' => $matching !== [] ? 'Correct' : 'Does not match',
        ];
    }

    /** @return array<int,array{id:string,dad_number:string,name_en:string}> */
    public function parents(PDO $pdo, string $childLocationId, string $relationshipType, string $appointmentDate): array
    {
        if (preg_match('/^[A-Z_]{3,80}$/', $relationshipType) !== 1) {
            throw new DomainException('Invalid location relationship type.');
        }
        $validationDate = self::validationDate($appointmentDate);
        $relationship = self::relationshipAtSql('lr', '?');
        $sql = "SELECT parent_l.id,parent_l.dad_number,parent_l.name_en
                FROM location_relationship lr
                JOIN location parent_l ON parent_l.id=lr.parent_location_id
                WHERE lr.child_location_id=? AND lr.relationship_type=?
                  AND {$relationship}
                ORDER BY lr.effective_from DESC,lr.id";
        $statement = $pdo->prepare($sql);
        $statement->execute([$childLocationId, $relationshipType, $validationDate, $validationDate]);
        return $statement->fetchAll();
    }
}
