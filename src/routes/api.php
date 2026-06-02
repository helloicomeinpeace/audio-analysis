<?php

use App\Http\Controllers\AudioController;
use App\Http\Controllers\AudioJobController;
use Illuminate\Support\Facades\Route;

Route::post('/upload', [AudioController::class, 'upload']);
Route::get('/jobs/{jobId}', [AudioJobController::class, 'show']);
