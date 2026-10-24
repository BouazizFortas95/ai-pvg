<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('canonical_title');
            $table->text('canonical_summary')->nullable();
            $table->json('fact_sheet')->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->decimal('confidence', 5, 2)->default(0);
            $table->timestamp('window_start')->nullable()->index();
            $table->timestamp('window_end')->nullable()->index();
            $table->unsignedTinyInteger('source_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_clusters');
    }
};
