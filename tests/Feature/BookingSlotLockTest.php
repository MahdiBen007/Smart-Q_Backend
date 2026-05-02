<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Service;
use App\Support\Operations\OperationsScheduleTimeSlotService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BookingSlotLockTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_booking_slot_lock_is_reused_for_the_same_slot(): void
    {
        [$branch, $service] = $this->createBranchServicePair();
        $timeSlots = app(OperationsScheduleTimeSlotService::class);
        $date = Carbon::parse('2026-04-27');

        $firstLock = DB::transaction(fn () => $timeSlots->acquireBookingSlotLock(
            branchId: $branch->getKey(),
            serviceId: $service->getKey(),
            date: $date,
            time: '08:00',
            bookingChannel: 'remote',
        ));

        $secondLock = DB::transaction(fn () => $timeSlots->acquireBookingSlotLock(
            branchId: $branch->getKey(),
            serviceId: $service->getKey(),
            date: $date,
            time: '08:00',
            bookingChannel: 'remote',
        ));

        $this->assertSame($firstLock->getKey(), $secondLock->getKey());
        $this->assertDatabaseCount('booking_slot_locks', 1);
    }

    public function test_remote_slot_capacity_is_enforced_before_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-27 07:00:00'));

        [$branch, $service] = $this->createBranchServicePair();
        $date = Carbon::parse('2026-04-27');

        for ($index = 1; $index <= 10; $index++) {
            $customer = Customer::query()->create([
                'full_name' => "Customer {$index}",
                'phone_number' => "+213500000{$index}",
                'email_address' => "customer{$index}@smartqdz.test",
            ]);

            Appointment::query()->create([
                'customer_id' => $customer->getKey(),
                'branch_id' => $branch->getKey(),
                'service_id' => $service->getKey(),
                'appointment_date' => $date->toDateString(),
                'appointment_time' => '08:00',
                'appointment_end_time' => '09:00',
                'appointment_time_label' => '08:00 - 09:00',
                'appointment_session_id' => 'morning',
                'appointment_channel' => 'remote',
                'appointment_status' => 'pending',
            ]);
        }

        $this->expectException(ValidationException::class);

        app(OperationsScheduleTimeSlotService::class)->ensureSlotIsBookable(
            branchId: $branch->getKey(),
            serviceId: $service->getKey(),
            date: $date,
            time: '08:00',
            bookingChannel: 'remote',
        );
    }

    protected function createBranchServicePair(): array
    {
        $company = Company::query()->create([
            'company_name' => 'SmartQ Test Company',
            'company_status' => 'active',
        ]);

        $branch = Branch::query()->create([
            'company_id' => $company->getKey(),
            'branch_name' => 'Main Branch',
            'branch_address' => 'Algiers',
        ]);

        $service = Service::query()->create([
            'branch_id' => $branch->getKey(),
            'service_name' => 'Carte Chifa',
            'average_service_duration_minutes' => 15,
            'is_active' => true,
        ]);

        DB::table('branch_service')->updateOrInsert([
            'branch_id' => $branch->getKey(),
            'service_id' => $service->getKey(),
        ], [
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$branch, $service];
    }
}
