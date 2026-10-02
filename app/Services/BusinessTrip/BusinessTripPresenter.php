<?php

namespace App\Services\BusinessTrip;

use App\Models\BusinessTrip;
use App\Models\BusinessTripApproval;
use App\Models\BusinessTripArrangement;
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

            /*
            | Tanggal berangkatnya sudah lewat. Draft yang begini tidak bisa
            | diajukan lagi -- dikabarkan supaya layar bisa menandainya, bukan
            | menunggu tombolnya ditekan lalu ditolak.
            */
            'is_departure_passed' => (bool) $trip->depart_date
                && $trip->depart_date->startOfDay()->lt(now()->startOfDay()),
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

            /*
            | Pemesanan hotel, tiket, dan transport.
            |
            | Yang dibatalkan ikut terbawa, lengkap dengan alasannya. Justru
            | itu yang perlu dibaca pemohon: hotel yang batal dan sudah
            | diganti lebih penting diketahui daripada yang berjalan mulus.
            */
            'arrangements' => $trip->relationLoaded('arrangements')
                ? $trip->arrangements
                    ->map(fn (BusinessTripArrangement $a): array => self::arrangement($a))
                    ->values()
                    ->all()
                : [],

            'created_at' => $trip->created_at,
            'updated_at' => $trip->updated_at,
        ];
    }

    /**
     * Satu pemesanan beserta berkas buktinya.
     *
     * Jenisnya dikirim sebagai kode, bukan kalimat jadi. Layarnya yang
     * menerjemahkan -- pengguna berbahasa Inggris tidak semestinya membaca
     * "PENGINAPAN" hanya karena kalimatnya sudah dibentuk di server.
     *
     * @return array<string, mixed>
     */
    public static function arrangement(BusinessTripArrangement $a): array
    {
        return [
            'id' => $a->id,
            'type' => $a->type,
            'vendor_name' => $a->vendor_name,
            'reference_no' => $a->reference_no,
            'starts_at' => optional($a->starts_at)->toDateString(),
            'ends_at' => optional($a->ends_at)->toDateString(),
            'notes' => $a->notes,

            'status' => $a->status,
            'cancelled_at' => $a->cancelled_at,
            'cancellation_notes' => $a->cancellation_notes,
            'cancelled_by_name' => $a->relationLoaded('canceller')
                ? optional($a->canceller)->name
                : null,

            /*
            | Pemesanan lama yang digantikan baris ini. Cukup id-nya: layarnya
            | mencari pasangannya di daftar yang sama, jadi keterangannya tidak
            | perlu disalin dan tidak bisa berbeda dari aslinya.
            */
            'replaces_id' => $a->replaces_id,

            'created_by_name' => $a->relationLoaded('creator')
                ? optional($a->creator)->name
                : null,
            'created_at' => $a->created_at,

            'files' => $a->relationLoaded('files')
                ? $a->files
                    ->map(fn ($f): array => [
                        'id' => $f->id,
                        'original_filename' => $f->original_filename,
                        'mime_type' => $f->mime_type,
                        'file_size' => $f->file_size,
                        'url' => $f->url,
                    ])
                    ->values()
                    ->all()
                : [],
        ];
    }
}
