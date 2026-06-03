<?php

namespace App\Services\MasterVendor;

use App\Models\MasterVendorApproval;
use App\Models\User;
use Illuminate\Support\Collection;

class MasterVendorApprovalService
{
    public function resolveApprovers(MasterVendorApproval $approval): Collection
    {
        if ($approval->approver_type === 'USER') {
            return User::where('id', $approval->approver_id)
                ->where('is_active', true)
                ->get();
        }

        if ($approval->approver_type === 'ROLE') {
            return User::whereHas('roles', function ($q) use ($approval) {
                $q->where('roles.id', $approval->approver_id);
            })
                ->where('is_active', true)
                ->get();
        }

        return collect();
    }
}
