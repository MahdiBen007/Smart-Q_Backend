<?php

namespace App\Http\Controllers\Api\Dashboard\Branches;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Branches\StoreBranchRequest;
use App\Http\Requests\Api\Dashboard\Branches\UpdateBranchRequest;
use App\Http\Requests\Api\Dashboard\Branches\UpdateBranchStatusRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Service;
use Illuminate\Validation\ValidationException;

class BranchController extends DashboardApiController
{
    public function bootstrap()
    {
        $branches = $this->baseQuery()->get();

        return $this->respond([
            'branches' => $branches->map(fn (Branch $branch) => $this->transformBranch($branch))->values()->all(),
            'defaultSelectedId' => $branches->first()?->getKey(),
        ]);
    }

    public function index()
    {
        return $this->respond(
            $this->baseQuery()
                ->get()
                ->map(fn (Branch $branch) => $this->transformBranch($branch))
                ->values()
                ->all()
        );
    }

    public function show(Branch $branch)
    {
        $branch->loadMissing($this->branchRelations());

        return $this->respond($this->transformBranch($branch));
    }

    public function store(StoreBranchRequest $request)
    {
        $validated = $request->validated();

        $companyId = $validated['company_id']
            ?? $request->user()?->staffMember?->company_id
            ?? Company::query()->value('id');

        if (! $companyId) {
            throw ValidationException::withMessages([
                'company_id' => ['A company context is required before creating branches.'],
            ]);
        }

        $branch = Branch::query()->create($this->branchPayload($validated, companyId: $companyId));

        return $this->respond(
            $this->transformBranch($branch->load($this->branchRelations())),
            'Branch created successfully.',
            201
        );
    }

    public function update(UpdateBranchRequest $request, Branch $branch)
    {
        $branch->update($this->branchPayload($request->validated(), $branch));

        return $this->respond(
            $this->transformBranch($branch->fresh($this->branchRelations())),
            'Branch updated successfully.'
        );
    }

    public function updateStatus(UpdateBranchStatusRequest $request, Branch $branch)
    {
        $branch->update([
            'branch_status' => $request->validated('status'),
        ]);

        return $this->respond(
            $this->transformBranch($branch->fresh($this->branchRelations())),
            'Branch status updated successfully.'
        );
    }

    public function services(Branch $branch)
    {
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

    protected function baseQuery()
    {
        return Branch::query()->with($this->branchRelations())->orderBy('branch_name');
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
            'branch_status' => $validated['status'] ?? $branch?->branch_status,
            'latitude' => array_key_exists('latitude', $validated) ? $validated['latitude'] : $branch?->latitude,
            'longitude' => array_key_exists('longitude', $validated) ? $validated['longitude'] : $branch?->longitude,
            'pin_top' => array_key_exists('pin_top', $validated) ? $validated['pin_top'] : $branch?->pin_top,
            'pin_left' => array_key_exists('pin_left', $validated) ? $validated['pin_left'] : $branch?->pin_left,
        ];
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
