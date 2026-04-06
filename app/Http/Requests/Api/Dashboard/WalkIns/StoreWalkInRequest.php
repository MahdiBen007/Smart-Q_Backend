<?php

namespace App\Http\Requests\Api\Dashboard\WalkIns;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;
use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class StoreWalkInRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', $this->branchExistsRule()],
            'service_id' => ['required', $this->serviceExistsRule()],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email_address' => ['nullable', 'email'],
            'ticket_source' => ['nullable', Rule::in(DashboardCatalog::TICKET_SOURCES)],
        ];
    }

    public function attributes(): array
    {
        return [
            'branch_id' => 'branch',
            'service_id' => 'service',
            'customer_id' => 'customer',
            'customer_name' => 'customer name',
            'phone_number' => 'phone number',
            'email_address' => 'email address',
            'ticket_source' => 'ticket source',
        ];
    }
}
