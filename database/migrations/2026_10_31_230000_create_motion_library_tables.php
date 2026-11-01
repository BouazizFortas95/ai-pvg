<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motion_sources', function (Blueprint $table) {
            $table->id();
            $table->string('source_url');
            $table->string('creator_handle');
            $table->string('site_name');
            $table->string('license_status')->default('reference_only');
            $table->text('attribution_text');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('motion_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motion_source_id')->nullable()->constrained('motion_sources')->nullOnDelete();
            $table->string('title');
            $table->text('prompt_text');
            $table->string('category');
            $table->json('source_payload')->nullable();
            $table->string('status')->default('imported');
            $table->string('license_status')->default('reference_only');
            $table->timestamps();
        });

        Schema::create('motion_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->nullable()->constrained('motion_recipes')->nullOnDelete();
            $table->string('component_key')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->string('category');
            $table->longText('html_template');
            $table->longText('gsap_script');
            $table->json('params_schema');
            $table->json('supported_formats');
            $table->json('sfx_hooks')->nullable();
            $table->json('tone_compatibility');
            $table->float('contrast_rating')->default(4.5);
            $table->string('license_status')->default('reference_only');
            $table->string('status')->default('pending_review');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('motion_component_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();
            $table->foreignId('motion_component_id')->constrained('motion_components')->cascadeOnDelete();
            $table->unsignedInteger('scene_index');
            $table->json('applied_params');
            $table->unsignedBigInteger('seed');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motion_component_usages');
        Schema::dropIfExists('motion_components');
        Schema::dropIfExists('motion_recipes');
        Schema::dropIfExists('motion_sources');
    }
};

