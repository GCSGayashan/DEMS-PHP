# Controlled historical approval registration

This CLI registers **DRAFT governance cases only** for the reported historical
cohort. It never submits, approves, materializes, closes, demotes or edits an
appointment, source request or correction. There is no web registration route.

## Prerequisites

- Review/deploy the Phase 1–3 files together and verify correction lineage
  (062, existing 063 baseline) and complete migration 067 schema, including its
  append-only event triggers. This command runs no migrations.
- Take and verify a recoverable backup before an authorized production apply.
- Use an access-controlled OS administrator session and protected application
  database configuration. The CLI is a trusted administrative tool, not a
  password-login endpoint. `--operator` identifies the actual responsible
  operator's canonical dems.admin account; it does not authenticate an arbitrary
  end user. Never grant untrusted shell users access to this command/configuration.
- Supply that account's active `SYSTEM_ADMIN` / `SYSTEM` role-assignment ID
  (and scope-assignment ID only if its existing context requires one).
  User enabled/approval state, role, permission and context are checked through
  the existing Auth/UserContext model. A username alone is insufficient.
- Confirm the configured database and server against independent deployment
  records. Do not assume that a development `.env` targets production or vice versa.
- Protect the manifest: it includes officer names/identifiers and source evidence,
  but no NIC, passwords or connection credentials. Do not commit manifests.

## Default read-only command

```text
php bin/register-arpa-historical-approvals.php --operator=<admin-id> --role-assignment=<system-role-assignment-id>
```

`--dry-run` is optional. Options use `--name=value` syntax; quote whole arguments
containing spaces as appropriate for the host shell. Output is a JSON manifest
with actual database/server identity, canonical/request IDs, officer/ASC/Division,
type, canonical dates/closure, selected correction/evidence, case identity/status,
source baseline hash, classification/reason, counts, manifest hash and exact
confirmation text. No operational write or case/event insertion occurs.

Discovery is deliberately limited to canonical `LEGACY_IMPORT` appointments
with `legacy_history_only=0`, `legacy_exception=1`, `approved_at IS NULL`, linked
to original `CREATED` requests. Missing request links are reported, not hidden by
an inner join. Soft-deleted/excluded sources remain discoverable but are blocked.
There is no hard-coded count, officer list, type composition, date or source-table
year. Other original statuses, native/history-only/approved appointments and
non-exception canonical appointments are outside this particular registration
cohort. A mismatch with the reported 39 must be investigated, not forced to 39.

Classifications:

- `ELIGIBLE`: uniquely linked effective canonical-resolution decision, valid
  source identities/provenance/dates, and no active amendment/conflicting case.
  This is **not** a statement that the appointment is eligible for approval.
- `ALREADY_REGISTERED`: the same source decision already has a consistent case
  and REGISTER audit. Preserve it, even if terminal; never restart it.
- `MANUAL_REVIEW`: deleted/excluded source or exclusion audit; missing/mismatched
  identities/provenance; invalid/ambiguous closure; missing/ambiguous effective
  resolution; effective KEEP; cyclic/cross-record/unapplied lineage; pending
  amendment; or conflicting/inconsistent existing case/audit. No writes for it.

Only explicit valid supersession retires a decision. Unrelated corrections are
not discarded. Permanent foundations need not complete approval before dependent
cases can be registered as DRAFT. Existing Phase 1 validation still controls
submission and approval, including final Permanent-foundation revalidation.

## Explicit apply

Review every manifest row, resolve/review manual blockers separately, and retain
the protected manifest and backup reference. Then add all of the following to
the same operator/context command:

```text
--apply --database=<actual-database> --server-uuid=<actual-server-uuid>
--manifest-hash=<reviewed-manifest-hash>
--confirm="<exact confirmation from the dry-run>"
--reason="<registration reason>" --evidence-reference="<verified HR/evidence reference>"
```

The command checks the explicit target before writes. Apply re-discovers the
complete manifest under a READ COMMITTED transaction, locks sources using the
Phase 1 lock order, and requires an unchanged hash/confirmation. It calls the
shared Phase 1 writer with each locked baseline hash. Any failure rolls back the
whole batch, including earlier successful case/event inserts. Existing unique
indexes additionally protect against concurrent duplicate registration.

Each new case has actual registration actor/context/time and exactly one
REGISTER event, with no fabricated historic approval timestamps. An old apply
manifest fails after registration; a fresh repeat reports existing cases and is
a no-op. No case/event is deleted to retry a failed registration.

## Automatic future registration (canonicalization transactions)

`ArpaHistoricalAppointmentApprovalService::registerCanonicalCorrectionInTransaction()`
is an internal, transaction-only adapter to the same Phase 1 case/event writer.
It has no route, does not use the CLI identity setup, does not impersonate an ASC,
and does not grant submission/review/approval permissions.

Integration points:

1. `ArpaAppointmentDataIssueCorrectionService::resolveHistoricalRequest()` calls
   it after canonical/source/closure/provenance writes and `writeCorrection()`.
2. `promoteHistoricalAppointment()` calls it after the promotion and correction
   audit. Direct correction uses the assigned ASC correction authority; the
   existing bulk entry point explicitly passes the BULK path and batch ID.
   The path is not inferred from client-supplied input. The existing canonical
   administrator bulk authority and matching batch provenance are rechecked.
3. `ArpaAppointmentCorrectionAmendmentService::review()` calls it only for final
   `RESOLVE_CANONICAL_ASSIGNMENT`, after the replacement becomes effective through
   `NATIONAL_APPROVED` / `applied_correction_id` and the final National event is
   inserted. National authorization, source/target lineage, final actor and event
   are rechecked. It is not called prematurely inside the raw application helper.

Complete lineage, InnoDB case/event tables and append-only 067 triggers are
mandatory. Missing/partial schema fails closed; there is no auto-migration or
feature-flag bypass. The adapter validates the imported non-deleted canonical
source, effective canonical-resolution decision, actual correction audit,
request/appointment identity, exclusion history and verified source references.
It creates only DRAFT, with actual actor/context/time. If no external evidence
reference was supplied, the case explicitly references the linked correction
decision; no external letter or historical approval timestamp is invented.

Registration failure rolls back the related canonicalization/application,
correction/provenance/audit and case/event writes together. Correction and amendment
wrappers use savepoints when their caller already owns a transaction, so catching
a failed candidate cannot accidentally commit partial promotion writes.

An existing same-source case is preserved, including terminal status and events.
An older active case for a different decision blocks the replacement transaction;
it is never silently rebound, cancelled or rejected. Retire a stale case using
the existing authorized historical-case workflow, then retry final correction.
A terminal predecessor case is retained and an explicitly approved successor
receives a separate DRAFT. Unique indexes and source locks prevent duplicates.

Automatic promotion registration does not depend on `legacy_exception=1` or
original `CREATED` status: those are the CLI backlog cohort, not a reason to omit
a new promotion that clears exception flags. Original imported workflow status,
canonical IDs, dates/closures and correction history remain as established by the
authorized correction. No case is automatically submitted or approved. KEEP,
non-promoting corrections and normal native appointment processing are unchanged.

Pre-existing canonical appointments are not backfilled by these hooks; use the
reviewed CLI/authorized registration process. Missing provenance, excluded or
ineligible sources, and conflicting active cases require governed manual review,
not bypasses or data edits to force registration. Migration 067 is unchanged; no
additional schema or permission grants are introduced.

## Acceptance

Use only disposable schemas for `ArpaHistoricalApprovalRegistrationIsolatedTest`,
`ArpaHistoricalAutomaticRegistrationIsolatedTest`, then rerun the existing Phase 1
and Phase 2 isolated suites and exclusion/KEEP
regressions. Validate source-table snapshots, case/event counts, stale manifests,
rollback, repeat execution and role/scope changes. Production population/counts
must come from a separately authorized dry-run; synthetic counts are not evidence
that production contains or qualifies exactly 39 records.
