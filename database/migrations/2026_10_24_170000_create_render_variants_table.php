<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_render_id')->constrained('video_renders')->cascadeOnDelete();
            $table->string('preset_name');
            $table->unsignedInteger('resolution_width');
            $table->unsignedInteger('resolution_height');
            $table->string('storage_path');
            $table->string('status')->default('pending');
            $table->string('checksum_sha256', 64)->nullable();
            $table->timestamps();
            $table->unique(['video_render_id', 'preset_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_variants');
    }
};
