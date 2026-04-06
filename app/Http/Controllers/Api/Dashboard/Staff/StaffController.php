<?php

namespace App\Http\Controllers\Api\Dashboard\Staff;

use App\Enums\EmploymentStatus;
use App\Enums\UserRoleName;
use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Staff\ListStaffRequest;
use App\Http\Requests\Api\Dashboard\Staff\StoreStaffRequest;
use App\Http\Requests\Api\Dashboard\Staff\UpdateStaffBranchRequest;
use App\Http\Requests\Api\Dashboard\Staff\UpdateStaffRequest;
use App\Http\Requests\Api\Dashboard\Staff\UpdateStaffStatusRequest;
use App\Http\Requests\Api\Dashboard\Staff\UploadStaffAvatarRequest;
use App\Models\Branch;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Dashboard\DashboardFormatting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StaffController extends DashboardApiController
{
    public function bootstrap(ListStaffRequest $request)
    {
        $branches = $this->scopeQueryByCompanyColumn(
            Branch::query()
            ->select(['id', 'branch_name'])
            ->orderBy('branch_name'),
            $request
        )->get();

        $services = $this->scopeQueryByCompanyRelation(
            Service::query()
                ->select(['id', 'service_name', 'branch_id'])
                ->with(['branches:id'])
                ->orderBy('service_name'),
            $request,
            'branch'
        )->get();

        return $this->respond([
            'staffMembers' => $this->applyFilters($this->baseQuery($request), $request)
                ->get()
                ->map(fn (StaffMember $staffMember) => $this->transformStaff($staffMember))
                ->values()
                ->all(),
            'branches' => $branches
                ->map(fn (Branch $branch) => [
                    'id' => $branch->getKey(),
                    'name' => $branch->branch_name,
                ])
                ->values()
                ->all(),
            'services' => $services
                ->map(fn (Service $service) => [
                    'id' => $service->getKey(),
                    'name' => $service->service_name,
                    'branchIds' => collect([$service->branch_id])
                        ->merge($service->branches->pluck('id'))
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ]);
    }

    public function index(ListStaffRequest $request)
    {
        return $this->respondIndexCollection(
            $request,
            $this->applyFilters($this->baseQuery($request), $request),
            fn (StaffMember $staffMember) => $this->transformStaff($staffMember)
        );
    }

    public function show(ListStaffRequest $request, StaffMember $staff)
    {
        $this->ensureCompanyAccess($request, $staff);
        $staff->loadMissing($this->staffRelations());

        return $this->respond($this->transformStaff($staff));
    }

    public function store(StoreStaffRequest $request)
    {
        $validated = $request->validated();

        $staff = DB::transaction(function () use ($validated) {
            $branch = Branch::query()->findOrFail($validated['branch_id']);
            $role = $validated['role'];

            $user = User::query()->create([
                'email' => $validated['email'],
                'phone_number' => $validated['phone_number'] ?? $this->randomPhone(),
                'password_hash' => $validated['password'],
                'is_active' => $validated['status'] !== 'Inactive',
            ]);

            UserRole::query()->create([
                'user_id' => $user->getKey(),
                'role_name' => $this->mapRoleLabelToEnum($validated['role']),
            ]);

            return StaffMember::query()->create([
                'user_id' => $user->getKey(),
                'company_id' => $branch->company_id,
                'branch_id' => $branch->getKey(),
                'service_id' => $role === 'Staff' ? ($validated['service_id'] ?? null) : null,
                'full_name' => $validated['name'],
                'display_staff_code' => $this->nextStaffCode(),
                'employment_status' => $this->mapStatusLabelToEnum($validated['status']),
                'avatar_url' => $validated['avatar_url'] ?? null,
                'is_online' => $validated['status'] === 'Active',
                'last_active_at' => $validated['status'] === 'Active' ? now() : null,
            ]);
        });

        $this->invalidateDashboardCache($request, $staff->company_id);

        return $this->respond(
            $this->transformStaff($staff->fresh($this->staffRelations())),
            'Staff member created successfully.',
            201
        );
    }

    public function update(UpdateStaffRequest $request, StaffMember $staff)
    {
        $this->ensureCompanyAccess($request, $staff);
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $staff) {
            $targetRole = $validated['role'] ?? DashboardFormatting::titleCase(
                $staff->user?->userRoles->first()?->role_name?->value ?? 'staff'
            );

            if (isset($validated['email']) || array_key_exists('phone_number', $validated) || isset($validated['status'])) {
                $staff->user->update([
                    'email' => $validated['email'] ?? $staff->user->email,
                    'phone_number' => array_key_exists('phone_number', $validated) ? $validated['phone_number'] : $staff->user->phone_number,
                    'is_active' => isset($validated['status'])
                        ? $validated['status'] !== 'Inactive'
                        : $staff->user->is_active,
                ]);
            }

            if (isset($validated['role'])) {
                $staff->user->userRoles()->delete();
                $staff->user->userRoles()->create([
                    'role_name' => $this->mapRoleLabelToEnum($validated['role']),
                ]);
            }

            $status = isset($validated['status'])
                ? $this->mapStatusLabelToEnum($validated['status'])
                : $staff->employment_status;

            $staff->update([
                'full_name' => $validated['name'] ?? $staff->full_name,
                'service_id' => $targetRole === 'Staff'
                    ? ($validated['service_id'] ?? $staff->service_id)
                    : null,
                'employment_status' => $status,
                'avatar_url' => array_key_exists('avatar_url', $validated) ? $validated['avatar_url'] : $staff->avatar_url,
                'is_online' => isset($validated['status'])
                    ? $validated['status'] === 'Active'
                    : $staff->is_online,
                'last_active_at' => (isset($validated['status']) && $validated['status'] === 'Active')
                    ? now()
                    : $staff->last_active_at,
            ]);
        });

        $this->invalidateDashboardCache($request, $staff->company_id);

        return $this->respond(
            $this->transformStaff($staff->fresh($this->staffRelations())),
            'Staff member updated successfully.'
        );
    }

    public function updateBranch(UpdateStaffBranchRequest $request, StaffMember $staff)
    {
        $this->ensureCompanyAccess($request, $staff);
        $branch = Branch::query()->findOrFail($request->validated('branch_id'));

        $staff->update([
            'branch_id' => $branch->getKey(),
            'company_id' => $branch->company_id,
            'service_id' => $staff->service()
                ->where(function (Builder $query) use ($branch): void {
                    $query
                        ->where('services.branch_id', $branch->getKey())
                        ->orWhereHas('branches', fn (Builder $branchQuery) => $branchQuery->whereKey($branch->getKey()));
                })
                ->exists()
                ? $staff->service_id
                : null,
            'last_active_at' => now(),
        ]);

        $this->invalidateDashboardCache($request, $branch->company_id);

        return $this->respond(
            $this->transformStaff($staff->fresh($this->staffRelations())),
            'Staff branch updated successfully.'
        );
    }

    public function updateStatus(UpdateStaffStatusRequest $request, StaffMember $staff)
    {
        $this->ensureCompanyAccess($request, $staff);
        $statusLabel = $request->validated('status');
        $status = $this->mapStatusLabelToEnum($statusLabel);

        $staff->update([
            'employment_status' => $status,
            'is_online' => $statusLabel === 'Active',
            'last_active_at' => $statusLabel === 'Active' ? now() : $staff->last_active_at,
        ]);

        $staff->user->update([
            'is_active' => $statusLabel !== 'Inactive',
        ]);

        $this->invalidateDashboardCache($request, $staff->company_id);

        return $this->respond(
            $this->transformStaff($staff->fresh($this->staffRelations())),
            'Staff status updated successfully.'
        );
    }

    public function uploadAvatar(UploadStaffAvatarRequest $request, StaffMember $staff)
    {
        $this->ensureCompanyAccess($request, $staff);
        $validated = $request->validated();

        $avatarUrl = $validated['avatar_url'] ?? $staff->avatar_url;

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('staff-avatars', 'public');
            $avatarUrl = Storage::url($path);
        }

        $staff->update([
            'avatar_url' => $avatarUrl,
            'last_active_at' => now(),
        ]);

        $this->invalidateDashboardCache($request, $staff->company_id);

        return $this->respond(
            $this->transformStaff($staff->fresh($this->staffRelations())),
            'Staff avatar updated successfully.'
        );
    }

    protected function baseQuery(ListStaffRequest $request): Builder
    {
        return $this->scopeQueryByCompanyColumn(
            StaffMember::query()
            ->with($this->staffRelations())
            ->orderBy('full_name'),
            $request
        );
    }

    protected function staffRelations(): array
    {
        return ['user.userRoles', 'branch', 'service', 'company', 'servedQueueEntries.queueSession.service'];
    }

    protected function applyFilters(Builder $query, ListStaffRequest $request): Builder
    {
        $search = trim((string) $request->input('search', ''));
        $branchId = $request->input('branch_id');
        $role = $request->input('role');
        $status = $request->input('status');

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('full_name', 'like', '%'.$search.'%')
                    ->orWhere('display_staff_code', 'like', '%'.$search.'%')
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery
                            ->where('email', 'like', '%'.$search.'%')
                            ->orWhere('phone_number', 'like', '%'.$search.'%');
                    });
            });
        }

        if (is_string($branchId) && $branchId !== '') {
            $query->where('branch_id', $branchId);
        }

        if (is_string($role) && $role !== '') {
            $query->whereHas(
                'user.userRoles',
                fn (Builder $roleQuery) => $roleQuery->where('role_name', $this->mapRoleLabelToEnum($role)->value)
            );
        }

        if (is_string($status) && $status !== '') {
            $query->where('employment_status', $this->mapStatusLabelToEnum($status)->value);
        }

        if ($request->has('online')) {
            $query->where('is_online', $request->boolean('online'));
        }

        return $query;
    }

    protected function transformStaff(StaffMember $staff): array
    {
        $role = $staff->user?->userRoles->first()?->role_name?->value ?? 'staff';
        $servedToday = $staff->servedQueueEntries
            ->filter(fn ($entry) => $entry->updated_at?->isToday() && $entry->queue_status->value === 'completed')
            ->count();

        $avgServiceMinutes = (int) round(
            $staff->servedQueueEntries
                ->filter(fn ($entry) => $entry->queue_status->value === 'completed')
                ->map(fn ($entry) => $entry->queueSession?->service?->average_service_duration_minutes)
                ->filter()
                ->avg() ?? 0
        );

        $performance = min(99, max(55, 60 + ($servedToday * 4) + ($staff->is_online ? 8 : 0)));

        return [
            'id' => $staff->getKey(),
            'name' => $staff->full_name,
            'email' => $staff->user?->email,
            'branch' => $staff->branch?->branch_name ?? 'Unassigned Branch',
            'serviceId' => $staff->service?->getKey(),
            'serviceName' => $staff->service?->service_name,
            'role' => DashboardFormatting::titleCase($role),
            'status' => DashboardFormatting::employmentStatusLabel($staff->employment_status->value),
            'performance' => $performance,
            'servedToday' => $servedToday,
            'avgService' => $avgServiceMinutes > 0 ? $avgServiceMinutes.'m' : 'N/A',
            'accessLevel' => $this->roleAccessLevel($role),
            'joinedAt' => DashboardFormatting::shortDate($staff->created_at),
            'lastActive' => $staff->is_online
                ? 'Online now'
                : DashboardFormatting::compactTimeAgo($staff->last_active_at, 'Offline'),
            'online' => (bool) $staff->is_online,
            'avatarUrl' => $staff->avatar_url,
            'avatarType' => Str::contains(Str::lower((string) $staff->avatar_url), 'female') ? 'Female' : 'Male',
        ];
    }

    protected function roleAccessLevel(string $role): string
    {
        return match ($role) {
            'admin' => 'Branch Administrator',
            default => 'Assigned Service Operator',
        };
    }

    protected function nextStaffCode(): string
    {
        $lastCode = StaffMember::query()
            ->whereNotNull('display_staff_code')
            ->orderByDesc('display_staff_code')
            ->value('display_staff_code');

        $lastNumber = $lastCode ? (int) preg_replace('/\D+/', '', $lastCode) : 0;

        return 'STF-'.str_pad((string) ($lastNumber + 1), 5, '0', STR_PAD_LEFT);
    }

    protected function mapRoleLabelToEnum(string $role): UserRoleName
    {
        return match ($role) {
            'Admin' => UserRoleName::Admin,
            default => UserRoleName::Staff,
        };
    }

    protected function mapStatusLabelToEnum(string $status): EmploymentStatus
    {
        return match ($status) {
            'Inactive' => EmploymentStatus::Inactive,
            'On Leave' => EmploymentStatus::OnLeave,
            default => EmploymentStatus::Active,
        };
    }

    protected function randomPhone(): string
    {
        return '+2135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }
}
