import Swal, { type SweetAlertIcon, type SweetAlertOptions, type SweetAlertResult } from 'sweetalert2'

interface ConfirmAlertOptions {
  title: string
  text?: string
  html?: string
  icon?: SweetAlertIcon
  confirmButtonText?: string
  cancelButtonText?: string
}

interface NotifyAlertOptions {
  title?: string
  text?: string
  html?: string
  icon?: SweetAlertIcon
  timer?: number
}

const baseConfirmOptions: SweetAlertOptions = {
  icon: 'warning',
  showCancelButton: true,
  reverseButtons: true,
  buttonsStyling: true,
  allowOutsideClick: false,
  confirmButtonText: 'Ya',
  cancelButtonText: 'Batal',
  customClass: {
    confirmButton: 'swal-confirm-btn',
    cancelButton: 'swal-cancel-btn',
  },
}

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
    ...baseConfirmOptions,
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
    title: options.title ?? 'Berhasil',
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
    title: options.title ?? 'Gagal',
    text: options.text ?? 'Terjadi kesalahan.',
    html: options.html,
  })
}

export const showWarningAlert = async (
  options: NotifyAlertOptions = {},
): Promise<SweetAlertResult> => {
  return Swal.fire({
    ...baseNotifyOptions,
    icon: options.icon ?? 'warning',
    title: options.title ?? 'Peringatan',
    text: options.text,
    html: options.html,
  })
}

export const showLoadingAlert = (title = 'Memproses...', text = 'Mohon tunggu sebentar'): void => {
  void Swal.fire({
    title,
    text,
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
  itemName = 'data ini',
): Promise<SweetAlertResult> => {
  return showConfirmAlert({
    title: 'Hapus data?',
    text: `${itemName} akan dihapus permanen.`,
    icon: 'warning',
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })
}