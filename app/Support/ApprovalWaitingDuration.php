<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class ApprovalWaitingDuration
{
    /**
     * Menghitung berapa lama sebuah approval sudah menggantung
     * sejak $since, dan mengembalikan representasi angka + label.
     */
    public static function describe(
        Carbon $since,
        ?Carbon $now = null,
    ): array {
        $now ??= Carbon::now();

        $waitingMinutes = max(
            $since->diffInMinutes($now),
            0,
        );

        $waitingHours = (int) floor($waitingMinutes / 60);
        $waitingDays = (int) floor($waitingHours / 24);

        return [
            'waiting_days' => $waitingDays,
            'waiting_hours' => $waitingHours,
            'waiting_label' => self::label(
                $waitingMinutes,
                $waitingHours,
                $waitingDays,
            ),
        ];
    }

    private static function label(
        int $waitingMinutes,
        int $waitingHours,
        int $waitingDays,
    ): string {
        if ($waitingDays > 0)
            return "{$waitingDays} hari";

        if ($waitingHours > 0)
            return "{$waitingHours} jam";

        return max($waitingMinutes, 1) . ' menit';
    }
}
