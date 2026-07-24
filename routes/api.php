<?php

use App\Http\Controllers\Api\TrainController;
use Illuminate\Support\Facades\Route;

Route::get('/active-trains', TrainController::class);
