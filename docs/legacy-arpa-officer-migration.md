# Legacy ARPA Officer Master migration

Run `php bin/migrate-legacy-arpa-officers.php --dry-run` first. After review,
an authorized administrator may run the same command with `--execute`.
Dry-run reads `dems_legacy_hr` through the separate legacy PDO connection,
writes report files only, makes no target database changes, and allocates no
enterprise numbers.

The selector is the distinct `officer_id` population from
`tbl_officer_apoint_2026` where `officer_level = 'ARPA Division'`. Appointment
rows are used only to select the population. No appointment or assignment field
is read into an Officer.

| Legacy `tbl_officer` | Target `officer` |
| --- | --- |
| safely canonical legacy `nic` | `nic`, `nic_normalized`, and `nic_match_key`; invalid values remain `NULL` |
| `name_with_initial` | `name_with_initials` |
| `full_name` | `full_name_en` |
| valid `birth_day` | `date_of_birth` (invalid/zero remains `NULL`) |
| recognized `gender` | `gender` (`MALE`/`FEMALE`) |
| `residential_address` | `permanent_address` |
| `tp_no` | `primary_mobile` |
| non-empty `whatsapp_no` | `alternative_mobile` |
| valid, non-shared `email_address` | `personal_email` |
| valid `first_appoint_date` | `initial_appointment_date` |
| `officer_status` 1/0 | `officer_status_id` ACTIVE/INACTIVE |
| valid `created_at` date | `effective_from` |

`officer_id`, the source designation, source status, raw profile fields, and
source timestamps are retained in `legacy_officer_reference`. The source
designation is traceability only. `title_id`, translated names, retirement
date, appointment nature, primary designation, class, primary office,
employee number, and all assignment fields remain `NULL`.

NIC cleanup is intentionally narrow: surrounding whitespace is trimmed,
`v`/`x` is uppercased, whitespace immediately before a final `V`/`X` is
removed, and a trailing slash is removed only when the result is otherwise a
valid 9-digit-plus-letter or 12-digit NIC. Numeric digits are never changed.
If validation still fails, all target NIC fields remain `NULL`; the exact raw
source value remains in the reference payload and CSV report.

Execute mode allocates DAD numbers only through `NumberService` using category
`OFFICER` (`70045`). Each Officer, number allocation, and legacy reference are
committed atomically. A legacy reference is checked before NIC matching, making
reruns idempotent.

## ARPA designation and Officer Class

Migration `012_arpa_officer_designation_classes` preserves the existing
`ARPA_OFFICER` designation ID, system key, and `72003-0000003` DAD number while
renaming it to **Agriculture Research and Production Assistant**. It creates
approved `CLASS_I`, `CLASS_II`, and `CLASS_III` masters through the
`OFFICER_CLASS` enterprise number ledger.

Run `php bin/backfill-legacy-arpa-officer-designation-class.php --dry-run` to
reconcile imported Officers selected exclusively through
`legacy_officer_reference`. Execute mode changes only
`officer.primary_designation_id` and `officer.class_id`; it creates no
appointment, Office/ARPA assignment, user, role, permission, or scope record.

Legacy grades are trimmed and mapped exactly: `Grade1`/`Grade 1` to `CLASS_I`,
`Grade2`/`Grade 2` to `CLASS_II`, and `Grade3`/`Grade 3` to `CLASS_III`.
`Select`, blank, and unsupported grades leave `class_id` NULL.

## Special-function ASC reconciliation

The Legacy Migration Review workbench keeps derived ASC evidence separate from
the administrator's decision. `STRONG_DERIVED` and `CURRENT_STATE_ONLY` are
evidence classes only; neither is accepted directly by the Office backfill or
final appointment importer.

The **Bulk Confirm Strong-Derived ASC** action is user-initiated and includes
only current special-function records with one non-conflicting candidate ASC,
an exact target Officer, an active approved ASC, an active approved ASC Office,
and no existing decision. It creates one `CONFIRM_ASC` decision and one audit
row per reconciled business record. The batch ID, original evidence class,
evidence summary, administrator, timestamp, and reason are retained. Repeating
the action does not duplicate decisions.

`CURRENT_STATE_ONLY` records remain in the manual review queue. Administrators
must explicitly confirm the candidate, select another ASC, or preserve the
record as history only. Group review is by Officer, function, and candidate ASC,
but decisions remain record-level. The workbench never creates Office
assignments, ARPA appointments, subject assignments, or Sithamu periods.

After decisions are complete, run only the dry-runs first:

```powershell
php bin/backfill-current-special-function-asc-offices.php --dry-run
php bin/migrate-legacy-arpa-appointments.php --dry-run
```

Office backfill execution and appointment migration execution remain separate,
explicitly controlled operations.
