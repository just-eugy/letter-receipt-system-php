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
        Schema::create('letter_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('version')->default(0);

            // Unified reference/tracking code (e.g., REF-2026-7K4P92)
            $table->string('tracking_number', 40)->unique();

            // Delivery person fields (denormalized from mobile payload)
            $table->string('delivery_person_name', 160);
            $table->string('delivery_person_phone', 60)->nullable();
            $table->string('delivery_person_email', 180)->nullable();

            // Recipient field (from mobile recipient chips)
            // Single field combining what was previously recipient_name + recipient_title
            $table->string('recipient_title', 160)->nullable();

            // Status and timestamps
            $table->enum('status', ['DELIVERED', 'RECEIVED']);
            $table->dateTime('delivered_at', 6);
            $table->dateTime('received_at', 6)->nullable();

            // Received by user (FK to users table)
            $table->foreignId('received_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->name('fk_delivery_received_by');

            $table->timestamps(6);
        });

        // Add foreign key explicitly (constrained() may not create it with nullable)
        Schema::table('letter_deliveries', function (Blueprint $table) {
            $table->foreign('received_by_user_id', 'fk_delivery_received_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        // Indexes matching V9 schema
        Schema::table('letter_deliveries', function (Blueprint $table) {
            $table->index(['status', 'delivered_at'], 'idx_delivery_status_time');
            $table->index('delivered_at', 'idx_delivery_delivered_at');
            $table->index('received_at', 'idx_delivery_received_at');
            $table->index('tracking_number', 'idx_delivery_tracking_number');
            $table->index('delivery_person_name', 'idx_delivery_person_name');
            $table->index('recipient_title', 'idx_delivery_recipient_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('letter_deliveries');
    }
};