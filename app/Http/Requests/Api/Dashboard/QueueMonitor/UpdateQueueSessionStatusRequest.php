<?php

namespace App\Http\Requests\Api\Dashboard\QueueMonitor;

use App\Support\Dashboard\DashboardCatalog;
use Illuminate\Validation\Rule;

class UpdateQueueSessionStatusRequest extends ResolveQueueSessionRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['required', Rule::in(DashboardCatalog::QUEUE_MONITOR_STATUSES)],
        ];
    }

    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'status' => 'operation status',
        ];
    }
}
