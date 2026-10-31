<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_history', function (Blueprint $table) {
            $table->id();
            $table->string('run_id')->nullable()->index();
            $table->unsignedBigInteger('seed');
            $table->string('variation_fingerprint', 64)->index();
            $table->json('categories_json');
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_history');
    }
};
