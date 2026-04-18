<?php

namespace App\Http\Controllers\Api\Mobile\Services;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Branch;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends MobileApiController
{
    public function index(Request $request)
    {
        $branchId = $request->string('branch_id')->value();

        $query = Service::query();

        if ($branchId !== '') {
            $branch = Branch::query()->find($branchId);

            if ($branch) {
                $query = $branch->services();
            } else {
                $query->where('branch_id', $branchId);
            }
        }

        $services = $query
            ->orderBy('service_name')
            ->get()
            ->map(function (Service $service) {
                return [
                    'id' => $service->getKey(),
                    'service_name' => $service->service_name,
                    'duration' => $service->average_service_duration_minutes
                        ? $service->average_service_duration_minutes.' min'
                        : '',
                    'processing' => $service->average_service_duration_minutes
                        ? $service->average_service_duration_minutes.' min'
                        : '',
                    'icon' => $service->service_icon ?? 'service',
                ];
            })
            ->values()
            ->all();

        return $this->respond($services);
    }
}
