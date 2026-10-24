<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_publishing_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_export_id')->constrained('video_exports')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider_video_id')->nullable();
            $table->string('channel_id')->nullable();
            $table->string('status')->default('pending'); // pending, authorized, uploading, uploaded, published, failed
            $table->string('visibility')->default('private');
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_publishing_states');
    }
};
