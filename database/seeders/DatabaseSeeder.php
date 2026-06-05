<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $doctors = [
            ['name' => 'Dr. Jane Smith', 'email' => 'jane.smith@example.com', 'specialization' => 'Cardiology'],
            ['name' => 'Dr. John Doe', 'email' => 'john.doe@example.com', 'specialization' => 'Pediatrics'],
            ['name' => 'Dr. Alice Johnson', 'email' => 'alice.johnson@example.com', 'specialization' => 'Dermatology'],
            ['name' => 'Dr. Bob Brown', 'email' => 'bob.brown@example.com', 'specialization' => 'General Medicine'],
        ];

        foreach ($doctors as $docData) {
            $doctor = \App\Models\Doctor::create($docData);

            // Create availability for tomorrow
            $date = now()->addDay()->toDateString();
            $availability = \App\Models\Availability::create([
                'doctor_id' => $doctor->id,
                'date' => $date,
                'start_time' => '09:00',
                'end_time' => '12:00',
                'slot_duration' => 30,
            ]);

            // Generate slots
            $start = \Illuminate\Support\Carbon::parse($date . ' 09:00');
            $end = \Illuminate\Support\Carbon::parse($date . ' 12:00');
            $duration = 30;

            $slotsData = [];
            $current = $start->copy();
            while ($current->copy()->addMinutes($duration)->lte($end)) {
                $slotEnd = $current->copy()->addMinutes($duration);
                $slotsData[] = [
                    'doctor_id' => $doctor->id,
                    'availability_id' => $availability->id,
                    'date' => $date,
                    'start_time' => $current->format('H:i:s'),
                    'end_time' => $slotEnd->format('H:i:s'),
                    'status' => 'available',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $current->addMinutes($duration);
            }
            \App\Models\Slot::insert($slotsData);
        }
    }
}
