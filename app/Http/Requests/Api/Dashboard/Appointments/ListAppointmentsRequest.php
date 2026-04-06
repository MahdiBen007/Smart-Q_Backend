<?php

namespace App\Http\Requests\Api\Dashboard\Appointments;

use App\Http\Requests\Api\Dashboard\DashboardIndexRequest;
use Illuminate\Validation\Rule;

class ListAppointmentsRequest extends DashboardIndexRequest
{
    public function rules(): array
    {
        return [
            ...$this->paginationRules(),
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'branch_id' => ['sometimes', 'nullable', $this->branchExistsRule()],
            'service_id' => ['sometimes', 'nullable', $this->serviceExistsRule()],
            'status' => ['sometimes', Rule::in(['Confirmed', 'Active', 'Pending', 'No-Show', 'Cancelled'])],
            'queue_state' => ['sometimes', Rule::in(['Checked In', 'Awaiting Check-In', 'Not Queued', 'Expired'])],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ];
    }

    public function attributes(): array
    {
        return [
            ...$this->paginationAttributes(),
            'search' => 'search term',
            'branch_id' => 'branch',
            'service_id' => 'service',
            'status' => 'appointment status',
            'queue_state' => 'queue state',
            'date_from' => 'start date',
            'date_to' => 'end date',
        ];
    }
}
