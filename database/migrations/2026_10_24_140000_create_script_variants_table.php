<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('script_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_script_id')
                ->constrained('video_scripts')
                ->cascadeOnDelete();
            $table->string('variant_key', 191)->unique();
            $table->string('variant_type', 32)->default('hook')->index();
            $table->unsignedTinyInteger('hook_index')->default(1);
            $table->string('hook_type', 32)->nullable();
            $table->text('content');
            $table->text('retention_rationale')->nullable();
            $table->decimal('score', 5, 2)->default(0);
            $table->boolean('selected')->default(false)->index();
            $table->json('source_news_ids');
            $table->string('prompt_version', 64)->nullable();
            $table->string('model_version', 128)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(
                ['video_script_id', 'variant_type', 'hook_index'],
                'script_variants_script_type_hook_unique',
            );
            $table->index(['video_script_id', 'selected']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_variants');
    }
};
