<?php

namespace App\Http\Controllers\Api\Dashboard\WalkIns;

use App\Enums\CheckInResult;
use App\Enums\TicketStatus;
use App\Enums\TokenStatus;
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
use Illuminate\Support\Str;

class WalkInController extends DashboardApiController
{
    public function __construct(
        protected OperationalWorkflowService $workflow,
    ) {}

    public function bootstrap()
    {
        $branches = Branch::query()->orderBy('branch_name')->get();
        $services = Service::query()->with('branches')->orderBy('service_name')->get();
        $staffMembers = StaffMember::query()->with('branch')->orderBy('full_name')->get();
        $tickets = WalkInTicket::query()
            ->with($this->ticketRelations())
            ->latest()
            ->get();
        $sessions = DailyQueueSession::query()->with('queueEntries')->latest('session_date')->get();
        $qrTokens = QrCodeToken::query()->latest()->get();
        $checkIns = CheckInRecord::query()->latest()->get();
        $kiosks = KioskDevice::query()->orderBy('device_identifier')->get();
        $customers = Customer::query()->latest()->get();

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
        $created = $this->workflow->registerWalkIn($request->validated());

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
        $validated = $request->validated();

        $result = $validated['result'] ?? CheckInResult::Success->value;
        $qrToken = $ticket->qrCodeTokens()->latest()->first();

        if (! $qrToken) {
            $qrToken = $ticket->qrCodeTokens()->create([
                'token_value' => Str::upper(Str::random(24)),
                'expiration_date_time' => now()->addHours(8),
                'token_status' => TokenStatus::Active,
            ]);
        }

        $record = CheckInRecord::query()->create([
            'qr_token_id' => $qrToken->getKey(),
            'kiosk_id' => $validated['kiosk_id'] ?? null,
            'customer_id' => $ticket->customer_id,
            'check_in_date_time' => now(),
            'check_in_result' => $result,
        ]);

        $ticket->queueEntries()->update([
            'checked_in_at' => now(),
        ]);

        if ($result === CheckInResult::Success->value) {
            $ticket->update([
                'ticket_status' => $ticket->ticket_status === TicketStatus::Serving ? TicketStatus::Serving : TicketStatus::CheckedIn,
            ]);

            $qrToken->update([
                'used_date_time' => now(),
                'token_status' => TokenStatus::Consumed,
            ]);
        }

        return $this->respond([
            'ticket' => $this->transformTicket($ticket->fresh($this->ticketRelations())),
            'checkIn' => [
                'checkInId' => $record->getKey(),
                'checkInResult' => match ($result) {
                    'manual_assist' => 'Manual Assist',
                    default => DashboardFormatting::titleCase($result),
                },
            ],
        ], 'Walk-in checked in successfully.');
    }

    public function escalate(EscalateWalkInRequest $request, WalkInTicket $ticket)
    {
        $ticket->update([
            'ticket_status' => TicketStatus::Escalated,
            'notes' => $request->validated('notes') ?? 'Escalated from the dashboard walk-ins API.',
        ]);

        return $this->respond(
            $this->transformTicket($ticket->fresh($this->ticketRelations())),
            'Walk-in ticket escalated successfully.'
        );
    }

    public function complete(WalkInTicket $ticket)
    {
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
                'checked_in' => 'Checked In',
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
