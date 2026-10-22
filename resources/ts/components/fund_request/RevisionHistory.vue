<script setup lang="ts">

/*
|--------------------------------------------------------------------------
| Riwayat revisi dokumen
|--------------------------------------------------------------------------
| Dipakai FPU, Realisasi, Claim, dan Perdin. Keempatnya menyimpan riwayat
| yang sama bentuknya -- satu tabel, satu penyaji di server -- jadi yang
| menampilkannya pun satu, bukan empat salinan yang akan berbeda pada hari
| salah satunya diperbaiki.
|
| DUA HAL YANG DITAMPILKAN, DAN SENGAJA DIPISAH.
|
| Pertama, revisi yang SEDANG berjalan: alasannya ditaruh paling atas dan
| paling menonjol, karena itulah satu-satunya hal yang perlu dibaca pemohon
| saat ia membuka dokumennya -- bukan dicari di antara riwayat.
|
| Kedua, riwayat yang sudah lewat: dibaca orang yang ingin mengerti kenapa
| nominalnya berubah, berbulan-bulan kemudian. Di sana yang penting justru
| daftar perubahannya, bukan alasannya.
|--------------------------------------------------------------------------
*/

import { useI18n } from 'vue-i18n'
import { formatAuditDateTime } from '@/utils/textFormatter'
import type { RevisionEntry } from '@/components/fund_request/revision'

const props = withDefaults(
  defineProps<{
    revisions?: RevisionEntry[]
    openRevision?: RevisionEntry | null

    /* Panel riwayatnya disembunyikan saat belum pernah ada revisi. */
    hideWhenEmpty?: boolean
  }>(),
  {
    revisions: () => [],
    openRevision: null,
    hideWhenEmpty: true,
  },
)

const { t } = useI18n()

/**
 * Nilai yang berubah, siap dibaca.
 *
 * Yang kosong disebut kosong, bukan dibiarkan menjadi ruang hampa: "(kosong)
 * -> 500.000" menjelaskan bahwa kolomnya tadinya belum diisi, sedangkan
 * " -> 500.000" hanya terbaca seperti tampilan yang rusak.
 */
const nilai = (v: unknown): string => {
  if (v === null || v === undefined || v === '')
    return t('common.revision.empty')

  return String(v)
}

const tanggal = (v: string | null): string => (v ? formatAuditDateTime(v) : '-')

/* Dari mana dokumennya dikembalikan: tahap approval, atau meja Finance. */
const asal = (r: RevisionEntry): string => {
  if (r.returned_from_receipt)
    return t('common.revision.fromReceipt')

  if (r.returned_step_order)
    return t('common.revision.returnedAtStep', { step: r.returned_step_label || r.returned_step_order })

  return ''
}
</script>

<template>
  <!--
    Revisi yang sedang berjalan.

    Ditampilkan terpisah dan paling atas. Pemohon yang membuka dokumennya
    sedang mencari satu hal saja: apa yang harus ia perbaiki.
  -->
  <VAlert
    v-if="props.openRevision"
    type="warning"
    variant="tonal"
    class="mb-4"
  >
    <div class="text-subtitle-2 font-weight-bold mb-1">
      {{ t('common.revision.badge', { n: props.openRevision.revision_number }) }}
      &middot;
      {{ t('common.revision.returnedBy') }}
      {{ props.openRevision.returned_by || '-' }}
      <span v-if="asal(props.openRevision)">
        ({{ asal(props.openRevision) }})
      </span>
    </div>

    <div class="text-body-2 mb-2">
      {{ props.openRevision.return_reason }}
    </div>

    <div class="text-caption">
      {{ tanggal(props.openRevision.returned_at) }}
      &middot;
      {{ t('common.revision.waitingForRevision') }}
    </div>
  </VAlert>

  <!-- Riwayat yang sudah lewat. -->
  <div v-if="props.revisions.length || !props.hideWhenEmpty">
    <div class="text-subtitle-1 font-weight-bold mb-2">
      {{ t('common.revision.historyTitle') }}
    </div>

    <div
      v-if="!props.revisions.length"
      class="text-body-2 text-medium-emphasis"
    >
      {{ t('common.revision.historyEmpty') }}
    </div>

    <VCard
      v-for="r in props.revisions"
      :key="r.revision_number"
      variant="outlined"
      class="mb-3 fr-revision-card"
    >
      <VCardText>
        <div class="d-flex flex-wrap align-center gap-2 mb-2">
          <VChip
            size="small"
            color="warning"
            variant="tonal"
          >
            {{ t('common.revision.badge', { n: r.revision_number }) }}
          </VChip>

          <span class="text-body-2">
            {{ t('common.revision.returnedBy') }}
            <strong>{{ r.returned_by || '-' }}</strong>
            <span v-if="asal(r)"> ({{ asal(r) }})</span>
          </span>

          <span class="text-caption text-medium-emphasis">
            {{ tanggal(r.returned_at) }}
          </span>
        </div>

        <div class="mb-3">
          <div class="text-caption text-medium-emphasis">
            {{ t('common.revision.reason') }}
          </div>

          <div class="text-body-2">
            {{ r.return_reason }}
          </div>
        </div>

        <div
          v-if="r.is_open"
          class="text-body-2 text-warning"
        >
          {{ t('common.revision.waitingForRevision') }}
        </div>

        <template v-else>
          <div class="text-body-2 mb-3">
            {{ t('common.revision.resubmittedBy') }}
            <strong>{{ r.resubmitted_by || '-' }}</strong>
            <span class="text-caption text-medium-emphasis">
              &middot; {{ tanggal(r.resubmitted_at) }}
            </span>
          </div>

          <!--
            Daftar perubahannya.

            Dihitung server sekali saat dokumennya diajukan ulang, lalu
            dibaca apa adanya di sini: riwayat yang dihitung ulang setiap
            kali ditampilkan akan ikut bergeser bersama kodenya.
          -->
          <div class="text-caption text-medium-emphasis mb-1">
            {{ t('common.revision.changesTitle') }}
          </div>

          <div
            v-if="!r.changes || r.changes.total === 0"
            class="text-body-2 text-medium-emphasis"
          >
            {{ t('common.revision.changesNone') }}
          </div>

          <template v-else>
            <ul class="fr-revision-changes">
              <li
                v-for="f in r.changes.header"
                :key="`h-${f.field}`"
                class="text-body-2"
              >
                {{ t('common.revision.changeRow', {
                  label: f.label,
                  old: nilai(f.old),
                  new: nilai(f.new),
                }) }}
              </li>

              <template
                v-for="rel in r.changes.relations"
                :key="rel.name"
              >
                <li
                  v-if="rel.added"
                  class="text-body-2"
                >
                  {{ rel.label }}: {{ t('common.revision.rowsAdded', { n: rel.added }) }}
                </li>

                <li
                  v-if="rel.removed"
                  class="text-body-2"
                >
                  {{ rel.label }}: {{ t('common.revision.rowsRemoved', { n: rel.removed }) }}
                </li>

                <li
                  v-if="rel.changed.length"
                  class="text-body-2"
                >
                  {{ rel.label }}: {{ t('common.revision.rowsChanged', { n: rel.changed.length }) }}

                  <ul>
                    <template
                      v-for="baris in rel.changed"
                      :key="`${rel.name}-${baris.id}`"
                    >
                      <li
                        v-for="f in baris.fields"
                        :key="`${rel.name}-${baris.id}-${f.field}`"
                        class="text-body-2"
                      >
                        {{ t('common.revision.changeRow', {
                          label: f.label,
                          old: nilai(f.old),
                          new: nilai(f.new),
                        }) }}
                      </li>
                    </template>
                  </ul>
                </li>
              </template>
            </ul>
          </template>
        </template>

        <!--
          Persetujuan pada putaran itu.

          Satu-satunya tempat tanda tangan yang pernah diberikan orang pada
          putaran sebelumnya masih tercatat: baris approval-nya sendiri sudah
          dibongkar untuk putaran yang baru.
        -->
        <VExpansionPanels
          v-if="r.approvals_before?.length"
          variant="accordion"
          class="mt-3"
        >
          <VExpansionPanel>
            <VExpansionPanelTitle class="text-body-2">
              {{ t('common.revision.previousApprovals') }}
            </VExpansionPanelTitle>

            <VExpansionPanelText>
              <div
                v-for="(a, i) in r.approvals_before"
                :key="i"
                class="text-body-2 d-flex flex-wrap gap-2 mb-1"
              >
                <span class="text-medium-emphasis">
                  {{ a.step_order }}.
                </span>

                <span>{{ a.label || '-' }}</span>

                <span class="text-medium-emphasis">
                  {{ a.approver_name_snapshot || '-' }}
                </span>

                <VChip
                  size="x-small"
                  variant="tonal"
                >
                  {{ a.status }}
                </VChip>
              </div>
            </VExpansionPanelText>
          </VExpansionPanel>
        </VExpansionPanels>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
/*
| Daftar perubahannya menjorok sedikit saja.
|
| Bawaan browser memberi jarak yang jauh lebih lebar, dan pada modal yang
| sempit itu mendorong teksnya menjadi satu kolom tipis yang sukar dibaca.
*/
.fr-revision-changes {
  padding-inline-start: 1.1rem;
  margin-block: 0;
}

.fr-revision-changes ul {
  padding-inline-start: 1.1rem;
}

.fr-revision-card {
  border-color: rgba(var(--v-border-color), 0.16);
}
</style>
