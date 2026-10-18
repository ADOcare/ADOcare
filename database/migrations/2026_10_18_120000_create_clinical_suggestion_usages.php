<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_suggestion_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('company')->cascadeOnDelete();
            $table->string('suggestion_type', 32);
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('context_id')->default(0);
            $table->unsignedBigInteger('usage_count')->default(0);
            $table->timestamps();

            $table->unique(
                ['company_id', 'suggestion_type', 'item_id', 'context_id'],
                'clinical_suggestion_usage_unique',
            );
            $table->index(
                ['company_id', 'suggestion_type', 'context_id', 'usage_count'],
                'clinical_suggestion_usage_lookup',
            );
        });

        Schema::create('proposal_nurse_diagnoses', function (Blueprint $table) {
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('company')->cascadeOnDelete();
            $table->foreignId('nurse_diagnosis_id')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->primary(['document_id', 'nurse_diagnosis_id']);
            $table->index(
                ['company_id', 'active', 'nurse_diagnosis_id'],
                'proposal_nurse_diagnosis_usage_lookup',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresTriggers();
        }

        $this->backfillPatientPointUsage();
        $this->backfillProposalNurseDiagnoses();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS sync_proposal_nurse_diagnoses_active ON documents');
            DB::unprepared('DROP TRIGGER IF EXISTS count_proposal_nurse_diagnosis_usage ON proposal_nurse_diagnoses');
            DB::unprepared('DROP TRIGGER IF EXISTS count_patient_point_suggestion_usage ON patient_points');
            DB::unprepared('DROP FUNCTION IF EXISTS sync_proposal_nurse_diagnoses_active()');
            DB::unprepared('DROP FUNCTION IF EXISTS count_proposal_nurse_diagnosis_usage()');
            DB::unprepared('DROP FUNCTION IF EXISTS count_patient_point_suggestion_usage()');
            DB::unprepared('DROP FUNCTION IF EXISTS adjust_clinical_suggestion_usage(BIGINT, VARCHAR, BIGINT, BIGINT, BIGINT)');
        }

        Schema::dropIfExists('proposal_nurse_diagnoses');
        Schema::dropIfExists('clinical_suggestion_usages');
    }

    private function createPostgresTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION adjust_clinical_suggestion_usage(
                target_company_id BIGINT,
                target_type VARCHAR,
                target_item_id BIGINT,
                target_context_id BIGINT,
                usage_delta BIGINT
            ) RETURNS VOID AS $$
            BEGIN
                IF target_company_id IS NULL OR target_item_id IS NULL OR usage_delta = 0 THEN
                    RETURN;
                END IF;

                IF usage_delta < 0 THEN
                    UPDATE clinical_suggestion_usages
                    SET usage_count = GREATEST(0, usage_count + usage_delta),
                        updated_at = CURRENT_TIMESTAMP
                    WHERE company_id = target_company_id
                      AND suggestion_type = target_type
                      AND item_id = target_item_id
                      AND context_id = target_context_id;

                    DELETE FROM clinical_suggestion_usages
                    WHERE company_id = target_company_id
                      AND suggestion_type = target_type
                      AND item_id = target_item_id
                      AND context_id = target_context_id
                      AND usage_count = 0;

                    RETURN;
                END IF;

                INSERT INTO clinical_suggestion_usages (
                    company_id,
                    suggestion_type,
                    item_id,
                    context_id,
                    usage_count,
                    created_at,
                    updated_at
                ) VALUES (
                    target_company_id,
                    target_type,
                    target_item_id,
                    target_context_id,
                    usage_delta,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT (company_id, suggestion_type, item_id, context_id)
                DO UPDATE SET
                    usage_count = clinical_suggestion_usages.usage_count + EXCLUDED.usage_count,
                    updated_at = CURRENT_TIMESTAMP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION count_patient_point_suggestion_usage()
            RETURNS TRIGGER AS $$
            DECLARE
                resolved_company_id BIGINT;
                resolved_quantity BIGINT;
                point RECORD;
                direction BIGINT;
            BEGIN
                IF TG_OP = 'DELETE' OR TG_OP = 'UPDATE' THEN
                    point := OLD;
                    direction := -1;
                    SELECT company_id INTO resolved_company_id FROM branches WHERE id = point.branch_id;
                    resolved_quantity := COALESCE(point.quantity, 1) * direction;

                    PERFORM adjust_clinical_suggestion_usage(resolved_company_id, 'diagnosis', point.diagnosis_id, 0, resolved_quantity);
                    PERFORM adjust_clinical_suggestion_usage(resolved_company_id, 'procedure', point.procedure_id, 0, resolved_quantity);

                    IF point.diagnosis_id IS NOT NULL THEN
                        PERFORM adjust_clinical_suggestion_usage(resolved_company_id, 'procedure', point.procedure_id, point.diagnosis_id, resolved_quantity);
                    END IF;
                END IF;

                IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
                    point := NEW;
                    direction := 1;
                    SELECT company_id INTO resolved_company_id FROM branches WHERE id = point.branch_id;
                    resolved_quantity := COALESCE(point.quantity, 1) * direction;

                    PERFORM adjust_clinical_suggestion_usage(resolved_company_id, 'diagnosis', point.diagnosis_id, 0, resolved_quantity);
                    PERFORM adjust_clinical_suggestion_usage(resolved_company_id, 'procedure', point.procedure_id, 0, resolved_quantity);

                    IF point.diagnosis_id IS NOT NULL THEN
                        PERFORM adjust_clinical_suggestion_usage(resolved_company_id, 'procedure', point.procedure_id, point.diagnosis_id, resolved_quantity);
                    END IF;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER count_patient_point_suggestion_usage
            AFTER INSERT OR UPDATE OR DELETE ON patient_points
            FOR EACH ROW EXECUTE FUNCTION count_patient_point_suggestion_usage();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION count_proposal_nurse_diagnosis_usage()
            RETURNS TRIGGER AS $$
            BEGIN
                IF TG_OP = 'DELETE' OR TG_OP = 'UPDATE' THEN
                    IF OLD.active THEN
                        PERFORM adjust_clinical_suggestion_usage(
                            OLD.company_id,
                            'nurse_diagnosis',
                            OLD.nurse_diagnosis_id,
                            0,
                            -1
                        );
                    END IF;
                END IF;

                IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
                    IF NEW.active THEN
                        PERFORM adjust_clinical_suggestion_usage(
                            NEW.company_id,
                            'nurse_diagnosis',
                            NEW.nurse_diagnosis_id,
                            0,
                            1
                        );
                    END IF;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER count_proposal_nurse_diagnosis_usage
            AFTER INSERT OR UPDATE OR DELETE ON proposal_nurse_diagnoses
            FOR EACH ROW EXECUTE FUNCTION count_proposal_nurse_diagnosis_usage();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sync_proposal_nurse_diagnoses_active()
            RETURNS TRIGGER AS $$
            BEGIN
                                IF OLD.deleted_at IS DISTINCT FROM NEW.deleted_at OR OLD.type IS DISTINCT FROM NEW.type THEN
                    UPDATE proposal_nurse_diagnoses
                                        SET active = NEW.deleted_at IS NULL AND NEW.type = 'proposal',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE document_id = NEW.id
                                            AND active IS DISTINCT FROM (NEW.deleted_at IS NULL AND NEW.type = 'proposal');
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER sync_proposal_nurse_diagnoses_active
            AFTER UPDATE OF deleted_at, type ON documents
            FOR EACH ROW EXECUTE FUNCTION sync_proposal_nurse_diagnoses_active();
        SQL);
    }

    private function backfillPatientPointUsage(): void
    {
        $diagnoses = DB::table('patient_points as points')
            ->join('branches', 'branches.id', '=', 'points.branch_id')
            ->whereNotNull('points.diagnosis_id')
            ->groupBy('branches.company_id', 'points.diagnosis_id')
            ->select('branches.company_id', 'points.diagnosis_id as item_id')
            ->selectRaw('SUM(COALESCE(points.quantity, 1)) as usage_count')
            ->get();

        $globalProcedures = DB::table('patient_points as points')
            ->join('branches', 'branches.id', '=', 'points.branch_id')
            ->whereNotNull('points.procedure_id')
            ->groupBy('branches.company_id', 'points.procedure_id')
            ->select('branches.company_id', 'points.procedure_id as item_id')
            ->selectRaw('SUM(COALESCE(points.quantity, 1)) as usage_count')
            ->get();

        $contextualProcedures = DB::table('patient_points as points')
            ->join('branches', 'branches.id', '=', 'points.branch_id')
            ->whereNotNull('points.procedure_id')
            ->whereNotNull('points.diagnosis_id')
            ->groupBy('branches.company_id', 'points.procedure_id', 'points.diagnosis_id')
            ->select(
                'branches.company_id',
                'points.procedure_id as item_id',
                'points.diagnosis_id as context_id',
            )
            ->selectRaw('SUM(COALESCE(points.quantity, 1)) as usage_count')
            ->get();

        $now = now();
        $rows = collect()
            ->concat($diagnoses->map(fn ($row) => [
                'company_id' => $row->company_id,
                'suggestion_type' => 'diagnosis',
                'item_id' => $row->item_id,
                'context_id' => 0,
                'usage_count' => $row->usage_count,
                'created_at' => $now,
                'updated_at' => $now,
            ]))
            ->concat($globalProcedures->map(fn ($row) => [
                'company_id' => $row->company_id,
                'suggestion_type' => 'procedure',
                'item_id' => $row->item_id,
                'context_id' => 0,
                'usage_count' => $row->usage_count,
                'created_at' => $now,
                'updated_at' => $now,
            ]))
            ->concat($contextualProcedures->map(fn ($row) => [
                'company_id' => $row->company_id,
                'suggestion_type' => 'procedure',
                'item_id' => $row->item_id,
                'context_id' => $row->context_id,
                'usage_count' => $row->usage_count,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

        $rows->chunk(500)->each(
            fn ($chunk) => DB::table('clinical_suggestion_usages')->insert($chunk->all()),
        );
    }

    private function backfillProposalNurseDiagnoses(): void
    {
        $diagnosisIdsByCode = DB::table('nurse_diagnoses')
            ->whereNotNull('code')
            ->get(['id', 'code'])
            ->mapWithKeys(fn ($diagnosis) => [
                mb_strtoupper(trim((string) $diagnosis->code)) => (int) $diagnosis->id,
            ]);

        DB::table('documents as documents')
            ->join('branches', 'branches.id', '=', 'documents.branch_id')
            ->where('documents.type', 'proposal')
            ->whereNotNull('documents.path')
            ->select([
                'documents.id',
                'documents.path',
                'documents.deleted_at',
                'branches.company_id',
            ])
            ->orderBy('documents.id')
            ->chunkById(100, function ($documents) use ($diagnosisIdsByCode) {
                $now = now();
                $rows = [];

                foreach ($documents as $document) {
                    if (! Storage::disk('local')->exists($document->path)) {
                        continue;
                    }

                    $payload = json_decode(Storage::disk('local')->get($document->path), true);
                    $diagnosisIds = collect((array) ($payload['nurse_diagnosis'] ?? []))
                        ->map(function ($diagnosis) use ($diagnosisIdsByCode) {
                            $code = mb_strtoupper(trim(explode(' - ', (string) $diagnosis, 2)[0]));

                            return $diagnosisIdsByCode->get($code);
                        })
                        ->filter()
                        ->unique();

                    foreach ($diagnosisIds as $diagnosisId) {
                        $rows[] = [
                            'document_id' => $document->id,
                            'company_id' => $document->company_id,
                            'nurse_diagnosis_id' => $diagnosisId,
                            'active' => $document->deleted_at === null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('proposal_nurse_diagnoses')->insertOrIgnore($rows);
                }
            }, 'documents.id', 'id');
    }
};
