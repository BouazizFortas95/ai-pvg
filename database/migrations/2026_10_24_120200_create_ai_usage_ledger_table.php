<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')
                ->nullable()
                ->constrained('automation_runs')
                ->nullOnDelete();
            $table->string('provider');
            $table->string('model')->nullable();
            $table->string('purpose');
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_ledger');
    }
};
