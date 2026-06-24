export const tglIndo = (dateStr: string) => {
  if (!dateStr) return '-'

  const date = new Date(dateStr)

  const bulan = [
    'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
  ]

  const d = date.getDate()
  const m = bulan[date.getMonth()]
  const y = date.getFullYear()

  return `${d} ${m} ${y}`
}

export const formatRangeDate = (first: string, last: string) => {
  if (!first || !last) return '-'

  const d1 = new Date(first)
  const d2 = new Date(last)

  const sameDay =
    d1.getDate() === d2.getDate() &&
    d1.getMonth() === d2.getMonth() &&
    d1.getFullYear() === d2.getFullYear()

  const sameMonth =
    d1.getMonth() === d2.getMonth() &&
    d1.getFullYear() === d2.getFullYear()

  if (sameDay) {
    return tglIndo(first)
  }

  if (sameMonth) {
    return `${d1.getDate()} - ${d2.getDate()} ${tglIndo(last).split(' ')[1]} ${d2.getFullYear()}`
  }

  return `${tglIndo(first)} - ${tglIndo(last)}`
}