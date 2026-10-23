<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rss_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_source_id')->constrained('news_sources')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('imported_items')->default(0);
            $table->unsignedInteger('duplicate_items')->default(0);
            $table->unsignedInteger('skipped_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rss_import_logs');
    }
};
