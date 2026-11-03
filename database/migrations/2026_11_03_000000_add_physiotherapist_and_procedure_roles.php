<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->updateOrInsert(
            ['position' => 'physiotherapist'],
            ['scope' => 'branch', 'updated_at' => now(), 'created_at' => now()],
        );

        Schema::create('procedure_role', function (Blueprint $table) {
            $table->unsignedInteger('procedure_id');
            $table->unsignedInteger('role_id');
            $table->primary(['procedure_id', 'role_id']);
            $table->foreign('procedure_id')->references('id')->on('procedures')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        $nurseRoleId = DB::table('roles')->where('position', 'nurse')->value('id');
        if ($nurseRoleId) {
            DB::table('procedures')
                ->select('id')
                ->orderBy('id')
                ->eachById(function ($procedures) use ($nurseRoleId) {
                    DB::table('procedure_role')->insert(
                        $procedures->map(fn ($procedure) => [
                            'procedure_id' => $procedure->id,
                            'role_id' => $nurseRoleId,
                        ])->all(),
                    );
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_role');
        DB::table('roles')->where('position', 'physiotherapist')->delete();
    }
};
