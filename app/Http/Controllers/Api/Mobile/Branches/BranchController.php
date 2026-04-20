<?php

namespace App\Http\Controllers\Api\Mobile\Branches;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BranchController extends MobileApiController
{
    public function index(Request $request)
    {
        $companyId = trim($request->string('company_id')->value());
        $companyName = trim($request->string('company_name')->value());
        $normalizedCompanyName = mb_strtolower($companyName);
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = (int) $request->integer('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        // Backward-compatible support: some clients may still send company id
        // through the company_name parameter.
        if ($companyId === '' && $companyName !== '' && Str::isUuid($companyName)) {
            $companyId = $companyName;
            $companyName = '';
            $normalizedCompanyName = '';
        }

        $query = Branch::query()
            ->with(['company:id,company_name,company_status'])
            ->select([
                'id',
                'company_id',
                'branch_name',
                'branch_address',
                'pin_top',
                'pin_left',
                'latitude',
                'longitude',
            ]);

        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        if ($companyName !== '') {
            $query->whereHas('company', function ($builder) use ($normalizedCompanyName) {
                $builder->whereRaw('LOWER(TRIM(company_name)) = ?', [$normalizedCompanyName]);
            });
        }

        $paginator = $query
            ->orderBy('branch_name')
            ->paginate($perPage, ['*'], 'page', $page);

        $branches = $paginator->getCollection()
            ->map(function (Branch $branch) {
                return [
                    'id' => $branch->getKey(),
                    'company_id' => $branch->company_id,
                    'company_name' => $branch->company?->company_name ?? '',
                    'company_status' => $branch->company?->company_status?->value
                        ?? (string) ($branch->company?->company_status ?? ''),
                    'branch_name' => $branch->branch_name,
                    'branch_address' => $branch->branch_address,
                    'address_line_1' => $branch->branch_address,
                    'address_line_2' => '',
                    'wait_label' => '',
                    'icon' => 'branch',
                    'pin_top' => $branch->pin_top,
                    'pin_left' => $branch->pin_left,
                    'latitude' => $branch->latitude,
                    'longitude' => $branch->longitude,
                ];
            })
            ->values()
            ->all();

        return $this->respond(
            $branches,
            meta: $this->paginationMeta($paginator->currentPage(), $paginator->perPage(), $paginator->total()),
        );
    }
}
