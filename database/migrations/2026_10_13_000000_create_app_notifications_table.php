<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('type');
            $table->enum('kind', ['event', 'issue'])->default('event');
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'resolved_at', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
