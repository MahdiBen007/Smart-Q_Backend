<?php

namespace App\Http\Controllers\Api\Dashboard\Operations;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Operations\UpsertOperationsScheduleRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\OperationsSchedule;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;

class OperationsScheduleController extends DashboardApiController
{
    protected function buildTargetKey(string $scope, ?string $branchId, ?string $serviceId): string
    {
        return match ($scope) {
            'service' => 'service:'.(string) $serviceId,
            'branch' => 'branch:'.(string) $branchId,
            default => 'global',
        };
    }

    public function bootstrap(Request $request)
    {
        abort_unless(
            $this->isDashboardAdmin($request) || $this->hasDashboardRole($request, 'manager'),
            403
        );

        $companyId = $this->currentCompanyId($request) ?: Company::query()->value('id');
        abort_unless($companyId !== null, 403);

        $branchesQuery = Branch::query()
            ->select(['id', 'branch_name'])
            ->where('company_id', $companyId)
            ->orderBy('branch_name');
        $branchesQuery = $this->scopeQueryByAssignedBranchColumn($branchesQuery, $request, 'id');

        $branches = $branchesQuery->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->getKey(),
                'name' => $branch->branch_name,
            ])
            ->values()
            ->all();

        $servicesQuery = Service::query()
            ->select(['id', 'service_name', 'branch_id'])
            ->whereHas('branch', fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('service_name');

        if ($this->shouldRestrictToAssignedBranch($request)) {
            $branchId = $this->currentBranchId($request);

            if ($branchId !== null) {
                $servicesQuery->where(function ($query) use ($branchId): void {
                    $query
                        ->where('branch_id', $branchId)
                        ->orWhereHas('branches', fn ($branchQuery) => $branchQuery->whereKey($branchId));
                });
            }
        }

        $services = $servicesQuery->get()
            ->map(fn (Service $service) => [
                'id' => $service->getKey(),
                'name' => $service->service_name,
                'branch_id' => $service->branch_id,
            ])
            ->values()
            ->all();

        $canEditGlobal = $this->isDashboardAdmin($request);

        $branchIds = array_map(fn (array $branch) => (string) ($branch['id'] ?? ''), $branches);
        $serviceIds = array_map(fn (array $service) => (string) ($service['id'] ?? ''), $services);

        $defaultBranchId = $this->currentBranchId($request);
        if ($defaultBranchId === '' || $defaultBranchId === null || ! in_array($defaultBranchId, $branchIds, true)) {
            $defaultBranchId = $branchIds[0] ?? null;
        }

        $defaultServiceId = $this->currentServiceId($request);
        if ($defaultServiceId === '' || $defaultServiceId === null || ! in_array($defaultServiceId, $serviceIds, true)) {
            $defaultServiceId = $serviceIds[0] ?? null;
        }

        if ($defaultBranchId === '') {
            $defaultBranchId = null;
        }

        if ($defaultServiceId === '') {
            $defaultServiceId = null;
        }

        $defaultScope = match (true) {
            $this->shouldRestrictToAssignedService($request) => 'service',
            $this->shouldRestrictToAssignedBranch($request) => 'branch',
            $canEditGlobal => $defaultBranchId !== null ? 'branch' : 'global',
            $defaultBranchId !== null => 'branch',
            $defaultServiceId !== null => 'service',
            default => 'branch',
        };

        return $this->respond([
            'branches' => $branches,
            'services' => $services,
            'default_scope' => $defaultScope,
            'default_branch_id' => $defaultBranchId,
            'default_service_id' => $defaultServiceId,
            'can_edit_global' => $canEditGlobal,
        ]);
    }

    public function show(Request $request)
    {
        abort_unless(
            $this->isDashboardAdmin($request) || $this->hasDashboardRole($request, 'manager'),
            403
        );

        $companyId = $this->currentCompanyId($request);

        $requestedScope = strtolower((string) $request->query('scope', 'branch'));
        $scope = match (true) {
            $this->shouldRestrictToAssignedService($request) => 'service',
            $this->shouldRestrictToAssignedBranch($request) => 'branch',
            default => $requestedScope,
        };

        abort_unless(in_array($scope, ['branch', 'service', 'global'], true), 422);

        if ($scope === 'global') {
            abort_unless($this->isDashboardAdmin($request), 403);
        }

        $branchId = null;
        if ($scope === 'branch') {
            $branchId = trim((string) $request->query('branch_id'));

            if ($this->shouldRestrictToAssignedBranch($request)) {
                $branchId = (string) $this->currentBranchId($request);
            } elseif ($branchId === '') {
                $branchId = $this->currentBranchId($request);
            }
        }

        $serviceId = null;
        if ($scope === 'service') {
            $serviceId = trim((string) $request->query('service_id'));

            if ($this->shouldRestrictToAssignedService($request)) {
                $serviceId = (string) $this->currentServiceId($request);
            } elseif ($serviceId === '') {
                $serviceId = $this->currentServiceId($request);
            }
        }

        if ($scope === 'branch') {
            abort_unless($branchId !== null && $branchId !== '', 422);
            $branch = Branch::query()->findOrFail($branchId);

            if ($companyId === null) {
                $companyId = $branch->company_id;
            } else {
                abort_unless($branch->company_id === $companyId, 404);
            }
        }

        if ($scope === 'service') {
            abort_unless($serviceId !== null && $serviceId !== '', 422);
            $service = Service::query()->with('branch:id,company_id')->findOrFail($serviceId);
            $serviceCompanyId = $service->branch?->company_id;
            abort_unless($serviceCompanyId !== null, 404);

            if ($companyId === null) {
                $companyId = $serviceCompanyId;
            } else {
                abort_unless($serviceCompanyId === $companyId, 404);
            }
        }

        if ($companyId === null) {
            $companyId = Company::query()->value('id');
        }
        abort_unless($companyId !== null, 403);

        $schedule = OperationsSchedule::query()
            ->where('company_id', $companyId)
            ->where('target_key', $this->buildTargetKey($scope, $branchId, $serviceId))
            ->first();

        return $this->respond([
            'id' => $schedule?->getKey(),
            'scope' => $scope,
            'branch_id' => $branchId,
            'service_id' => $serviceId,
            'status' => $schedule?->status ?? 'draft',
            'published_at' => $schedule?->published_at?->toISOString(),
            'updated_at' => $schedule?->updated_at?->toISOString(),
            'schedule' => $schedule?->schedule,
        ]);
    }

    public function upsert(UpsertOperationsScheduleRequest $request)
    {
        abort_unless(
            $this->isDashboardAdmin($request) || $this->hasDashboardRole($request, 'manager'),
            403
        );

        $companyId = $this->currentCompanyId($request);

        /** @var User $user */
        $user = $request->user();

        $requestedScope = strtolower((string) $request->input('scope', 'branch'));
        $scope = match (true) {
            $this->shouldRestrictToAssignedService($request) => 'service',
            $this->shouldRestrictToAssignedBranch($request) => 'branch',
            default => $requestedScope,
        };

        if ($scope === 'global') {
            abort_unless($this->isDashboardAdmin($request), 403);
        }

        $branchId = null;
        if ($scope === 'branch') {
            $branchId = $request->input('branch_id');

            if ($this->shouldRestrictToAssignedBranch($request)) {
                $branchId = $this->currentBranchId($request);
            } elseif (! is_string($branchId) || trim($branchId) === '') {
                $branchId = $this->currentBranchId($request);
            }
        }

        $serviceId = null;
        if ($scope === 'service') {
            $serviceId = $request->input('service_id');

            if ($this->shouldRestrictToAssignedService($request)) {
                $serviceId = $this->currentServiceId($request);
            } elseif (! is_string($serviceId) || trim($serviceId) === '') {
                $serviceId = $this->currentServiceId($request);
            }
        }

        if ($scope === 'branch') {
            abort_unless(is_string($branchId) && $branchId !== '', 422);
            $branch = Branch::query()->findOrFail($branchId);

            if ($companyId === null) {
                $companyId = $branch->company_id;
            } else {
                abort_unless($branch->company_id === $companyId, 404);
            }
        }

        if ($scope === 'service') {
            abort_unless(is_string($serviceId) && $serviceId !== '', 422);
            $service = Service::query()->with('branch:id,company_id')->findOrFail($serviceId);
            $serviceCompanyId = $service->branch?->company_id;
            abort_unless($serviceCompanyId !== null, 404);

            if ($companyId === null) {
                $companyId = $serviceCompanyId;
            } else {
                abort_unless($serviceCompanyId === $companyId, 404);
            }
        }

        if ($companyId === null) {
            $companyId = Company::query()->value('id');
        }
        abort_unless($companyId !== null, 403);

        $status = strtolower((string) $request->input('status', 'draft'));
        if (! in_array($status, ['draft', 'published'], true)) {
            $status = 'draft';
        }

        $schedulePayload = $request->input('schedule', []);

        $schedule = OperationsSchedule::query()->firstOrNew([
            'company_id' => $companyId,
            'target_key' => $this->buildTargetKey($scope, $branchId, $serviceId),
        ]);

        if (! $schedule->exists) {
            $schedule->created_by = $user->getKey();
        }

        $schedule->fill([
            'scope' => $scope,
            'branch_id' => $branchId,
            'service_id' => $serviceId,
            'status' => $status,
            'schedule' => $schedulePayload,
            'updated_by' => $user->getKey(),
        ]);

        if ($status === 'published') {
            $schedule->published_at = now();
            $schedule->published_schedule = $schedulePayload;
        }

        $schedule->save();

        $this->invalidateDashboardCache($request);

        return $this->respond([
            'id' => $schedule->getKey(),
            'scope' => $schedule->scope,
            'branch_id' => $schedule->branch_id,
            'service_id' => $schedule->service_id,
            'status' => $schedule->status,
            'published_at' => $schedule->published_at?->toISOString(),
            'updated_at' => $schedule->updated_at?->toISOString(),
            'schedule' => $schedule->schedule,
        ], 'Operations schedule saved.');
    }
}
