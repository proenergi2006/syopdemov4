import Swal, { type SweetAlertIcon, type SweetAlertInput, type SweetAlertOptions, type SweetAlertResult } from 'sweetalert2'
import { toast } from 'vue3-toastify'
import i18n from '@/plugins/i18n'

/*
|--------------------------------------------------------------------------
| Kenapa pakai instance i18n global langsung
|--------------------------------------------------------------------------
| File ini murni utility (bukan komponen), sehingga tidak bisa memanggil
| useI18n() di dalam setup context. Dipanggil sebagai fungsi (bukan
| computed) supaya selalu membaca locale TERKINI saat alert ditampilkan,
| bukan locale saat modul ini pertama kali di-import.
|--------------------------------------------------------------------------
*/
const t = (key: string, params?: Record<string, unknown>): string =>
  i18n.global.t(key, params ?? {})

interface ConfirmAlertOptions {
  title: string
  text?: string
  html?: string
  icon?: SweetAlertIcon
  confirmButtonText?: string
  cancelButtonText?: string

  /*
  | Isian opsional di dalam penegasan, untuk hal yang tidak layak diberi
  | formulir tersendiri -- misalnya alasan singkat saat sebuah pembayaran
  | dilakukan di luar jadwalnya. Nilainya terbaca dari `result.value`.
  */
  input?: SweetAlertInput
  inputLabel?: string
  inputPlaceholder?: string
  inputValue?: string
  inputAttributes?: Record<string, string>
}

interface NotifyAlertOptions {
  title?: string
  text?: string
  html?: string
  icon?: SweetAlertIcon
  timer?: number
}

/*
|--------------------------------------------------------------------------
| Bukan konstanta statis
|--------------------------------------------------------------------------
| confirmButtonText/cancelButtonText sengaja dibangun via fungsi (bukan
| object literal level-modul) supaya selalu membaca locale terkini setiap
| dialog ditampilkan, bukan locale saat modul ini pertama kali di-import.
|--------------------------------------------------------------------------
*/
const getBaseConfirmOptions = (): SweetAlertOptions => ({
  icon: 'warning',
  showCancelButton: true,
  reverseButtons: true,
  buttonsStyling: true,
  allowOutsideClick: false,
  confirmButtonText: t('common.actions.confirm'),
  cancelButtonText: t('common.actions.cancel'),
  customClass: {
    confirmButton: 'swal-confirm-btn',
    cancelButton: 'swal-cancel-btn',
  },
})

const baseNotifyOptions: SweetAlertOptions = {
  buttonsStyling: true,
  allowOutsideClick: false,
  customClass: {
    confirmButton: 'swal-confirm-btn',
    cancelButton: 'swal-cancel-btn',
  },
}

export const showConfirmAlert = async (
  options: ConfirmAlertOptions,
): Promise<SweetAlertResult> => {
  return Swal.fire({
    ...getBaseConfirmOptions(),
    ...options,
  })
}

export const showSuccessAlert = async (
  options: NotifyAlertOptions = {},
): Promise<SweetAlertResult> => {
  const finalTimer = options.timer ?? 1800

  return Swal.fire({
    ...baseNotifyOptions,
    icon: options.icon ?? 'success',
    title: options.title ?? t('common.alert.success'),
    text: options.text,
    html: options.html,
    timer: finalTimer,
    showConfirmButton: finalTimer > 0 ? false : true,
  })
}

export const showErrorAlert = async (
  options: NotifyAlertOptions = {},
): Promise<SweetAlertResult> => {
  return Swal.fire({
    ...baseNotifyOptions,
    icon: options.icon ?? 'error',
    title: options.title ?? t('common.alert.error'),
    text: options.text ?? t('common.alert.errorGeneric'),
    html: options.html,
  })
}

export const showWarningAlert = async (
  options: NotifyAlertOptions = {},
): Promise<SweetAlertResult> => {
  return Swal.fire({
    ...baseNotifyOptions,
    icon: options.icon ?? 'warning',
    title: options.title ?? t('common.alert.warning'),
    text: options.text,
    html: options.html,
  })
}

export const showLoadingAlert = (title?: string, text?: string): void => {
  void Swal.fire({
    title: title ?? t('common.alert.processing'),
    text: text ?? t('common.alert.pleaseWait'),
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading()
    },
  })
}

export const closeAlert = (): void => {
  Swal.close()
}

export const showDeleteConfirm = async (
  itemName?: string,
): Promise<SweetAlertResult> => {
  const resolvedItemName = itemName ?? t('common.alert.defaultItemName')

  return showConfirmAlert({
    title: t('common.alert.deleteConfirmTitle'),
    text: t('common.alert.deleteConfirmText', { item: resolvedItemName }),
    icon: 'warning',
    confirmButtonText: t('common.alert.deleteConfirmButton'),
    cancelButtonText: t('common.actions.cancel'),
  })
}

/* =========================================================
 * BASE TOAST
========================================================= */
interface ToastOptions {
  title?: string
  text?: string
}

const buildMessage = ({ title, text }: ToastOptions): string => {
  if (title && text) return `${title}\n${text}`
  return title || text || ''
}

export const showSuccessToast = (options: ToastOptions): void => {
  toast.success(buildMessage(options))
}

export const showErrorToast = (options: ToastOptions): void => {
  toast.error(buildMessage(options))
}

export const showWarningToast = (options: ToastOptions): void => {
  toast.warning(buildMessage(options))
}

export const showInfoToast = (options: ToastOptions): void => {
  toast.info(buildMessage(options))
}

/* =========================================================
 * TOAST YANG MENYEBERANGI PERPINDAHAN HALAMAN
 * =========================================================
 * Toast yang ditampilkan tepat sebelum router.push ikut lenyap bersama
 * halaman yang ditinggalkan: komponennya dilepas sebelum toast sempat
 * terbaca, sehingga yang terlihat hanya kedipan. Pesannya karena itu
 * dititipkan dulu, lalu ditampilkan oleh halaman tujuan setelah terpasang.
 *
 * Dipakai sessionStorage, bukan variabel modul, dengan dua alasan: muat
 * ulang penuh sesudah menyimpan tetap membawa pesannya, dan titipannya
 * hilang sendiri begitu tab ditutup.
 * ========================================================= */
type JenisToast = 'success' | 'error' | 'warning' | 'info'

const KUNCI_TOAST_TERTUNDA = 'toastTertunda'

export const queueToast = (jenis: JenisToast, options: ToastOptions): void => {
  try {
    sessionStorage.setItem(
      KUNCI_TOAST_TERTUNDA,
      JSON.stringify({ jenis, title: options.title, text: options.text }),
    )
  }
  catch {
    /*
     * Penyimpanan tab bisa ditolak peramban (mode privat, setelan privasi
     * ketat). Sebuah toast bukan alasan untuk menggagalkan apa pun.
     */
  }
}

/**
 * Menampilkan titipan toast, lalu membuangnya.
 *
 * Dipanggil dari onMounted halaman tujuan. Titipannya dihapus LEBIH DULU,
 * sebelum ditampilkan: kalau tidak, pesan yang isinya rusak akan tertinggal
 * di sana dan mencoba tampil lagi pada setiap kunjungan berikutnya.
 */
export const flushQueuedToast = (): void => {
  let mentah: string | null = null

  try {
    mentah = sessionStorage.getItem(KUNCI_TOAST_TERTUNDA)
    sessionStorage.removeItem(KUNCI_TOAST_TERTUNDA)
  }
  catch {
    return
  }

  if (!mentah)
    return

  const penampil: Record<JenisToast, (options: ToastOptions) => void> = {
    success: showSuccessToast,
    error: showErrorToast,
    warning: showWarningToast,
    info: showInfoToast,
  }

  try {
    const titipan = JSON.parse(mentah) as { jenis?: JenisToast } & ToastOptions

    const tampilkan = titipan.jenis ? penampil[titipan.jenis] : undefined

    if (tampilkan)
      tampilkan({ title: titipan.title, text: titipan.text })
  }
  catch {
    /* Titipan yang rusak dibuang diam-diam -- sudah terhapus di atas. */
  }
}
