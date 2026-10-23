<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_theme_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('theme_name')->default('Cyber Dark');
            $table->string('primary_color')->default('#00d4ff');
            $table->string('secondary_color')->default('#1e293b');
            $table->string('accent_color')->default('#64748b');
            $table->string('background_color')->default('#0f172a');
            $table->string('text_color')->default('#f8fafc');
            $table->string('mode')->default('dark');
            $table->unsignedSmallInteger('border_radius')->default(8);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_theme_preferences');
    }
};
