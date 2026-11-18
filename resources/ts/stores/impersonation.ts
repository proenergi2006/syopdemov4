import { defineStore } from 'pinia'

import axiosIns from '@/plugins/axios'

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

export interface ImpersonationParty {
  id: number
  name: string
  username: string | null
}

interface ImpersonationState {
  impersonating: boolean
  sessionId: number | null
  reason: string | null
  startedAt: string | null

  /** Admin yang sebenarnya menekan tombolnya. */
  admin: ImpersonationParty | null

  /** User yang akunnya sedang dipakai. */
  target: ImpersonationParty | null

  loaded: boolean
  loading: boolean
}

/*
|--------------------------------------------------------------------------
| Bentuk userData di localStorage
|--------------------------------------------------------------------------
| Sengaja menyalin bentuk yang dipakai saveUserData() di pages/login.vue.
| UserProfile.vue dan beberapa halaman membaca kunci-kunci ini apa adanya,
| jadi sesi hasil penyamaran harus meninggalkan bentuk yang sama persis
| seperti sesi hasil login biasa -- kalau tidak, nama atau cabang di layar
| akan kosong tanpa sebab yang kelihatan.
|--------------------------------------------------------------------------
*/
function bentukUserData(pengguna: Record<string, any>): Record<string, unknown> {
  return {
    id: pengguna.id ?? null,
    name: pengguna.name ?? '-',
    username: pengguna.username ?? null,
    email: pengguna.email ?? null,

    role_id: pengguna.role_id ?? null,
    role: pengguna.role || pengguna.role_name || '-',
    roles: pengguna.roles || [],
    role_code: pengguna.role_code || null,

    cabang_id: pengguna.cabang_id ?? null,
    cabang: pengguna.cabang ?? null,

    department_id: pengguna.department_id ?? null,
    department: pengguna.department ?? null,
  }
}

/*
|--------------------------------------------------------------------------
| Keadaan penyamaran selalu ditanyakan ke server
|--------------------------------------------------------------------------
| Sengaja TIDAK disimpan di localStorage. Banner "Anda sedang masuk sebagai
| X" adalah satu-satunya hal yang mencegah admin lupa dan melakukan sesuatu
| yang ia pikir atas namanya sendiri, jadi ia tidak boleh bergantung pada
| nilai yang bisa basi, bisa diedit dari konsol, atau bisa tertinggal setelah
| sesi berakhir.
|
| Yang menentukan tetap token: server menjawab dari baris sesi yang terikat
| pada id token yang dipakai request ini.
|--------------------------------------------------------------------------
*/
export const useImpersonationStore = defineStore('impersonation', {
  state: (): ImpersonationState => ({
    impersonating: false,
    sessionId: null,
    reason: null,
    startedAt: null,
    admin: null,
    target: null,
    loaded: false,
    loading: false,
  }),

  actions: {
    kosongkan(): void {
      this.impersonating = false
      this.sessionId = null
      this.reason = null
      this.startedAt = null
      this.admin = null
      this.target = null
    },

    async muat(): Promise<void> {
      if (this.loading)
        return

      if (!localStorage.getItem('accessToken')) {
        this.kosongkan()
        this.loaded = true

        return
      }

      this.loading = true

      try {
        const { data } = await axiosIns.get('/auth/impersonate/status')

        const isi = data?.data ?? {}

        this.impersonating = Boolean(isi.impersonating)
        this.sessionId = isi.session_id ?? null
        this.reason = isi.reason ?? null
        this.startedAt = isi.started_at ?? null
        this.admin = isi.admin ?? null
        this.target = isi.target ?? null
      }
      catch {
        /*
         * Gagal menanyakan status bukan alasan untuk menampilkan banner yang
         * mungkin salah, dan juga bukan alasan untuk menghalangi halaman.
         */
        this.kosongkan()
      }
      finally {
        this.loading = false
        this.loaded = true
      }
    },

    /*
    |--------------------------------------------------------------------------
    | Mulai menyamar
    |--------------------------------------------------------------------------
    */
    async mulai(userId: number, reason: string): Promise<void> {
      const { data } = await axiosIns.post(
        `/auth/impersonate/${userId}`,
        { reason },
      )

      await this.gantiSesi(data?.token, '/dashboards/crm')
    },

    /*
    |--------------------------------------------------------------------------
    | Kembali ke akun sendiri
    |--------------------------------------------------------------------------
    */
    async hentikan(): Promise<void> {
      const { data } = await axiosIns.post('/auth/impersonate/stop')

      await this.gantiSesi(data?.token, '/master/impersonation')
    },

    /*
    |--------------------------------------------------------------------------
    | Tukar token lalu muat ulang halaman sepenuhnya
    |--------------------------------------------------------------------------
    | Urutannya menyalin alur login, dan alasannya sama: identitas user, daftar
    | permission, ability CASL, dan menu semuanya diturunkan dari token. Menukar
    | token tanpa menyegarkan semua itu akan meninggalkan layar yang menampilkan
    | nama satu orang sambil memakai hak orang lain.
    |
    | Diakhiri hard reload, bukan router.push, supaya tidak ada satu pun
    | komponen, store, atau data halaman yang masih memegang keadaan lama.
    |--------------------------------------------------------------------------
    */
    async gantiSesi(token: unknown, tujuan: string): Promise<void> {
      const tokenBaru = String(token ?? '').trim()

      if (!tokenBaru)
        throw new Error('Server tidak mengembalikan token sesi yang baru.')

      /*
       * Token harus ditulis ke localStorage lebih dulu, bukan dikirim sebagai
       * header per-request: interceptor di plugins/axios.ts menimpa
       * Authorization dari localStorage pada setiap request, jadi header yang
       * dipasang sendiri akan terhapus olehnya.
       */
      localStorage.setItem('accessToken', tokenBaru)

      axiosIns.defaults.headers.common.Authorization = `Bearer ${tokenBaru}`

      try {
        const { data } = await axiosIns.get('/auth/me')

        const pengguna = data?.data ?? data?.user ?? null

        if (!pengguna)
          throw new Error('Identitas sesi baru tidak terbaca.')

        localStorage.setItem('userData', JSON.stringify(bentukUserData(pengguna)))

        /*
         * Menu ikut berganti, karena menu diturunkan dari hak akses. Tanpa ini
         * admin melihat menu miliknya sendiri sambil berperan sebagai orang
         * lain -- dan menekannya hanya menghasilkan penolakan.
         */
        const menu = await axiosIns.get('/master/menus/navigation')

        localStorage.setItem('navItems', JSON.stringify(menu.data))

        /*
         * Hard reload, bukan router.push: identitas, permission, ability CASL,
         * dan menu semuanya diturunkan dari token, dan hanya muat ulang penuh
         * yang menjamin tidak ada komponen atau store yang masih memegang
         * keadaan sesi sebelumnya.
         */
        window.location.replace(tujuan)
      }
      catch (error) {
        /*
         * Sampai di sini pertukaran sesi di server SUDAH terjadi dan token
         * lama sudah dihapus, jadi tidak ada sesi untuk kembali. Membiarkan
         * layar apa adanya akan menampilkan nama satu orang dengan hak orang
         * lain, jadi yang jujur hanya satu: bersihkan dan minta login lagi.
         */
        localStorage.removeItem('userData')
        localStorage.removeItem('navItems')
        sessionStorage.removeItem('navItems')
        localStorage.removeItem('userAbilities')

        console.error('GAGAL MENERAPKAN SESI PENYAMARAN:', error)

        window.location.replace('/login?session_expired=1')
      }
    },
  },
})
