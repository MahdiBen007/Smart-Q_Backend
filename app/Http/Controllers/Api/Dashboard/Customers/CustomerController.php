<?php

namespace App\Http\Controllers\Api\Dashboard\Customers;

use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\Customers\ListCustomersRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Support\Dashboard\DashboardFormatting;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class CustomerController extends DashboardApiController
{
    public function bootstrap(ListCustomersRequest $request)
    {
        $branches = $this->scopeQueryByCompanyColumn(
            Branch::query()
                ->select(['id', 'branch_name'])
                ->orderBy('branch_name'),
            $request
        )->get();

        $baseQuery = $this->baseQuery($request);

        return $this->respond([
            'customers' => $this->applyFilters($baseQuery, $request)
                ->get()
                ->map(fn (Customer $customer) => $this->transformCustomer($customer))
                ->values()
                ->all(),
            'branches' => $branches
                ->map(fn (Branch $branch) => [
                    'id' => $branch->getKey(),
                    'name' => $branch->branch_name,
                ])
                ->values()
                ->all(),
            'summary' => [
                'totalCustomers' => (clone $baseQuery)->count(),
                'linkedAccounts' => (clone $baseQuery)->whereNotNull('user_id')->count(),
                'guestProfiles' => (clone $baseQuery)->whereNull('user_id')->count(),
                'activeThisMonth' => $this->countActiveThisMonth(clone $baseQuery),
            ],
        ]);
    }

    protected function baseQuery(ListCustomersRequest $request): Builder
    {
        $query = Customer::query()
            ->withCount(['appointments', 'walkInTickets', 'queueEntries'])
            ->with([
                'user:id,email,phone_number,is_active',
                'latestAppointment' => fn ($relation) => $relation->select([
                    'appointments.id',
                    'appointments.customer_id',
                    'appointments.branch_id',
                    'appointments.service_id',
                    'appointments.created_at',
                ]),
                'latestAppointment.branch:id,branch_name',
                'latestAppointment.service:id,service_name',
                'latestWalkInTicket' => fn ($relation) => $relation->select([
                    'walk_in_tickets.id',
                    'walk_in_tickets.customer_id',
                    'walk_in_tickets.branch_id',
                    'walk_in_tickets.service_id',
                    'walk_in_tickets.created_at',
                ]),
                'latestWalkInTicket.branch:id,branch_name',
                'latestWalkInTicket.service:id,service_name',
                'latestQueueEntry' => fn ($relation) => $relation->select([
                    'queue_entries.id',
                    'queue_entries.customer_id',
                    'queue_entries.queue_session_id',
                    'queue_entries.updated_at',
                ]),
                'latestQueueEntry.queueSession:id,branch_id,service_id',
                'latestQueueEntry.queueSession.branch:id,branch_name',
                'latestQueueEntry.queueSession.service:id,service_name',
            ])
            ->orderBy('full_name');

        $companyId = $this->currentCompanyId($request);

        if ($companyId === null) {
            return $query;
        }

        return $query->where(function (Builder $companyQuery) use ($companyId): void {
            $companyQuery
                ->whereHas('appointments.branch', fn (Builder $branchQuery) => $branchQuery->where('company_id', $companyId))
                ->orWhereHas('walkInTickets.branch', fn (Builder $branchQuery) => $branchQuery->where('company_id', $companyId))
                ->orWhereHas('queueEntries.queueSession.branch', fn (Builder $branchQuery) => $branchQuery->where('company_id', $companyId));
        });
    }

    protected function applyFilters(Builder $query, ListCustomersRequest $request): Builder
    {
        $search = trim((string) $request->input('search', ''));
        $branchId = $request->input('branch_id');

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery
                    ->where('full_name', 'like', '%'.$search.'%')
                    ->orWhere('email_address', 'like', '%'.$search.'%')
                    ->orWhere('phone_number', 'like', '%'.$search.'%')
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery
                            ->where('email', 'like', '%'.$search.'%')
                            ->orWhere('phone_number', 'like', '%'.$search.'%');
                    });
            });
        }

        if (is_string($branchId) && $branchId !== '') {
            $query->where(function (Builder $branchQuery) use ($branchId): void {
                $branchQuery
                    ->whereHas('appointments', fn (Builder $appointmentQuery) => $appointmentQuery->where('branch_id', $branchId))
                    ->orWhereHas('walkInTickets', fn (Builder $ticketQuery) => $ticketQuery->where('branch_id', $branchId))
                    ->orWhereHas('queueEntries.queueSession', fn (Builder $sessionQuery) => $sessionQuery->where('branch_id', $branchId));
            });
        }

        return $query;
    }

    protected function countActiveThisMonth(Builder $query): int
    {
        $monthStart = now()->startOfMonth();

        return $query
            ->where(function (Builder $activityQuery) use ($monthStart): void {
                $activityQuery
                    ->whereHas('appointments', fn (Builder $appointmentQuery) => $appointmentQuery->where('created_at', '>=', $monthStart))
                    ->orWhereHas('walkInTickets', fn (Builder $ticketQuery) => $ticketQuery->where('created_at', '>=', $monthStart))
                    ->orWhereHas('queueEntries', fn (Builder $entryQuery) => $entryQuery->where('updated_at', '>=', $monthStart));
            })
            ->count();
    }

    protected function transformCustomer(Customer $customer): array
    {
        $latestInteractions = collect([
            [
                'channel' => 'Appointments',
                'timestamp' => $customer->latestAppointment?->created_at,
                'branch' => $customer->latestAppointment?->branch?->branch_name,
                'service' => $customer->latestAppointment?->service?->service_name,
            ],
            [
                'channel' => 'Walk-ins',
                'timestamp' => $customer->latestWalkInTicket?->created_at,
                'branch' => $customer->latestWalkInTicket?->branch?->branch_name,
                'service' => $customer->latestWalkInTicket?->service?->service_name,
            ],
            [
                'channel' => 'Queue',
                'timestamp' => $customer->latestQueueEntry?->updated_at,
                'branch' => $customer->latestQueueEntry?->queueSession?->branch?->branch_name,
                'service' => $customer->latestQueueEntry?->queueSession?->service?->service_name,
            ],
        ])
            ->filter(fn (array $interaction) => $interaction['timestamp'] instanceof CarbonInterface)
            ->sortByDesc(fn (array $interaction) => $interaction['timestamp']?->timestamp ?? 0)
            ->values();

        $latestInteraction = $latestInteractions->first();
        $appointmentsCount = (int) ($customer->appointments_count ?? 0);
        $walkInsCount = (int) ($customer->walk_in_tickets_count ?? 0);
        $queueEntriesCount = (int) ($customer->queue_entries_count ?? 0);
        $channel = match (true) {
            $appointmentsCount > 0 && $walkInsCount > 0 => 'Hybrid',
            $appointmentsCount > 0 => 'Appointments',
            $walkInsCount > 0 => 'Walk-ins',
            $queueEntriesCount > 0 => 'Queue',
            default => 'Profile',
        };

        return [
            'id' => $customer->getKey(),
            'fullName' => $customer->full_name,
            'initials' => DashboardFormatting::initials($customer->full_name),
            'emailAddress' => $customer->email_address ?: ($customer->user?->email ?? null),
            'phoneNumber' => $customer->phone_number ?: ($customer->user?->phone_number ?? null),
            'hasPortalAccount' => $customer->user_id !== null,
            'isAccountActive' => (bool) ($customer->user?->is_active ?? false),
            'channel' => $channel,
            'latestChannel' => $latestInteraction['channel'] ?? 'Profile',
            'latestBranch' => $latestInteraction['branch'] ?? null,
            'latestService' => $latestInteraction['service'] ?? null,
            'appointmentsCount' => $appointmentsCount,
            'walkInsCount' => $walkInsCount,
            'queueEntriesCount' => $queueEntriesCount,
            'totalVisits' => $appointmentsCount + $walkInsCount,
            'lastSeen' => DashboardFormatting::compactTimeAgo($latestInteraction['timestamp'] ?? null, 'No recent activity'),
            'lastSeenAt' => $latestInteraction['timestamp']?->toIso8601String(),
            'createdAt' => $customer->created_at?->toIso8601String(),
        ];
    }
}
