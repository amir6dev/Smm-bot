<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('smm_providers')->cascadeOnDelete();
            $table->string('provider_service_id', 100);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->bigInteger('price_per_1000_rial');
            $table->bigInteger('cost_per_1000_rial')->default(0);
            $table->unsignedInteger('min_quantity')->default(100);
            $table->unsignedInteger('max_quantity')->default(10000);
            $table->json('input_schema_json');
            $table->boolean('is_active')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('estimated_time', 100)->nullable();
            $table->timestamps();

            $table->unique(['provider_id', 'provider_service_id']);
            $table->index(['category_id', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
