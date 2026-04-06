<?php

namespace App\Http\Requests\Api\Dashboard;

abstract class DashboardIndexRequest extends DashboardFormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('paginate')) {
            $normalized['paginate'] = $this->normalizeBooleanQueryValue($this->input('paginate'));
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    protected function paginationRules(): array
    {
        return [
            'paginate' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function paginationAttributes(): array
    {
        return [
            'paginate' => 'pagination flag',
            'page' => 'page number',
            'per_page' => 'items per page',
        ];
    }

    public function shouldPaginate(): bool
    {
        return $this->boolean('paginate');
    }

    public function perPage(int $default = 15): int
    {
        return max(1, min($this->integer('per_page', $default), 100));
    }

    protected function normalizeBooleanQueryValue(mixed $value): mixed
    {
        if (is_bool($value) || $value === null) {
            return $value;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $normalized ?? $value;
    }
}
