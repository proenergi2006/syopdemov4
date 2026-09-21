<?php

namespace App\Services\BusinessTrip;

use App\Models\BusinessTrip;
use App\Models\BusinessTripApproval;
use App\Models\BusinessTripItinerary;
use Illuminate\Support\Facades\Crypt;

/**
 * Bentuk baris perdin untuk layar.
 *
 * Dipakai dua tempat: daftar perdin, dan detail FPU yang menumpang perdin
 * itu. Modal rinciannya satu komponen yang sama, jadi datanya pun harus
 * satu bentuk yang sama -- dua penyusun yang kebetulan mirip cepat atau
 * lambat akan berbeda.
 */
class BusinessTripPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(BusinessTrip $trip, bool $canApprove = false): array
    {
        $jam = fn (?string $nilai): ?string => $nilai ? substr($nilai, 0, 5) : null;

        return [
            'public_id' => Crypt::encryptString((string) $trip->id),
            'trip_number' => $trip->trip_number,
            'date' => optional($trip->date)->toDateString(),

            'status' => $trip->status,
            'is_editable' => $trip->is_editable,

            /* Menjelaskan kenapa FPU-nya sudah boleh atau masih terkunci. */
            'is_on_time' => $trip->is_on_time,
            'allows_parallel_cash_advance' => $trip->allows_parallel_cash_advance,
            'can_approve' => $canApprove,
            'submitted_at' => $trip->submitted_at,
            'rejection_notes' => $trip->rejection_notes,
            'cancellation_notes' => $trip->cancellation_notes,

            'employee_name' => $trip->employee_name,
            'department_id' => $trip->department_id,
            'department_name' => $trip->department_name,
            'position_name' => $trip->position_name,
            'branch' => $trip->branch,

            'destination' => $trip->destination,

            'depart_date' => optional($trip->depart_date)->toDateString(),
            'depart_time' => $jam($trip->depart_time),
            'return_date' => optional($trip->return_date)->toDateString(),
            'return_time' => $jam($trip->return_time),
            'duration_days' => $trip->duration_days,

            'purpose' => $trip->purpose,
            'notes' => $trip->notes,

            'itineraries' => $trip->itineraries
                ->map(fn (BusinessTripItinerary $b): array => [
                    'sort_no' => $b->sort_no,
                    'date' => optional($b->date)->toDateString(),
                    'time_start' => $jam($b->time_start),
                    'time_end' => $jam($b->time_end),
                    'timezone' => $b->timezone,
                    'time_text' => $b->time_text,
                    'description' => $b->description,
                    'pic' => $b->pic,
                ])
                ->values()
                ->all(),

            'approvals' => $trip->relationLoaded('approvals')
                ? $trip->approvals
                    ->map(fn (BusinessTripApproval $a): array => [
                        'step_order' => $a->step_order,
                        'label' => $a->label,
                        'approver_name' => $a->approver_name_snapshot,
                        'approver_type' => $a->approver_type,
                        'status' => $a->status,
                        'approved_at' => $a->approved_at,
                        'rejected_at' => $a->rejected_at,
                        'notes' => $a->notes,
                    ])
                    ->values()
                    ->all()
                : [],

            'created_at' => $trip->created_at,
            'updated_at' => $trip->updated_at,
        ];
    }
}
