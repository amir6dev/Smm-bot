<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('status_from', 30)->nullable();
            $table->string('status_to', 30);
            $table->string('source', 30);
            $table->json('payload_json')->nullable();
            // Append-only constraint: no updated_at column
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
