<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unique(['id', 'company_id'], 'branches_id_company_id_ados_unique');
        });

        Schema::create('ados_episode_types', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->foreign('company_id')->references('id')->on('company')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->unsignedInteger('default_duration_days')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'id'], 'ados_episode_types_company_id_id_unique');
            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        Schema::create('ados_episode_type_transitions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('from_episode_type_id');
            $table->unsignedInteger('to_episode_type_id');
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('company')->cascadeOnDelete();
            $table->foreign(['company_id', 'from_episode_type_id'], 'ados_transition_from_company_fk')
                ->references(['company_id', 'id'])->on('ados_episode_types')->restrictOnDelete();
            $table->foreign(['company_id', 'to_episode_type_id'], 'ados_transition_to_company_fk')
                ->references(['company_id', 'id'])->on('ados_episode_types')->restrictOnDelete();
            $table->unique(
                ['company_id', 'from_episode_type_id', 'to_episode_type_id'],
                'ados_episode_transitions_unique'
            );
            $table->index(['company_id', 'from_episode_type_id']);
        });

        Schema::create('ados_episodes', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('patient_id');
            $table->unsignedInteger('branch_id');
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('episode_type_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('ended_by')->nullable();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('ended_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('company_id')->references('id')->on('company')->cascadeOnDelete();
            $table->foreign(['branch_id', 'company_id'], 'ados_episode_branch_company_fk')
                ->references(['id', 'company_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['company_id', 'episode_type_id'], 'ados_episode_type_company_fk')
                ->references(['company_id', 'id'])->on('ados_episode_types')->restrictOnDelete();
            $table->string('status', 20)->default('active');
            $table->date('started_at');
            $table->date('expected_end_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestamps();

            $table->index('patient_id');
            $table->index('company_id');
            $table->index('status');
            $table->index('started_at');
            $table->index('episode_type_id');
            $table->index(['patient_id', 'status']);
        });

        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX ados_episodes_one_active_per_patient '.
                "ON ados_episodes (patient_id) WHERE status = 'active'"
            );
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('ados_episodes', function (Blueprint $table) {
                $table->unsignedInteger('active_patient_id')
                    ->nullable()
                    ->storedAs("CASE WHEN status = 'active' THEN patient_id ELSE NULL END")
                    ->unique();
            });
        }

        Schema::create('ados_episode_type_history', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('ados_episode_id');
            $table->unsignedInteger('episode_type_id');
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->foreign('ados_episode_id')->references('id')->on('ados_episodes')->cascadeOnDelete();
            $table->foreign('episode_type_id')->references('id')->on('ados_episode_types')->restrictOnDelete();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['ados_episode_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ados_episode_type_history');

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS ados_episodes_one_active_per_patient');
        }

        Schema::dropIfExists('ados_episodes');
        Schema::dropIfExists('ados_episode_type_transitions');
        Schema::dropIfExists('ados_episode_types');
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_id_company_id_ados_unique');
        });
    }
};
