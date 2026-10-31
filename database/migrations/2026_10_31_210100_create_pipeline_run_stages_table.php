<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_run_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_run_id')->constrained('pipeline_runs')->cascadeOnDelete();
            $table->unsignedTinyInteger('step_number');
            $table->string('step_name');
            $table->string('status')->default('pending')->index();
            $table->string('job_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->longText('log')->nullable();
            $table->text('error_message')->nullable();
            $table->text('fix_hint')->nullable();
            $table->json('artifacts')->nullable();
            $table->timestamps();

            $table->index(['pipeline_run_id', 'step_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_run_stages');
    }
};

