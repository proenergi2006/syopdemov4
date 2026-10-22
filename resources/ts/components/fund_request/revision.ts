/*
|--------------------------------------------------------------------------
| Bentuk riwayat revisi dokumen
|--------------------------------------------------------------------------
| Sama persis dengan yang dikirim DocumentRevisionService::sajikan() di
| server, dan dipakai keempat modul: FPU, Realisasi, Claim, dan Perdin.
|
| Ditaruh di berkas tersendiri, bukan di dalam komponennya, karena dua
| tempat membutuhkannya: komponen yang menampilkannya, dan kartu rincian
| perdin yang menerimanya sebagai bagian dari dokumennya. Tipe yang
| diumumkan dari dalam <script setup> tidak bisa diimpor siapa pun.
|--------------------------------------------------------------------------
*/

export interface RevisionChangeField {
  field: string
  label: string
  old: unknown
  new: unknown
}

export interface RevisionChangedRow {
  id: number | string | null
  fields: RevisionChangeField[]
}

export interface RevisionChangeRelation {
  name: string
  label: string
  added: number
  removed: number
  changed: RevisionChangedRow[]
}

/**
 * Satu baris rantai approval putaran sebelumnya.
 *
 * Diarsipkan saat dokumennya dikembalikan, karena baris aslinya dibongkar
 * untuk putaran yang baru -- inilah satu-satunya tempat tanda tangan yang
 * pernah diberikan orang pada putaran itu masih tercatat.
 */
export interface RevisionApprovalSnapshot {
  step_order: number | null
  label: string | null
  approver_name_snapshot: string | null
  status: string | null
  approved_at: string | null
  rejected_at: string | null
  notes: string | null
}

export interface RevisionEntry {
  revision_number: number

  returned_by: string | null
  returned_at: string | null
  returned_from_status: string | null
  returned_from_receipt: boolean
  returned_step_order: number | null
  returned_step_label: string | null
  return_reason: string | null

  revised_at: string | null

  resubmitted_by: string | null
  resubmitted_at: string | null

  /** Masih menunggu pemohon mengajukannya ulang. */
  is_open: boolean

  changes: {
    total: number
    header: RevisionChangeField[]
    relations: RevisionChangeRelation[]
  } | null

  approvals_before: RevisionApprovalSnapshot[]
}
