<?php

namespace App\Http\Controllers\Api\Mobile\TimeSlots;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Support\Operations\OperationsScheduleTimeSlotService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TimeSlotController extends MobileApiController
{
    public function __construct(
        protected OperationsScheduleTimeSlotService $timeSlots,
    ) {}

    public function index(Request $request)
    {
        $branchId = trim($request->string('branch_id')->value());
        $serviceId = trim($request->string('service_id')->value());
        $date = $request->string('date')->value();
        $bookingChannel = $request->string('booking_channel', 'in_person')->value();

        if ($branchId === '' || $serviceId === '') {
            return $this->respondValidationError(
                'Branch and service are required to fetch time slots.',
                [
                    'branch_id' => ['Select a branch first.'],
                    'service_id' => ['Select a service first.'],
                ],
            );
        }

        try {
            $baseDate = $date !== '' ? Carbon::parse($date) : Carbon::now();
        } catch (\Throwable) {
            $baseDate = Carbon::now();
        }

        $slots = $this->timeSlots->listSlots($branchId, $serviceId, $baseDate, $bookingChannel);

        return $this->respond($slots);
    }
}
