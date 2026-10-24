<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visual_assets', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // image | video | logo
            $table->string('storage_path');
            $table->string('source_url')->nullable();
            $table->string('domain')->nullable();
            $table->string('license_type')->default('unknown');
            $table->string('attribution_text')->nullable();
            $table->string('status')->default('pending');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['checksum_sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visual_assets');
    }
};
