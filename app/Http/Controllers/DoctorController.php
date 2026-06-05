<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Doctor;
use App\Models\Availability;
use App\Models\Slot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DoctorController extends Controller
{
    /**
     * Create a new doctor.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:doctors,email|max:255',
            'specialization' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $doctor = Doctor::create($validator->validated());

        return response()->json($doctor, 201);
    }

    /**
     * Define availability and generate slots.
     */
    public function defineAvailability(Request $request, Doctor $doctor)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'slot_duration' => 'required|integer|min:5|max:480',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $date = $request->date;
        $startTime = $request->start_time;
        $endTime = $request->end_time;
        $duration = (int) $request->slot_duration;

        // Parse start and end times to calculate slots
        $start = Carbon::parse($date . ' ' . $startTime);
        $end = Carbon::parse($date . ' ' . $endTime);

        // Calculate slots to generate
        $slotsData = [];
        $current = $start->copy();
        while ($current->copy()->addMinutes($duration)->lte($end)) {
            $slotEnd = $current->copy()->addMinutes($duration);
            $slotsData[] = [
                'doctor_id' => $doctor->id,
                'date' => $date,
                'start_time' => $current->format('H:i:s'),
                'end_time' => $slotEnd->format('H:i:s'),
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $current->addMinutes($duration);
        }

        if (empty($slotsData)) {
            return response()->json([
                'message' => 'No slots could be generated. The duration is larger than the specified time range.',
            ], 422);
        }

        try {
            $slots = DB::transaction(function () use ($doctor, $date, $startTime, $endTime, $duration, $slotsData) {
                // 1. Acquire lock on existing availabilities for the doctor and date to avoid concurrent overlap creation
                // Select for update is used here on a dummy search or we can check overlap
                $overlapping = Availability::where('doctor_id', $doctor->id)
                    ->where('date', $date)
                    ->where(function ($query) use ($startTime, $endTime) {
                        $query->where('start_time', '<', $endTime)
                              ->where('end_time', '>', $startTime);
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($overlapping) {
                    throw new \Exception('Overlapping availability schedule already exists for this doctor on the selected date/time.');
                }

                // 2. Create Availability
                $availability = Availability::create([
                    'doctor_id' => $doctor->id,
                    'date' => $date,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'slot_duration' => $duration,
                ]);

                // 3. Prepare slots data
                foreach ($slotsData as &$slot) {
                    $slot['availability_id'] = $availability->id;
                }

                // 4. Insert slots
                Slot::insert($slotsData);

                // Fetch and return the inserted slots
                return Slot::where('availability_id', $availability->id)->get();
            });

            return response()->json([
                'message' => 'Availability and slots generated successfully.',
                'slots' => $slots
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
