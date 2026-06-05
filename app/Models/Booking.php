<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Str;

class Booking extends Model
{
    protected $fillable = ['slot_id', 'booking_reference', 'patient_name', 'patient_email', 'status', 'cancellation_reason'];

    public function slot()
    {
        return $this->belongsTo(Slot::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public static function generateReference(): string
    {
        do {
            $ref = 'BK-' . Str::upper(Str::random(4)) . '-' . Str::upper(Str::random(4));
        } while (self::where('booking_reference', $ref)->exists());

        return $ref;
    }
}
