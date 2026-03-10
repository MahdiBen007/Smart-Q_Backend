<?php

namespace App\Http\Requests\Api\Dashboard\QueueMonitor;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;

class StoreQueueEntryRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'exists:branches,id'],
            'service_id' => ['required', 'exists:services,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:50'],
            'email_address' => ['nullable', 'email'],
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
        ];
    }
}
