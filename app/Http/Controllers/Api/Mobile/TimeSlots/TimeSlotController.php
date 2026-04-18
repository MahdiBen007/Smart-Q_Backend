<?php

namespace App\Http\Controllers\Api\Mobile\TimeSlots;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TimeSlotController extends MobileApiController
{
    public function index(Request $request)
    {
        $date = $request->string('date')->value();
        $baseDate = $date !== '' ? Carbon::parse($date) : Carbon::now();

        $slots = [];

        for ($hour = 9; $hour <= 16; $hour++) {
            $time = $baseDate->copy()->setTime($hour, 0);
            $slots[] = [
                'time' => $time->format('H:i'),
                'label' => $time->format('g:i A'),
                'available' => true,
            ];
        }

        return $this->respond($slots);
    }
}
