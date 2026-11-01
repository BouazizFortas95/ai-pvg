<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('voice_profiles')) {
            Schema::create('voice_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('provider')->default('local'); // local | elevenlabs
                $table->string('voice_id')->index();
                $table->string('name');
                $table->string('model_id')->default('eleven_multilingual_v2');
                $table->string('language', 12)->default('en')->index();
                $table->json('settings')->nullable();
                $table->text('license_note')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_profiles');
    }
};

