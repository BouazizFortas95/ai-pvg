<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_renders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_script_id')->constrained('video_scripts')->cascadeOnDelete();
            $table->string('template')->default('cyber');
            $table->string('status')->default('pending');
            $table->string('output_path')->nullable();
            $table->unsignedSmallInteger('duration')->nullable();
            $table->string('resolution')->default('1080x1920');
            $table->unsignedSmallInteger('fps')->default(30);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_renders');
    }
};
