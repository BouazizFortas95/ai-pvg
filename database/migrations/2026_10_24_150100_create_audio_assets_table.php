<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_script_id')->nullable()->constrained('video_scripts')->onDelete('cascade');
            $table->string('kind')->default('music'); // music | sfx | narration
            $table->string('storage_path');
            $table->string('license_source')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->float('loudness_lu')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_assets');
    }
};
