<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Slot extends Model
{
    protected $fillable = ['doctor_id', 'availability_id', 'date', 'start_time', 'end_time', 'status'];

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function availability()
    {
        return $this->belongsTo(Availability::class);
    }

    public function booking()
    {
        return $this->hasOne(Booking::class);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }
}
