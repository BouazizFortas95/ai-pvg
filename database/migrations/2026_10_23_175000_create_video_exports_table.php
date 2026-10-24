<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_render_id')->constrained('video_renders')->cascadeOnDelete();
            $table->string('export_name');
            $table->string('platform_preset')->default('youtube_shorts');
            $table->string('status')->default('pending');
            $table->string('output_path')->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('resolution')->default('1080x1920');
            $table->json('validation_report')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_exports');
    }
};
