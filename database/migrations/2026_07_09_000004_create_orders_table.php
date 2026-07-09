<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('service_id');
            $table->string('order_code', 20)->unique();
            $table->string('status', 30)->default('draft');
            $table->unsignedInteger('quantity');
            $table->bigInteger('amount_rial');
            $table->json('target_data_json');
            $table->json('snapshot_json');
            $table->string('action_token', 64)->unique()->nullable();
            $table->string('provider_order_id', 100)->nullable();
            $table->string('provider_status', 50)->nullable();
            $table->unsignedInteger('remains')->nullable();
            $table->unsignedInteger('start_count')->nullable();
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();
            
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
