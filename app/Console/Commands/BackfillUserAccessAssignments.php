<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserAccessAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillUserAccessAssignments extends Command
{
    protected $signature = 'user-access:backfill';

    protected $description = 'Backfill default user access assignments from users.cabang_id and users.departemen_id';

    public function handle(): int
    {
        $this->info('Starting user access assignment backfill...');

        $created = 0;
        $skipped = 0;

        User::query()
            ->select([
                'id',
                'cabang_id',
                'departemen_id',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$created, &$skipped) {
                foreach ($users as $user) {
                    $branchId = (int) ($user->cabang_id ?? 0);
                    $departmentId = (int) ($user->departemen_id ?? 0);

                    if ($branchId <= 0 || $departmentId <= 0) {
                        $skipped++;

                        continue;
                    }

                    DB::transaction(function () use ($user, $branchId, $departmentId, &$created, &$skipped) {
                        $exists = UserAccessAssignment::query()
                            ->where('user_id', $user->id)
                            ->where('branch_id', $branchId)
                            ->where('department_id', $departmentId)
                            ->exists();

                        if ($exists) {
                            $skipped++;

                            return;
                        }

                        $hasPrimary = UserAccessAssignment::query()
                            ->where('user_id', $user->id)
                            ->where('is_primary', true)
                            ->exists();

                        UserAccessAssignment::query()->create([
                            'user_id' => $user->id,
                            'branch_id' => $branchId,
                            'department_id' => $departmentId,
                            'is_primary' => !$hasPrimary,
                            'is_active' => true,
                            'created_by' => null,
                            'updated_by' => null,
                        ]);

                        $created++;
                    });
                }
            });

        $this->info("Backfill complete.");
        $this->info("Created: {$created}");
        $this->info("Skipped: {$skipped}");

        return self::SUCCESS;
    }
}
