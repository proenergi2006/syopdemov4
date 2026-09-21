/*
|--------------------------------------------------------------------------
| Kalender tanggal di dalam dialog
|--------------------------------------------------------------------------
| flatpickr menempelkan kalendernya ke <body>, lalu memposisikannya dengan
| koordinat dokumen: top = pageYOffset + posisi field.
|
| Di dalam dialog, Vuetify mengunci gulir halaman dengan membuat <html>
| position:fixed dan menggesernya sejauh halaman sudah tergulir. Titik nol
| dokumen ikut bergeser, sementara pageYOffset menjadi nol -- selisihnya persis
| sebesar gulir halaman di belakangnya. Kalendernya mendarat sejauh itu di atas
| tempatnya seharusnya, dan pada halaman yang tergulir cukup jauh ia terdorong
| keluar dari layar.
|
| Karena itu posisinya diambil alih di sini: kalendernya dipasang dengan
| koordinat LAYAR (position: fixed), bukan koordinat dokumen. Koordinat layar
| tidak ikut bergeser oleh kunci gulir, dan karena wadahnya tetap <body>, tidak
| ada kotak berpenggal yang bisa memotongnya.
|--------------------------------------------------------------------------
*/

interface DialogDateOptions {
  minDate?: string
  maxDate?: string
}

/**
 * Bagian flatpickr yang benar-benar dipakai di sini.
 *
 * Dinyatakan sendiri, bukan diambil dari tipe flatpickr: yang dibutuhkan hanya
 * tiga hal, dan menyebutkannya membuat jelas apa saja yang disentuh.
 */
interface FlatpickrInstance {
  input: HTMLElement
  _positionElement?: HTMLElement
  calendarContainer?: HTMLElement
}

/** Jarak kalender dari tepi field, dan dari tepi layar. */
const GAP = 4
const MARGIN = 8

/**
 * Memasang kalender tepat di bawah field-nya, dengan koordinat layar.
 *
 * Dinaikkan ke atas field hanya kalau di bawah benar-benar tidak muat DAN di
 * atas muat -- kalau keduanya sempit, di bawah tetap dipilih supaya arah
 * munculnya bisa ditebak.
 */
const positionBelowField = (instance: FlatpickrInstance): void => {
  const field = instance._positionElement ?? instance.input
  const calendar = instance.calendarContainer

  if (!field || !calendar)
    return

  const rect = field.getBoundingClientRect()

  const calendarHeight = calendar.offsetHeight || 300
  const calendarWidth = calendar.offsetWidth || 307

  const roomBelow = window.innerHeight - rect.bottom
  const showAbove = roomBelow < calendarHeight && rect.top > calendarHeight

  /* Tidak boleh keluar tepi kanan layar pada kolom yang berada jauh di kanan. */
  const left = Math.max(
    MARGIN,
    Math.min(rect.left, window.innerWidth - calendarWidth - MARGIN),
  )

  calendar.style.position = 'fixed'
  calendar.style.left = `${left}px`
  calendar.style.right = 'auto'

  calendar.style.top = showAbove
    ? `${rect.top - calendarHeight - GAP}px`
    : `${rect.bottom + GAP}px`

  calendar.classList.toggle('arrowTop', !showAbove)
  calendar.classList.toggle('arrowBottom', showAbove)
}

/**
 * Config flatpickr untuk field tanggal yang berada di dalam dialog.
 *
 * Dipakai bersama AppDateTimePicker supaya tampilannya sama persis dengan
 * field tanggal di halaman biasa.
 */
export const useDialogDatePicker = () => {
  const dialogDateConfig = (options: DialogDateOptions = {}) => ({
    dateFormat: 'Y-m-d',
    position: positionBelowField,
    minDate: options.minDate || undefined,
    maxDate: options.maxDate || undefined,
  })

  return { dialogDateConfig }
}
