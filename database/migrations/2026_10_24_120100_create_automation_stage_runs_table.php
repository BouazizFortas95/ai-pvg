<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_stage_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')
                ->constrained('automation_runs')
                ->cascadeOnDelete();
            $table->string('stage')->index();
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt')->default(1);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->json('input_ref')->nullable();
            $table->json('output_ref')->nullable();
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(
                ['automation_run_id', 'stage', 'attempt'],
                'automation_stage_runs_attempt_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_stage_runs');
    }
};
