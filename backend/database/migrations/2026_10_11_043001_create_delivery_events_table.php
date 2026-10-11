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
        Schema::create('delivery_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_id');
            $table->enum('event_type', ['DELIVERED', 'RECEIVED']);
            $table->string('actor_name', 160);
            $table->string('remarks', 500)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->dateTime('event_at', 6);
        });

        // Add foreign key explicitly
        Schema::table('delivery_events', function (Blueprint $table) {
            $table->foreign('delivery_id', 'fk_event_delivery')
                ->references('id')
                ->on('letter_deliveries')
                ->cascadeOnDelete();
        });

        // Index matching V9 schema
        Schema::table('delivery_events', function (Blueprint $table) {
            $table->index(['delivery_id', 'event_at'], 'idx_event_delivery_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_events');
    }
};