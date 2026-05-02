<?php

namespace App\Http\Requests\Api\Dashboard;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpFoundation\Response;

abstract class DashboardFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        return [
            'required' => 'The :attribute field is required.',
            'required_with' => 'The :attribute field is required when :values is present.',
            'array' => 'The :attribute must be a valid array.',
            'boolean' => 'The :attribute field must be true or false.',
            'between.numeric' => 'The :attribute must be between :min and :max.',
            'between.integer' => 'The :attribute must be between :min and :max.',
            'confirmed' => 'The :attribute confirmation does not match.',
            'email' => 'The :attribute must be a valid email address.',
            'exists' => 'The selected :attribute is invalid.',
            'file' => 'The :attribute must be a valid file.',
            'image' => 'The :attribute must be an image.',
            'in' => 'The selected :attribute is invalid.',
            'integer' => 'The :attribute must be an integer.',
            'max.array' => 'The :attribute may not contain more than :max items.',
            'max.file' => 'The :attribute may not be greater than :max kilobytes.',
            'max.numeric' => 'The :attribute may not be greater than :max.',
            'max.string' => 'The :attribute may not be greater than :max characters.',
            'min.array' => 'The :attribute must contain at least :min items.',
            'min.file' => 'The :attribute must be at least :min kilobytes.',
            'min.numeric' => 'The :attribute must be at least :min.',
            'min.string' => 'The :attribute must be at least :min characters.',
            'numeric' => 'The :attribute must be a valid number.',
            'string' => 'The :attribute must be a valid string.',
            'unique' => 'The :attribute has already been taken.',
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'This action is unauthorized.',
        ], Response::HTTP_FORBIDDEN));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first() ?: 'The given data was invalid.',
            'errors' => $validator->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    protected function currentCompanyId(): ?string
    {
        return $this->user()?->staffMember?->company_id;
    }

    protected function currentBranchId(): ?string
    {
        return $this->user()?->staffMember?->branch_id;
    }

    protected function currentServiceId(): ?string
    {
        return $this->currentServiceIds()[0] ?? null;
    }

    /**
     * @return list<string>
     */
    protected function currentServiceIds(): array
    {
        $staffMember = $this->user()?->staffMember;

        if (! $staffMember) {
            return [];
        }

        $counter = $staffMember->relationLoaded('counter')
            ? $staffMember->counter
            : $staffMember->counter()->with('services:id')->first();
        $serviceIds = $counter?->services?->pluck('id')
            ->filter()
            ->values()
            ->all() ?? [];

        if ($serviceIds === [] && $staffMember->service_id !== null) {
            // Legacy fallback while existing deployments migrate to counter_id.
            $serviceIds = [$staffMember->service_id];
        }

        return $serviceIds;
    }

    protected function currentRoleNames(): array
    {
        $user = $this->user();

        if (! $user) {
            return [];
        }

        $roles = $user->relationLoaded('userRoles')
            ? $user->userRoles
            : $user->userRoles()->get(['role_name']);

        return $roles
            ->map(fn ($userRole) => $userRole->role_name?->value ?? (string) $userRole->role_name)
            ->filter()
            ->values()
            ->all();
    }

    protected function hasDashboardRole(string $role): bool
    {
        return in_array(strtolower($role), $this->currentRoleNames(), true);
    }

    protected function isDashboardAdmin(): bool
    {
        return $this->hasDashboardRole('admin');
    }

    protected function shouldRestrictToAssignedBranch(): bool
    {
        return ! $this->isDashboardAdmin() && $this->currentBranchId() !== null;
    }

    protected function shouldRestrictToAssignedService(): bool
    {
        return ! $this->isDashboardAdmin() && $this->currentServiceIds() !== [];
    }

    protected function branchExistsRule(): Exists
    {
        $rule = Rule::exists('branches', 'id');
        $companyId = $this->currentCompanyId();
        $branchId = $this->currentBranchId();

        if ($companyId !== null) {
            $rule = $rule->where(fn ($query) => $query->where('company_id', $companyId));
        }

        if ($this->shouldRestrictToAssignedBranch() && $branchId !== null) {
            $rule = $rule->where(fn ($query) => $query->where('id', $branchId));
        }

        return $rule;
    }

    protected function serviceExistsRule(): Exists
    {
        $rule = Rule::exists('services', 'id');
        $companyId = $this->currentCompanyId();
        $branchId = $this->currentBranchId();

        if ($companyId !== null) {
            $companyBranchIds = DB::table('branches')->select('id')->where('company_id', $companyId);

            $rule = $rule->where(function ($query) use ($companyBranchIds): void {
                $query
                    ->whereIn('branch_id', $companyBranchIds)
                    ->orWhereIn('id', function ($pivotQuery) use ($companyBranchIds): void {
                        $pivotQuery
                            ->select('service_id')
                            ->from('branch_service')
                            ->whereIn('branch_id', $companyBranchIds);
                    });
            });
        }

        if ($this->shouldRestrictToAssignedBranch() && $branchId !== null) {
            $rule = $rule->where(function ($query) use ($branchId): void {
                $query
                    ->where('branch_id', $branchId)
                    ->orWhereIn('id', function ($pivotQuery) use ($branchId): void {
                        $pivotQuery
                            ->select('service_id')
                            ->from('branch_service')
                            ->where('branch_id', $branchId);
                    });
            });
        }

        return $rule;
    }

    protected function counterExistsRule(): Exists
    {
        $rule = Rule::exists('counters', 'id');
        $companyId = $this->currentCompanyId();
        $branchId = $this->currentBranchId();

        if ($companyId !== null) {
            $rule = $rule->where(fn ($query) => $query->whereIn(
                'branch_id',
                DB::table('branches')->select('id')->where('company_id', $companyId)
            ));
        }

        if ($this->shouldRestrictToAssignedBranch() && $branchId !== null) {
            $rule = $rule->where(fn ($query) => $query->where('branch_id', $branchId));
        }

        return $rule;
    }

    protected function staffExistsRule(): Exists
    {
        $rule = Rule::exists('staff_members', 'id');
        $companyId = $this->currentCompanyId();
        $branchId = $this->currentBranchId();

        if ($companyId !== null) {
            $rule = $rule->where(fn ($query) => $query->where('company_id', $companyId));
        }

        if ($this->shouldRestrictToAssignedBranch() && $branchId !== null) {
            $rule = $rule->where(fn ($query) => $query->where('branch_id', $branchId));
        }

        return $rule;
    }

    protected function queueSessionExistsRule(): Exists
    {
        $rule = Rule::exists('daily_queue_sessions', 'id');
        $companyId = $this->currentCompanyId();
        $branchId = $this->currentBranchId();

        if ($companyId !== null) {
            $rule = $rule->where(fn ($query) => $query->whereIn(
                'branch_id',
                DB::table('branches')->select('id')->where('company_id', $companyId)
            ));
        }

        if ($this->shouldRestrictToAssignedBranch() && $branchId !== null) {
            $rule = $rule->where(fn ($query) => $query->where('branch_id', $branchId));
        }

        return $rule;
    }

    protected function kioskExistsRule(): Exists
    {
        $rule = Rule::exists('kiosk_devices', 'id');
        $companyId = $this->currentCompanyId();
        $branchId = $this->currentBranchId();

        if ($companyId !== null) {
            $rule = $rule->where(fn ($query) => $query->whereIn(
                'branch_id',
                DB::table('branches')->select('id')->where('company_id', $companyId)
            ));
        }

        if ($this->shouldRestrictToAssignedBranch() && $branchId !== null) {
            $rule = $rule->where(fn ($query) => $query->where('branch_id', $branchId));
        }

        return $rule;
    }
}
