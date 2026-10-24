<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('pipeline')->index();
            $table->nullableMorphs('subject');
            $table->string('status')->default('pending')->index();
            $table->boolean('dry_run')->default(true);
            $table->string('trigger')->default('manual');
            $table->unsignedBigInteger('budget_limit_micros')->default(0);
            $table->unsignedBigInteger('budget_spent_micros')->default(0);
            $table->unsignedBigInteger('budget_reserved_micros')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->string('active_window', 64)->nullable();
            $table->timestamps();

            $table->unique(
                ['pipeline', 'subject_type', 'subject_id', 'active_window'],
                'automation_runs_idempotency_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
