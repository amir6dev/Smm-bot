<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallet_accounts')->cascadeOnDelete();
            $table->string('type', 20); // credit, debit, refund
            $table->bigInteger('amount_rial');
            $table->bigInteger('balance_after_rial');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason', 500);
            $table->string('idempotency_key', 100)->unique();
            $table->string('actor_type', 20)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            // Append-only constraint: no updated_at column
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
