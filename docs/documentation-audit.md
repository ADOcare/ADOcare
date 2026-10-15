# Retrospective documentation audit

The documentation audit compares billed `patient_points` in eligible closed months with finalized supporting documentation. It does not use editable Dekurz drafts, current templates, or the notification center.

## Period selection

A month becomes eligible when the current date is later than `end_of_month + grace_days`. With five grace days, September becomes eligible on October 6. The exact configured start date is also applied to points in the first month.

The scheduled run checks the latest eligible month plus older periods that still have open issues. `--from` deliberately checks each eligible month from the requested boundary; `--month` checks one eligible month.

## Rules

- `DOC-001`: applies to scoring from November 1, 2026 onward. Documentable procedure codes from `config/dekurz.php` must occur in a stored schema-v2 finalized Dekurz snapshot. Matching prefers `patient_point_snapshot_id`; deterministic patient/date/procedure/quantity fallback is used only for schema-v2 entries without that identifier. A V1 free-text Dekurz is marked `legacy_not_structurally_verifiable` in run diagnostics and does not fabricate procedure-level issues.
- `DOC-002`: `3390` requires an `agreement` document. Existence is primary; the closest stored payload date is available for relevance selection.
- `DOC-003`: `3390` requires a `record` in the same period. The record service currently discards the submitted exact date from JSON and retains only `documents.period`, so exact-day tolerance cannot yet be enforced reliably.
- `DOC-004`: `3393` requires a `proposal` in the same period. No proposal validity-period or procedure/frequency inspection is performed.
- `DOC-005`: `3395` requires a `leave` document in the same period. No care-episode reconstruction is performed.

`DOC-006` is intentionally absent until Nález has a reliable clinical date.

## Identity and lifecycle

Every issue fingerprint is SHA-256 over `rule_code|patient_id|period|patient_point_id`. Metadata is not identity. Repeated detection updates `last_detected_at`; a missing finding resolves the existing row; later recurrence reopens that same row while preserving `first_detected_at`.

Resolution is limited to the periods, patients, companies, and enabled rules evaluated by the run. Disabling a rule therefore preserves its existing audit history instead of silently resolving it.

## Commands

```bash
php artisan documentation:audit
php artisan documentation:audit --month=2026-09
php artisan documentation:audit --from=2026-08
php artisan documentation:audit --month=2026-09 --patient=668 --dry-run
php artisan documentation:audit --month=2026-09 --company=12
```

The daily schedule uses the configured time with `withoutOverlapping()` and `onOneServer()`.

## Nurse notification-center integration

Open audit issues are aggregated into one persistent `documentation_audit_issue` action per patient, period, and rule. Multiple missing rows for the same rule stay grouped, while different document types remain separate so every action has one exact repair destination. Only the patient's currently assigned nurse receives it; company managers are not recipients. The notification data includes issue IDs, rule code, count, severity, patient, branch, period, and target route.

The title contains the patient's full name. The description lists up to three concrete missing-document problems with procedure code and scoring date, followed by a remaining-problem count when necessary.

Persistent audit actions cannot be dismissed through the API or notification-center UI. They resolve automatically only when a later audit leaves no open issue for that patient and period. A recurrence reopens the same `(user_id, key)` notification, and nurse reassignment resolves the previous nurse's copy before opening one for the newly assigned nurse.

Opening an action switches to the patient's branch, stores the patient as the current patient, and routes to `/patient/dekurz`, `/patient/agreement`, `/patient/record`, `/patient/proposal`, or `/patient/leave` according to `DOC-001` through `DOC-005`.