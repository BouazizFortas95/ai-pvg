<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_start')->unique();
            $table->date('period_end');
            $table->unsignedBigInteger('limit_micros')->default(0);
            $table->unsignedBigInteger('spent_micros')->default(0);
            $table->unsignedBigInteger('reserved_micros')->default(0);
            $table->string('breaker_state')->default('closed')->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('reset_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_periods');
    }
};
