<script lang="ts" setup>
import SignaturePad from 'signature_pad'
import { computed, nextTick, onMounted, ref } from 'vue'
import axios from '@axios'
import {
  closeAlert,
  showConfirmAlert,
  showErrorToast,
  showLoadingAlert,
  showSuccessToast,
} from '@/utils/alert'

interface Slot {
  id: number
  label: string
  source: string
  is_active: boolean
  url: string | null
  created_at: string | null
}

const slots = ref<Slot[]>([])
const maksSlot = ref(3)
const sisaSlot = ref(3)
const sedangMemuat = ref(false)

/* -------------------------------------------------------------------- */
/* Dialog tambah                                                        */
/* -------------------------------------------------------------------- */

const dialogTambah = ref(false)
const cara = ref<'draw' | 'upload'>('draw')
const label = ref('')
const setuju = ref(false)
const sedangMenyimpan = ref(false)
const keluhan = ref('')

const kanvasRef = ref<HTMLCanvasElement | null>(null)
const pena = ref<SignaturePad | null>(null)

const berkas = ref<File[]>([])
const pratinjau = ref<string>('')
const sedangMemproses = ref(false)

/* Dialog ubah nama */
const dialogNama = ref(false)
const namaBaru = ref('')
const idDiubah = ref<number | null>(null)

const adaTandaTangan = computed(() => slots.value.length > 0)
const bolehTambah = computed(() => sisaSlot.value > 0)

/* -------------------------------------------------------------------- */
/* Pembacaan                                                            */
/* -------------------------------------------------------------------- */

const terapkan = (data: any): void => {
  slots.value = data?.signatures ?? []
  maksSlot.value = Number(data?.max ?? 3)
  sisaSlot.value = Number(data?.remaining ?? 0)
}

const muat = async (): Promise<void> => {
  sedangMemuat.value = true

  try {
    const response = await axios.get('/account/signatures', {
      headers: { Accept: 'application/json' },
    })

    terapkan(response.data?.data)
  }
  catch (error: any) {
    showErrorToast({
      title: 'Gagal',
      text: error.response?.data?.message
        || 'Gagal memuat daftar tanda tangan.',
    })
  }
  finally {
    sedangMemuat.value = false
  }
}

onMounted(muat)

/* -------------------------------------------------------------------- */
/* Kanvas tulis                                                         */
/* -------------------------------------------------------------------- */

const siapkanKanvas = (): void => {
  const kanvas = kanvasRef.value

  if (!kanvas)
    return

  const kotak = kanvas.getBoundingClientRect()

  if (!kotak.width || !kotak.height) {
    setTimeout(siapkanKanvas, 150)

    return
  }

  const nisbah = Math.max(window.devicePixelRatio || 1, 1)

  kanvas.width = kotak.width * nisbah
  kanvas.height = kotak.height * nisbah

  const konteks = kanvas.getContext('2d')

  konteks?.setTransform(nisbah, 0, 0, nisbah, 0, 0)

  pena.value?.off()

  pena.value = new SignaturePad(kanvas, {
    minWidth: 0.8,
    maxWidth: 2.4,
    throttle: 16,
    penColor: 'black',
    backgroundColor: 'rgba(255,255,255,0)',
  })

  pena.value.clear()
}

const bersihkanKanvas = (): void => {
  pena.value?.clear()
  keluhan.value = ''
}

/* -------------------------------------------------------------------- */
/* Unggah foto                                                          */
/* -------------------------------------------------------------------- */

const berkasTerpilih = computed<File | null>(() => berkas.value?.[0] ?? null)

/*
| Foto kertas sulit diduga hasilnya, jadi hasil bersihnya ditampilkan lebih
| dulu dan tidak ada yang tersimpan sampai user menekan simpan. Tanpa ini
| user hanya bisa menilai hasilnya setelah slotnya terpakai.
*/
const prosesPratinjau = async (): Promise<void> => {
  const file = berkasTerpilih.value

  pratinjau.value = ''
  keluhan.value = ''

  if (!file)
    return

  sedangMemproses.value = true

  try {
    const formData = new FormData()

    formData.append('file', file)

    const response = await axios.post(
      '/account/signatures/preview',
      formData,
      { headers: { Accept: 'application/json' } },
    )

    pratinjau.value = response.data?.data?.preview ?? ''
  }
  catch (error: any) {
    keluhan.value = error.response?.data?.errors?.file?.[0]
      || error.response?.data?.message
      || 'Gagal memproses foto itu.'
  }
  finally {
    sedangMemproses.value = false
  }
}

/* -------------------------------------------------------------------- */
/* Tambah                                                               */
/* -------------------------------------------------------------------- */

const bukaDialogTambah = async (): Promise<void> => {
  cara.value = 'draw'
  label.value = ''
  setuju.value = false
  keluhan.value = ''
  berkas.value = []
  pratinjau.value = ''
  dialogTambah.value = true

  await nextTick()

  setTimeout(siapkanKanvas, 250)
}

const gantiCara = async (nilai: 'draw' | 'upload'): Promise<void> => {
  cara.value = nilai
  keluhan.value = ''

  if (nilai !== 'draw')
    return

  await nextTick()

  setTimeout(siapkanKanvas, 150)
}

const simpan = async (): Promise<void> => {
  keluhan.value = ''

  if (!setuju.value) {
    keluhan.value = 'Anda perlu menyetujui penggunaan tanda tangan digital ini.'

    return
  }

  const formData = new FormData()

  formData.append('mode', cara.value)
  formData.append('agree', '1')

  if (label.value.trim())
    formData.append('label', label.value.trim())

  if (cara.value === 'draw') {
    if (!pena.value || pena.value.isEmpty()) {
      keluhan.value = 'Tanda tangan belum ditulis.'

      return
    }

    formData.append('signature', pena.value.toDataURL('image/png'))
  }
  else {
    const file = berkasTerpilih.value

    if (!file) {
      keluhan.value = 'Belum ada foto yang dipilih.'

      return
    }

    formData.append('file', file)
  }

  sedangMenyimpan.value = true

  try {
    showLoadingAlert('Menyimpan tanda tangan...', 'Mohon tunggu sebentar.')

    const response = await axios.post('/account/signatures', formData, {
      headers: { Accept: 'application/json' },
    })

    closeAlert()

    terapkan(response.data?.data)

    dialogTambah.value = false

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Tanda tangan berhasil disimpan.',
    })
  }
  catch (error: any) {
    closeAlert()

    const pesan = error.response?.data?.errors?.file?.[0]
      || error.response?.data?.errors?.label?.[0]
      || error.response?.data?.errors?.signature?.[0]
      || error.response?.data?.errors?.agree?.[0]
      || error.response?.data?.message
      || 'Gagal menyimpan tanda tangan.'

    keluhan.value = pesan
  }
  finally {
    sedangMenyimpan.value = false
  }
}

/* -------------------------------------------------------------------- */
/* Pilih, ubah nama, hapus                                              */
/* -------------------------------------------------------------------- */

const pakai = async (slot: Slot): Promise<void> => {
  if (slot.is_active)
    return

  try {
    showLoadingAlert('Mengubah tanda tangan...', 'Mohon tunggu sebentar.')

    const response = await axios.put(
      `/account/signatures/${slot.id}/activate`,
      {},
      { headers: { Accept: 'application/json' } },
    )

    closeAlert()

    terapkan(response.data?.data)

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message
        || 'Tanda tangan itu sekarang dipakai untuk dokumen berikutnya.',
    })
  }
  catch (error: any) {
    closeAlert()

    showErrorToast({
      title: 'Gagal',
      text: error.response?.data?.message
        || 'Gagal mengubah tanda tangan yang dipakai.',
    })
  }
}

const bukaDialogNama = (slot: Slot): void => {
  idDiubah.value = slot.id
  namaBaru.value = slot.label
  dialogNama.value = true
}

const simpanNama = async (): Promise<void> => {
  if (!idDiubah.value || !namaBaru.value.trim())
    return

  try {
    const response = await axios.patch(
      `/account/signatures/${idDiubah.value}`,
      { label: namaBaru.value.trim() },
      { headers: { Accept: 'application/json' } },
    )

    terapkan(response.data?.data)

    dialogNama.value = false

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Nama tanda tangan berhasil diubah.',
    })
  }
  catch (error: any) {
    showErrorToast({
      title: 'Gagal',
      text: error.response?.data?.errors?.label?.[0]
        || error.response?.data?.message
        || 'Gagal mengubah nama tanda tangan.',
    })
  }
}

const hapus = async (slot: Slot): Promise<void> => {
  const konfirmasi = await showConfirmAlert({
    icon: 'warning',
    title: 'Hapus tanda tangan ini?',
    text: slot.is_active
      ? 'Tanda tangan ini sedang dipakai. Setelah dihapus, tanda tangan lain'
        + ' yang akan dipakai untuk dokumen berikutnya. Dokumen yang sudah'
        + ' bertanda tangan tidak berubah.'
      : 'Slotnya akan kosong dan bisa dipakai untuk tanda tangan baru.'
        + ' Dokumen yang sudah bertanda tangan tidak berubah.',
    confirmButtonText: 'Ya, hapus',
    cancelButtonText: 'Batal',
  })

  if (!konfirmasi.isConfirmed)
    return

  try {
    showLoadingAlert('Menghapus tanda tangan...', 'Mohon tunggu sebentar.')

    const response = await axios.delete(`/account/signatures/${slot.id}`, {
      headers: { Accept: 'application/json' },
    })

    closeAlert()

    terapkan(response.data?.data)

    showSuccessToast({
      title: 'Berhasil',
      text: response.data?.message || 'Tanda tangan berhasil dihapus.',
    })
  }
  catch (error: any) {
    closeAlert()

    showErrorToast({
      title: 'Gagal',
      text: error.response?.data?.message || 'Gagal menghapus tanda tangan.',
    })
  }
}

const sumberLabel = (sumber: string): string =>
  (sumber === 'upload' ? 'Dari foto' : 'Ditulis di layar')
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard>
        <VCardTitle class="text-h6 font-weight-bold d-flex align-center flex-wrap gap-2">
          Tanda Tangan Digital

          <VChip
            size="x-small"
            variant="tonal"
            color="secondary"
          >
            {{ slots.length }} dari {{ maksSlot }}
          </VChip>
        </VCardTitle>

        <VCardText class="pt-0">
          <p class="text-body-2 text-medium-emphasis mb-0">
            Tanda tangan yang dipakai akan dicap pada dokumen yang Anda ajukan
            atau setujui <strong>setelah ini</strong>. Dokumen yang sudah
            bertanda tangan tidak berubah, meskipun Anda berganti tanda tangan
            atau menghapusnya dari daftar.
          </p>
        </VCardText>

        <VCardText v-if="sedangMemuat">
          <div class="d-flex align-center gap-3">
            <VProgressCircular
              indeterminate
              size="20"
              width="2"
            />

            <span class="text-body-2 text-medium-emphasis">
              Memuat tanda tangan Anda...
            </span>
          </div>
        </VCardText>

        <template v-else>
          <VCardText
            v-if="!adaTandaTangan"
            class="pt-0"
          >
            <VAlert
              type="warning"
              variant="tonal"
              density="comfortable"
            >
              Anda belum punya tanda tangan digital. Dokumen tidak bisa diajukan
              atau disetujui sebelum tanda tangan dibuat.
            </VAlert>
          </VCardText>

          <VCardText class="pt-0">
            <VRow>
              <VCol
                v-for="slot in slots"
                :key="slot.id"
                cols="12"
                md="4"
              >
                <div
                  class="slot-ttd"
                  :class="{ 'slot-ttd--dipakai': slot.is_active }"
                >
                  <div class="slot-ttd__kepala">
                    <span class="text-body-2 font-weight-medium text-truncate">
                      {{ slot.label }}
                    </span>

                    <VChip
                      v-if="slot.is_active"
                      size="x-small"
                      color="success"
                      variant="flat"
                    >
                      Dipakai
                    </VChip>
                  </div>

                  <div class="slot-ttd__gambar">
                    <img
                      v-if="slot.url"
                      :src="slot.url"
                      alt="Tanda tangan"
                    >
                  </div>

                  <div class="slot-ttd__kaki">
                    <span class="text-caption text-medium-emphasis">
                      {{ sumberLabel(slot.source) }}
                    </span>

                    <VSpacer />

                    <VBtn
                      icon
                      size="x-small"
                      variant="text"
                      color="secondary"
                      @click="bukaDialogNama(slot)"
                    >
                      <VIcon
                        icon="mdi-pencil-outline"
                        size="18"
                      />

                      <VTooltip
                        activator="parent"
                        location="top"
                      >
                        Ubah nama
                      </VTooltip>
                    </VBtn>

                    <VBtn
                      icon
                      size="x-small"
                      variant="text"
                      color="error"
                      @click="hapus(slot)"
                    >
                      <VIcon
                        icon="mdi-trash-can-outline"
                        size="18"
                      />

                      <VTooltip
                        activator="parent"
                        location="top"
                      >
                        Hapus dari daftar
                      </VTooltip>
                    </VBtn>
                  </div>

                  <VBtn
                    v-if="!slot.is_active"
                    block
                    size="small"
                    variant="tonal"
                    class="text-none mt-2"
                    @click="pakai(slot)"
                  >
                    Pakai yang ini
                  </VBtn>

                  <div
                    v-else
                    class="slot-ttd__penanda text-caption"
                  >
                    <VIcon
                      icon="mdi-check-circle-outline"
                      size="16"
                      class="me-1"
                    />
                    Dipakai untuk dokumen berikutnya
                  </div>
                </div>
              </VCol>

              <VCol
                v-if="bolehTambah"
                cols="12"
                md="4"
              >
                <button
                  type="button"
                  class="slot-ttd slot-ttd--tambah"
                  @click="bukaDialogTambah"
                >
                  <VIcon
                    icon="mdi-signature-freehand"
                    size="32"
                  />

                  <span class="text-body-2 font-weight-medium mt-2">
                    Tambah tanda tangan
                  </span>

                  <span class="text-caption text-medium-emphasis">
                    Sisa {{ sisaSlot }} slot
                  </span>
                </button>
              </VCol>
            </VRow>
          </VCardText>

          <VCardText
            v-if="!bolehTambah"
            class="pt-0"
          >
            <p class="text-caption text-medium-emphasis mb-0">
              Slotnya sudah penuh ({{ maksSlot }} tanda tangan). Hapus salah satu
              dulu kalau ingin menambah yang baru.
            </p>
          </VCardText>
        </template>
      </VCard>
    </VCol>
  </VRow>

  <!-- ============================================================ -->
  <!-- Dialog tambah                                                -->
  <!-- ============================================================ -->

  <VDialog
    v-model="dialogTambah"
    max-width="700"
    persistent
  >
    <VCard>
      <VCardTitle class="text-h6 font-weight-bold">
        Tambah Tanda Tangan
      </VCardTitle>

      <VCardText class="pt-0">
        <VTabs
          :model-value="cara"
          class="mb-4"
          @update:model-value="gantiCara($event as 'draw' | 'upload')"
        >
          <VTab value="draw">
            <VIcon
              icon="mdi-draw-pen"
              size="18"
              start
            />
            Tulis di layar
          </VTab>

          <VTab value="upload">
            <VIcon
              icon="mdi-camera-outline"
              size="18"
              start
            />
            Unggah foto
          </VTab>
        </VTabs>

        <!-- Tulis di layar -->
        <div v-show="cara === 'draw'">
          <div class="kanvas-bungkus">
            <canvas
              ref="kanvasRef"
              class="kanvas-ttd"
            />
          </div>

          <div class="d-flex justify-end mt-2">
            <VBtn
              size="small"
              variant="text"
              color="secondary"
              class="text-none"
              @click="bersihkanKanvas"
            >
              <VIcon
                icon="mdi-eraser"
                size="18"
                start
              />
              Bersihkan
            </VBtn>
          </div>
        </div>

        <!-- Unggah foto -->
        <div v-show="cara === 'upload'">
          <ol class="petunjuk-foto text-body-2">
            <li>
              Tanda tangan di <strong>kertas putih bersih</strong>, pakai pena
              hitam atau biru. Hindari kertas bergaris atau bermotif.
            </li>
            <li>
              Foto dari atas, tegak lurus, di tempat yang terang. Jaga agar
              bayangan tangan tidak jatuh ke kertas.
            </li>
            <li>
              Tidak perlu dipotong. Sistem memotong sendiri ke area tanda
              tangan, menghapus latar kertasnya, dan menyimpannya sebagai PNG
              transparan.
            </li>
          </ol>

          <VFileInput
            v-model="berkas"
            accept="image/jpeg,image/png,image/webp"
            label="Foto tanda tangan"
            placeholder="Pilih foto JPG, PNG, atau WEBP (maks 8 MB)"
            density="compact"
            variant="outlined"
            prepend-icon=""
            prepend-inner-icon="mdi-image-outline"
            class="mt-2"
            @update:model-value="prosesPratinjau"
          />

          <div
            v-if="sedangMemproses"
            class="d-flex align-center gap-3 mt-2"
          >
            <VProgressCircular
              indeterminate
              size="20"
              width="2"
            />

            <span class="text-body-2 text-medium-emphasis">
              Membersihkan latar fotonya...
            </span>
          </div>

          <div
            v-if="pratinjau && !sedangMemproses"
            class="mt-3"
          >
            <p class="text-caption text-medium-emphasis mb-1">
              Begini hasilnya nanti di dokumen:
            </p>

            <div class="pratinjau-kotak">
              <img
                :src="pratinjau"
                alt="Pratinjau tanda tangan"
              >
            </div>
          </div>
        </div>

        <VTextField
          v-model="label"
          label="Nama tanda tangan (opsional)"
          placeholder="Misalnya: Tanda tangan resmi"
          density="compact"
          variant="outlined"
          maxlength="60"
          counter
          class="mt-4"
        />

        <VCheckbox
          v-model="setuju"
          class="mt-2"
        >
          <template #label>
            <span class="text-body-2">
              Saya menyetujui penggunaan tanda tangan digital ini pada dokumen
              yang saya ajukan dan setujui.
            </span>
          </template>
        </VCheckbox>

        <VAlert
          v-if="keluhan"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-2"
        >
          {{ keluhan }}
        </VAlert>
      </VCardText>

      <VCardText class="d-flex flex-wrap justify-end gap-3 pt-0">
        <VBtn
          color="secondary"
          variant="tonal"
          class="text-none"
          :disabled="sedangMenyimpan"
          @click="dialogTambah = false"
        >
          Batal
        </VBtn>

        <VBtn
          class="text-none"
          :loading="sedangMenyimpan"
          :disabled="sedangMenyimpan || sedangMemproses"
          @click="simpan"
        >
          Simpan
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>

  <!-- ============================================================ -->
  <!-- Dialog ubah nama                                             -->
  <!-- ============================================================ -->

  <VDialog
    v-model="dialogNama"
    max-width="420"
  >
    <VCard>
      <VCardTitle class="text-h6 font-weight-bold">
        Ubah Nama Tanda Tangan
      </VCardTitle>

      <VCardText class="pt-0">
        <VTextField
          v-model="namaBaru"
          label="Nama tanda tangan"
          density="compact"
          variant="outlined"
          maxlength="60"
          counter
          autofocus
          @keyup.enter="simpanNama"
        />
      </VCardText>

      <VCardText class="d-flex justify-end gap-3 pt-0">
        <VBtn
          color="secondary"
          variant="tonal"
          class="text-none"
          @click="dialogNama = false"
        >
          Batal
        </VBtn>

        <VBtn
          class="text-none"
          :disabled="!namaBaru.trim()"
          @click="simpanNama"
        >
          Simpan
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>
</template>

<style scoped lang="scss">
/* Petak-petak abu-abu, supaya latar yang tembus pandang kelihatan memang
   tembus pandang -- bukan putih yang akan jadi kotak putih di dokumen. */
@mixin petak {
  background-color: rgb(var(--v-theme-surface));
  background-image:
    linear-gradient(45deg, rgba(var(--v-theme-on-surface), 0.06) 25%, transparent 25%),
    linear-gradient(-45deg, rgba(var(--v-theme-on-surface), 0.06) 25%, transparent 25%),
    linear-gradient(45deg, transparent 75%, rgba(var(--v-theme-on-surface), 0.06) 75%),
    linear-gradient(-45deg, transparent 75%, rgba(var(--v-theme-on-surface), 0.06) 75%);
  background-position: 0 0, 0 8px, 8px -8px, -8px 0;
  background-size: 16px 16px;
}

.slot-ttd {
  display: flex;
  flex-direction: column;
  padding: 12px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  block-size: 100%;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;

  &--dipakai {
    border-color: rgb(var(--v-theme-success));
    box-shadow: 0 0 0 1px rgb(var(--v-theme-success)) inset;
  }

  &__kepala {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-block-end: 8px;
    min-block-size: 24px;
  }

  &__gambar {
    @include petak;

    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    block-size: 110px;

    img {
      max-block-size: 94px;
      max-inline-size: 100%;
      object-fit: contain;
    }
  }

  &__kaki {
    display: flex;
    align-items: center;
    gap: 2px;
    margin-block-start: 8px;
  }

  &__penanda {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-block-start: 8px;
    color: rgb(var(--v-theme-success));
  }

  &--tambah {
    align-items: center;
    justify-content: center;
    border-style: dashed;
    color: rgba(var(--v-theme-on-surface), 0.6);
    cursor: pointer;
    min-block-size: 210px;
    text-align: center;

    &:hover {
      border-color: rgb(var(--v-theme-primary));
      color: rgb(var(--v-theme-primary));
    }
  }
}

.kanvas-bungkus {
  @include petak;

  border: 1px dashed rgba(var(--v-border-color), 0.6);
  border-radius: 10px;
}

.kanvas-ttd {
  display: block;
  inline-size: 100%;
  block-size: 220px;
  touch-action: none;
}

.pratinjau-kotak {
  @include petak;

  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  padding: 12px;

  img {
    max-block-size: 150px;
    max-inline-size: 100%;
    object-fit: contain;
  }
}

.petunjuk-foto {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-block-end: 8px;
  padding-inline-start: 20px;
  color: rgba(var(--v-theme-on-surface), 0.72);
}
</style>
