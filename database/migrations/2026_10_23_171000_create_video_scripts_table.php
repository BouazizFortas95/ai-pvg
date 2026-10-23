<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->string('title');
            $table->string('language');
            $table->unsignedInteger('target_duration');
            $table->unsignedInteger('estimated_duration')->nullable();
            $table->longText('narration')->nullable();
            $table->json('scenes')->nullable();
            $table->string('status')->default('draft');
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_scripts');
    }
};
