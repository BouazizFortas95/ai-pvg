<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('voice_generations', function (Blueprint $table) {
            $table->json('word_timings')->nullable()->after('audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('voice_generations', function (Blueprint $table) {
            $table->dropColumn('word_timings');
        });
    }
};
