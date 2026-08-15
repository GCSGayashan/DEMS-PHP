<?php
declare(strict_types=1);

namespace App\Services;

/** Plain-language labels for Appointment Data Issues. Internal codes remain unchanged. */
final class ArpaAppointmentIssuePresentation
{
    private const ISSUES = [
        'DIVISION_MULTIPLE_OPEN' => [
            'title' => 'More than one current appointment for this ARPA Division',
            'explanation' => 'This ARPA Division has more than one appointment without an end date.',
            'what_to_check' => 'Check which appointment is the current appointment and close or correct the other record if necessary.',
        ],
        'OFFICER_MULTIPLE_PERMANENT' => [
            'title' => 'Officer has more than one Permanent appointment',
            'explanation' => 'This officer has more than one Permanent appointment without an end date.',
            'what_to_check' => 'Check which Permanent appointment is correct.',
        ],
        'OFFICER_MULTIPLE_ACTING' => [
            'title' => 'Officer has more than one Acting appointment',
            'explanation' => 'This officer has more than one Acting appointment without an end date.',
            'what_to_check' => 'Check whether both Acting appointments are correct.',
        ],
        'OFFICER_MULTIPLE_ATTEND_TO_DUTY' => [
            'title' => 'Officer has more than one Attend to the Duty appointment',
            'explanation' => 'This officer has more than one Attend to the Duty appointment without an end date.',
            'what_to_check' => 'Check which appointment is currently valid.',
        ],
        'DEPENDENT_WITHOUT_PERMANENT' => [
            'title' => 'Permanent appointment not found',
            'explanation' => 'This officer has an Acting, Duty Covering, or Attend to the Duty appointment, but DEMS cannot find a valid Permanent appointment for the required period.',
            'what_to_check' => "Check the officer's Permanent appointment history.",
        ],
        'DEPENDENT_WITHOUT_QUALIFYING_PERMANENT' => [
            'title' => 'Permanent appointment not found',
            'explanation' => 'This officer has an Acting, Duty Covering, or Attend to the Duty appointment, but DEMS cannot find a valid Permanent appointment for the required period.',
            'what_to_check' => "Check the officer's Permanent appointment history.",
        ],
        'PERMANENT_SERVICE_WITH_ATTEND_TO_DUTY' => [
            'title' => 'Attend to the Duty appointment may be incorrect',
            'explanation' => 'This officer is recorded as Permanent in Service and also has an Attend to the Duty appointment.',
            'what_to_check' => "Check the officer's service status and appointment type.",
        ],
        'NON_PERMANENT_SERVICE_WITH_ACTING' => [
            'title' => 'Acting appointment may be incorrect',
            'explanation' => 'This officer is recorded as Not Permanent in Service and also has an Acting appointment.',
            'what_to_check' => "Check the officer's service status and appointment type.",
        ],
        'EXCLUSIVE_FUNCTION_OVERLAP' => [
            'title' => 'Special function overlaps another appointment',
            'explanation' => 'This officer has a special function and another current appointment at the same time.',
            'what_to_check' => 'Check which appointment or function should remain current.',
        ],
        'MULTIPLE_EXCLUSIVE_FUNCTIONS' => [
            'title' => 'Officer has more than one special function',
            'explanation' => 'This officer has more than one current special function at the same time.',
            'what_to_check' => 'Check which special function is correct.',
        ],
        'MISSING_ASC_OFFICE_ASSIGNMENT' => [
            'title' => 'ASC Office assignment is missing',
            'explanation' => 'This officer does not have a current Office assignment for the recorded Agrarian Service Center.',
            'what_to_check' => "Check the officer's current Office assignments.",
        ],
        'APPOINTMENT_OUTSIDE_ASC' => [
            'title' => 'ARPA Division and ASC do not match',
            'explanation' => 'This ARPA Division is not listed under the selected Agrarian Service Center.',
            'what_to_check' => 'Check the ASC and ARPA Division.',
        ],
        'ARPA_DIVISION_ASC_HIERARCHY_MISMATCH' => [
            'title' => 'ARPA Division and ASC do not match',
            'explanation' => 'This ARPA Division is not listed under the selected Agrarian Service Center.',
            'what_to_check' => 'Check the ASC and ARPA Division.',
        ],
        'MISSING_ARPA_DIVISION' => [
            'title' => 'ARPA Division is missing',
            'explanation' => 'No ARPA Division is recorded for this appointment.',
            'what_to_check' => 'Select the correct ARPA Division if it can be confirmed.',
        ],
        'INVALID_DATE_RANGE' => [
            'title' => 'Appointment dates are not correct',
            'explanation' => 'The appointment end date is earlier than the start date.',
            'what_to_check' => 'Check the appointment start date and end date.',
        ],
        'INVALID_EFFECTIVE_PERIOD' => [
            'title' => 'Appointment dates are not correct',
            'explanation' => 'The appointment end date is earlier than the start date.',
            'what_to_check' => 'Check the appointment start date and end date.',
        ],
        'ENDED_APPOINTMENT_WITHOUT_END_REASON' => [
            'title' => 'End reason is missing',
            'explanation' => 'This appointment has an end date but no reason for ending.',
            'what_to_check' => 'Select the correct end reason.',
        ],
        'END_DATE_WITHOUT_REASON' => [
            'title' => 'End reason is missing',
            'explanation' => 'This appointment has an end date but no reason for ending.',
            'what_to_check' => 'Select the correct end reason.',
        ],
        'OPEN_APPOINTMENT_WITH_END_REASON' => [
            'title' => 'End date is missing',
            'explanation' => 'An end reason is recorded, but the appointment does not have an end date.',
            'what_to_check' => 'Check and enter the correct end date.',
        ],
        'REASON_WITHOUT_END_DATE' => [
            'title' => 'End date is missing',
            'explanation' => 'An end reason is recorded, but the appointment does not have an end date.',
            'what_to_check' => 'Check and enter the correct end date.',
        ],
        'FUTURE_OVERLAP_CONFLICT' => [
            'title' => 'Future appointment overlaps another appointment',
            'explanation' => 'A future appointment is set for an ARPA Division that already has a current or future appointment.',
            'what_to_check' => 'Check the appointment dates and the current appointment for this ARPA Division.',
        ],
        'LEGACY_HISTORICAL_EXCEPTION' => [
            'title' => 'Old appointment information needs review',
            'explanation' => 'This old record contains unusual information preserved from the previous system.',
            'what_to_check' => 'Check the old record only when reliable supporting information is available.',
        ],
        'MANUAL_REVIEW_REQUIRED' => [
            'title' => 'Old appointment needs a review decision',
            'explanation' => 'DEMS could not safely confirm all information for this old appointment.',
            'what_to_check' => 'Review the available old records and select only information that can be confirmed.',
        ],
    ];

    /** @return array{title:string,explanation:string,what_to_check:string} */
    public static function for(string $issueCode): array
    {
        return self::ISSUES[$issueCode] ?? [
            'title' => 'Appointment record needs checking',
            'explanation' => 'DEMS found information in this appointment that may not be correct.',
            'what_to_check' => 'Review the appointment information and supporting records.',
        ];
    }

    public static function severity(string $severity): string
    {
        return match ($severity) {
            'ERROR', 'CRITICAL' => 'Needs Correction',
            'WARNING' => 'Please Check',
            'HISTORICAL_EXCEPTION' => 'Old Data Warning',
            'RESOLVED', 'RESOLVED_BY_CORRECTION', 'REVIEWED_UNRESOLVED', 'KEPT_HISTORICAL_EXCEPTION' => 'Reviewed',
            default => 'Please Check',
        };
    }

    public static function action(string $action): string
    {
        return match ($action) {
            'MARK_HISTORICAL_ONLY' => 'Mark as Old Record - History Only',
            'SET_EFFECTIVE_TO' => 'Correct Appointment End Date',
            'CORRECT_APPOINTMENT_TYPE' => 'Correct Appointment Type',
            'CORRECT_ARPA_DIVISION' => 'Correct ARPA Division',
            'CORRECT_EFFECTIVE_FROM' => 'Correct Appointment Start Date',
            'CORRECT_END_REASON' => 'Correct End Reason',
            'SELECT_CURRENT_RECORD' => 'Select the Current Appointment',
            'KEEP_AS_HISTORICAL_EXCEPTION' => 'Keep as Historical Record',
            default => ucwords(strtolower(str_replace('_', ' ', $action))),
        };
    }

    public static function resolution(string $status): string
    {
        return match ($status) {
            'RESOLVED_BY_CORRECTION' => 'Corrected',
            'REVIEWED_UNRESOLVED' => 'Reviewed - Please Check',
            'KEPT_HISTORICAL_EXCEPTION' => 'Kept as Historical Record',
            default => 'Reviewed',
        };
    }
}
