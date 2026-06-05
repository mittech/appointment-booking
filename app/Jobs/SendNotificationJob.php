<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use App\Models\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendNotificationJob implements ShouldQueue
{
    use Queueable;

    protected $notificationId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $notificationId)
    {
        $this->notificationId = $notificationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $notification = Notification::with('booking.slot.doctor')->find($this->notificationId);

        if (!$notification) {
            Log::warning("Notification not found for ID: {$this->notificationId}");
            return;
        }

        $booking = $notification->booking;
        $slot = $booking->slot;
        $doctor = $slot->doctor;

        $subject = "";
        $body = "";

        switch ($notification->type) {
            case 'booking_confirmed':
                $subject = "Appointment Booking Confirmed - Ref: {$booking->booking_reference}";
                $body = "Dear {$booking->patient_name},\n\nYour appointment with Dr. {$doctor->name} has been booked successfully.\n"
                    . "Date: {$slot->date}\nTime: {$slot->start_time} - {$slot->end_time}\n"
                    . "Booking Reference: {$booking->booking_reference}\n\nThank you.";
                break;
            case 'booking_cancelled':
                $subject = "Appointment Booking Cancelled - Ref: {$booking->booking_reference}";
                $body = "Dear {$booking->patient_name},\n\nYour appointment with Dr. {$doctor->name} on {$slot->date} at {$slot->start_time} has been cancelled.\n"
                    . "Reason: " . ($booking->cancellation_reason ?? 'Not specified') . "\n\nThank you.";
                break;
            case 'booking_rescheduled':
                $subject = "Appointment Booking Rescheduled - Ref: {$booking->booking_reference}";
                $body = "Dear {$booking->patient_name},\n\nYour appointment with Dr. {$doctor->name} has been rescheduled.\n"
                    . "New Date: {$slot->date}\nNew Time: {$slot->start_time} - {$slot->end_time}\n"
                    . "Booking Reference: {$booking->booking_reference}\n\nThank you.";
                break;
        }

        // Simulate sending email
        Log::info("=== SIMULATED EMAIL SENT ===");
        Log::info("To: {$notification->recipient_email}");
        Log::info("Subject: {$subject}");
        Log::info("Body:\n{$body}");
        Log::info("============================");

        // Update notification sent time
        $notification->update([
            'sent_at' => now(),
        ]);
    }
}
