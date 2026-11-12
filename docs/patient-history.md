# Patient history

Patient history is stored as effective-dated records in dedicated tables for names, addresses, identity, contact details, doctor, nurse, branch, and insurance coverage. `valid_from` and `valid_to` are inclusive calendar dates; a null end date is open-ended. A transaction locks the patient before splitting or adding a period, and all ordinary patient changes made through `PatientService` use the same writer. `patients` remains a compatibility projection of the values effective today.

The history migration copies the current patient values and current insurance coverage without inventing a start date. A row with `valid_from = NULL` therefore means that its values are known from the existing record, but when they first became valid is not known. The resolver returns `unknown` for those rows, `missing` when no record covers a date, and `conflict` instead of silently choosing if overlapping rows are detected. Use `PatientHistoryResolver::patientOn()` (or its category-specific methods) for dated reads; do not use current patient columns as proof of a historical value.

Normal edits require an effective date whenever a historical field changes. Future periods do not change the current projection until their effective date. `patients:sync-history-projections` is scheduled daily at 00:05 to advance compatibility columns even if nobody edits a patient that day; the `latestCoverage` relationship is also date-aware. Keep Laravel's scheduler running in production. Historical corrections are restricted to managers and superadmins, require a reason, update the selected period, and append before/after values to `patient_history_corrections`. The correction form warns that an existing claim or visit may need separate review; it does not rewrite `patient_points`, visits, or generated claims.

## Revision journal and user workflow

Effective periods answer which values apply on a service date. The immutable revision journal answers what Adocare knew immediately after each save. `patient_history_changes` stores one logical revision, its server-generated recording time, actor, type, reason, and a complete snapshot of the state effective on that recording date. `patient_history_change_items` stores the category changes within that revision, including structured previous/new values and each category's effective date. A single save can therefore change insurance, doctor, and address on different dates without creating unrelated revisions. Later corrections never rewrite older revision snapshots.

Patient creation records **Vytvorenie pacienta**. The journal migration creates one baseline revision for existing patients and explicitly marks it as a legacy baseline; it does not invent prior edits. Corrections continue to use `patient_history_corrections` and are linked to the corresponding journal revision.

The patient-card clock icon opens two distinct views:

- **Zaznamenané zmeny** shows immutable revisions ordered by when they were entered.
- **Platnosť údajov** resolves the effective-dated state with `PatientHistoryResolver::patientOn()`.

The ordinary edit form no longer asks for an effective date before the user edits anything. On save, semantic differences are grouped by history category. If tracked values changed, a confirmation dialog defaults to today and optionally allows a separate date per category. Managers and superadmins can explicitly choose a correction and must provide a reason. Past dates trigger a non-blocking check for visits, points, claim lines, and transport claim lines; no dependent clinical or exported record is modified automatically.

`death_date` remains a correctable patient event rather than an effective-dated succession. `reference_date` is not patient history: usage shows it is a referral/service reference on `patient_points` and documents, while the patient-level copy is legacy compatibility data. New referral dates should remain attached to the relevant point or document.

## Deployment and known integration work

The migrations are additive. Deploy the application version, run `php artisan migrate --force`, and then route traffic to code that reads history. The effective-period migration must run before the revision-journal migration. The backfills use batches and preserve existing patient and coverage rows. Do not roll back the forward-only effective-period migration because that would discard regulated history. No deleted pre-October 2026 insurance periods can be reconstructed from current rows.

The transport claim selection and both transport export queries now filter coverage periods against the recorded `patient_points.date`, preventing one-to-many coverage rows from duplicating claim lines. Full dated patient-identity integration remains a follow-up for `PointClaimSelectionService`, `Transport/ClaimExportService`, and `Transport/LegacyClaimExportService`: these still read names, personal numbers, registered addresses, and doctor identifiers from present-day patient/doctor columns. Generated claims and existing `patient_points` values intentionally remain unchanged.

Period overlap is checked under a patient row lock in the writer, and PostgreSQL has date-order checks. The application reports any pre-existing overlap as a resolver conflict rather than selecting one row. A database exclusion constraint is not installed because it would require enabling PostgreSQL's `btree_gist` extension in every production environment.
