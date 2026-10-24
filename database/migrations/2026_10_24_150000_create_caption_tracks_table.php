<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caption_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_script_id')->nullable()->constrained('video_scripts')->onDelete('cascade');
            $table->string('language')->default('en');
            $table->string('source')->default('estimated'); // estimated | verified
            $table->json('style_preset')->nullable();
            $table->json('word_timings')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caption_tracks');
    }
};
