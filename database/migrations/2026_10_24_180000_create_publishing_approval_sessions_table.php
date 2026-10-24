<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishing_approval_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_script_id')->nullable()->constrained('video_scripts')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->unsignedBigInteger('first_approver_id')->nullable();
            $table->timestamp('first_approved_at')->nullable();
            $table->unsignedBigInteger('second_approver_id')->nullable();
            $table->timestamp('second_approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['video_script_id', 'first_approver_id', 'second_approver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publishing_approval_sessions');
    }
};
