<?php

namespace App\Http\Controllers\Api\Mobile\Branches;

use App\Http\Controllers\Api\Mobile\MobileApiController;
use App\Models\Branch;
use Illuminate\Http\Request;

class BranchController extends MobileApiController
{
    public function index(Request $request)
    {
        $companyId = trim($request->string('company_id')->value());
        $companyName = trim($request->string('company_name')->value());

        $query = Branch::query()->with('company');

        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        if ($companyName !== '') {
            $query->whereHas('company', function ($builder) use ($companyName) {
                $builder->whereRaw('LOWER(company_name) = ?', [mb_strtolower($companyName)]);
            });
        }

        $branches = $query
            ->orderBy('branch_name')
            ->get()
            ->map(function (Branch $branch) {
                return [
                    'id' => $branch->getKey(),
                    'company_id' => $branch->company_id,
                    'company_name' => $branch->company?->company_name ?? '',
                    'branch_name' => $branch->branch_name,
                    'branch_address' => $branch->branch_address,
                    'address_line_1' => $branch->branch_address,
                    'address_line_2' => '',
                    'wait_label' => '15 min',
                    'icon' => 'branch',
                    'pin_top' => $branch->pin_top,
                    'pin_left' => $branch->pin_left,
                    'latitude' => $branch->latitude,
                    'longitude' => $branch->longitude,
                ];
            })
            ->values()
            ->all();

        return $this->respond($branches);
    }
}
