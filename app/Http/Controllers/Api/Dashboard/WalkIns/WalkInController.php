<?php

namespace App\Http\Controllers\Api\Dashboard\WalkIns;

use App\Enums\TicketStatus;
use App\Http\Controllers\Api\Dashboard\DashboardApiController;
use App\Http\Requests\Api\Dashboard\WalkIns\CheckInWalkInRequest;
use App\Http\Requests\Api\Dashboard\WalkIns\EscalateWalkInRequest;
use App\Http\Requests\Api\Dashboard\WalkIns\StoreWalkInRequest;
use App\Models\Branch;
use App\Models\CheckInRecord;
use App\Models\Customer;
use App\Models\DailyQueueSession;
use App\Models\KioskDevice;
use App\Models\QrCodeToken;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\WalkInTicket;
use App\Support\Dashboard\DashboardFormatting;
use App\Support\Dashboard\OperationalWorkflowService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WalkInController extends DashboardApiController
{
    public function __construct(
        protected OperationalWorkflowService $workflow,
    ) {}

    public function bootstrap(Request $request)
    {
        $today = now()->toDateString();
        $branchesQuery = $this->scopeQueryByCompanyColumn(Branch::query()->orderBy('branch_name'), $request);
        $branchesQuery = $this->scopeQueryByAssignedBranchColumn($branchesQuery, $request, 'id');
        $branches = $branchesQuery->get();

        $servicesQuery = $this->scopeQueryByCompanyRelation(
            Service::query()->with('branches')->orderBy('service_name'),
            $request,
            'branches'
        );
        $servicesQuery = $this->scopeQueryByAssignedBranchRelation($servicesQuery, $request, 'branches');
        $servicesQuery = $this->scopeQueryByAssignedServiceColumn($servicesQuery, $request, 'id');
        $services = $servicesQuery->get();

        $staffMembersQuery = $this->scopeQueryByCompanyColumn(
            StaffMember::query()->with('branch')->orderBy('full_name'),
            $request
        );
        $staffMembersQuery = $this->scopeQueryByAssignedBranchColumn($staffMembersQuery, $request);
        $staffMembersQuery = $this->scopeQueryByAssignedServiceColumn($staffMembersQuery, $request);
        $staffMembers = $staffMembersQuery->get();

        $ticketsQuery = $this->scopeQueryByCompanyRelation(
            WalkInTicket::query()
                ->with($this->ticketRelations())
                ->whereDate('created_at', $today)
                ->latest(),
            $request,
            'branch'
        );
        $ticketsQuery = $this->scopeQueryByAssignedBranchColumn($ticketsQuery, $request);
        $ticketsQuery = $this->scopeQueryByAssignedServiceColumn($ticketsQuery, $request);
        $tickets = $ticketsQuery->get();
        $ticketIds = $tickets->pluck('id');
        $sessionsQuery = $this->scopeQueryByCompanyRelation(
            DailyQueueSession::query()
                ->with('queueEntries')
                ->whereDate('session_date', $today)
                ->latest('session_date'),
            $request,
            'branch'
        );
        $sessionsQuery = $this->scopeQueryByAssignedBranchRelation($sessionsQuery, $request, 'branch');
        $sessionsQuery = $this->scopeQueryByAssignedServiceColumn($sessionsQuery, $request);
        $sessions = $sessionsQuery->get();
        $qrTokens = QrCodeToken::query()
            ->whereIn('ticket_id', $ticketIds)
            ->latest()
            ->get();
        $checkIns = CheckInRecord::query()
            ->whereHas('qrToken', fn (Builder $query) => $query->whereIn('ticket_id', $ticketIds))
            ->latest()
            ->get();
        $kiosksQuery = $this->scopeQueryByCompanyRelation(
            KioskDevice::query()->orderBy('device_identifier'),
            $request,
            'branch'
        );
        $kiosksQuery = $this->scopeQueryByAssignedBranchRelation($kiosksQuery, $request, 'branch');
        $kiosks = $kiosksQuery->get();
        $customers = $tickets
            ->map(fn (WalkInTicket $ticket) => $ticket->customer)
            ->filter()
            ->unique(fn (Customer $customer) => $customer->getKey())
            ->values();

        return $this->respond([
            'branches' => $branches->map(fn (Branch $branch) => [
                'branchId' => $branch->getKey(),
                'companyId' => $branch->company_id,
                'branchName' => $branch->branch_name,
                'branchAddress' => $branch->branch_address,
            ])->values()->all(),
            'services' => $services
                ->flatMap(function (Service $service) {
                    $branchIds = $service->branches->pluck('id');
                    if ($branchIds->isEmpty() && $service->branch_id) {
                        $branchIds = collect([$service->branch_id]);
                    }

                    return $branchIds->map(fn (string $branchId) => [
                        'serviceId' => $service->getKey(),
                        'branchId' => $branchId,
                        'serviceName' => $service->service_name,
                        'averageServiceDurationMinutes' => $service->average_service_duration_minutes,
                        'isActive' => (bool) $service->is_active,
                    ]);
                })
                ->values()
                ->all(),
            'staffMembers' => $staffMembers->map(fn (StaffMember $staff) => [
                'staffId' => $staff->getKey(),
                'branchId' => $staff->branch_id,
                'fullName' => $staff->full_name,
                'employmentStatus' => DashboardFormatting::employmentStatusLabel($staff->employment_status->value),
            ])->values()->all(),
            'customers' => $customers->map(fn ($customer) => [
                'customerId' => $customer->getKey(),
                'userId' => $customer->user_id,
                'fullName' => $customer->full_name,
                'phoneNumber' => $customer->phone_number,
                'emailAddress' => $customer->email_address,
                'createdAt' => $customer->created_at?->toIso8601String(),
            ])->values()->all(),
            'sessions' => $sessions->map(fn ($session) => [
                'queueSessionId' => $session->getKey(),
                'branchId' => $session->branch_id,
                'serviceId' => $session->service_id,
                'sessionDate' => optional($session->session_date)?->toDateString(),
                'sessionStartTime' => (string) $session->session_start_time,
                'sessionEndTime' => (string) $session->session_end_time,
                'sessionStatus' => DashboardFormatting::queueSessionStatusLabel($session->session_status->value),
                'currentQueuePosition' => (int) ($session->queueEntries->max('queue_position') ?? 0),
                'liveTickets' => $session->queueEntries->filter(
                    fn ($entry) => ! in_array($entry->queue_status->value, ['completed', 'cancelled'], true)
                )->count(),
            ])->values()->all(),
            'tickets' => $tickets->map(fn (WalkInTicket $ticket) => $this->transformTicket($ticket))->values()->all(),
            'qrTokens' => $qrTokens->map(fn (QrCodeToken $token) => [
                'qrTokenId' => $token->getKey(),
                'tokenValue' => $token->token_value,
                'expiryDateTime' => $token->expiration_date_time?->toIso8601String(),
                'usedDateTime' => $token->used_date_time?->toIso8601String(),
                'tokenStatus' => DashboardFormatting::titleCase($token->token_status->value),
                'appointmentId' => $token->appointment_id,
                'ticketId' => $token->ticket_id,
            ])->values()->all(),
            'checkIns' => $checkIns->map(fn (CheckInRecord $record) => [
                'checkInId' => $record->getKey(),
                'qrTokenId' => $record->qr_token_id,
                'kioskId' => $record->kiosk_id,
                'customerId' => $record->customer_id,
                'checkInDateTime' => $record->check_in_date_time?->toIso8601String(),
                'checkInResult' => match ($record->check_in_result->value) {
                    'manual_assist' => 'Manual Assist',
                    default => DashboardFormatting::titleCase($record->check_in_result->value),
                },
            ])->values()->all(),
            'kiosks' => $kiosks->map(fn (KioskDevice $kiosk) => [
                'kioskId' => $kiosk->getKey(),
                'branchId' => $kiosk->branch_id,
                'deviceIdentifier' => $kiosk->device_identifier,
                'deviceLocationDescription' => $kiosk->device_location_description,
                'deviceStatus' => DashboardFormatting::titleCase($kiosk->device_status->value),
            ])->values()->all(),
            'defaults' => [
                'selectedTicketId' => $tickets->first()?->getKey() ?? '',
                'selectedBranchId' => $branches->first()?->getKey() ?? 'all-branches',
                'selectedServiceId' => $services->first()?->getKey() ?? 'all-services',
                'selectedStatus' => 'all-statuses',
                'selectedSource' => 'all-sources',
            ],
        ]);
    }

    public function store(StoreWalkInRequest $request)
    {
        $branch = Branch::query()->findOrFail($request->validated('branch_id'));
        $service = Service::query()->findOrFail($request->validated('service_id'));
        $this->ensureCompanyAccess($request, $branch);
        $this->ensureCompanyAccess($request, $service);

        try {
            $created = $this->workflow->registerWalkIn($request->validated());
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $firstError = null;
            foreach ($errors as $messages) {
                if (is_array($messages) && $messages !== []) {
                    $firstError = (string) ($messages[0] ?? null);
                    break;
                }
            }

            return $this->respondValidationError(
                $firstError ?: 'Selected walk-in slot is not available.',
                $errors,
                $this->walkInConflictStatus($firstError),
            );
        }

        $this->invalidateDashboardCache($request, $this->currentCompanyId($request));

        return $this->respond(
            [
                'ticket' => $this->transformTicket($created['ticket']->loadMissing($this->ticketRelations())),
                'customer' => [
                    'customerId' => $created['customer']->getKey(),
                    'fullName' => $created['customer']->full_name,
                ],
                'sessionId' => $created['session']->getKey(),
            ],
            'Walk-in registered successfully.',
            201
        );
    }

    public function checkIn(CheckInWalkInRequest $request, WalkInTicket $ticket)
    {
        $this->ensureCompanyAccess($request, $ticket);

        return $this->respond(
            null,
            'Walk-in tickets do not require check-in. They are treated as on-site by default.',
            422
        );
    }

    public function escalate(EscalateWalkInRequest $request, WalkInTicket $ticket)
    {
        $this->ensureCompanyAccess($request, $ticket);
        $ticket->update([
            'ticket_status' => TicketStatus::Escalated,
            'notes' => $request->validated('notes') ?? 'Escalated from the dashboard walk-ins API.',
        ]);
        $this->invalidateDashboardCache($request, $ticket->branch?->company_id);

        return $this->respond(
            $this->transformTicket($ticket->fresh($this->ticketRelations())),
            'Walk-in ticket escalated successfully.'
        );
    }

    public function complete(Request $request, WalkInTicket $ticket)
    {
        $this->ensureCompanyAccess($request, $ticket);
        $queueEntry = $ticket->queueEntries()
            ->whereNotIn('queue_status', ['completed', 'cancelled'])
            ->latest()
            ->first();

        if ($queueEntry) {
            $this->workflow->completeEntry($queueEntry);
        } else {
            $ticket->update([
                'ticket_status' => TicketStatus::Completed,
            ]);
        }
        $this->invalidateDashboardCache($request, $ticket->branch?->company_id);

        return $this->respond(
            $this->transformTicket($ticket->fresh($this->ticketRelations())),
            'Walk-in ticket completed successfully.'
        );
    }

    protected function ticketRelations(): array
    {
        return [
            'customer',
            'service.branches',
            'branch',
            'queueSession.queueEntries',
            'appointment',
            'queueEntries',
            'qrCodeTokens',
            'queueEntries.servedByStaff',
        ];
    }

    protected function walkInConflictStatus(?string $message): int
    {
        $normalized = strtolower((string) $message);

        return str_contains($normalized, 'capacity')
            || str_contains($normalized, 'full')
                ? 409
                : 422;
    }

    protected function transformTicket(WalkInTicket $ticket): array
    {
        $queueEntry = $ticket->queueEntries
            ->sortByDesc('created_at')
            ->first();
        $token = $ticket->qrCodeTokens->sortByDesc('created_at')->first();
        $estimatedWait = $queueEntry
            ? max(($queueEntry->queue_position - 1) * ($ticket->service?->average_service_duration_minutes ?? 10), 0)
            : 0;

        return [
            'ticketId' => $ticket->getKey(),
            'customerId' => $ticket->customer_id,
            'branchId' => $ticket->branch_id,
            'serviceId' => $ticket->service_id,
            'ticketNumber' => $ticket->ticket_number,
            'ticketSource' => DashboardFormatting::ticketSourceLabel($ticket->ticket_source->value),
            'ticketStatus' => match ($ticket->ticket_status->value) {
                'checked_in' => 'Queued',
                default => DashboardFormatting::titleCase($ticket->ticket_status->value),
            },
            'createdAt' => $ticket->created_at?->toIso8601String(),
            'queueSessionId' => $ticket->queue_session_id,
            'appointmentId' => $ticket->appointment_id,
            'qrTokenId' => $token?->getKey(),
            'queuePosition' => $queueEntry?->queue_position ?? 0,
            'estimatedWaitMinutes' => $estimatedWait,
            'servedByStaffId' => $queueEntry?->served_by_staff_id,
            'notes' => $ticket->notes,
        ];
    }
}
