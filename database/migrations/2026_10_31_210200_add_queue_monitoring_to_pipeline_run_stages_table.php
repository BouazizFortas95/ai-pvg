<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_run_stages', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable()->after('job_id');
            $table->timestamp('heartbeat_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_run_stages', function (Blueprint $table) {
            $table->dropColumn(['queued_at', 'heartbeat_at']);
        });
    }
};

