<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->after('payload_json');
        });
    }

    public function down(): void
    {
        Schema::table('order_events', function (Blueprint $table) {
            $table->dropColumn('actor_id');
        });
    }
};
