<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_manifests', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->unique();
            $table->foreignId('pipeline_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();
            $table->foreignId('video_script_id')->nullable()->constrained('video_scripts')->nullOnDelete();
            $table->string('status')->default('staging'); // staging | verified | quarantined
            $table->json('manifest_data');
            $table->string('checksum_sha256');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_manifests');
    }
};

