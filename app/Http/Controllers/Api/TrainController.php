<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TrainTrackingService;
use Illuminate\Http\Request;

class TrainController extends Controller
{
    public function __invoke(Request $request)
    {
        $service = new TrainTrackingService();

        $simulatedTime = $request->query('time');
        $speedMultiplier = (int) $request->query('speed', 1);

        $activeTrains = $service->getActiveTrains($simulatedTime, $speedMultiplier);

        return response()->json([
            'success' => true,
            'data' => $activeTrains,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
