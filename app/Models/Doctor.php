<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Doctor extends Model
{
    protected $fillable = ['name', 'email', 'specialization'];

    public function availabilities()
    {
        return $this->hasMany(Availability::class);
    }

    public function slots()
    {
        return $this->hasMany(Slot::class);
    }
}
