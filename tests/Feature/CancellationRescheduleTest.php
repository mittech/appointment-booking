<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Slot;
use App\Models\Booking;
use App\Jobs\SendNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CancellationRescheduleTest extends TestCase
{
    use RefreshDatabase;

    protected $doctor;
    protected $slot1;
    protected $slot2;
    protected $pastSlot;
    protected $booking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = Doctor::create([
            'name' => 'Dr. Jane Smith',
            'email' => 'jane@example.com'
        ]);

        $tomorrow = now()->addDay()->format('Y-m-d');
        $this->slot1 = Slot::create([
            'doctor_id' => $this->doctor->id,
            'date' => $tomorrow,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'booked'
        ]);

        $this->slot2 = Slot::create([
            'doctor_id' => $this->doctor->id,
            'date' => $tomorrow,
            'start_time' => '11:00:00',
            'end_time' => '11:30:00',
            'status' => 'available'
        ]);

        $yesterday = now()->subDay()->format('Y-m-d');
        $this->pastSlot = Slot::create([
            'doctor_id' => $this->doctor->id,
            'date' => $yesterday,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'available'
        ]);

        $this->booking = Booking::create([
            'slot_id' => $this->slot1->id,
            'booking_reference' => 'BK-TEST-1234',
            'patient_name' => 'John Doe',
            'patient_email' => 'john@example.com',
            'status' => 'active'
        ]);
    }

    /**
     * Test booking cancellation.
     */
    public function test_can_cancel_booking_successfully()
    {
        Queue::fake();

        $response = $this->postJson("/api/bookings/{$this->booking->booking_reference}/cancel", [
            'cancellation_reason' => 'Changed my mind'
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Appointment cancelled successfully.'
            ]);

        // Assert booking is cancelled in DB and contains reason
        $this->assertDatabaseHas('bookings', [
            'id' => $this->booking->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'Changed my mind',
        ]);

        // Assert slot is now available
        $this->assertEquals('available', $this->slot1->fresh()->status);

        // Assert cancellation notification queued
        $this->assertDatabaseHas('notifications', [
            'booking_id' => $this->booking->id,
            'type' => 'booking_cancelled',
        ]);
        Queue::assertPushed(SendNotificationJob::class);
    }

    /**
     * Test booking rescheduling.
     */
    public function test_can_reschedule_booking_successfully()
    {
        Queue::fake();

        $response = $this->postJson("/api/bookings/{$this->booking->booking_reference}/reschedule", [
            'new_slot_id' => $this->slot2->id
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'message' => 'Appointment rescheduled successfully.'
            ]);

        // Assert booking slot is updated in DB
        $this->assertDatabaseHas('bookings', [
            'id' => $this->booking->id,
            'slot_id' => $this->slot2->id,
            'status' => 'active',
        ]);

        // Assert old slot is available and new slot is booked
        $this->assertEquals('available', $this->slot1->fresh()->status);
        $this->assertEquals('booked', $this->slot2->fresh()->status);

        // Assert reschedule notification queued
        $this->assertDatabaseHas('notifications', [
            'booking_id' => $this->booking->id,
            'type' => 'booking_rescheduled',
        ]);
        Queue::assertPushed(SendNotificationJob::class);
    }

    /**
     * Test invalid rescheduling attempts.
     */
    public function test_cannot_reschedule_to_same_slot()
    {
        $response = $this->postJson("/api/bookings/{$this->booking->booking_reference}/reschedule", [
            'new_slot_id' => $this->slot1->id
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'New slot must be different from the current slot.',
            ]);
    }

    public function test_cannot_reschedule_to_booked_slot()
    {
        // Mark slot2 as booked
        $this->slot2->update(['status' => 'booked']);

        $response = $this->postJson("/api/bookings/{$this->booking->booking_reference}/reschedule", [
            'new_slot_id' => $this->slot2->id
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'The requested slot is already booked.',
            ]);
    }

    public function test_cannot_reschedule_to_past_slot()
    {
        $response = $this->postJson("/api/bookings/{$this->booking->booking_reference}/reschedule", [
            'new_slot_id' => $this->pastSlot->id
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot reschedule to a past time slot.',
            ]);
    }
}
