# ADOcare

This repository contains the ADOcare web application.

IMPORTANT: Developers and automated agents should read `STYLE_GUIDE.md` in the repository root before making changes.

The guide contains functional (architectural) and stylistic rules in a single, unified document. See `STYLE_GUIDE.md` for details.

## Frontend and PrimeVue licensing

Use `npm ci` to install the locked frontend dependencies and `npm run build` to
build the application. The application uses PrimeVue 5 with matching v5 auto-import
metadata and PrimeUIX themes.

PrimeVue 5 requires a valid Community or Commercial license. Obtain a key from
[PrimeUI](https://primeui.dev/licenses/community) and set
`VITE_PRIMEVUE_LICENSE_KEY` in your local environment or build environment, then
restart Vite or rebuild. This frontend key is embedded in the browser bundle;
do not commit it or use a server-side secret here. Without a valid key, PrimeVue
may show a license notice. Community eligibility must be renewed annually.

Multiple selection uses `Select multiple` with custom value/header/option slots.
The shared `FormField` keeps `control="multiselect"` as a compatibility alias for
this API. Existing v5-compatible deprecated APIs, such as `Password`, `InputMask`
and `AutoComplete multiple`, remain supported until v6; migrate them before a
future v6 upgrade.

## Document preview PDF downloads

The preview viewer captures its HTML pages with html2canvas and assembles the
download with jsPDF. This is separate from server-side Dompdf generation.
During capture, the viewer temporarily restores inline image display in the host
document so Tailwind's image reset does not distort html2canvas's font baseline
measurements. The override is removed on success or failure.

## Dekurz narrative form

The Dekurz editor uses free-text sections with macros and per-section date
selection, while retaining the split-page preview and the v2 draft/finalization
workflow. Apply migrations `2026_10_31_120000_add_narrative_sections_to_dekurz_drafts`
and `2026_10_31_130000_add_dekurz_texts_to_patients` before using the editor.

Section texts autosave to the patient (`/v1/patients/{patient}/dekurz-texts`,
column `patients.dekurz_texts`) and load immediately, even when the month draft
cannot be initialized yet. Without saved patient texts, the patient's Dekurz
history is searched newest first: free-text sections from older (v1) documents
win, and procedure-based v2 texts are used only when no sections exist. Texts and selected days also autosave to the
patient/month draft, separately from scoring entries; a draft that already has
its own written texts keeps them. Preview and final PDF text are assembled from
the sections selected for each day. Procedure/default texts are not used to
populate the form; historical documents are not modified. The Adonis button
prefills texts from the latest proposal (with undo), and each section can be
improved by AI and restored.
Finalization still verifies access, visit timeline freshness, scoring coverage,
dates, quantities, required text and unresolved `[TEXT]` markers. The
procedure-default-text check remains for legacy procedure-based drafts, not for
independent patient narratives. Every draft day must have narrative text before
generation.

## Vertex monthly retraining (adocare-dekurz, adocare-proposal)

Both fine-tuned models (`dekurz`, `proposal`) are retrained by the same fail-safe pipeline.

Training data:

- Dekurz: every finalized Dekurz v2 is stored in `dekurz_ai_feedback` (source `dekurz_v2`) together with the patient's latest proposal.
- Proposal: every stored proposal is saved in `proposal_ai_feedback` together with the OCR text of the latest scan (max. 90 days old).
- Existing documents can be imported with `php artisan vertex:backfill-feedback`.

High-level lifecycle:

1. `vertex:monthly-retrain` runs daily and starts a run per pipeline only on `VERTEX_AUTOTRAIN_DAY`.
2. Build a cumulative dataset in Vertex `contents` format (stable validation/holdout split).
3. Upload train/validation dataset to GCS.
4. Create a Vertex tuning job, always from `VERTEX_AUTOTRAIN_BASE_MODEL` (Gemini 3.5 Flash).
5. Poll tuning asynchronously.
6. Evaluate the candidate on validation/holdout rows against the current production model.
7. Promote atomically by updating the pipeline state file (`ai/dekurz-autotrain/state.json`, `ai/proposal-autotrain/state.json`).
8. Run a post-promotion health check and roll back automatically on failure.

Safety guarantees:

- Failed training or evaluation does not change the production endpoint.
- Runtime candidate endpoint failures fall back to the static `VERTEX_DEKURZ_ENDPOINT_ID` / `VERTEX_PROPOSAL_ENDPOINT_ID`.
- Every failure, skip, rejection, rollback and successful retrain is emailed to `VERTEX_AUTOTRAIN_NOTIFICATION_EMAILS`.
- `.env` is never modified automatically.

Required config (see `.env.example`):

- `VERTEX_AUTOTRAIN_ENABLED=true`
- `VERTEX_AUTOTRAIN_DAY=1`
- `VERTEX_AUTOTRAIN_MIN_NEW_FEEDBACK=25`
- `VERTEX_AUTOTRAIN_SOURCE=proposal_ai_prefill,dekurz_v2`
- `VERTEX_AUTOTRAIN_BASE_MODEL=publishers/google/models/gemini-3.5-flash`
- `VERTEX_AUTOTRAIN_LOCATION=` (optional tuning region override)
- `VERTEX_AUTOTRAIN_MAX_EVALUATION_EXAMPLES=50`
- `VERTEX_AUTOTRAIN_MIN_JSON_VALIDITY=1.00`
- `VERTEX_AUTOTRAIN_MIN_REQUIRED_FIELDS_RATE=1.00`
- `VERTEX_AUTOTRAIN_MAX_SCORE_REGRESSION=0.01`
- `VERTEX_AUTOTRAIN_MAX_HTTP_FAILURES=0`
- `VERTEX_AUTOTRAIN_MAX_CRITICAL_ERRORS=0`
- `VERTEX_AUTOTRAIN_POLL_MINUTES=15`
- `VERTEX_AUTOTRAIN_MAX_HOURS=48`
- `VERTEX_AUTOTRAIN_RETENTION_DAYS=180`
- `VERTEX_AUTOTRAIN_RUNTIME_FALLBACK=true`
- `VERTEX_AUTOTRAIN_NOTIFICATION_EMAILS=adocare@studiokristian.com`
- `VERTEX_PROPOSAL_AUTOTRAIN_STATE_PATH`, `VERTEX_PROPOSAL_AUTOTRAIN_DATASET_PREFIX`

Scheduler (requires the Laravel scheduler cron and a queue worker):

- Registered in `bootstrap/app.php`:
	- `Schedule::command('vertex:monthly-retrain')->dailyAt('02:30')->withoutOverlapping(1440)->onOneServer()`

Operational commands:

- `php artisan vertex:backfill-feedback [--pipeline=dekurz] [--since=2026-05-01]`
- `php artisan vertex:monthly-retrain --dry-run`
- `php artisan vertex:monthly-retrain`
- `php artisan vertex:monthly-retrain --force`
- `php artisan vertex:monthly-retrain --pipeline=proposal`
- `php artisan vertex:run-status`
- `php artisan vertex:run-status --id=123`
- `php artisan vertex:run-status --pipeline=proposal`
- `php artisan vertex:check-job "projects/.../tuningJobs/..."`
- `php artisan vertex:evaluate-candidate 123`
- `php artisan vertex:promote-candidate 123`
- `php artisan vertex:promote-candidate 123 --emergency`
- `php artisan vertex:rollback 123 --reason="manual_rollback"`
- `php artisan vertex:test-static-endpoint`
- `php artisan vertex:test-active-endpoint`
- `php artisan vertex:cleanup`
