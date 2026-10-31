<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avatar_characters', function (Blueprint $table) {
            $table->id();
            $table->string('character_id')->unique();
            $table->json('spec_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avatar_characters');
    }
};
