<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Slot;
use App\Models\Booking;
use App\Models\Notification;
use App\Jobs\SendNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected $doctor;
    protected $futureSlot;
    protected $pastSlot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = Doctor::create([
            'name' => 'Dr. Jane Smith',
            'email' => 'jane@example.com'
        ]);

        $tomorrow = now()->addDay()->format('Y-m-d');
        $this->futureSlot = Slot::create([
            'doctor_id' => $this->doctor->id,
            'date' => $tomorrow,
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
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
    }

    /**
     * Test viewing available slots.
     */
    public function test_can_view_available_slots()
    {
        $response = $this->getJson('/api/slots/available');

        $response->assertStatus(200);
        $slots = $response->json();

        // Should include futureSlot, but NOT pastSlot
        $this->assertTrue(collect($slots)->contains('id', $this->futureSlot->id));
        $this->assertFalse(collect($slots)->contains('id', $this->pastSlot->id));
    }

    /**
     * Test booking a slot successfully.
     */
    public function test_can_book_slot_successfully()
    {
        Queue::fake();

        $response = $this->postJson('/api/bookings', [
            'slot_id' => $this->futureSlot->id,
            'patient_name' => 'John Doe',
            'patient_email' => 'john@example.com'
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'message' => 'Appointment booked successfully.',
                'patient_name' => 'John Doe',
                'patient_email' => 'john@example.com',
            ]);

        // Assert slot status changed to booked
        $this->assertEquals('booked', $this->futureSlot->fresh()->status);

        // Assert booking record is in database
        $this->assertDatabaseHas('bookings', [
            'slot_id' => $this->futureSlot->id,
            'patient_name' => 'John Doe',
            'patient_email' => 'john@example.com',
            'status' => 'active',
        ]);

        // Assert notification record created
        $booking = Booking::where('slot_id', $this->futureSlot->id)->first();
        $this->assertDatabaseHas('notifications', [
            'booking_id' => $booking->id,
            'type' => 'booking_confirmed',
            'recipient_email' => 'john@example.com',
        ]);

        // Assert notification job queued
        $notification = Notification::where('booking_id', $booking->id)->first();
        Queue::assertPushed(SendNotificationJob::class, function ($job) use ($notification) {
            // Retrieve reflection or direct property from job since it's protected
            $reflector = new \ReflectionClass($job);
            $property = $reflector->getProperty('notificationId');
            $property->setAccessible(true);
            return $property->getValue($job) === $notification->id;
        });
    }

    /**
     * Test double booking prevention.
     */
    public function test_cannot_book_same_slot_twice()
    {
        // First booking
        $this->postJson('/api/bookings', [
            'slot_id' => $this->futureSlot->id,
            'patient_name' => 'John Doe',
            'patient_email' => 'john@example.com'
        ])->assertStatus(201);

        // Second booking attempt on the same slot
        $response = $this->postJson('/api/bookings', [
            'slot_id' => $this->futureSlot->id,
            'patient_name' => 'Jane Smith',
            'patient_email' => 'jane@example.com'
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This time slot is already booked.',
            ]);
    }

    /**
     * Test booking past slots is blocked.
     */
    public function test_cannot_book_past_slot()
    {
        $response = $this->postJson('/api/bookings', [
            'slot_id' => $this->pastSlot->id,
            'patient_name' => 'John Doe',
            'patient_email' => 'john@example.com'
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Cannot book a past time slot.',
            ]);
    }
}
