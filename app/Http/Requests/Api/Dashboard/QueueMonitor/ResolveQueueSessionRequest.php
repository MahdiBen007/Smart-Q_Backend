<?php

namespace App\Http\Requests\Api\Dashboard\QueueMonitor;

use App\Http\Requests\Api\Dashboard\DashboardFormRequest;
use Illuminate\Validation\Validator;

class ResolveQueueSessionRequest extends DashboardFormRequest
{
    public function rules(): array
    {
        return [
            'queue_session_id' => ['nullable', $this->queueSessionExistsRule()],
            'branch_id' => ['nullable', $this->branchExistsRule()],
            'service_id' => ['nullable', $this->serviceExistsRule()],
        ];
    }

    public function attributes(): array
    {
        return [
            'queue_session_id' => 'queue session',
            'branch_id' => 'branch',
            'service_id' => 'service',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasSessionId = filled($this->input('queue_session_id'));
            $hasBranchAndService = filled($this->input('branch_id')) && filled($this->input('service_id'));

            if (! $hasSessionId && ! $hasBranchAndService) {
                $validator->errors()->add(
                    'queue_session_id',
                    'Provide queue_session_id or both branch_id and service_id.'
                );
            }
        });
    }
}
