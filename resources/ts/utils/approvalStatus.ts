/*
|--------------------------------------------------------------------------
| Warna status satu baris approval
|--------------------------------------------------------------------------
| STATUS BARIS APPROVAL, BUKAN STATUS DOKUMEN. Keduanya memakai sebagian
| kata yang sama -- APPROVED, REJECTED -- tetapi himpunannya berbeda:
| dokumen tidak pernah berstatus WAITING atau PENDING, dan baris approval
| tidak pernah berstatus DRAFT atau NEED REVISION. Memakai peta warna
| dokumen untuk baris approval membuat WAITING dan PENDING jatuh ke warna
| cadangan yang sama, dan dua keadaan yang paling perlu dibedakan justru
| tampil serupa.
|
| Nilainya mengikuti yang sudah dipakai layar riwayat approval PR, PO, dan
| vendor sejak lama -- bukan pilihan baru. Ketiga layar itu masih memegang
| salinannya masing-masing; yang baru memakai berkas ini.
|
| KENAPA PENDING TETAP KELABU.
|
| PENDING berarti tahapnya belum tiba, jadi belum ada pekerjaan siapa pun
| di sana. Memberinya warna menjadikan seluruh rantai menyala sekaligus,
| dan tahap yang benar-benar menunggu -- satu-satunya yang berarti bagi
| pembacanya -- tenggelam di antaranya.
|--------------------------------------------------------------------------
*/

const PETA: Record<string, string> = {
  /* Sudah ditandatangani. */
  APPROVED: 'success',

  /* Giliran yang sedang berjalan: satu-satunya yang menuntut tindakan. */
  WAITING: 'warning',

  /* Tahapnya belum tiba. */
  PENDING: 'secondary',

  REJECTED: 'error',

  /*
  | Dilewati karena aturan flow-nya, bukan karena orangnya tidak menjawab.
  | Dibedakan dari PENDING: yang ini sudah selesai urusannya.
  */
  SKIPPED: 'info',

  CANCELLED: 'secondary',
}

/**
 * Warna Vuetify untuk status satu baris approval.
 *
 * Nilai kosong dan status yang belum dikenal jatuh ke 'secondary' -- diam,
 * bukan menyala. Status baru yang belum sempat dipetakan lebih baik tidak
 * kelihatan menuntut apa-apa daripada mengaku sebagai sesuatu yang bukan.
 */
export const approvalStatusColor = (status?: string | null): string => {
  if (!status)
    return 'secondary'

  return PETA[status.trim().toUpperCase()] ?? 'secondary'
}
