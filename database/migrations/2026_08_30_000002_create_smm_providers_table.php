<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smm_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('code', 50)->unique();
            $table->string('base_url', 500);
            $table->string('api_profile', 20)->default('mock');
            $table->text('encrypted_token');
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->unsignedInteger('max_retries')->default(3);
            $table->json('capabilities_json');
            $table->json('status_mapping_json')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_health_status', 20)->nullable();
            $table->bigInteger('last_balance')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smm_providers');
    }
};
