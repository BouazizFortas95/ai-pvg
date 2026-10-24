<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thumbnail_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_render_id')->constrained('video_renders')->cascadeOnDelete();
            $table->string('storage_path');
            $table->unsignedInteger('frame_offset_ms')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thumbnail_variants');
    }
};
