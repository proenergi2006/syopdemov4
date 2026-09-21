import { useI18n } from 'vue-i18n'

/**
 * Nama hari dalam penomoran ISO (1 = Senin ... 7 = Minggu).
 *
 * Servernya mengirim angka, layar yang merangkai namanya. Kalimat jadi dari
 * server hanya benar selama bahasanya tidak berganti: begitu pengguna menukar
 * bahasa, kalimat itu masih berbunyi dalam bahasa lama sampai datanya diambil
 * ulang. Angka tidak punya bahasa, jadi tidak pernah basi.
 *
 * Pemanggilnya perlu membungkus hasilnya dalam computed supaya ikut berubah
 * saat bahasanya berganti.
 */
export const useDayNames = () => {
  const { t } = useI18n()

  /** Nama satu hari; angka di luar 1-7 dikembalikan apa adanya. */
  const dayName = (iso: number): string => {
    if (!Number.isInteger(iso) || iso < 1 || iso > 7)
      return String(iso)

    return t(`common.days.${iso}`)
  }

  /**
   * Beberapa hari dirangkai jadi satu kalimat: "Senin, Rabu dan Jumat".
   *
   * Bentuknya sengaja sama dengan rangkaian di server, supaya pesan penolakan
   * dan keterangan di layar menyebut hal yang sama dengan kata yang sama.
   */
  const dayList = (days: number[] | null | undefined): string => {
    const nama = (days ?? []).map(dayName)

    if (nama.length === 0)
      return '-'

    if (nama.length === 1)
      return nama[0]

    const terakhir = nama[nama.length - 1]

    return `${nama.slice(0, -1).join(', ')} ${t('common.and')} ${terakhir}`
  }

  return { dayName, dayList }
}
