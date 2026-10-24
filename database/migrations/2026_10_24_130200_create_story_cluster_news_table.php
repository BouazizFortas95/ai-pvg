<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_cluster_news', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_cluster_id')
                ->constrained('story_clusters')
                ->cascadeOnDelete();
            $table->foreignId('news_id')
                ->constrained('news')
                ->cascadeOnDelete();
            $table->decimal('similarity', 5, 4)->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['story_cluster_id', 'news_id']);
            $table->index('news_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_cluster_news');
    }
};
