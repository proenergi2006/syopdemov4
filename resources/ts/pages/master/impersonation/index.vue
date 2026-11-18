<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'

import axiosIns from '@/plugins/axios'
import { useImpersonationStore } from '@/stores/impersonation'
import { usePermissionStore } from '@/stores/permission'
import { closeAlert, showConfirmAlert, showErrorToast, showLoadingAlert } from '@/utils/alert'

/*
|--------------------------------------------------------------------------
| Masuk Sebagai User
|--------------------------------------------------------------------------
| Dua tab yang sengaja ditaruh di satu halaman: tindakannya dan jejaknya.
|
| Penyamaran di aplikasi ini penuh dan user yang disamari tidak diberi tahu,
| sehingga catatan audit adalah satu-satunya pengaman yang tersisa. Menaruh
| riwayat tepat di sebelah tombolnya membuat jejak itu terlihat oleh orang
| yang memakai fitur ini, bukan tersembunyi di menu lain yang tak pernah
| dibuka.
|--------------------------------------------------------------------------
*/

interface Pihak {
  id: number
  name: string
  username: string | null
}

interface Kandidat {
  id: number
  name: string
  username: string | null
  email: string | null
  is_active: boolean
  role: string | null
  cabang: string | null
}

interface Sesi {
  id: number
  admin: Pihak | null
  target: Pihak | null
  reason: string
  ip: string | null
  user_agent: string | null
  started_at: string | null
  ended_at: string | null
  ended_reason: string | null
  is_running: boolean
  action_count: number
}

interface Aksi {
  id: number
  description: string | null
  event: string | null
  method: string | null
  path: string | null
  status_code: number | null
  ip: string | null
  created_at: string | null
}

const IZIN_MULAI = 'auth_impersonation.create'
const IZIN_LIHAT = 'auth_impersonation.view'

const permissionStore = usePermissionStore()
const impersonation = useImpersonationStore()

const bolehMulai = computed(() => permissionStore.can(IZIN_MULAI))
const bolehLihat = computed(() => permissionStore.can(IZIN_LIHAT))

const tab = ref<'masuk' | 'riwayat'>('masuk')

/*
|--------------------------------------------------------------------------
| Daftar kandidat
|--------------------------------------------------------------------------
*/
const kandidat = ref<Kandidat[]>([])
const kandidatLoading = ref(false)
const cari = ref('')
const halaman = ref(1)
const perHalaman = ref(10)
const totalKandidat = ref(0)

const muatKandidat = async (): Promise<void> => {
  if (!bolehMulai.value)
    return

  kandidatLoading.value = true

  try {
    const { data } = await axiosIns.get('/auth/impersonate/candidates', {
      params: {
        search: cari.value || undefined,
        per_page: perHalaman.value,
        page: halaman.value,
      },
    })

    kandidat.value = data?.data?.data ?? []
    totalKandidat.value = Number(data?.data?.total ?? 0)
  }
  catch (error: any) {
    showErrorToast({
      title: 'Gagal memuat daftar akun',
      text: error?.response?.data?.message ?? 'Silakan coba lagi.',
    })
  }
  finally {
    kandidatLoading.value = false
  }
}

let jedaCari: ReturnType<typeof setTimeout> | null = null

watch(cari, () => {
  if (jedaCari)
    clearTimeout(jedaCari)

  jedaCari = setTimeout(() => {
    halaman.value = 1
    muatKandidat()
  }, 400)
})

watch([halaman, perHalaman], () => {
  muatKandidat()
})

/*
|--------------------------------------------------------------------------
| Dialog mulai menyamar
|--------------------------------------------------------------------------
*/
const dialogMulai = ref(false)
const terpilih = ref<Kandidat | null>(null)
const alasan = ref('')
const alasanError = ref('')
const mulaiLoading = ref(false)

const bukaDialogMulai = (baris: Kandidat): void => {
  terpilih.value = baris
  alasan.value = ''
  alasanError.value = ''
  dialogMulai.value = true
}

const umurSesiMenit = 60

const mulai = async (): Promise<void> => {
  if (mulaiLoading.value || !terpilih.value)
    return

  const isi = alasan.value.trim()

  if (isi.length < 5) {
    alasanError.value = 'Tuliskan alasan minimal 5 karakter. Alasan inilah yang membuat catatan ini bisa dibaca ulang nanti.'

    return
  }

  alasanError.value = ''

  const konfirmasi = await showConfirmAlert({
    title: `Masuk sebagai ${terpilih.value.name}?`,
    text: 'Sesi Anda sebagai administrator akan ditutup dan diganti sesi user tersebut. Semua tindakan Anda akan tercatat atas nama Anda.',
    confirmButtonText: 'Ya, masuk',
    cancelButtonText: 'Batal',
  })

  if (!konfirmasi.isConfirmed)
    return

  mulaiLoading.value = true

  try {
    showLoadingAlert('Menyiapkan sesi...', 'Mohon tunggu sebentar')

    /*
     * Berhasil berarti store memuat ulang halaman, jadi tidak ada kode
     * sesudah ini yang dijalankan pada jalur normal.
     */
    await impersonation.mulai(terpilih.value.id, isi)
  }
  catch (error: any) {
    closeAlert()

    mulaiLoading.value = false

    const pesanValidasi = error?.response?.data?.errors?.user_id?.[0]

    showErrorToast({
      title: 'Gagal masuk sebagai user tersebut',
      text: pesanValidasi
        ?? error?.response?.data?.message
        ?? 'Silakan coba lagi.',
    })
  }
}

/*
|--------------------------------------------------------------------------
| Riwayat
|--------------------------------------------------------------------------
*/
const riwayat = ref<Sesi[]>([])
const riwayatLoading = ref(false)
const cariRiwayat = ref('')
const halamanRiwayat = ref(1)
const perHalamanRiwayat = ref(10)
const totalRiwayat = ref(0)

const muatRiwayat = async (): Promise<void> => {
  if (!bolehLihat.value)
    return

  riwayatLoading.value = true

  try {
    const { data } = await axiosIns.get('/auth/impersonate/history', {
      params: {
        search: cariRiwayat.value || undefined,
        per_page: perHalamanRiwayat.value,
        page: halamanRiwayat.value,
      },
    })

    riwayat.value = data?.data?.data ?? []
    totalRiwayat.value = Number(data?.data?.total ?? 0)
  }
  catch (error: any) {
    showErrorToast({
      title: 'Gagal memuat riwayat',
      text: error?.response?.data?.message ?? 'Silakan coba lagi.',
    })
  }
  finally {
    riwayatLoading.value = false
  }
}

let jedaCariRiwayat: ReturnType<typeof setTimeout> | null = null

watch(cariRiwayat, () => {
  if (jedaCariRiwayat)
    clearTimeout(jedaCariRiwayat)

  jedaCariRiwayat = setTimeout(() => {
    halamanRiwayat.value = 1
    muatRiwayat()
  }, 400)
})

watch([halamanRiwayat, perHalamanRiwayat], () => {
  muatRiwayat()
})

watch(tab, nilai => {
  if (nilai === 'riwayat' && riwayat.value.length === 0)
    muatRiwayat()
})

/*
|--------------------------------------------------------------------------
| Detail satu sesi
|--------------------------------------------------------------------------
*/
const dialogDetail = ref(false)
const detailSesi = ref<Sesi | null>(null)
const detailAksi = ref<Aksi[]>([])
const detailLoading = ref(false)

const bukaDetail = async (baris: Sesi): Promise<void> => {
  dialogDetail.value = true
  detailSesi.value = baris
  detailAksi.value = []
  detailLoading.value = true

  try {
    const { data } = await axiosIns.get(`/auth/impersonate/history/${baris.id}`)

    detailSesi.value = data?.data?.session ?? baris
    detailAksi.value = data?.data?.actions ?? []
  }
  catch (error: any) {
    showErrorToast({
      title: 'Gagal memuat detail sesi',
      text: error?.response?.data?.message ?? 'Silakan coba lagi.',
    })
  }
  finally {
    detailLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Tampilan
|--------------------------------------------------------------------------
*/
const waktu = (nilai: string | null): string => {
  if (!nilai)
    return '-'

  const tanggal = new Date(nilai)

  if (Number.isNaN(tanggal.getTime()))
    return '-'

  return tanggal.toLocaleString('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const labelSebabBerakhir = (sesi: Sesi): string => {
  if (sesi.is_running)
    return 'Sedang berjalan'

  const peta: Record<string, string> = {
    manual: 'Kembali ke akun admin',
    logout: 'Logout',
    expired: 'Kedaluwarsa',
  }

  return peta[String(sesi.ended_reason)] ?? '-'
}

const warnaSebabBerakhir = (sesi: Sesi): string => {
  if (sesi.is_running)
    return 'warning'

  if (sesi.ended_reason === 'expired')
    return 'secondary'

  return 'success'
}

const warnaStatus = (kode: number | null): string => {
  const angka = Number(kode ?? 0)

  if (angka >= 500)
    return 'error'

  if (angka >= 400)
    return 'warning'

  return 'success'
}

onMounted(async () => {
  await permissionStore.loadPermissions()

  if (bolehMulai.value)
    await muatKandidat()
  else
    tab.value = 'riwayat'

  if (bolehLihat.value && tab.value === 'riwayat')
    await muatRiwayat()
})
</script>

<template>
  <VRow>
    <VCol cols="12">
      <!-- Tidak punya kedua izin: tidak ada yang bisa ditampilkan. -->
      <VCard v-if="permissionStore.isLoaded && !bolehMulai && !bolehLihat">
        <VCardText class="text-center py-10">
          <VIcon
            icon="mdi-lock-outline"
            size="48"
            class="mb-3 text-disabled"
          />

          <div class="text-h6 mb-1">
            Tidak punya akses
          </div>

          <div class="text-body-2 text-medium-emphasis">
            Anda tidak memiliki akses ke halaman Masuk Sebagai User.
          </div>
        </VCardText>
      </VCard>

      <VCard v-else>
        <VCardItem>
          <VCardTitle class="text-h5">
            Masuk Sebagai User
          </VCardTitle>

          <VCardSubtitle>
            Masuk ke akun user lain untuk menelusuri keluhan yang hanya muncul di akunnya.
          </VCardSubtitle>
        </VCardItem>

        <VCardText>
          <VAlert
            type="warning"
            variant="tonal"
            density="comfortable"
            class="mb-0"
          >
            <div class="text-body-2">
              Selama menyamar, Anda bisa melakukan <strong>apa pun yang user itu bisa</strong>,
              termasuk mengajukan dan menyetujui dokumen dengan tanda tangan digitalnya.
              Setiap tindakan tercatat di Activity Log beserta nama Anda sebagai pelaku
              sebenarnya. Yang tidak bisa dilakukan hanya mengubah password dan mengelola
              tanda tangan user tersebut.
            </div>
          </VAlert>
        </VCardText>

        <VTabs v-model="tab">
          <VTab
            v-if="bolehMulai"
            value="masuk"
          >
            <VIcon
              start
              icon="mdi-account-switch-outline"
            />
            Pilih Akun
          </VTab>

          <VTab
            v-if="bolehLihat"
            value="riwayat"
          >
            <VIcon
              start
              icon="mdi-history"
            />
            Riwayat
          </VTab>
        </VTabs>

        <VDivider />

        <VWindow
          v-model="tab"
          class="pt-4"
        >
          <!-- ================= PILIH AKUN ================= -->
          <VWindowItem value="masuk">
            <VCardText>
              <VRow class="mb-2">
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="cari"
                    label="Cari nama, username, atau email"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    density="compact"
                  />
                </VCol>

                <VCol
                  cols="12"
                  md="3"
                >
                  <VSelect
                    v-model="perHalaman"
                    :items="[10, 25, 50]"
                    label="Baris per halaman"
                    density="compact"
                  />
                </VCol>
              </VRow>

              <VTable density="comfortable">
                <thead>
                  <tr>
                    <th>Nama</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Cabang</th>
                    <th>Status</th>
                    <th class="text-end">
                      Tindakan
                    </th>
                  </tr>
                </thead>

                <tbody>
                  <tr v-if="kandidatLoading">
                    <td
                      colspan="6"
                      class="text-center py-6"
                    >
                      <VProgressCircular
                        indeterminate
                        size="24"
                      />
                    </td>
                  </tr>

                  <tr v-else-if="kandidat.length === 0">
                    <td
                      colspan="6"
                      class="text-center py-6 text-medium-emphasis"
                    >
                      Tidak ada akun yang cocok.
                    </td>
                  </tr>

                  <tr
                    v-for="baris in kandidat"
                    v-else
                    :key="baris.id"
                  >
                    <td>{{ baris.name }}</td>
                    <td>{{ baris.username ?? '-' }}</td>
                    <td>{{ baris.role ?? '-' }}</td>
                    <td>{{ baris.cabang ?? '-' }}</td>
                    <td>
                      <VChip
                        size="small"
                        :color="baris.is_active ? 'success' : 'secondary'"
                      >
                        {{ baris.is_active ? 'Aktif' : 'Nonaktif' }}
                      </VChip>
                    </td>
                    <td class="text-end">
                      <VBtn
                        size="small"
                        variant="tonal"
                        color="primary"
                        class="text-none"
                        prepend-icon="mdi-login-variant"
                        @click="bukaDialogMulai(baris)"
                      >
                        Masuk sebagai
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>

              <div class="d-flex justify-end mt-4">
                <VPagination
                  v-model="halaman"
                  :length="Math.max(1, Math.ceil(totalKandidat / perHalaman))"
                  :total-visible="5"
                  density="comfortable"
                />
              </div>
            </VCardText>
          </VWindowItem>

          <!-- ================= RIWAYAT ================= -->
          <VWindowItem value="riwayat">
            <VCardText>
              <VRow class="mb-2">
                <VCol
                  cols="12"
                  md="6"
                >
                  <VTextField
                    v-model="cariRiwayat"
                    label="Cari admin, user, atau alasan"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    density="compact"
                  />
                </VCol>

                <VCol
                  cols="12"
                  md="3"
                >
                  <VSelect
                    v-model="perHalamanRiwayat"
                    :items="[10, 25, 50]"
                    label="Baris per halaman"
                    density="compact"
                  />
                </VCol>
              </VRow>

              <VTable density="comfortable">
                <thead>
                  <tr>
                    <th>Admin</th>
                    <th>Masuk sebagai</th>
                    <th>Alasan</th>
                    <th>Mulai</th>
                    <th>Berakhir</th>
                    <th class="text-center">
                      Tindakan
                    </th>
                    <th class="text-end">
                      Detail
                    </th>
                  </tr>
                </thead>

                <tbody>
                  <tr v-if="riwayatLoading">
                    <td
                      colspan="7"
                      class="text-center py-6"
                    >
                      <VProgressCircular
                        indeterminate
                        size="24"
                      />
                    </td>
                  </tr>

                  <tr v-else-if="riwayat.length === 0">
                    <td
                      colspan="7"
                      class="text-center py-6 text-medium-emphasis"
                    >
                      Belum ada penyamaran yang tercatat.
                    </td>
                  </tr>

                  <tr
                    v-for="baris in riwayat"
                    v-else
                    :key="baris.id"
                  >
                    <td>
                      <div>{{ baris.admin?.name ?? '-' }}</div>
                      <div class="text-caption text-medium-emphasis">
                        {{ baris.admin?.username ?? '-' }}
                      </div>
                    </td>

                    <td>
                      <div>{{ baris.target?.name ?? '-' }}</div>
                      <div class="text-caption text-medium-emphasis">
                        {{ baris.target?.username ?? '-' }}
                      </div>
                    </td>

                    <td class="text-wrap">
                      {{ baris.reason }}
                    </td>

                    <td>{{ waktu(baris.started_at) }}</td>

                    <td>
                      <VChip
                        size="small"
                        :color="warnaSebabBerakhir(baris)"
                      >
                        {{ labelSebabBerakhir(baris) }}
                      </VChip>

                      <div
                        v-if="baris.ended_at"
                        class="text-caption text-medium-emphasis mt-1"
                      >
                        {{ waktu(baris.ended_at) }}
                      </div>
                    </td>

                    <td class="text-center text-no-wrap">
                      <VChip
                        v-if="baris.action_count > 0"
                        size="small"
                        variant="tonal"
                        color="info"
                      >
                        {{ baris.action_count }} tindakan
                      </VChip>

                      <!--
                        Nol tindakan bukan sekadar "kosong": artinya selama
                        sesi itu tidak ada satu pun permintaan yang mengubah
                        data, jadi adminnya memang hanya melihat-lihat. Itu
                        keterangan yang berguna bagi pemeriksa, bukan ketiadaan
                        data.
                      -->
                      <span
                        v-else
                        class="text-caption text-medium-emphasis"
                      >
                        Hanya melihat
                      </span>
                    </td>

                    <td class="text-end">
                      <VBtn
                        size="small"
                        variant="text"
                        color="primary"
                        class="text-none"
                        prepend-icon="mdi-eye-outline"
                        @click="bukaDetail(baris)"
                      >
                        Lihat
                      </VBtn>
                    </td>
                  </tr>
                </tbody>
              </VTable>

              <div class="d-flex justify-end mt-4">
                <VPagination
                  v-model="halamanRiwayat"
                  :length="Math.max(1, Math.ceil(totalRiwayat / perHalamanRiwayat))"
                  :total-visible="5"
                  density="comfortable"
                />
              </div>
            </VCardText>
          </VWindowItem>
        </VWindow>
      </VCard>
    </VCol>
  </VRow>

  <!-- ================= DIALOG MULAI ================= -->
  <VDialog
    v-model="dialogMulai"
    max-width="560"
    persistent
  >
    <VCard>
      <VCardItem>
        <VCardTitle>
          Masuk sebagai {{ terpilih?.name }}
        </VCardTitle>

        <VCardSubtitle>
          {{ terpilih?.username ?? '-' }}
        </VCardSubtitle>
      </VCardItem>

      <VDivider />

      <VCardText>
        <VAlert
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          <div class="text-body-2">
            Sesi Anda sebagai administrator akan ditutup dan diganti sesi user ini
            selama {{ umurSesiMenit }} menit. Untuk kembali, tekan
            <strong>Kembali ke akun saya</strong> pada banner di bagian atas halaman.
          </div>
        </VAlert>

        <VTextarea
          v-model="alasan"
          label="Alasan"
          placeholder="Contoh: menelusuri laporan bahwa menu Klaim tidak muncul di akun ini"
          rows="3"
          counter="255"
          :error-messages="alasanError"
        />
      </VCardText>

      <VDivider />

      <VCardActions class="px-4 py-3">
        <VSpacer />

        <VBtn
          variant="text"
          color="secondary"
          class="text-none"
          :disabled="mulaiLoading"
          @click="dialogMulai = false"
        >
          Batal
        </VBtn>

        <VBtn
          variant="flat"
          color="primary"
          class="text-none"
          :loading="mulaiLoading"
          @click="mulai"
        >
          Masuk sekarang
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- ================= DIALOG DETAIL ================= -->
  <VDialog
    v-model="dialogDetail"
    max-width="900"
    scrollable
  >
    <VCard>
      <VCardItem>
        <VCardTitle>
          Detail sesi penyamaran
        </VCardTitle>

        <VCardSubtitle v-if="detailSesi">
          {{ detailSesi.admin?.name ?? '-' }} masuk sebagai {{ detailSesi.target?.name ?? '-' }}
        </VCardSubtitle>
      </VCardItem>

      <VDivider />

      <VCardText>
        <VRow
          v-if="detailSesi"
          class="mb-2"
        >
          <VCol
            cols="12"
            md="6"
          >
            <div class="text-caption text-medium-emphasis">
              Alasan
            </div>
            <div class="text-body-2">
              {{ detailSesi.reason }}
            </div>
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <div class="text-caption text-medium-emphasis">
              Mulai
            </div>
            <div class="text-body-2">
              {{ waktu(detailSesi.started_at) }}
            </div>
          </VCol>

          <VCol
            cols="12"
            md="3"
          >
            <div class="text-caption text-medium-emphasis">
              Berakhir
            </div>
            <div class="text-body-2">
              {{ detailSesi.ended_at ? waktu(detailSesi.ended_at) : labelSebabBerakhir(detailSesi) }}
            </div>
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <div class="text-caption text-medium-emphasis">
              IP
            </div>
            <div class="text-body-2">
              {{ detailSesi.ip ?? '-' }}
            </div>
          </VCol>

          <VCol
            cols="12"
            md="6"
          >
            <div class="text-caption text-medium-emphasis">
              Perangkat
            </div>
            <div class="text-body-2 text-truncate">
              {{ detailSesi.user_agent ?? '-' }}
            </div>
          </VCol>
        </VRow>

        <VDivider class="my-3" />

        <div class="text-subtitle-2 mb-2">
          Tindakan yang dilakukan selama sesi ini
        </div>

        <div
          v-if="detailLoading"
          class="text-center py-6"
        >
          <VProgressCircular
            indeterminate
            size="24"
          />
        </div>

        <VAlert
          v-else-if="detailAksi.length === 0"
          type="info"
          variant="tonal"
          density="compact"
        >
          Tidak ada tindakan yang mengubah data pada sesi ini. Membuka halaman
          dan melihat data tidak dicatat, hanya tindakan yang mengubah sesuatu.
        </VAlert>

        <VTable
          v-else
          density="compact"
        >
          <thead>
            <tr>
              <th>Waktu</th>
              <th>Tindakan</th>
              <th>Endpoint</th>
              <th class="text-center">
                Status
              </th>
            </tr>
          </thead>

          <tbody>
            <tr
              v-for="aksi in detailAksi"
              :key="aksi.id"
            >
              <td class="text-no-wrap">
                {{ waktu(aksi.created_at) }}
              </td>

              <td>{{ aksi.description ?? '-' }}</td>

              <td class="text-caption">
                <span class="font-weight-medium">{{ aksi.method }}</span>
                {{ aksi.path }}
              </td>

              <td class="text-center">
                <VChip
                  size="small"
                  :color="warnaStatus(aksi.status_code)"
                >
                  {{ aksi.status_code ?? '-' }}
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>

      <VDivider />

      <VCardActions class="px-4 py-3">
        <VSpacer />

        <VBtn
          variant="text"
          color="secondary"
          class="text-none"
          @click="dialogDetail = false"
        >
          Tutup
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
