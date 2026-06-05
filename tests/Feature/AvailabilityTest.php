<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Availability;
use App\Models\Slot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test doctor creation.
     */
    public function test_can_create_doctor()
    {
        $response = $this->postJson('/api/doctors', [
            'name' => 'Dr. Gregory House',
            'email' => 'house@example.com',
            'specialization' => 'Diagnostic Medicine',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name' => 'Dr. Gregory House',
                'email' => 'house@example.com',
            ]);

        $this->assertDatabaseHas('doctors', [
            'email' => 'house@example.com',
        ]);
    }

    /**
     * Test defining availability generates correct slots.
     */
    public function test_defining_availability_generates_slots()
    {
        $doctor = Doctor::create([
            'name' => 'Dr. Watson',
            'email' => 'watson@example.com',
        ]);

        $tomorrow = now()->addDay()->format('Y-m-d');

        $response = $this->postJson("/api/doctors/{$doctor->id}/availabilities", [
            'date' => $tomorrow,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration' => 30, // should generate 4 slots: 09:00-09:30, 09:30-10:00, 10:00-10:30, 10:30-11:00
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'slots' => [
                    '*' => ['id', 'doctor_id', 'date', 'start_time', 'end_time', 'status']
                ]
            ]);

        $this->assertCount(4, $response->json('slots'));
        $this->assertDatabaseHas('availabilities', [
            'doctor_id' => $doctor->id,
            'date' => $tomorrow,
            'slot_duration' => 30,
        ]);
        $this->assertDatabaseHas('slots', [
            'doctor_id' => $doctor->id,
            'date' => $tomorrow,
            'start_time' => '09:00:00',
            'end_time' => '09:30:00',
            'status' => 'available',
        ]);
    }

    /**
     * Test overlapping availability schedules.
     */
    public function test_cannot_define_overlapping_availability()
    {
        $doctor = Doctor::create([
            'name' => 'Dr. Watson',
            'email' => 'watson@example.com',
        ]);

        $tomorrow = now()->addDay()->format('Y-m-d');

        // Define first availability
        $this->postJson("/api/doctors/{$doctor->id}/availabilities", [
            'date' => $tomorrow,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration' => 30,
        ])->assertStatus(201);

        // Define overlapping availability: 10:00 to 12:00
        $response = $this->postJson("/api/doctors/{$doctor->id}/availabilities", [
            'date' => $tomorrow,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'slot_duration' => 30,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Overlapping availability schedule already exists for this doctor on the selected date/time.',
            ]);
    }

    /**
     * Test invalid inputs for availability definition.
     */
    public function test_cannot_define_availability_with_invalid_times()
    {
        $doctor = Doctor::create([
            'name' => 'Dr. Watson',
            'email' => 'watson@example.com',
        ]);

        $yesterday = now()->subDay()->format('Y-m-d');

        // Past date
        $response = $this->postJson("/api/doctors/{$doctor->id}/availabilities", [
            'date' => $yesterday,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'slot_duration' => 30,
        ]);
        $response->assertStatus(422);

        // End time before start time
        $response = $this->postJson("/api/doctors/{$doctor->id}/availabilities", [
            'date' => now()->addDay()->format('Y-m-d'),
            'start_time' => '11:00',
            'end_time' => '09:00',
            'slot_duration' => 30,
        ]);
        $response->assertStatus(422);
    }
}
