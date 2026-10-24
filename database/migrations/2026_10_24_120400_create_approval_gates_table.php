<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_gates', function (Blueprint $table) {
            $table->id();
            $table->morphs('gateable');
            $table->string('gate_type');
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('decided_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('first_approver_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable();
            $table->foreignId('second_approver_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'gate_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_gates');
    }
};
