<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metadata_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_script_id')->constrained('video_scripts')->cascadeOnDelete();
            $table->string('variant_key');
            $table->string('title_text')->nullable();
            $table->text('description_text')->nullable();
            $table->json('tags_json')->nullable();
            $table->string('status')->default('pending');
            $table->timestamps();
            $table->unique(['video_script_id', 'variant_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_variants');
    }
};
