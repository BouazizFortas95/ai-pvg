<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_id')->unique()->constrained('news')->cascadeOnDelete();

            $table->decimal('velocity_score', 5, 2)->default(0);
            $table->decimal('keyword_heat_score', 5, 2)->default(0);
            $table->decimal('novelty_score', 5, 2)->default(0);
            $table->decimal('source_diversity_score', 5, 2)->default(0);
            $table->decimal('recency_score', 5, 2)->default(0);
            $table->decimal('composite_score', 5, 2)->default(0)->index();

            $table->string('band', 32)->default('below_threshold')->index();
            $table->json('breakdown')->nullable();
            $table->timestamp('scored_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_scores');
    }
};
