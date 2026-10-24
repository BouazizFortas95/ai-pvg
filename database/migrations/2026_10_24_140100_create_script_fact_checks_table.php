<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('script_fact_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_script_id')
                ->constrained('video_scripts')
                ->cascadeOnDelete();
            $table->foreignId('script_variant_id')
                ->nullable()
                ->constrained('script_variants')
                ->nullOnDelete();
            $table->char('claim_hash', 64);
            $table->text('claim');
            $table->string('verdict', 32)->default('uncertain')->index();
            $table->json('source_news_ids');
            $table->text('evidence')->nullable();
            $table->decimal('critic_score', 5, 2)->default(0);
            $table->string('checker_version', 64)->default('g03-fact-check-v1');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['video_script_id', 'claim_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('script_fact_checks');
    }
};
