<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            // Primary key is the idempotency key itself (UUID from client)
            $table->string('idempotency_key', 64)->primary();
            $table->string('tracking_number', 40);
            $table->dateTime('created_at', 6);
            $table->dateTime('expires_at', 6); // 24h TTL from creation
        });

        // Index for cleanup job (expired keys)
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->index('expires_at', 'idx_idempotency_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};