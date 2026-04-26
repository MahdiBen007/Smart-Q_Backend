<?php

namespace App\Http\Controllers\Api\Dashboard\Counters;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Counters\ListCountersRequest;
use App\Http\Requests\Api\Dashboard\Counters\StoreCounterRequest;
use App\Http\Requests\Api\Dashboard\Counters\UpdateCounterRequest;
use App\Http\Requests\Api\Dashboard\Counters\UpdateCounterStatusRequest;
use App\Models\Counter;
use Illuminate\Database\Eloquent\Builder;

class CounterController extends DashboardApiController
{
    public function bootstrap(ListCountersRequest $request)
    {
        $counters = $this->applyFilters($this->baseQuery($request), $request)->get();

        $branchesQuery = $this->scopeQueryByCompanyColumn(
            \App\Models\Branch::query()
                ->select(['id', 'branch_name'])
                ->orderBy('branch_name'),
            $request
        );
        $branchesQuery = $this->scopeQueryByAssignedBranchColumn($branchesQuery, $request, 'id');
        $branches = $branchesQuery->get();
        $branchOptions = $branches->pluck('branch_name')->values()->all();

        if (! $this->shouldRestrictToAssignedBranch($request)) {
            $branchOptions = ['All Branches', ...$branchOptions];
        }

        return $this->respond([
            'branchOptions' => $branchOptions,
            'counters' => $counters->map(fn (Counter $counter) => $this->transformCounter($counter))->values()->all(),
        ]);
    }

    public function index(ListCountersRequest $request)
    {
        return $this->respondIndexCollection(
            $request,
            $this->applyFilters($this->baseQuery($request), $request),
            fn (Counter $counter) => $this->transformCounter($counter)
        );
    }

    public function show(ListCountersRequest $request, Counter $counter)
    {
        $this->ensureCompanyAccess($request, $counter->branch);
        $counter->loadMissing(['branch:id,branch_name', 'services:id']);

        return $this->respond($this->transformCounter($counter));
    }

    public function store(StoreCounterRequest $request)
    {
        $validated = $request->validated();

        if (
            $this->shouldRestrictToAssignedBranch($request)
            && $this->currentBranchId($request) !== null
            && $validated['branch_id'] !== $this->currentBranchId($request)
        ) {
            abort(403);
        }

        $counter = Counter::query()->create([
            'branch_id' => $validated['branch_id'],
            'counter_code' => $validated['counter_code'],
            'counter_name' => $validated['name'],
            'is_active' => $validated['status'] === 'Active',
            'display_order' => (int) ($validated['display_order'] ?? 0),
        ]);

        $this->invalidateDashboardCache($request, $request->user()?->staffMember?->company_id);

        return $this->respond(
            $this->transformCounter($counter->fresh(['branch:id,branch_name', 'services:id'])),
            'Guichet created successfully.',
            201
        );
    }

    public function update(UpdateCounterRequest $request, Counter $counter)
    {
        $this->ensureCompanyAccess($request, $counter->branch);
        $validated = $request->validated();

        if (
            $this->shouldRestrictToAssignedBranch($request)
            && $this->currentBranchId($request) !== null
            && array_key_exists('branch_id', $validated)
            && $validated['branch_id'] !== $this->currentBranchId($request)
        ) {
            abort(403);
        }

        $counter->update([
            'branch_id' => $validated['branch_id'] ?? $counter->branch_id,
            'counter_code' => $validated['counter_code'] ?? $counter->counter_code,
            'counter_name' => $validated['name'] ?? $counter->counter_name,
            'is_active' => array_key_exists('status', $validated) ? $validated['status'] === 'Active' : $counter->is_active,
            'display_order' => array_key_exists('display_order', $validated) ? (int) $validated['display_order'] : $counter->display_order,
        ]);

        $this->invalidateDashboardCache($request, $counter->branch?->company_id);

        return $this->respond(
            $this->transformCounter($counter->fresh(['branch:id,branch_name', 'services:id'])),
            'Guichet updated successfully.'
        );
    }

    public function updateStatus(UpdateCounterStatusRequest $request, Counter $counter)
    {
        $this->ensureCompanyAccess($request, $counter->branch);
        $counter->update([
            'is_active' => $request->validated('status') === 'Active',
        ]);

        $this->invalidateDashboardCache($request, $counter->branch?->company_id);

        return $this->respond(
            $this->transformCounter($counter->fresh(['branch:id,branch_name', 'services:id'])),
            'Guichet status updated successfully.'
        );
    }

    protected function baseQuery(ListCountersRequest $request): Builder
    {
        $query = $this->scopeQueryByCompanyRelation(
            Counter::query()
                ->with(['branch:id,branch_name', 'services:id'])
                ->orderBy('display_order')
                ->orderBy('counter_name'),
            $request,
            'branch'
        );

        return $this->scopeQueryByAssignedBranchColumn($query, $request);
    }

    protected function applyFilters(Builder $query, ListCountersRequest $request): Builder
    {
        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status');
        $branchId = $request->input('branch_id');

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('counter_name', 'like', '%'.$search.'%')
                    ->orWhere('counter_code', 'like', '%'.$search.'%');
            });
        }

        if (is_string($status) && $status !== '') {
            $query->where('is_active', $status === 'Active');
        }

        if (is_string($branchId) && $branchId !== '') {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    protected function transformCounter(Counter $counter): array
    {
        return [
            'id' => $counter->getKey(),
            'code' => $counter->counter_code,
            'name' => $counter->counter_name,
            'status' => $counter->is_active ? 'Active' : 'Inactive',
            'branchId' => $counter->branch_id,
            'branchName' => $counter->branch?->branch_name,
            'displayOrder' => (int) $counter->display_order,
            'serviceIds' => $counter->services?->pluck('id')->values()->all() ?? [],
        ];
    }
}
