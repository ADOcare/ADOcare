# ADOcare

This repository contains the ADOcare web application.

IMPORTANT: Developers and automated agents should read `STYLE_GUIDE.md` in the repository root before making changes.

The guide contains functional (architectural) and stylistic rules in a single, unified document. See `STYLE_GUIDE.md` for details.

## Document preview PDF downloads

The preview viewer captures its HTML pages with html2canvas and assembles the
download with jsPDF. This is separate from server-side Dompdf generation.
During capture, the viewer temporarily restores inline image display in the host
document so Tailwind's image reset does not distort html2canvas's font baseline
measurements. The override is removed on success or failure.

## Vertex monthly retraining (Dekurz)

The Dekurz retraining pipeline is built to fail safe.

High-level lifecycle:

1. `vertex:monthly-retrain` validates eligibility and dispatches queue work.
2. Build versioned dataset from approved feedback (training + stable validation/holdout).
3. Validate schema and deduplicate rows.
4. Upload train/validation dataset to GCS.
5. Create Vertex tuning job.
6. Poll tuning asynchronously.
7. Resolve candidate model/endpoint.
8. Evaluate candidate against stable validation/holdout and compare against current production.
9. Promote atomically by updating `storage/app/private/ai/dekurz-autotrain/state.json`.
10. Run post-promotion health check and rollback automatically on failure.

Safety guarantees:

- Failed training does not change production endpoint.
- Failed evaluation does not change production endpoint.
- Runtime candidate endpoint failures fall back to static `VERTEX_DEKURZ_ENDPOINT_ID`.
- Post-promotion health failure triggers automatic rollback.
- `.env` is never modified automatically.

Required config (see `.env.example`):

- `VERTEX_AUTOTRAIN_ENABLED`
- `VERTEX_AUTOTRAIN_FREQUENCY=monthly`
- `VERTEX_AUTOTRAIN_DAY=1`
- `VERTEX_AUTOTRAIN_TIME=02:30`
- `VERTEX_AUTOTRAIN_MIN_NEW_FEEDBACK=25`
- `VERTEX_AUTOTRAIN_MIN_JSON_VALIDITY=1.00`
- `VERTEX_AUTOTRAIN_MIN_REQUIRED_FIELDS_RATE=1.00`
- `VERTEX_AUTOTRAIN_MAX_SCORE_REGRESSION=0.01`
- `VERTEX_AUTOTRAIN_MAX_HTTP_FAILURES=0`
- `VERTEX_AUTOTRAIN_MAX_CRITICAL_ERRORS=0`
- `VERTEX_AUTOTRAIN_POLL_MINUTES=15`
- `VERTEX_AUTOTRAIN_MAX_HOURS=48`
- `VERTEX_AUTOTRAIN_RETENTION_DAYS=180`
- `VERTEX_AUTOTRAIN_KEEP_SUCCESSFUL_VERSIONS=4`
- `VERTEX_AUTOTRAIN_REQUIRE_MANUAL_APPROVAL=false`
- `VERTEX_AUTOTRAIN_ROLLBACK_ON_HEALTH_FAILURE=true`
- `VERTEX_AUTOTRAIN_RUNTIME_FALLBACK=true`
- `VERTEX_AUTOTRAIN_NOTIFICATION_EMAILS=ops@example.com,ml@example.com` (optional)

Scheduler:

- Registered in `bootstrap/app.php`:
	- `Schedule::command('vertex:monthly-retrain')->monthlyOn(1, '02:30')->withoutOverlapping(1440)->onOneServer()`

Operational commands:

- `php artisan vertex:monthly-retrain --dry-run`
- `php artisan vertex:monthly-retrain`
- `php artisan vertex:monthly-retrain --force`
- `php artisan vertex:run-status`
- `php artisan vertex:run-status --id=123`
- `php artisan vertex:check-job "projects/.../tuningJobs/..."`
- `php artisan vertex:evaluate-candidate 123`
- `php artisan vertex:promote-candidate 123`
- `php artisan vertex:promote-candidate 123 --emergency`
- `php artisan vertex:rollback 123 --reason="manual_rollback"`
- `php artisan vertex:test-static-endpoint`
- `php artisan vertex:test-active-endpoint`
- `php artisan vertex:cleanup`
