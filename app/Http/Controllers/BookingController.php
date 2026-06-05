<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Booking;
use App\Models\Slot;
use App\Models\Notification;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BookingController extends Controller
{
    /**
     * View available slots (current and future only).
     */
    public function index(Request $request)
    {
        $now = now();
        $query = Slot::with('doctor')->available();

        // Prevent viewing past slots
        $query->where(function ($q) use ($now) {
            $q->where('date', '>', $now->toDateString())
              ->orWhere(function ($q2) use ($now) {
                  $q2->where('date', '=', $now->toDateString())
                     ->where('start_time', '>=', $now->toTimeString());
              });
        });

        if ($request->has('doctor_id')) {
            $query->where('doctor_id', $request->doctor_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        $slots = $query->orderBy('date')->orderBy('start_time')->get();

        return response()->json($slots);
    }

    /**
     * Book an appointment.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'slot_id' => 'required|exists:slots,id',
            'patient_name' => 'required|string|max:255',
            'patient_email' => 'required|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $booking = DB::transaction(function () use ($request) {
                // 1. Lock slot row for update to prevent concurrent double-booking
                $slot = Slot::where('id', $request->slot_id)
                    ->lockForUpdate()
                    ->first();

                if (!$slot) {
                    throw new \Exception('Slot not found.');
                }

                if ($slot->status !== 'available') {
                    throw new \Exception('This time slot is already booked.');
                }

                // 2. Prevent booking past slots
                $slotDateTime = Carbon::parse($slot->date . ' ' . $slot->start_time);
                if ($slotDateTime->isPast()) {
                    throw new \Exception('Cannot book a past time slot.');
                }

                // 3. Mark slot as booked
                $slot->status = 'booked';
                $slot->save();

                // 4. Create booking
                $booking = Booking::create([
                    'slot_id' => $slot->id,
                    'booking_reference' => Booking::generateReference(),
                    'patient_name' => $request->patient_name,
                    'patient_email' => $request->patient_email,
                    'status' => 'active',
                ]);

                // 5. Create notification record
                $notification = Notification::create([
                    'booking_id' => $booking->id,
                    'type' => 'booking_confirmed',
                    'recipient_email' => $booking->patient_email,
                ]);

                // 6. Queue the notification
                SendNotificationJob::dispatch($notification->id);

                return $booking;
            });

            return response()->json([
                'message' => 'Appointment booked successfully.',
                'booking' => $booking->load('slot.doctor')
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Cancel an appointment.
     */
    public function cancel(Request $request, $reference)
    {
        $validator = Validator::make($request->all(), [
            'cancellation_reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $booking = DB::transaction(function () use ($reference, $request) {
                // 1. Lock the booking
                $booking = Booking::where('booking_reference', $reference)
                    ->lockForUpdate()
                    ->first();

                if (!$booking) {
                    throw new \Exception('Booking not found.');
                }

                if ($booking->status === 'cancelled') {
                    throw new \Exception('Booking is already cancelled.');
                }

                // 2. Lock associated slot
                $slot = Slot::where('id', $booking->slot_id)
                    ->lockForUpdate()
                    ->first();

                // 3. Update booking
                $booking->status = 'cancelled';
                $booking->cancellation_reason = $request->cancellation_reason;
                $booking->save();

                // 4. Make slot available again
                if ($slot) {
                    $slot->status = 'available';
                    $slot->save();
                }

                // 5. Create notification record
                $notification = Notification::create([
                    'booking_id' => $booking->id,
                    'type' => 'booking_cancelled',
                    'recipient_email' => $booking->patient_email,
                ]);

                // 6. Queue the notification
                SendNotificationJob::dispatch($notification->id);

                return $booking;
            });

            return response()->json([
                'message' => 'Appointment cancelled successfully.',
                'booking' => $booking
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Reschedule an appointment.
     */
    public function reschedule(Request $request, $reference)
    {
        $validator = Validator::make($request->all(), [
            'new_slot_id' => 'required|exists:slots,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $booking = DB::transaction(function () use ($reference, $request) {
                // 1. Lock the booking
                $booking = Booking::where('booking_reference', $reference)
                    ->lockForUpdate()
                    ->first();

                if (!$booking) {
                    throw new \Exception('Booking not found.');
                }

                if ($booking->status !== 'active') {
                    throw new \Exception('Only active bookings can be rescheduled.');
                }

                if ($booking->slot_id == $request->new_slot_id) {
                    throw new \Exception('New slot must be different from the current slot.');
                }

                // 2. Lock old slot and new slot
                $oldSlot = Slot::where('id', $booking->slot_id)
                    ->lockForUpdate()
                    ->first();

                $newSlot = Slot::where('id', $request->new_slot_id)
                    ->lockForUpdate()
                    ->first();

                if (!$newSlot) {
                    throw new \Exception('New slot not found.');
                }

                if ($newSlot->status !== 'available') {
                    throw new \Exception('The requested slot is already booked.');
                }

                // Prevent rescheduling to a past slot
                $newSlotDateTime = Carbon::parse($newSlot->date . ' ' . $newSlot->start_time);
                if ($newSlotDateTime->isPast()) {
                    throw new \Exception('Cannot reschedule to a past time slot.');
                }

                // 3. Mark old slot available
                if ($oldSlot) {
                    $oldSlot->status = 'available';
                    $oldSlot->save();
                }

                // 4. Mark new slot booked
                $newSlot->status = 'booked';
                $newSlot->save();

                // 5. Update booking
                $booking->slot_id = $newSlot->id;
                $booking->save();

                // 6. Create notification
                $notification = Notification::create([
                    'booking_id' => $booking->id,
                    'type' => 'booking_rescheduled',
                    'recipient_email' => $booking->patient_email,
                ]);

                // 7. Queue notification
                SendNotificationJob::dispatch($notification->id);

                return $booking;
            });

            return response()->json([
                'message' => 'Appointment rescheduled successfully.',
                'booking' => $booking->load('slot.doctor')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
