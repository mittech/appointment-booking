<?php

use App\Http\Controllers\DoctorController;
use App\Http\Controllers\BookingController;
use Illuminate\Support\Facades\Route;

Route::post('/doctors', [DoctorController::class, 'store']);
Route::post('/doctors/{doctor}/availabilities', [DoctorController::class, 'defineAvailability']);

Route::get('/slots/available', [BookingController::class, 'index']);
Route::post('/bookings', [BookingController::class, 'store']);
Route::post('/bookings/{reference}/cancel', [BookingController::class, 'cancel']);
Route::post('/bookings/{reference}/reschedule', [BookingController::class, 'reschedule']);
