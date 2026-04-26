<?php

namespace App\Http\Controllers\Api\Dashboard\Branches;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Branches\ListBranchesRequest;
use App\Http\Requests\Api\Dashboard\Branches\StoreBranchRequest;
use App\Http\Requests\Api\Dashboard\Branches\UpdateBranchRequest;
use App\Http\Requests\Api\Dashboard\Branches\UpdateBranchStatusRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\KioskDevice;
use App\Models\Service;
use App\Models\WalkInTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class BranchController extends DashboardApiController
{
    public function bootstrap(ListBranchesRequest $request)
    {
        $branches = $this->applyFilters($this->baseQuery($request), $request)->get();

        return $this->respond([
            'branches' => $branches->map(fn (Branch $branch) => $this->transformBranch($branch))->values()->all(),
            'defaultSelectedId' => $branches->first()?->getKey(),
        ]);
    }

    public function index(ListBranchesRequest $request)
    {
        return $this->respondIndexCollection(
            $request,
            $this->applyFilters($this->baseQuery($request), $request),
            fn (Branch $branch) => $this->transformBranch($branch)
        );
    }

    public function show(ListBranchesRequest $request, Branch $branch)
    {
        $this->ensureCompanyAccess($request, $branch);
        $branch->loadMissing($this->branchRelations());

        return $this->respond($this->transformBranch($branch));
    }

    public function store(StoreBranchRequest $request)
    {
        $validated = $request->validated();

        $companyId = $this->currentCompanyId($request)
            ?? $validated['company_id']
            ?? Company::query()->value('id');

        if (! $companyId) {
            throw ValidationException::withMessages([
                'company_id' => ['A company context is required before creating branches.'],
            ]);
        }

        $branch = Branch::query()->create($this->branchPayload($validated, companyId: $companyId));

        $this->invalidateDashboardCache($request, $companyId);

        return $this->respond(
            $this->transformBranch($branch->load($this->branchRelations())),
            'Branch created successfully.',
            201
        );
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $this->ensureCompanyAccess($request, $branch);
        $branch->update($this->branchPayload($request->validated(), $branch));
        $this->invalidateDashboardCache($request, $branch->company_id);

        return $this->respond(
            $this->transformBranch($branch->fresh($this->branchRelations())),
            'Branch updated successfully.'
        );
    }

    public function updateStatus(UpdateBranchStatusRequest $request, Branch $branch)
    {
        $this->ensureCompanyAccess($request, $branch);
        $branch->update([
            'branch_status' => $request->validated('status'),
        ]);
        $this->invalidateDashboardCache($request, $branch->company_id);

        return $this->respond(
            $this->transformBranch($branch->fresh($this->branchRelations())),
            'Branch status updated successfully.'
        );
    }

    public function destroy(ListBranchesRequest $request, Branch $branch)
    {
        $this->ensureCompanyAccess($request, $branch);

        $blockingRelations = $this->resolveDeletionBlockers($branch);

        if ($blockingRelations !== []) {
            throw ValidationException::withMessages([
                'branch' => [
                    sprintf(
                        'Remove the linked %s before deleting this branch.',
                        implode(', ', $blockingRelations)
                    ),
                ],
            ]);
        }

        $deletedBranchId = $branch->getKey();
        $companyId = $branch->company_id;

        $branch->delete();
        $this->invalidateDashboardCache($request, $companyId);

        return $this->respond(
            [
                'id' => $deletedBranchId,
                'deleted' => true,
            ],
            'Branch deleted successfully.'
        );
    }

    public function services(ListBranchesRequest $request, Branch $branch)
    {
        $this->ensureCompanyAccess($request, $branch);
        $branch->loadMissing('services');

        return $this->respond(
            $branch->services->map(fn (Service $service) => [
                'id' => $service->getKey(),
                'name' => $service->service_name,
                'subtitle' => $service->service_subtitle,
                'icon' => $service->service_icon ?: 'support',
                'status' => $service->is_active ? 'Active' : 'Inactive',
            ])->values()->all()
        );
    }

    protected function baseQuery(ListBranchesRequest $request): Builder
    {
        return $this->scopeQueryByCompanyColumn(
            Branch::query()->with($this->branchRelations())->orderBy('branch_name'),
            $request
        );
    }

    protected function branchRelations(): array
    {
        return [
            'company',
            'services',
            'staffMembers',
            'appointments',
            'dailyQueueSessions.queueEntries',
        ];
    }

    protected function branchPayload(array $validated, ?Branch $branch = null, ?string $companyId = null): array
    {
        return [
            'company_id' => $companyId ?? $branch?->company_id,
            'branch_name' => $validated['name'] ?? $branch?->branch_name,
            'branch_code' => array_key_exists('code', $validated) ? $validated['code'] : $branch?->branch_code,
            'branch_address' => $validated['address'] ?? $branch?->branch_address,
            'logo_url' => array_key_exists('logo_url', $validated) ? $validated['logo_url'] : $branch?->logo_url,
            'branch_status' => $validated['status'] ?? $branch?->branch_status,
            'latitude' => array_key_exists('latitude', $validated) ? $validated['latitude'] : $branch?->latitude,
            'longitude' => array_key_exists('longitude', $validated) ? $validated['longitude'] : $branch?->longitude,
            'pin_top' => array_key_exists('pin_top', $validated) ? $validated['pin_top'] : $branch?->pin_top,
            'pin_left' => array_key_exists('pin_left', $validated) ? $validated['pin_left'] : $branch?->pin_left,
        ];
    }

    protected function applyFilters(Builder $query, ListBranchesRequest $request): Builder
    {
        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status');

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('branch_name', 'like', '%'.$search.'%')
                    ->orWhere('branch_address', 'like', '%'.$search.'%')
                    ->orWhere('branch_code', 'like', '%'.$search.'%');
            });
        }

        if (is_string($status) && $status !== '') {
            $query->where('branch_status', $status);
        }

        return $query;
    }

    protected function resolveDeletionBlockers(Branch $branch): array
    {
        $blockers = [];

        $hasDirectServices = Service::query()
            ->where('branch_id', $branch->getKey())
            ->exists();

        if ($hasDirectServices || $branch->services()->exists()) {
            $blockers[] = 'services';
        }

        if ($branch->staffMembers()->exists()) {
            $blockers[] = 'staff records';
        }

        if ($branch->appointments()->exists()) {
            $blockers[] = 'appointments';
        }

        if (WalkInTicket::withTrashed()->where('branch_id', $branch->getKey())->exists()) {
            $blockers[] = 'walk-in tickets';
        }

        if ($branch->dailyQueueSessions()->exists()) {
            $blockers[] = 'queue sessions';
        }

        if (KioskDevice::query()->where('branch_id', $branch->getKey())->exists()) {
            $blockers[] = 'kiosk devices';
        }

        return $blockers;
    }

    protected function transformBranch(Branch $branch): array
    {
        $todayAppointments = $branch->appointments
            ->filter(fn ($appointment) => optional($appointment->appointment_date)?->isToday())
            ->count();

        $onlineStaff = $branch->staffMembers
            ->filter(fn ($staff) => (bool) $staff->is_online || $staff->employment_status->value === 'active')
            ->count();

        $activeEntries = $branch->dailyQueueSessions
            ->filter(fn ($session) => optional($session->session_date)?->isToday())
            ->flatMap->queueEntries
            ->filter(fn ($entry) => ! in_array($entry->queue_status->value, ['completed', 'cancelled'], true))
            ->values();

        $averageServiceMinutes = (int) round($branch->services->avg('average_service_duration_minutes') ?? 10);
        $waitMinutes = $activeEntries->count() > 0
            ? (int) round(($activeEntries->avg('queue_position') ?: 1) * max($averageServiceMinutes, 1) / max($onlineStaff, 1))
            : 0;

        return [
            'id' => $branch->getKey(),
            'code' => $branch->branch_code,
            'name' => $branch->branch_name,
            'address' => $branch->branch_address,
            'logoUrl' => $branch->logo_url,
            'latitude' => $branch->latitude !== null ? (float) $branch->latitude : null,
            'longitude' => $branch->longitude !== null ? (float) $branch->longitude : null,
            'status' => $branch->branch_status,
            'servicesAvailable' => $branch->services->count(),
            'staffOnline' => $onlineStaff,
            'appointmentsToday' => $todayAppointments,
            'waitMinutes' => $waitMinutes,
            'pinTop' => $branch->pin_top ?? 50,
            'pinLeft' => $branch->pin_left ?? 50,
            'trendPeakMinutes' => max($waitMinutes, $averageServiceMinutes + 8),
            'assignedServices' => $branch->services
                ->map(fn (Service $service) => [
                    'id' => $service->getKey(),
                    'name' => $service->service_name,
                    'subtitle' => $service->service_subtitle ?: 'Operational service',
                    'icon' => $service->service_icon ?: 'support',
                ])
                ->values()
                ->all(),
            'company' => $branch->company ? [
                'id' => $branch->company->getKey(),
                'name' => $branch->company->company_name,
            ] : null,
        ];
    }
}
