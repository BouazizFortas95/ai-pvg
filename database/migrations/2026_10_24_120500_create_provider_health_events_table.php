<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_health_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->index();
            $table->string('event_type');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('retry_after_seconds')->nullable();
            $table->text('message_sanitized')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_health_events');
    }
};
