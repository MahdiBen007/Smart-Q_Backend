<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\DailyQueueSession;
use App\Models\QueueEntry;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\User;
use App\Models\WalkInTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Throwable;

abstract class DashboardApiController extends Controller
{
    protected function respond(
        mixed $data = null,
        ?string $message = null,
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $payload = [];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    protected function respondValidationError(
        string $message,
        array $errors = [],
        int $status = 422,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    protected function rememberPayload(string $key, callable $callback, int $seconds = 15): mixed
    {
        if (app()->environment('testing')) {
            return $callback();
        }

        try {
            return Cache::remember($key, now()->addSeconds($seconds), $callback);
        } catch (Throwable) {
            return $callback();
        }
    }

    protected function rememberScopedPayload(
        Request $request,
        string $key,
        callable $callback,
        int $seconds = 15,
    ): mixed {
        if (app()->environment('testing')) {
            return $callback();
        }

        $companyId = $this->currentCompanyId($request);
        try {
            $version = (int) Cache::get($this->dashboardCacheVersionKey($companyId), 1);
        } catch (Throwable) {
            $version = 1;
        }
        $scopedKey = sprintf(
            'dashboard:%s:%s:v%d',
            $this->companyCacheScope($companyId),
            $key,
            max($version, 1)
        );

        try {
            return Cache::remember($scopedKey, now()->addSeconds($seconds), $callback);
        } catch (Throwable) {
            return $callback();
        }
    }

    protected function invalidateDashboardCache(?Request $request = null, ?string $companyId = null): void
    {
        $resolvedCompanyId = $companyId ?? ($request ? $this->currentCompanyId($request) : null);
        $versionKey = $this->dashboardCacheVersionKey($resolvedCompanyId);
        try {
            $currentVersion = (int) Cache::get($versionKey, 1);
            Cache::forever($versionKey, max($currentVersion, 1) + 1);
        } catch (Throwable) {
            // Cache backend is optional for dashboard operation.
        }
    }

    protected function currentCompanyId(?Request $request = null): ?string
    {
        return $request?->user()?->staffMember?->company_id;
    }

    protected function currentBranchId(?Request $request = null): ?string
    {
        return $request?->user()?->staffMember?->branch_id;
    }

    protected function currentServiceId(?Request $request = null): ?string
    {
        return $this->currentServiceIds($request)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    protected function currentServiceIds(?Request $request = null): array
    {
        $staffMember = $request?->user()?->staffMember;

        if (! $staffMember) {
            return [];
        }

        $counter = $staffMember->relationLoaded('counter')
            ? $staffMember->counter
            : $staffMember->counter()->with('services:id')->first();
        $serviceIds = $counter?->services?->pluck('id')
            ->filter()
            ->values()
            ->all() ?? [];

        if ($serviceIds === [] && $staffMember->service_id !== null) {
            // Legacy fallback while existing deployments migrate to counter_id.
            $serviceIds = [$staffMember->service_id];
        }

        return $serviceIds;
    }

    protected function currentRoleNames(?Request $request = null): array
    {
        /** @var User|null $user */
        $user = $request?->user();

        if (! $user) {
            return [];
        }

        $roles = $user->relationLoaded('userRoles')
            ? $user->userRoles
            : $user->userRoles()->get(['role_name']);

        return $roles
            ->map(fn ($userRole) => $userRole->role_name?->value ?? (string) $userRole->role_name)
            ->filter()
            ->values()
            ->all();
    }

    protected function hasDashboardRole(?Request $request, string $role): bool
    {
        return in_array($role, $this->currentRoleNames($request), true);
    }

    protected function isDashboardAdmin(?Request $request = null): bool
    {
        return $this->hasDashboardRole($request, 'admin');
    }

    protected function shouldRestrictToAssignedBranch(?Request $request = null): bool
    {
        return ! $this->isDashboardAdmin($request) && $this->currentBranchId($request) !== null;
    }

    protected function shouldRestrictToAssignedService(?Request $request = null): bool
    {
        return ! $this->isDashboardAdmin($request) && $this->currentServiceIds($request) !== [];
    }

    protected function scopeQueryByCompanyColumn(
        Builder $query,
        Request $request,
        string $column = 'company_id',
    ): Builder {
        $companyId = $this->currentCompanyId($request);

        if ($companyId === null) {
            return $query;
        }

        return $query->where($column, $companyId);
    }

    protected function scopeQueryByCompanyRelation(
        Builder $query,
        Request $request,
        string $relation,
        string $column = 'company_id',
    ): Builder {
        $companyId = $this->currentCompanyId($request);

        if ($companyId === null) {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $relationQuery) => $relationQuery->where($column, $companyId));
    }

    protected function scopeQueryByAssignedBranchColumn(
        Builder $query,
        Request $request,
        string $column = 'branch_id',
    ): Builder {
        $branchId = $this->currentBranchId($request);

        if (! $this->shouldRestrictToAssignedBranch($request) || $branchId === null) {
            return $query;
        }

        return $query->where($column, $branchId);
    }

    protected function scopeQueryByAssignedBranchRelation(
        Builder $query,
        Request $request,
        string $relation,
        string $column = 'id',
    ): Builder {
        $branchId = $this->currentBranchId($request);

        if (! $this->shouldRestrictToAssignedBranch($request) || $branchId === null) {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $relationQuery) => $relationQuery->where($column, $branchId));
    }

    protected function scopeQueryByAssignedServiceColumn(
        Builder $query,
        Request $request,
        string $column = 'service_id',
    ): Builder {
        $serviceIds = $this->currentServiceIds($request);

        if (! $this->shouldRestrictToAssignedService($request) || $serviceIds === []) {
            return $query;
        }

        return $query->whereIn($column, $serviceIds);
    }

    protected function scopeQueryByAssignedServiceRelation(
        Builder $query,
        Request $request,
        string $relation,
        string $column = 'id',
    ): Builder {
        $serviceIds = $this->currentServiceIds($request);

        if (! $this->shouldRestrictToAssignedService($request) || $serviceIds === []) {
            return $query;
        }

        return $query->whereHas($relation, fn (Builder $relationQuery) => $relationQuery->whereIn($column, $serviceIds));
    }

    protected function ensureCompanyAccess(Request $request, Model $model): void
    {
        $companyId = $this->currentCompanyId($request);

        if ($companyId === null) {
            return;
        }

        $hasAccess = match (true) {
            $model instanceof Branch => $model->company_id === $companyId,
            $model instanceof StaffMember => $model->company_id === $companyId,
            $model instanceof Service => $model->branch?->company_id === $companyId
                || $model->branches()->where('company_id', $companyId)->exists(),
            $model instanceof Appointment => $model->branch?->company_id === $companyId,
            $model instanceof WalkInTicket => $model->branch?->company_id === $companyId,
            $model instanceof DailyQueueSession => $model->branch?->company_id === $companyId,
            $model instanceof QueueEntry => $model->queueSession?->branch?->company_id === $companyId,
            default => true,
        };

        abort_unless($hasAccess, 404);

        $branchId = $this->currentBranchId($request);

        if (! $this->shouldRestrictToAssignedBranch($request) || $branchId === null) {
            return;
        }

        $hasBranchAccess = match (true) {
            $model instanceof Branch => $model->getKey() === $branchId,
            $model instanceof StaffMember => $model->branch_id === $branchId,
            $model instanceof Service => $model->branches()->whereKey($branchId)->exists()
                || $model->branch_id === $branchId,
            $model instanceof Appointment => $model->branch_id === $branchId,
            $model instanceof WalkInTicket => $model->branch_id === $branchId,
            $model instanceof DailyQueueSession => $model->branch_id === $branchId,
            $model instanceof QueueEntry => $model->queueSession?->branch_id === $branchId,
            default => true,
        };

        abort_unless($hasBranchAccess, 404);

        $serviceIds = $this->currentServiceIds($request);

        if (! $this->shouldRestrictToAssignedService($request) || $serviceIds === []) {
            return;
        }

        $hasServiceAccess = match (true) {
            $model instanceof Service => in_array($model->getKey(), $serviceIds, true),
            $model instanceof StaffMember => $model->counter_id === null
                || $model->counter?->services()->whereIn('services.id', $serviceIds)->exists()
                || ($model->service_id !== null && in_array($model->service_id, $serviceIds, true)),
            $model instanceof Appointment => in_array($model->service_id, $serviceIds, true),
            $model instanceof WalkInTicket => in_array($model->service_id, $serviceIds, true),
            $model instanceof DailyQueueSession => in_array($model->service_id, $serviceIds, true),
            $model instanceof QueueEntry => in_array((string) $model->queueSession?->service_id, $serviceIds, true),
            default => true,
        };

        abort_unless($hasServiceAccess, 404);
    }

    protected function respondIndexCollection(
        Request $request,
        Builder $query,
        callable $transformer,
        int $defaultPerPage = 15,
    ): JsonResponse {
        if (! $request->boolean('paginate')) {
            return $this->respond(
                $query->get()->map($transformer)->values()->all()
            );
        }

        $paginator = $query->paginate(
            perPage: max(1, min($request->integer('per_page', $defaultPerPage), 100))
        )->appends($request->query());

        return $this->respond(
            collect($paginator->items())->map($transformer)->values()->all(),
            meta: [
                'pagination' => $this->paginationMeta($paginator),
            ],
        );
    }

    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'has_more_pages' => $paginator->hasMorePages(),
        ];
    }

    protected function dashboardCacheVersionKey(?string $companyId): string
    {
        return 'dashboard:cache-version:'.$this->companyCacheScope($companyId);
    }

    protected function companyCacheScope(?string $companyId): string
    {
        return $companyId ?: 'global';
    }
}
