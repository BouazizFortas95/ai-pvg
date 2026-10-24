<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scene_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_script_id')->constrained('video_scripts')->cascadeOnDelete();
            $table->integer('scene_index');
            $table->foreignId('visual_asset_id')->constrained('visual_assets');
            $table->string('role')->default('b_roll');
            $table->string('directive_query')->nullable();
            $table->string('attribution_text')->nullable();
            $table->string('status')->default('pending');
            $table->unique(['video_script_id', 'scene_index', 'role']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scene_assets');
    }
};
