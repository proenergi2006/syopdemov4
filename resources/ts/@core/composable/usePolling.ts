import { onBeforeUnmount, onMounted } from 'vue'

interface UsePollingOptions {
  interval?: number
  immediate?: boolean

  /*
  |--------------------------------------------------------------------------
  | Syarat boleh menyegarkan
  |--------------------------------------------------------------------------
  | Dipanggil sesaat sebelum setiap penyegaran otomatis. Bila menjawab false,
  | giliran itu dilewati dan jadwal berikutnya tetap berjalan seperti biasa.
  |
  | Gunanya menahan penyegaran pada saat penyegaran justru merugikan: ketika
  | sebuah tindakan sedang berjalan, atau ketika pengguna sedang mencentang
  | baris. Daftar yang berganti isi di bawah tangan orang yang sedang memilih
  | akan menghapus pilihannya tanpa sebab yang terlihat.
  |
  | Pemuatan pertama saat halaman dibuka TIDAK tunduk pada syarat ini -- itu
  | bukan penyegaran, melainkan satu-satunya cara data muncul.
  |
  | Pemanggil yang tidak mengisinya berperilaku persis seperti sebelumnya.
  |--------------------------------------------------------------------------
  */
  canPoll?: () => boolean
}

export const usePolling = (
  callback: () => Promise<void> | void,
  options: UsePollingOptions = {},
) => {
  const interval = options.interval ?? 30000
  const immediate = options.immediate ?? true
  const canPoll = options.canPoll ?? (() => true)

  let pollingTimer: ReturnType<typeof setInterval> | null = null

  /*
  | Penanda bahwa satu penyegaran masih berjalan.
  |
  | Tanpa ini, jaringan yang lambat membuat giliran-giliran berikutnya
  | menumpuk: beberapa permintaan untuk daftar yang sama berangkat bersamaan,
  | dan yang tiba paling akhir belum tentu yang paling baru.
  */
  let sedangBerjalan = false

  const jalankan = async (): Promise<void> => {
    if (sedangBerjalan)
      return

    sedangBerjalan = true

    try {
      await callback()
    }
    finally {
      sedangBerjalan = false
    }
  }

  const startPolling = (): void => {
    if (pollingTimer)
      return

    pollingTimer = setInterval(async () => {
      if (document.hidden)
        return

      if (!canPoll())
        return

      await jalankan()
    }, interval)
  }

  const stopPolling = (): void => {
    if (!pollingTimer)
      return

    clearInterval(pollingTimer)
    pollingTimer = null
  }

  const handleVisibilityChange = async (): Promise<void> => {
    if (document.hidden) {
      stopPolling()
    }
    else {
      /*
       * Kembali ke tab ini juga penyegaran otomatis, jadi syaratnya sama --
       * pilihan yang ditinggalkan sebentar tidak boleh hilang hanya karena
       * penggunanya berpindah jendela lalu kembali.
       */
      if (canPoll())
        await jalankan()

      startPolling()
    }
  }

  onMounted(async () => {
    if (immediate)
      await jalankan()

    startPolling()

    document.addEventListener(
      'visibilitychange',
      handleVisibilityChange,
    )
  })

  onBeforeUnmount(() => {
    stopPolling()

    document.removeEventListener(
      'visibilitychange',
      handleVisibilityChange,
    )
  })

  return {
    startPolling,
    stopPolling,
  }
}
