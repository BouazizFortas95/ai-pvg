<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_script_id')->constrained('video_scripts')->cascadeOnDelete();
            $table->string('provider')->nullable();
            $table->string('voice')->nullable();
            $table->string('language');
            $table->string('status')->default('pending');
            $table->string('audio_path')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('voice_generations');
    }
};
