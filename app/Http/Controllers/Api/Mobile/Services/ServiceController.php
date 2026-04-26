<?php

namespace App\Http\Controllers\Api\Mobile\Services;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceController extends MobileApiController
{
    public function index(Request $request)
    {
        $branchId = trim($request->string('branch_id')->value());
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $query = Service::query();

        if ($branchId !== '') {
            $query
                ->leftJoin('branch_service as branch_service_override', function ($join) use ($branchId): void {
                    $join
                        ->on('services.id', '=', 'branch_service_override.service_id')
                        ->where('branch_service_override.branch_id', '=', $branchId);
                })
                ->where(function ($builder) use ($branchId): void {
                    $builder
                        // Legacy direct assignment on services table.
                        ->where('services.branch_id', $branchId)
                        // New many-to-many assignment via branch_service pivot.
                        ->orWhereNotNull('branch_service_override.branch_id');
                })
                ->where(function ($builder): void {
                    $builder
                        ->where(function ($activeBuilder): void {
                            $activeBuilder
                                ->whereNull('branch_service_override.is_active_override')
                                ->where(function ($legacyActive): void {
                                    // Legacy rows might have null is_active. Treat null as active.
                                    $legacyActive->where('services.is_active', true)->orWhereNull('services.is_active');
                                });
                        })
                        ->orWhere('branch_service_override.is_active_override', true);
                })
                ->select([
                    'services.id',
                    DB::raw('COALESCE(branch_service_override.service_name_override, services.service_name) as service_name'),
                    DB::raw('COALESCE(branch_service_override.average_service_duration_minutes_override, services.average_service_duration_minutes) as average_service_duration_minutes'),
                    DB::raw('COALESCE(branch_service_override.service_icon_override, services.service_icon) as service_icon'),
                ]);
        } else {
            $query
                ->where(function ($builder): void {
                    // Legacy rows might have null is_active. Treat null as active.
                    $builder->where('is_active', true)->orWhereNull('is_active');
                })
                ->select([
                    'id',
                    'service_name',
                    'average_service_duration_minutes',
                    'service_icon',
                ]);
        }

        $paginator = $query
            ->distinct('services.id')
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
