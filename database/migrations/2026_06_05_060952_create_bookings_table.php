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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slot_id')->constrained('slots')->onDelete('cascade');
            $table->string('booking_reference')->unique();
            $table->string('patient_name');
            $table->string('patient_email');
            $table->string('status')->default('active'); // 'active', 'cancelled'
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index('slot_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
