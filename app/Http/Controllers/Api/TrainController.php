<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrainTrackingService;

class TrainController extends Controller
{
    public function __invoke()
    {
        $service = new TrainTrackingService();
        $activeTrains = $service->getActiveTrains();

        return response()->json([
            'success' => true,
            'data' => $activeTrains,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
