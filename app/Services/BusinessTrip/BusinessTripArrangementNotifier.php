<?php

namespace App\Services\BusinessTrip;

use App\Mail\BusinessTripArrangementMail;
use App\Models\BusinessTrip;
use App\Models\BusinessTripArrangement;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Mengabari pihak yang berangkat
|--------------------------------------------------------------------------
| SATU PEMESANAN, SATU KABAR.
|
| Bukan satu ringkasan di akhir. Tiket sering menyusul sesudah hotelnya
| dipesan -- kadang berhari-hari kemudian, kadang oleh orang yang berbeda.
| Kabar yang ditahan sampai semuanya lengkap tidak pernah tahu kapan
| "lengkap" itu tiba, dan yang berangkat menunggu tanpa tahu apa yang sudah
| diurus. Maka tiap pemesanan berangkat sendiri, begitu pula tiap pembatalan.
|
| Penerimanya dua: yang berangkat, dan yang mengajukan. Sering orang yang
| sama, tapi tidak selalu -- sekretaris yang membuatkan perdin untuk
| direkturnya perlu tahu tiketnya sudah keluar, dan direkturnya perlu tahu
| nama hotelnya. Mengirim ke salah satu saja berarti salah satunya menelepon
| GA untuk menanyakan hal yang sudah dikirim ke orang lain.
|--------------------------------------------------------------------------
*/
class BusinessTripArrangementNotifier
{
    private const MODULE = 'business_trip';

    private const URL = '/business_trip/perdin';

    /**
     * Sebuah pemesanan baru dicatat.
     *
     * @param  BusinessTripArrangement|null  $replaces  Pemesanan batal yang digantikannya.
     */
    public function created(
        BusinessTrip $trip,
        BusinessTripArrangement $arrangement,
        ?BusinessTripArrangement $replaces = null,
    ): void {
        $kunci = $replaces ? 'replaced' : 'created';

        $this->kirim($trip, $arrangement, 'created', $kunci, $replaces);
    }

    /**
     * Sebuah pemesanan dibatalkan.
     *
     * Kabar ini mencabut kabar sebelumnya, jadi ia tidak boleh dilewatkan
     * walau penggantinya sudah disiapkan: yang berangkat mungkin sudah
     * menyimpan nomor booking lama di ponselnya.
     */
    public function cancelled(
        BusinessTrip $trip,
        BusinessTripArrangement $arrangement,
    ): void {
        $this->kirim($trip, $arrangement, 'cancelled', 'cancelled');
    }

    /*
    |--------------------------------------------------------------------------
    | Pendukung
    |--------------------------------------------------------------------------
    */

    private function kirim(
        BusinessTrip $trip,
        BusinessTripArrangement $arrangement,
        string $mode,
        string $kunci,
        ?BusinessTripArrangement $replaces = null,
    ): void {
        $penerima = $this->penerima($trip);

        if ($penerima->isEmpty()) {
            return;
        }

        $titleKey = "notification_messages.business_trip.arrangement.{$kunci}.title";
        $messageKey = "notification_messages.business_trip.arrangement.{$kunci}.message";

        $messageParams = [
            'trip_number' => $trip->trip_number ?: '-',
            'type' => __('notification_messages.business_trip.arrangement.type_'
                . strtolower($arrangement->type)),
            'vendor' => $arrangement->vendor_name
                ?: __('notification_messages.business_trip.arrangement.vendor_unset'),
        ];

        foreach ($penerima as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => 'business_trip_arrangement_' . $mode,
                'title' => __($titleKey),
                'title_key' => $titleKey,
                'message' => __($messageKey, $messageParams),
                'message_key' => $messageKey,
                'message_params' => $messageParams,
                'module' => self::MODULE,
                'reference_type' => BusinessTrip::class,
                'reference_id' => $trip->id,
                'reference_public_id' => $trip->encrypted_id,
                'url' => self::URL,
            ]);

            if (blank($user->email)) {
                continue;
            }

            $this->antrikan($trip, $arrangement, $user, $mode, $replaces);
        }
    }

    /**
     * Yang berangkat dan yang mengajukan, tanpa kembar.
     *
     * @return Collection<int, User>
     */
    private function penerima(BusinessTrip $trip): Collection
    {
        $ids = collect([
            $trip->user_id ?? null,
            $trip->submitted_by ?? null,
            $trip->created_by ?? null,
        ])
            ->filter(fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $ids)->get();
    }

    private function antrikan(
        BusinessTrip $trip,
        BusinessTripArrangement $arrangement,
        User $user,
        string $mode,
        ?BusinessTripArrangement $replaces,
    ): void {
        try {
            Mail::to($user->email)->queue(
                new BusinessTripArrangementMail(
                    trip: $trip,
                    arrangement: $arrangement,
                    recipient: $user,
                    mode: $mode,
                    replaces: $replaces,
                ),
            );
        } catch (\Throwable $e) {
            /*
            | Kegagalan email tidak menggagalkan pemesanannya. Vouchernya sudah
            | tersimpan dan sudah terlihat di layar perdin; yang hilang hanya
            | pemberitahuannya, dan itu tercatat di sini supaya bisa disusul.
            */
            Log::error('[Perdin Mail] Gagal queue email pemesanan', [
                'business_trip_id' => $trip->id,
                'arrangement_id' => $arrangement->id,
                'mode' => $mode,
                'user_id' => $user->id,
                'to' => $user->email,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
