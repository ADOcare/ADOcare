<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->index(
                ['branch_id', 'nurse_id', 'last_name', 'first_name', 'id'],
                'patients_selector_assignment_name_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_selector_assignment_name_index');
        });
    }
};
