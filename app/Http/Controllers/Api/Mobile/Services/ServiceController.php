<?php

namespace App\Http\Controllers\Api\Mobile\Services;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends MobileApiController
{
    public function index(Request $request)
    {
        $branchId = trim($request->string('branch_id')->value());
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $query = Service::query()
            ->where(function ($builder): void {
                // Legacy rows might have null is_active. Treat null as active.
                $builder->where('is_active', true)->orWhereNull('is_active');
            });

        if ($branchId !== '') {
            $query->where(function ($builder) use ($branchId): void {
                $builder
                    // Legacy direct assignment on services table.
                    ->where('branch_id', $branchId)
                    // New many-to-many assignment via branch_service pivot.
                    ->orWhereHas('branches', function ($branchQuery) use ($branchId): void {
                        $branchQuery->where('branches.id', $branchId);
                    });
            });
        }

        $paginator = $query
            ->distinct('services.id')
            ->select([
                'id',
                'service_name',
                'average_service_duration_minutes',
                'service_icon',
                'is_active',
            ])
            ->orderBy('service_name')
            ->paginate($perPage, ['*'], 'page', $page);

        $services = $paginator
            ->getCollection()
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

        return $this->respond(
            $services,
            meta: $this->paginationMeta($paginator->currentPage(), $paginator->perPage(), $paginator->total()),
        );
    }
}
