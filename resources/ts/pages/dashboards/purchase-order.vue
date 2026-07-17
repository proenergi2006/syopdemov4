<script setup lang="ts">
import axios from '@axios'
import VueApexCharts from 'vue3-apexcharts'
import {
  computed,
  onMounted,
  ref,
} from 'vue'
import { useRouter } from 'vue-router'

type PeriodType =
  | 'day'
  | 'week'
  | 'month'
  | 'year'
  | 'range'

type AlertType =
  | 'success'
  | 'warning'
  | 'info'
  | 'error'

type BreakdownMetric = 'count' | 'amount'
type CurrencyFormatMode = 'short' | 'long'
type ItemPriceVarianceType = 'increase' | 'decrease' | 'same'
type ValueComparisonVarianceType = 'efficiency' | 'increase' | 'same'

interface DashboardBreakdownItem {
  id: number | null
  name: string

  pr_count: number
  pr_amount: number

  po_count: number
  po_amount: number
}

interface SelectOption {
  title: string
  value: number | string
}

interface OptionRecord {
  id?: number | string
  value?: number | string
  cabang_id?: number | string
  department_id?: number | string

  title?: string
  label?: string
  name?: string
  nama?: string
  nama_cabang?: string
  nama_department?: string
  nama_departemen?: string
  department_name?: string
}

interface DashboardAccess {
  scope_view: string

  cabang_id: number | null
  cabang_name: string | null

  department_id: number | null
  department_name: string | null

  can_filter_cabang: boolean
  can_filter_department: boolean
}

interface DashboardSummary {
  total_pr: number
  total_pr_amount: number
  total_po: number
  total_po_amount: number

  approved_pr: number
  pr_not_ordered: number
  pending_po_approval: number
  outstanding_receipt: number
  rejected_po: number

  conversion_rate: number
}

interface DashboardTrend {
  label: string
  pr_amount: number
  po_amount: number
}

interface DashboardStatus {
  status: string
  label: string
  total: number
}

interface AttentionItem {
  public_id: string
  po_number: string
  po_date: string | null
  cabang_name: string | null
  department_name: string | null
  vendor_name: string | null
  total_amount: number
  status: string
  age_days: number
  reason: string
}

interface DashboardResponse {
  message: string

  data: {
    access: DashboardAccess
    summary: DashboardSummary
    trend: DashboardTrend[]
    statuses: DashboardStatus[]
    attention_items: AttentionItem[]
    breakdown: {
      by_cabang: DashboardBreakdownItem[]
      by_department: DashboardBreakdownItem[]
    }
    item_price_comparison?: ItemPriceComparison
    value_comparison?: ValueComparison
  }
}

interface ManagementInsight {
  type: AlertType
  icon: string
  title: string
  message: string
}

interface ExecutiveBreakdownItem {
  id: number | null
  name: string
  prValue: number
  poValue: number
  totalValue: number
}

interface ItemPriceComparisonSummary {
  total_items: number
  increased_items: number
  decreased_items: number
  unchanged_items: number
  average_difference_percent: number
  total_difference_amount: number
}

interface ItemPriceComparisonItem {
  purchase_request_item_id: number | null
  pr_number: string
  po_numbers: string
  item_name: string
  pr_unit_price: number
  po_unit_price: number
  min_po_unit_price: number
  max_po_unit_price: number
  price_difference: number
  price_difference_percent: number
  variance_type: ItemPriceVarianceType
  po_count: number
  po_qty: number
  po_amount: number
}

interface ItemPriceComparison {
  summary: ItemPriceComparisonSummary
  items: ItemPriceComparisonItem[]
}

interface ValueComparisonSummary {
  completed_pr_count: number
  efficiency_pr_count: number
  increase_pr_count: number
  same_pr_count: number
  total_pr_amount: number
  total_po_amount: number
  efficiency_amount: number
  increase_amount: number
  net_difference_amount: number
  average_difference_percent: number
}

interface ValueComparisonItem {
  purchase_request_id: number | null
  pr_number: string
  pr_date: string | null
  status_po: string
  po_numbers: string
  pr_amount: number
  po_amount: number
  difference_amount: number
  difference_raw: number
  difference_percent: number
  variance_type: ValueComparisonVarianceType
  variance_label: string
}

interface ValueComparison {
  summary: ValueComparisonSummary
  items: ValueComparisonItem[]
}

const router = useRouter()

/*
|--------------------------------------------------------------------------
| Default Date
|--------------------------------------------------------------------------
*/

const today = new Date()
const currentYear = today.getFullYear()

const selectedPeriod = ref<PeriodType>('month')
const selectedDate = ref(getLocalDateValue(today))
const selectedWeek = ref(getCurrentWeekValue(today))
const selectedMonth = ref(getMonthValue(today))
const selectedYear = ref(currentYear)

const startDate = ref(getFirstDateOfMonth(today))
const endDate = ref(getLocalDateValue(today))

const selectedCabangId = ref<number | string | null>(null)
const selectedDepartmentId = ref<number | string | null>(null)

/*
|--------------------------------------------------------------------------
| Filter Options
|--------------------------------------------------------------------------
*/

const periodOptions = [
  {
    title: 'Harian',
    value: 'day',
    icon: 'mdi-calendar-today-outline',
  },
  {
    title: 'Mingguan',
    value: 'week',
    icon: 'mdi-calendar-week-outline',
  },
  {
    title: 'Bulanan',
    value: 'month',
    icon: 'mdi-calendar-month-outline',
  },
  {
    title: 'Tahunan',
    value: 'year',
    icon: 'mdi-calendar-blank-multiple',
  },
  {
    title: 'Rentang Tanggal',
    value: 'range',
    icon: 'mdi-calendar-range',
  },
]

const yearOptions = Array.from(
  {
    length: 10,
  },
  (_, index) => {
    const year = currentYear - index

    return {
      title: String(year),
      value: year,
    }
  },
)

const cabangOptions = ref<SelectOption[]>([])
const departmentOptions = ref<SelectOption[]>([])

/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
*/

const isLoading = ref(false)
const isLoadingOptions = ref(false)

const errorMessage = ref('')
const lastUpdatedAt = ref<Date | null>(null)
const appliedPeriodLabel = ref('')

const breakdownByCabang = ref<
  DashboardBreakdownItem[]
>([])

const breakdownByDepartment = ref<
  DashboardBreakdownItem[]
>([])

const cabangBreakdownMetric
  = ref<BreakdownMetric>('amount')

const departmentBreakdownMetric
  = ref<BreakdownMetric>('amount')

const breakdownMetricOptions = [
  {
    title: 'Nilai Transaksi',
    value: 'amount',
  },
  {
    title: 'Jumlah Dokumen',
    value: 'count',
  },
]

const executiveChartSubtitle = computed(() => {
  return appliedPeriodLabel.value || selectedPeriodDescription.value
})

const access = ref<DashboardAccess>({
  scope_view: 'NONE',

  cabang_id: null,
  cabang_name: null,

  department_id: null,
  department_name: null,

  can_filter_cabang: false,
  can_filter_department: false,
})

function synchronizeFiltersWithAccess(): void {
  /*
   * Cabang terkunci.
   */
  if (!access.value.can_filter_cabang) {
    selectedCabangId.value
      = access.value.cabang_id

    if (
      access.value.cabang_id
      && access.value.cabang_name
    ) {
      cabangOptions.value = [
        {
          value: access.value.cabang_id,
          title: access.value.cabang_name,
        },
      ]
    }
  }

  /*
   * Departemen terkunci.
   */
  if (!access.value.can_filter_department) {
    selectedDepartmentId.value
      = access.value.department_id

    if (
      access.value.department_id
      && access.value.department_name
    ) {
      departmentOptions.value = [
        {
          value: access.value.department_id,
          title: access.value.department_name,
        },
      ]
    }
  }
}

const summary = ref<DashboardSummary>({
  total_pr: 0,
  total_pr_amount: 0,
  total_po: 0,
  total_po_amount: 0,

  approved_pr: 0,
  pr_not_ordered: 0,
  pending_po_approval: 0,
  outstanding_receipt: 0,
  rejected_po: 0,

  conversion_rate: 0,
})

const trend = ref<DashboardTrend[]>([])
const statuses = ref<DashboardStatus[]>([])
const attentionItems = ref<AttentionItem[]>([])

const defaultItemPriceComparison = (): ItemPriceComparison => ({
  summary: {
    total_items: 0,
    increased_items: 0,
    decreased_items: 0,
    unchanged_items: 0,
    average_difference_percent: 0,
    total_difference_amount: 0,
  },
  items: [],
})

const itemPriceComparison = ref<ItemPriceComparison>(
  defaultItemPriceComparison(),
)

const defaultValueComparison = (): ValueComparison => ({
  summary: {
    completed_pr_count: 0,
    efficiency_pr_count: 0,
    increase_pr_count: 0,
    same_pr_count: 0,
    total_pr_amount: 0,
    total_po_amount: 0,
    efficiency_amount: 0,
    increase_amount: 0,
    net_difference_amount: 0,
    average_difference_percent: 0,
  },
  items: [],
})

const valueComparison = ref<ValueComparison>(
  defaultValueComparison(),
)

const visibleStatuses = computed(() => {
  return statuses.value.filter(item => {
    return !isExcludedDashboardStatus(item.status)
  })
})

const visibleAttentionItems = computed(() => {
  return attentionItems.value.filter(item => {
    return !isExcludedDashboardStatus(item.status)
  })
})

const hasItemPriceComparison = computed(() => {
  return itemPriceComparison.value.items.length > 0
})

const topItemPriceComparisonItems = computed(() => {
  return itemPriceComparison.value.items.slice(0, 8)
})

const itemPriceComparisonSummaryCards = computed(() => [
  {
    title: 'Item dibandingkan',
    value: formatNumber(itemPriceComparison.value.summary.total_items),
    subtitle: 'Item PR yang sudah terealisasi menjadi PO',
    color: 'primary',
    icon: 'mdi-format-list-checks',
  },
  {
    title: 'Harga naik',
    value: formatNumber(itemPriceComparison.value.summary.increased_items),
    subtitle: 'Harga PO lebih tinggi dari PR',
    color: 'error',
    icon: 'mdi-trending-up',
  },
  {
    title: 'Harga turun',
    value: formatNumber(itemPriceComparison.value.summary.decreased_items),
    subtitle: 'Harga PO lebih rendah dari PR',
    color: 'success',
    icon: 'mdi-trending-down',
  },
  {
    title: 'Rata-rata perubahan',
    value: formatSignedPercent(itemPriceComparison.value.summary.average_difference_percent),
    subtitle: 'Selisih harga rata-rata PR ke PO',
    color: itemPriceComparison.value.summary.average_difference_percent > 0
      ? 'warning'
      : 'info',
    icon: 'mdi-percent-outline',
  },
])

const hasValueComparison = computed(() => {
  return valueComparison.value.items.length > 0
})

const topValueComparisonItems = computed(() => {
  return valueComparison.value.items.slice(0, 8)
})

const valueComparisonSummaryCards = computed(() => {
  const netDifferenceAmount = Number(
    valueComparison.value.summary.net_difference_amount ?? 0,
  )

  return [
    {
      title: 'PR Completed',
      value: formatNumber(valueComparison.value.summary.completed_pr_count),
      subtitle: 'PR yang sudah selesai menjadi PO',
      color: 'primary',
      icon: 'mdi-file-check-outline',
    },
    {
      title: 'Efisiensi Nilai',
      value: formatCompactCurrency(
        valueComparison.value.summary.efficiency_amount,
        'short',
      ),
      subtitle: `${formatNumber(valueComparison.value.summary.efficiency_pr_count)} PR nilai PO lebih rendah`,
      color: 'success',
      icon: 'mdi-trending-down',
    },
    {
      title: 'Kenaikan Nilai',
      value: formatCompactCurrency(
        valueComparison.value.summary.increase_amount,
        'short',
      ),
      subtitle: `${formatNumber(valueComparison.value.summary.increase_pr_count)} PR nilai PO lebih tinggi`,
      color: 'error',
      icon: 'mdi-trending-up',
    },
    {
      title: netDifferenceAmount >= 0
        ? 'Net Efisiensi'
        : 'Net Kenaikan',
      value: formatCompactCurrency(
        Math.abs(netDifferenceAmount),
        'short',
      ),
      subtitle: `Rata-rata ${formatSignedPercent(valueComparison.value.summary.average_difference_percent)}`,
      color: netDifferenceAmount >= 0
        ? 'success'
        : 'error',
      icon: netDifferenceAmount >= 0
        ? 'mdi-cash-check'
        : 'mdi-cash-alert',
    },
  ]
})

/*
|--------------------------------------------------------------------------
| Computed Filter
|--------------------------------------------------------------------------
*/

const showCabangFilter = computed(() => {
  return access.value.can_filter_cabang
})

const showDepartmentFilter = computed(() => {
  return access.value.can_filter_department
})

const isFilterValid = computed(() => {
  if (selectedPeriod.value === 'day')
    return Boolean(selectedDate.value)

  if (selectedPeriod.value === 'week')
    return Boolean(selectedWeek.value)

  if (selectedPeriod.value === 'month')
    return Boolean(selectedMonth.value)

  if (selectedPeriod.value === 'year')
    return Boolean(selectedYear.value)

  if (selectedPeriod.value === 'range') {
    return Boolean(
      startDate.value
      && endDate.value
      && startDate.value <= endDate.value,
    )
  }

  return false
})

const selectedPeriodDescription = computed(() => {
  if (selectedPeriod.value === 'day')
    return formatDate(selectedDate.value)

  if (selectedPeriod.value === 'week')
    return formatWeek(selectedWeek.value)

  if (selectedPeriod.value === 'month')
    return formatMonth(selectedMonth.value)

  if (selectedPeriod.value === 'year')
    return `Tahun ${selectedYear.value}`

  if (selectedPeriod.value === 'range') {
    if (!startDate.value || !endDate.value)
      return '-'

    return `${formatDate(startDate.value)} sampai ${formatDate(endDate.value)}`
  }

  return '-'
})

/*
|--------------------------------------------------------------------------
| Statistic Cards
|--------------------------------------------------------------------------
*/

const statisticCards = computed(() => [
  {
    title: 'Total Purchase Requisition',
    shortTitle: 'PR',
    value: formatNumber(summary.value.total_pr),
    fullValue: null,
    subtitle: 'Jumlah kebutuhan yang diajukan',
    icon: 'mdi-file-document-edit-outline',
    color: 'primary',
  },
  {
    title: 'Nilai Purchase Requisition',
    shortTitle: 'Nilai PR',
    value: formatCompactCurrency(
      summary.value.total_pr_amount,
    ),
    fullValue: formatCurrency(
      summary.value.total_pr_amount,
    ),
    subtitle: 'Total nilai kebutuhan pembelian',
    icon: 'mdi-cash-clock',
    color: 'info',
  },
  {
    title: 'Total Purchase Order',
    shortTitle: 'PO',
    value: formatNumber(summary.value.total_po),
    fullValue: null,
    subtitle: 'Jumlah pesanan yang diterbitkan',
    icon: 'mdi-file-sign',
    color: 'success',
  },
  {
    title: 'Nilai Purchase Order',
    shortTitle: 'Nilai PO',
    value: formatCompactCurrency(
      summary.value.total_po_amount,
    ),
    fullValue: formatCurrency(
      summary.value.total_po_amount,
    ),
    subtitle: 'Total nilai realisasi pembelian',
    icon: 'mdi-cash-check',
    color: 'warning',
  },
])

const operationalStatistics = computed(() => [
  {
    title: 'PR Belum Menjadi PO',
    value: formatNumber(summary.value.pr_not_ordered),
    icon: 'mdi-file-document-alert-outline',
    color: 'warning',
  },
  {
    title: 'PO Menunggu Persetujuan',
    value: formatNumber(
      summary.value.pending_po_approval,
    ),
    icon: 'mdi-account-clock-outline',
    color: 'warning',
  },
  {
    title: 'Outstanding Receipt',
    value: formatNumber(
      summary.value.outstanding_receipt,
    ),
    icon: 'mdi-package-variant',
    color: 'info',
  },
  {
    title: 'Konversi PR ke PO',
    value: `${formatDecimal(summary.value.conversion_rate)}%`,
    icon: 'mdi-swap-horizontal-circle-outline',
    color: 'success',
  },
])

/*
|--------------------------------------------------------------------------
| Management Insight
|--------------------------------------------------------------------------
*/

const managementInsight = computed<ManagementInsight>(() => {
  const messages: string[] = []

  if (summary.value.pr_not_ordered > 0) {
    messages.push(
      `${formatNumber(
        summary.value.pr_not_ordered,
      )} PR yang telah disetujui belum diproses menjadi PO`,
    )
  }

  if (summary.value.pending_po_approval > 0) {
    messages.push(
      `${formatNumber(
        summary.value.pending_po_approval,
      )} PO masih menunggu persetujuan`,
    )
  }

  if (summary.value.outstanding_receipt > 0) {
    messages.push(
      `${formatNumber(
        summary.value.outstanding_receipt,
      )} PO belum selesai diterima`,
    )
  }

  if (
    summary.value.total_pr > 0
    && summary.value.conversion_rate < 80
  ) {
    messages.push(
      `tingkat konversi PR ke PO baru mencapai ${formatDecimal(
        summary.value.conversion_rate,
      )}%`,
    )
  }

  if (messages.length === 0) {
    return {
      type: 'success',
      icon: 'mdi-check-decagram-outline',
      title: 'Proses procurement dalam kondisi terkendali',
      message:
        'Belum ditemukan PR atau PO yang membutuhkan perhatian khusus pada periode terpilih.',
    }
  }

  return {
    type: 'warning',
    icon: 'mdi-alert-outline',
    title: 'Perlu perhatian management',
    message: `${messages.join('. ')}.`,
  }
})

/*
|--------------------------------------------------------------------------
| PR vs PO Comparison Chart
|--------------------------------------------------------------------------
*/

const cabangBreakdownSeries = computed(() => {
  const useAmount
    = cabangBreakdownMetric.value === 'amount'

  return [
    {
      name: 'Purchase Requisition',
      data: breakdownByCabang.value.map(item => {
        return useAmount
          ? Number(item.pr_amount ?? 0)
          : Number(item.pr_count ?? 0)
      }),
    },
    {
      name: 'Purchase Order',
      data: breakdownByCabang.value.map(item => {
        return useAmount
          ? Number(item.po_amount ?? 0)
          : Number(item.po_count ?? 0)
      }),
    },
  ]
})

const cabangBreakdownMaxValue = computed(() => {
  const useAmount
    = cabangBreakdownMetric.value === 'amount'

  return getMaxNumber(
    breakdownByCabang.value.flatMap(item => [
      useAmount ? item.pr_amount : item.pr_count,
      useAmount ? item.po_amount : item.po_count,
    ]),
  )
})

const cabangBreakdownOptions = computed(() => {
  const useAmount
    = cabangBreakdownMetric.value === 'amount'

  const maxValue = cabangBreakdownMaxValue.value

  return {
    chart: {
      type: 'bar',
      toolbar: {
        show: false,
      },
      parentHeightOffset: 0,
      animations: {
        enabled: true,
        easing: 'easeinout',
        speed: 650,
        animateGradually: {
          enabled: true,
          delay: 80,
        },
      },
    },

    plotOptions: {
      bar: {
        horizontal: true,
        barHeight: '54%',
        borderRadius: 6,
        borderRadiusApplication: 'end',
      },
    },

    dataLabels: {
      enabled: true,
      offsetX: 8,
      style: {
        fontSize: '11px',
        fontWeight: 700,
      },
      formatter: (value: number) => {
        return useAmount
          ? formatChartCurrency(value, maxValue)
          : `${formatNumber(value)} dok.`
      },
    },

    xaxis: {
      categories: breakdownByCabang.value.map(
        item => item.name,
      ),
      tickAmount: 5,
      labels: {
        formatter: (value: number) => {
          return useAmount
            ? formatChartCurrency(value, maxValue)
            : formatNumber(value)
        },
      },
      title: {
        text: useAmount
          ? buildCurrencyAxisTitle(maxValue)
          : 'Jumlah dokumen',
      },
    },

    yaxis: {
      labels: {
        minWidth: 110,
        maxWidth: 180,
        style: {
          fontSize: '12px',
          fontWeight: 600,
        },
      },
    },

    tooltip: {
      shared: true,
      intersect: false,
      y: {
        formatter: (value: number) => {
          return useAmount
            ? formatCurrency(value)
            : `${formatNumber(value)} dokumen`
        },
      },
    },

    legend: {
      position: 'top',
      horizontalAlign: 'right',
      fontSize: '12px',
      markers: {
        width: 9,
        height: 9,
        radius: 3,
      },
    },

    grid: {
      borderColor:
        'rgba(var(--v-border-color), 0.22)',
      strokeDashArray: 4,
      padding: {
        left: 8,
        right: 28,
        top: 0,
        bottom: 6,
      },
    },

    responsive: [
      {
        breakpoint: 768,
        options: {
          dataLabels: {
            enabled: false,
          },
          legend: {
            position: 'bottom',
            horizontalAlign: 'center',
          },
          yaxis: {
            labels: {
              maxWidth: 130,
            },
          },
          grid: {
            padding: {
              left: 0,
              right: 8,
            },
          },
        },
      },
    ],

    noData: {
      text: 'Belum ada data cabang',
    },
  }
})

const cabangBreakdownChartHeight = computed(() => {
  return Math.max(
    360,
    breakdownByCabang.value.length * 86,
  )
})

const departmentBreakdownSeries = computed(() => {
  const useAmount
    = departmentBreakdownMetric.value === 'amount'

  return [
    {
      name: 'Purchase Requisition',
      data: breakdownByDepartment.value.map(item => {
        return useAmount
          ? Number(item.pr_amount ?? 0)
          : Number(item.pr_count ?? 0)
      }),
    },
    {
      name: 'Purchase Order',
      data: breakdownByDepartment.value.map(item => {
        return useAmount
          ? Number(item.po_amount ?? 0)
          : Number(item.po_count ?? 0)
      }),
    },
  ]
})

const departmentBreakdownMaxValue = computed(() => {
  const useAmount
    = departmentBreakdownMetric.value === 'amount'

  return getMaxNumber(
    breakdownByDepartment.value.flatMap(item => [
      useAmount ? item.pr_amount : item.pr_count,
      useAmount ? item.po_amount : item.po_count,
    ]),
  )
})

const departmentBreakdownOptions = computed(() => {
  const useAmount
    = departmentBreakdownMetric.value === 'amount'

  const maxValue = departmentBreakdownMaxValue.value

  return {
    chart: {
      type: 'bar',
      toolbar: {
        show: false,
      },
      parentHeightOffset: 0,
      animations: {
        enabled: true,
        easing: 'easeinout',
        speed: 650,
        animateGradually: {
          enabled: true,
          delay: 80,
        },
      },
    },

    plotOptions: {
      bar: {
        horizontal: true,
        barHeight: '54%',
        borderRadius: 6,
        borderRadiusApplication: 'end',
      },
    },

    dataLabels: {
      enabled: true,
      offsetX: 8,
      style: {
        fontSize: '11px',
        fontWeight: 700,
      },
      formatter: (value: number) => {
        return useAmount
          ? formatChartCurrency(value, maxValue)
          : `${formatNumber(value)} dok.`
      },
    },

    xaxis: {
      categories:
        breakdownByDepartment.value.map(
          item => item.name,
        ),
      tickAmount: 5,
      labels: {
        formatter: (value: number) => {
          return useAmount
            ? formatChartCurrency(value, maxValue)
            : formatNumber(value)
        },
      },
      title: {
        text: useAmount
          ? buildCurrencyAxisTitle(maxValue)
          : 'Jumlah dokumen',
      },
    },

    yaxis: {
      labels: {
        minWidth: 120,
        maxWidth: 190,
        style: {
          fontSize: '12px',
          fontWeight: 600,
        },
      },
    },

    tooltip: {
      shared: true,
      intersect: false,
      y: {
        formatter: (value: number) => {
          return useAmount
            ? formatCurrency(value)
            : `${formatNumber(value)} dokumen`
        },
      },
    },

    legend: {
      position: 'top',
      horizontalAlign: 'right',
      fontSize: '12px',
      markers: {
        width: 9,
        height: 9,
        radius: 3,
      },
    },

    grid: {
      borderColor:
        'rgba(var(--v-border-color), 0.22)',
      strokeDashArray: 4,
      padding: {
        left: 8,
        right: 28,
        top: 0,
        bottom: 6,
      },
    },

    responsive: [
      {
        breakpoint: 768,
        options: {
          dataLabels: {
            enabled: false,
          },
          legend: {
            position: 'bottom',
            horizontalAlign: 'center',
          },
          yaxis: {
            labels: {
              maxWidth: 135,
            },
          },
          grid: {
            padding: {
              left: 0,
              right: 8,
            },
          },
        },
      },
    ],

    noData: {
      text: 'Belum ada data departemen',
    },
  }
})

const departmentBreakdownChartHeight = computed(() => {
  return Math.max(
    360,
    breakdownByDepartment.value.length * 86,
  )
})


const executiveCabangBreakdownItems = computed<ExecutiveBreakdownItem[]>(() => {
  return normalizeExecutiveBreakdownItems(
    breakdownByCabang.value,
    cabangBreakdownMetric.value,
  )
})

const executiveDepartmentBreakdownItems = computed<ExecutiveBreakdownItem[]>(() => {
  return normalizeExecutiveBreakdownItems(
    breakdownByDepartment.value,
    departmentBreakdownMetric.value,
  )
})

const comparisonChartSeries = computed(() => [
  Number(comparisonRealizationRate.value),
])

const comparisonChartHeight = computed(() => {
  return 280
})

const comparisonRealizationRate = computed(() => {
  const prAmount = Number(
    summary.value.total_pr_amount ?? 0,
  )

  const poAmount = Number(
    summary.value.total_po_amount ?? 0,
  )

  if (prAmount <= 0)
    return 0

  return Math.min((poAmount / prAmount) * 100, 999)
})

const comparisonGapAmount = computed(() => {
  return Math.max(
    Number(summary.value.total_pr_amount ?? 0)
    - Number(summary.value.total_po_amount ?? 0),
    0,
  )
})

const comparisonChartOptions = computed(() => {
  return {
    chart: {
      type: 'radialBar',
      toolbar: {
        show: false,
      },
      sparkline: {
        enabled: false,
      },
      parentHeightOffset: 0,
      animations: {
        enabled: true,
        easing: 'easeinout',
        speed: 700,
      },
    },

    plotOptions: {
      radialBar: {
        startAngle: -130,
        endAngle: 130,
        hollow: {
          size: '66%',
        },
        track: {
          background: 'rgba(var(--v-theme-on-surface), 0.08)',
          strokeWidth: '100%',
        },
        dataLabels: {
          name: {
            show: true,
            offsetY: 38,
            fontSize: '13px',
            fontWeight: 700,
            color: 'rgba(var(--v-theme-on-surface), 0.68)',
          },
          value: {
            show: true,
            offsetY: -8,
            fontSize: '30px',
            fontWeight: 800,
            formatter: (value: number) => {
              return `${formatDecimal(value)}%`
            },
          },
          total: {
            show: true,
            label: 'Realisasi',
            formatter: () => {
              return `${formatDecimal(comparisonRealizationRate.value)}%`
            },
          },
        },
      },
    },

    labels: [
      'PO terhadap PR',
    ],

    stroke: {
      lineCap: 'round',
    },

    tooltip: {
      enabled: true,
      y: {
        formatter: (value: number) => {
          return `${formatDecimal(value)}%`
        },
      },
    },
  }
})

/*
|--------------------------------------------------------------------------
| Trend Chart
|--------------------------------------------------------------------------
*/

const trendChartSeries = computed(() => [
  {
    name: 'Nilai PR',
    data: trend.value.map(
      item => Number(item.pr_amount ?? 0),
    ),
  },
  {
    name: 'Nilai PO',
    data: trend.value.map(
      item => Number(item.po_amount ?? 0),
    ),
  },
])

const trendChartMaxValue = computed(() => {
  return getMaxNumber(
    trend.value.flatMap(item => [
      item.pr_amount,
      item.po_amount,
    ]),
  )
})

const trendChartHeight = computed(() => {
  if (trend.value.length > 12)
    return 390

  return 350
})

const trendChartOptions = computed(() => {
  const maxValue = trendChartMaxValue.value

  return {
    chart: {
      type: 'line',
      stacked: false,
      toolbar: {
        show: false,
      },
      zoom: {
        enabled: false,
      },
      parentHeightOffset: 0,
      animations: {
        enabled: true,
        easing: 'easeinout',
        speed: 650,
        animateGradually: {
          enabled: true,
          delay: 100,
        },
        dynamicAnimation: {
          enabled: true,
          speed: 350,
        },
      },
    },

    stroke: {
      curve: 'smooth',
      width: 3,
    },

    fill: {
      type: 'gradient',
      gradient: {
        shadeIntensity: 0.55,
        opacityFrom: 0.32,
        opacityTo: 0.05,
        stops: [0, 90, 100],
      },
    },

    dataLabels: {
      enabled: false,
    },

    markers: {
      size: trend.value.length <= 12 ? 4 : 0,
      strokeWidth: 2,
      hover: {
        size: 6,
      },
    },

    xaxis: {
      categories: trend.value.map(
        item => item.label,
      ),
      labels: {
        rotate: trend.value.length > 8 ? -35 : 0,
        rotateAlways: trend.value.length > 10,
        trim: false,
        hideOverlappingLabels: true,
        maxHeight: 80,
        style: {
          fontSize: '12px',
          fontWeight: 600,
        },
      },
      axisBorder: {
        show: false,
      },
      axisTicks: {
        show: false,
      },
    },

    yaxis: {
      min: 0,
      forceNiceScale: true,
      tickAmount: 5,
      labels: {
        minWidth: 70,
        formatter: (value: number) => {
          return formatChartCurrency(value, maxValue)
        },
      },
      title: {
        text: buildCurrencyAxisTitle(maxValue),
      },
    },

    tooltip: {
      shared: true,
      intersect: false,
      y: {
        formatter: (value: number) => {
          return formatCurrency(value)
        },
      },
    },

    legend: {
      position: 'top',
      horizontalAlign: 'right',
      fontSize: '13px',
      markers: {
        width: 9,
        height: 9,
        radius: 3,
      },
      itemMargin: {
        horizontal: 10,
      },
    },

    grid: {
      borderColor:
        'rgba(var(--v-border-color), 0.22)',
      strokeDashArray: 4,
      xaxis: {
        lines: {
          show: false,
        },
      },
      yaxis: {
        lines: {
          show: true,
        },
      },
      padding: {
        left: 4,
        right: 16,
        top: 0,
        bottom: 0,
      },
    },

    responsive: [
      {
        breakpoint: 768,
        options: {
          legend: {
            position: 'bottom',
            horizontalAlign: 'center',
          },
          yaxis: {
            title: {
              text: undefined,
            },
            labels: {
              minWidth: 56,
            },
          },
          markers: {
            size: 3,
          },
        },
      },
    ],

    noData: {
      text: 'Belum ada data tren PR dan PO',
      align: 'center',
      verticalAlign: 'middle',
    },
  }
})

const hasComparisonData = computed(() => {
  return (
    summary.value.total_pr_amount > 0
    || summary.value.total_po_amount > 0
  )
})

const hasTrendData = computed(() => {
  return trend.value.some(item => {
    return (
      Number(item.pr_amount) > 0
      || Number(item.po_amount) > 0
    )
  })
})

const totalStatus = computed(() => {
  return visibleStatuses.value.reduce(
    (total, item) => {
      return total + Number(item.total ?? 0)
    },
    0,
  )
})

function normalizeExecutiveBreakdownItems(
  items: DashboardBreakdownItem[],
  metric: BreakdownMetric,
): ExecutiveBreakdownItem[] {
  return items
    .map(item => {
      const prValue = metric === 'amount'
        ? Number(item.pr_amount ?? 0)
        : Number(item.pr_count ?? 0)

      const poValue = metric === 'amount'
        ? Number(item.po_amount ?? 0)
        : Number(item.po_count ?? 0)

      return {
        id: item.id,
        name: item.name || 'Belum Ditentukan',
        prValue,
        poValue,
        totalValue: prValue + poValue,
      }
    })
    .filter(item => item.totalValue > 0)
    .sort((a, b) => b.totalValue - a.totalValue)
    .slice(0, 8)
}

function getBreakdownPercent(
  value: number,
  total: number,
): number {
  if (total <= 0)
    return 0

  return Math.min(
    Math.max((Number(value || 0) / total) * 100, 0),
    100,
  )
}

function formatBreakdownMetricValue(
  value: number,
  metric: BreakdownMetric,
): string {
  if (metric === 'count')
    return `${formatNumber(value)} dok.`

  return formatCompactCurrency(value, 'short')
}

function normalizeItemPriceComparison(
  payload?: ItemPriceComparison | null,
): ItemPriceComparison {
  const defaultValue = defaultItemPriceComparison()

  if (!payload || typeof payload !== 'object')
    return defaultValue

  const summary = payload.summary || defaultValue.summary

  return {
    summary: {
      total_items: Number(summary.total_items ?? 0),
      increased_items: Number(summary.increased_items ?? 0),
      decreased_items: Number(summary.decreased_items ?? 0),
      unchanged_items: Number(summary.unchanged_items ?? 0),
      average_difference_percent: Number(summary.average_difference_percent ?? 0),
      total_difference_amount: Number(summary.total_difference_amount ?? 0),
    },
    items: Array.isArray(payload.items)
      ? payload.items.map(item => ({
        purchase_request_item_id: item.purchase_request_item_id ?? null,
        pr_number: String(item.pr_number ?? '-'),
        po_numbers: String(item.po_numbers ?? '-'),
        item_name: String(item.item_name ?? 'Item tanpa nama'),
        pr_unit_price: Number(item.pr_unit_price ?? 0),
        po_unit_price: Number(item.po_unit_price ?? 0),
        min_po_unit_price: Number(item.min_po_unit_price ?? 0),
        max_po_unit_price: Number(item.max_po_unit_price ?? 0),
        price_difference: Number(item.price_difference ?? 0),
        price_difference_percent: Number(item.price_difference_percent ?? 0),
        variance_type: normalizeItemPriceVarianceType(item.variance_type),
        po_count: Number(item.po_count ?? 0),
        po_qty: Number(item.po_qty ?? 0),
        po_amount: Number(item.po_amount ?? 0),
      }))
      : [],
  }
}

function normalizeValueComparison(
  payload?: ValueComparison | null,
): ValueComparison {
  const defaultValue = defaultValueComparison()

  if (!payload || typeof payload !== 'object')
    return defaultValue

  const summary = payload.summary || defaultValue.summary

  return {
    summary: {
      completed_pr_count: Number(summary.completed_pr_count ?? 0),
      efficiency_pr_count: Number(summary.efficiency_pr_count ?? 0),
      increase_pr_count: Number(summary.increase_pr_count ?? 0),
      same_pr_count: Number(summary.same_pr_count ?? 0),
      total_pr_amount: Number(summary.total_pr_amount ?? 0),
      total_po_amount: Number(summary.total_po_amount ?? 0),
      efficiency_amount: Number(summary.efficiency_amount ?? 0),
      increase_amount: Number(summary.increase_amount ?? 0),
      net_difference_amount: Number(summary.net_difference_amount ?? 0),
      average_difference_percent: Number(summary.average_difference_percent ?? 0),
    },
    items: Array.isArray(payload.items)
      ? payload.items.map(item => ({
        purchase_request_id: item.purchase_request_id ?? null,
        pr_number: String(item.pr_number ?? '-'),
        pr_date: item.pr_date ?? null,
        status_po: String(item.status_po ?? 'COMPLETED'),
        po_numbers: String(item.po_numbers ?? '-'),
        pr_amount: Number(item.pr_amount ?? 0),
        po_amount: Number(item.po_amount ?? 0),
        difference_amount: Number(item.difference_amount ?? 0),
        difference_raw: Number(item.difference_raw ?? 0),
        difference_percent: Number(item.difference_percent ?? 0),
        variance_type: normalizeValueComparisonVarianceType(item.variance_type),
        variance_label: String(item.variance_label ?? valueComparisonLabel(
          normalizeValueComparisonVarianceType(item.variance_type),
        )),
      }))
      : [],
  }
}

function normalizeValueComparisonVarianceType(
  value: string | null | undefined,
): ValueComparisonVarianceType {
  const normalized = String(value ?? '').trim().toLowerCase()

  if (normalized === 'efficiency')
    return 'efficiency'

  if (normalized === 'increase')
    return 'increase'

  return 'same'
}

function valueComparisonColor(
  value: ValueComparisonVarianceType,
): string {
  if (value === 'efficiency')
    return 'success'

  if (value === 'increase')
    return 'error'

  return 'secondary'
}

function valueComparisonLabel(
  value: ValueComparisonVarianceType,
): string {
  if (value === 'efficiency')
    return 'Efisiensi'

  if (value === 'increase')
    return 'Kenaikan'

  return 'Tetap'
}

function normalizeItemPriceVarianceType(
  value: string | null | undefined,
): ItemPriceVarianceType {
  const normalized = String(value ?? '').trim().toLowerCase()

  if (normalized === 'increase')
    return 'increase'

  if (normalized === 'decrease')
    return 'decrease'

  return 'same'
}

function itemPriceVarianceColor(
  value: ItemPriceVarianceType,
): string {
  if (value === 'increase')
    return 'error'

  if (value === 'decrease')
    return 'success'

  return 'secondary'
}

function itemPriceVarianceLabel(
  value: ItemPriceVarianceType,
): string {
  if (value === 'increase')
    return 'Naik'

  if (value === 'decrease')
    return 'Turun'

  return 'Tetap'
}

function formatSignedCurrency(
  value: number | null | undefined,
): string {
  const amount = Number(value ?? 0)

  if (amount === 0)
    return formatCurrency(0)

  const prefix = amount > 0 ? '+' : '-'

  return `${prefix}${formatCompactCurrency(Math.abs(amount), 'short')}`
}

function formatSignedPercent(
  value: number | null | undefined,
): string {
  const amount = Number(value ?? 0)

  if (amount === 0)
    return '0%'

  return `${amount > 0 ? '+' : ''}${formatDecimal(amount)}%`
}

function getItemPriceBarPercent(
  value: number,
  item: ItemPriceComparisonItem,
): number {
  const maxValue = Math.max(
    Number(item.pr_unit_price ?? 0),
    Number(item.po_unit_price ?? 0),
    1,
  )

  return Math.min(
    Math.max((Number(value || 0) / maxValue) * 100, 0),
    100,
  )
}

/*
|--------------------------------------------------------------------------
| Date Helper
|--------------------------------------------------------------------------
*/

function getLocalDateValue(date: Date): string {
  const year = date.getFullYear()
  const month = String(
    date.getMonth() + 1,
  ).padStart(2, '0')

  const day = String(
    date.getDate(),
  ).padStart(2, '0')

  return `${year}-${month}-${day}`
}

function getMonthValue(date: Date): string {
  const year = date.getFullYear()
  const month = String(
    date.getMonth() + 1,
  ).padStart(2, '0')

  return `${year}-${month}`
}

function getFirstDateOfMonth(date: Date): string {
  const firstDate = new Date(
    date.getFullYear(),
    date.getMonth(),
    1,
  )

  return getLocalDateValue(firstDate)
}

function getCurrentWeekValue(date: Date): string {
  const currentDate = new Date(Date.UTC(
    date.getFullYear(),
    date.getMonth(),
    date.getDate(),
  ))

  const dayNumber =
    currentDate.getUTCDay() || 7

  currentDate.setUTCDate(
    currentDate.getUTCDate() + 4 - dayNumber,
  )

  const yearStart = new Date(Date.UTC(
    currentDate.getUTCFullYear(),
    0,
    1,
  ))

  const weekNumber = Math.ceil(
    (
      (
        currentDate.getTime()
        - yearStart.getTime()
      ) / 86400000
      + 1
    ) / 7,
  )

  return `${currentDate.getUTCFullYear()}-W${String(
    weekNumber,
  ).padStart(2, '0')}`
}

/*
|--------------------------------------------------------------------------
| Formatter
|--------------------------------------------------------------------------
*/

function formatNumber(
  value: number | null | undefined,
): string {
  return new Intl.NumberFormat('id-ID').format(
    Number(value ?? 0),
  )
}

function formatDecimal(
  value: number | null | undefined,
): string {
  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 1,
  }).format(Number(value ?? 0))
}

function formatCurrency(
  value: number | null | undefined,
): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(Number(value ?? 0))
}

function formatCompactCurrency(
  value: number | null | undefined,
  mode: CurrencyFormatMode = 'long',
): string {
  const amount = Number(value ?? 0)
  const absAmount = Math.abs(amount)

  if (absAmount >= 1_000_000_000_000) {
    return buildScaledCurrencyText(
      amount,
      1_000_000_000_000,
      mode === 'short' ? 'T' : 'Triliun',
    )
  }

  if (absAmount >= 1_000_000_000) {
    return buildScaledCurrencyText(
      amount,
      1_000_000_000,
      mode === 'short' ? 'M' : 'Miliar',
    )
  }

  if (absAmount >= 1_000_000) {
    return buildScaledCurrencyText(
      amount,
      1_000_000,
      mode === 'short' ? 'Jt' : 'Juta',
    )
  }

  if (absAmount >= 1_000) {
    return buildScaledCurrencyText(
      amount,
      1_000,
      mode === 'short' ? 'Rb' : 'Ribu',
    )
  }

  return formatCurrency(amount)
}

function buildScaledCurrencyText(
  amount: number,
  divisor: number,
  suffix: string,
): string {
  return `Rp ${formatScaledNumber(amount / divisor)} ${suffix}`
}

function formatScaledNumber(
  value: number,
): string {
  const absValue = Math.abs(Number(value ?? 0))

  return new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 0,
    maximumFractionDigits: absValue >= 100 ? 0 : 1,
  }).format(Number(value ?? 0))
}

function getMaxNumber(
  values: Array<number | null | undefined>,
): number {
  return Math.max(
    ...values.map(value => Number(value ?? 0)),
    0,
  )
}

function getCurrencyScale(maxValue: number): {
  divisor: number
  shortSuffix: string
  longSuffix: string
  title: string
} {
  const value = Math.abs(Number(maxValue || 0))

  if (value >= 1_000_000_000_000) {
    return {
      divisor: 1_000_000_000_000,
      shortSuffix: 'T',
      longSuffix: 'Triliun',
      title: 'Triliun Rupiah',
    }
  }

  if (value >= 1_000_000_000) {
    return {
      divisor: 1_000_000_000,
      shortSuffix: 'M',
      longSuffix: 'Miliar',
      title: 'Miliar Rupiah',
    }
  }

  if (value >= 1_000_000) {
    return {
      divisor: 1_000_000,
      shortSuffix: 'Jt',
      longSuffix: 'Juta',
      title: 'Juta Rupiah',
    }
  }

  if (value >= 1_000) {
    return {
      divisor: 1_000,
      shortSuffix: 'Rb',
      longSuffix: 'Ribu',
      title: 'Ribu Rupiah',
    }
  }

  return {
    divisor: 1,
    shortSuffix: '',
    longSuffix: '',
    title: 'Rupiah',
  }
}

function formatChartCurrency(
  value: number | null | undefined,
  maxValue: number,
): string {
  const amount = Number(value ?? 0)
  const scale = getCurrencyScale(maxValue)
  const scaledAmount = amount / scale.divisor

  if (!scale.shortSuffix)
    return formatCurrency(amount)

  return `Rp ${formatScaledNumber(scaledAmount)} ${scale.shortSuffix}`
}

function buildCurrencyAxisTitle(maxValue: number): string {
  return `Nilai transaksi (${getCurrencyScale(maxValue).title})`
}

function normalizeStatusValue(
  value: string | null | undefined,
): string {
  return String(value ?? '')
    .trim()
    .toUpperCase()
    .replace(/\s+/g, '_')
}

function isExcludedDashboardStatus(
  value: string | null | undefined,
): boolean {
  return [
    'REJECTED',
    'REJECT',
    'CANCELLED',
    'CANCELED',
  ].includes(normalizeStatusValue(value))
}

function formatDate(
  value: string | null | undefined,
): string {
  if (!value)
    return '-'

  const date = new Date(`${value}T00:00:00`)

  if (Number.isNaN(date.getTime()))
    return value

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
  }).format(date)
}

function formatMonth(
  value: string | null | undefined,
): string {
  if (!value)
    return '-'

  const [year, month] = value.split('-')

  const date = new Date(
    Number(year),
    Number(month) - 1,
    1,
  )

  return new Intl.DateTimeFormat('id-ID', {
    month: 'long',
    year: 'numeric',
  }).format(date)
}

function formatWeek(
  value: string | null | undefined,
): string {
  if (!value)
    return '-'

  const [year, week] = value.split('-W')

  return `Minggu ${Number(week)}, Tahun ${year}`
}

function formatDateTime(
  value: Date | null,
): string {
  if (!value)
    return '-'

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(value)
}

function statusColor(status: string): string {
  const colors: Record<string, string> = {
    DRAFT: 'secondary',
    IN_PROGRESS: 'warning',
    APPROVED: 'success',
    REJECTED: 'error',
    CANCELLED: 'error',
    CLOSED: 'info',
  }

  return colors[
    status.toUpperCase()
  ] ?? 'primary'
}

function statusPercentage(total: number): number {
  if (totalStatus.value <= 0)
    return 0

  return (
    Number(total)
    / totalStatus.value
  ) * 100
}

/*
|--------------------------------------------------------------------------
| Dropdown Normalizer
|--------------------------------------------------------------------------
*/

function extractArray(
  payload: unknown,
): OptionRecord[] {
  if (Array.isArray(payload))
    return payload as OptionRecord[]

  if (
    payload
    && typeof payload === 'object'
    && 'data' in payload
  ) {
    const firstData = (
      payload as {
        data?: unknown
      }
    ).data

    if (Array.isArray(firstData))
      return firstData as OptionRecord[]

    if (
      firstData
      && typeof firstData === 'object'
      && 'data' in firstData
    ) {
      const secondData = (
        firstData as {
          data?: unknown
        }
      ).data

      if (Array.isArray(secondData))
        return secondData as OptionRecord[]
    }
  }

  return []
}

function normalizeOptions(
  records: OptionRecord[],
  type: 'cabang' | 'department',
): SelectOption[] {
  return records
    .map(record => {
      const value =
        record.id
        ?? record.value
        ?? (
          type === 'cabang'
            ? record.cabang_id
            : record.department_id
        )

      let title =
        record.title
        ?? record.label
        ?? record.name
        ?? record.nama

      if (type === 'cabang') {
        title =
          record.nama_cabang
          ?? title
      }

      if (type === 'department') {
        title =
          record.nama_department
          ?? record.nama_departemen
          ?? record.department_name
          ?? title
      }

      if (
        value === undefined
        || value === null
        || !title
      ) {
        return null
      }

      return {
        value,
        title: String(title),
      }
    })
    .filter(
      (item): item is SelectOption => {
        return item !== null
      },
    )
}

/*
|--------------------------------------------------------------------------
| API Filter Options
|--------------------------------------------------------------------------
*/

async function fetchFilterOptions(): Promise<void> {
  isLoadingOptions.value = true

  try {
    /*
     * Scope ALL atau OWN_DEPARTMENT:
     * cabang dapat dipilih.
     */
    if (access.value.can_filter_cabang) {
      const cabangResponse = await axios.get(
        '/master/cabang/options',
      )

      cabangOptions.value = normalizeOptions(
        extractArray(cabangResponse.data),
        'cabang',
      )
    }
    else if (
      access.value.cabang_id
      && access.value.cabang_name
    ) {
      cabangOptions.value = [
        {
          value: access.value.cabang_id,
          title: access.value.cabang_name,
        },
      ]
    }

    /*
     * Hanya scope ALL yang dapat memilih departemen.
     */
    if (
      access.value.can_filter_department
    ) {
      const departmentResponse = await axios.get(
        '/master/department/dropdown-select',
      )

      departmentOptions.value = normalizeOptions(
        extractArray(
          departmentResponse.data,
        ),
        'department',
      )
    }
    else if (
      access.value.department_id
      && access.value.department_name
    ) {
      departmentOptions.value = [
        {
          value: access.value.department_id,
          title: access.value.department_name,
        },
      ]
    }
  }
  catch (error) {
    console.error(
      'Failed to load dashboard filter options:',
      error,
    )
  }
  finally {
    isLoadingOptions.value = false
  }
}

/*
|--------------------------------------------------------------------------
| API Dashboard
|--------------------------------------------------------------------------
*/

function buildFilterParams(): Record<
  string,
  string | number
> {
  const params: Record<
    string,
    string | number
  > = {
    period: selectedPeriod.value,
  }

  if (selectedPeriod.value === 'day')
    params.date = selectedDate.value

  if (selectedPeriod.value === 'week')
    params.week = selectedWeek.value

  if (selectedPeriod.value === 'month')
    params.month = selectedMonth.value

  if (selectedPeriod.value === 'year')
    params.year = selectedYear.value

  if (selectedPeriod.value === 'range') {
    params.start_date = startDate.value
    params.end_date = endDate.value
  }

  if (
    selectedCabangId.value !== null
    && selectedCabangId.value !== ''
  ) {
    params.cabang_id =
      selectedCabangId.value
  }

  if (
    selectedDepartmentId.value !== null
    && selectedDepartmentId.value !== ''
  ) {
    params.department_id =
      selectedDepartmentId.value
  }

  return params
}

async function fetchDashboard(): Promise<void> {
  if (!isFilterValid.value) {
    errorMessage.value =
      'Periode filter belum diisi dengan benar.'

    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    const response =
      await axios.get<DashboardResponse>(
        '/dashboard/purchase-order',
        {
          params: buildFilterParams(),
        },
      )

    const data = response.data.data

    access.value = data.access

    synchronizeFiltersWithAccess()

    summary.value = {
      total_pr: Number(
        data.summary.total_pr ?? 0,
      ),

      total_pr_amount: Number(
        data.summary.total_pr_amount ?? 0,
      ),

      total_po: Number(
        data.summary.total_po ?? 0,
      ),

      total_po_amount: Number(
        data.summary.total_po_amount ?? 0,
      ),

      approved_pr: Number(
        data.summary.approved_pr ?? 0,
      ),

      pr_not_ordered: Number(
        data.summary.pr_not_ordered ?? 0,
      ),

      pending_po_approval: Number(
        data.summary.pending_po_approval ?? 0,
      ),

      outstanding_receipt: Number(
        data.summary.outstanding_receipt ?? 0,
      ),

      rejected_po: Number(
        data.summary.rejected_po ?? 0,
      ),

      conversion_rate: Number(
        data.summary.conversion_rate ?? 0,
      ),
    }

    trend.value = data.trend ?? []
    statuses.value = data.statuses ?? []

    attentionItems.value =
      data.attention_items ?? []

    itemPriceComparison.value = normalizeItemPriceComparison(
      data.item_price_comparison,
    )

    valueComparison.value = normalizeValueComparison(
      data.value_comparison,
    )

    breakdownByCabang.value =
        data.breakdown?.by_cabang ?? []

    breakdownByDepartment.value =
      data.breakdown?.by_department ?? []

    appliedPeriodLabel.value =
      selectedPeriodDescription.value

    lastUpdatedAt.value = new Date()
  }
  catch (error) {
    console.error(
      'Failed to load procurement dashboard:',
      error,
    )

    errorMessage.value =
      'Data dashboard Purchase Order gagal dimuat.'
  }
  finally {
    isLoading.value = false
  }
}

/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

async function applyFilter(): Promise<void> {
  await fetchDashboard()
}

async function resetFilter(): Promise<void> {
  selectedPeriod.value = 'month'
  selectedDate.value = getLocalDateValue(today)
  selectedWeek.value = getCurrentWeekValue(today)
  selectedMonth.value = getMonthValue(today)
  selectedYear.value = currentYear

  startDate.value = getFirstDateOfMonth(today)
  endDate.value = getLocalDateValue(today)

  selectedCabangId.value = null
  selectedDepartmentId.value = null

  await fetchDashboard()
}

async function refreshDashboard(): Promise<void> {
  await fetchDashboard()
}

function backToDashboard(): void {
  router.push('/dashboards/crm')
}

/*
|--------------------------------------------------------------------------
| Lifecycle
|--------------------------------------------------------------------------
*/

onMounted(async () => {
  await fetchDashboard()
  await fetchFilterOptions()
})
</script>

<template>
  <section class="purchase-order-dashboard">
    <!-- Header -->
    <VCard class="dashboard-header mb-6">
      <VCardText class="pa-5 pa-md-7">
        <div
          class="d-flex flex-wrap align-center justify-space-between gap-4"
        >
          <div class="d-flex align-center gap-4">
            <VBtn
              icon
              color="secondary"
              variant="tonal"
              @click="backToDashboard"
            >
              <VIcon icon="mdi-arrow-left" />
            </VBtn>

            <VAvatar
              color="success"
              variant="flat"
              rounded="lg"
              size="58"
              class="header-avatar"
            >
              <VIcon
                icon="mdi-file-sign"
                size="31"
              />
            </VAvatar>

            <div>
              <div
                class="d-flex flex-wrap align-center gap-2 mb-1"
              >
                <h1 class="text-h4 font-weight-bold mb-0">
                  Purchase Order Management Dashboard
                </h1>
              </div>

              <p class="text-body-2 text-medium-emphasis mb-0">
                Perbandingan kebutuhan Purchase Requisition
                dan realisasi Purchase Order.
              </p>
            </div>
          </div>

          <div class="text-md-end">
            <div class="text-caption text-medium-emphasis">
              Terakhir diperbarui
            </div>

            <div class="text-body-2 font-weight-medium">
              {{ formatDateTime(lastUpdatedAt) }}
            </div>

            <VBtn
              size="small"
              variant="text"
              color="primary"
              prepend-icon="mdi-refresh"
              :loading="isLoading"
              class="mt-1 text-none"
              @click="refreshDashboard"
            >
              Perbarui
            </VBtn>
          </div>
        </div>
      </VCardText>

      <VProgressLinear
        v-if="isLoading"
        color="success"
        indeterminate
      />
    </VCard>

    <!-- Filter -->
    <VCard class="dashboard-card filter-card mb-6">
      <VCardText class="pa-5">
        <div class="filter-header">
          <div>
            <h2 class="text-h6 font-weight-semibold mb-1">
              Filter Data
            </h2>

            <p class="text-body-2 text-medium-emphasis mb-0">
              Periode aktif:
              <strong>
                {{
                  appliedPeriodLabel
                    || selectedPeriodDescription
                }}
              </strong>
            </p>
          </div>

          <VChip
            color="primary"
            variant="tonal"
            prepend-icon="mdi-shield-account-outline"
          >
            Scope {{ access.scope_view }}
          </VChip>
        </div>

        <div class="filter-grid">
          <VSelect
            v-model="selectedPeriod"
            :items="periodOptions"
            item-title="title"
            item-value="value"
            label="Jenis Periode"
            prepend-inner-icon="mdi-calendar-filter-outline"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <VTextField
            v-if="selectedPeriod === 'day'"
            v-model="selectedDate"
            type="date"
            label="Pilih Tanggal"
            prepend-inner-icon="mdi-calendar-today-outline"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <VTextField
            v-if="selectedPeriod === 'week'"
            v-model="selectedWeek"
            type="week"
            label="Pilih Minggu"
            prepend-inner-icon="mdi-calendar-week-outline"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <VTextField
            v-if="selectedPeriod === 'month'"
            v-model="selectedMonth"
            type="month"
            label="Pilih Bulan"
            prepend-inner-icon="mdi-calendar-month-outline"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <VSelect
            v-if="selectedPeriod === 'year'"
            v-model="selectedYear"
            :items="yearOptions"
            item-title="title"
            item-value="value"
            label="Pilih Tahun"
            prepend-inner-icon="mdi-calendar-blank-multiple"
            variant="outlined"
            density="comfortable"
            hide-details
          />

          <template v-if="selectedPeriod === 'range'">
            <VTextField
              v-model="startDate"
              type="date"
              label="Tanggal Mulai"
              prepend-inner-icon="mdi-calendar-start"
              variant="outlined"
              density="comfortable"
              hide-details
            />

            <VTextField
              v-model="endDate"
              type="date"
              label="Tanggal Selesai"
              prepend-inner-icon="mdi-calendar-end"
              variant="outlined"
              density="comfortable"
              hide-details
            />
          </template>

          <VSelect
            v-model="selectedCabangId"
            :items="cabangOptions"
            item-title="title"
            item-value="value"
            :label="
              access.can_filter_cabang
                ? 'Semua Cabang'
                : 'Cabang'
            "
            prepend-inner-icon="mdi-office-building-outline"
            variant="outlined"
            density="comfortable"
            hide-details
            :readonly="!access.can_filter_cabang"
            :clearable="access.can_filter_cabang"
            :loading="isLoadingOptions"
          />

          <VSelect
            v-model="selectedDepartmentId"
            :items="departmentOptions"
            item-title="title"
            item-value="value"
            :label="
              access.can_filter_department
                ? 'Semua Departemen'
                : 'Departemen'
            "
            prepend-inner-icon="mdi-account-group-outline"
            variant="outlined"
            density="comfortable"
            hide-details
            :readonly="
              !access.can_filter_department
            "
            :clearable="
              access.can_filter_department
            "
            :loading="isLoadingOptions"
          />
        </div>

        <VDivider class="my-5" />

        <div class="filter-footer">
          <div class="text-body-2 text-medium-emphasis">
            <VIcon
              icon="mdi-information-outline"
              size="18"
              class="me-1"
            />

            Filter cabang dan departemen mengikuti scope
            permission pengguna.
          </div>

          <div class="d-flex align-center gap-2">
            <VBtn
              color="secondary"
              variant="tonal"
              prepend-icon="mdi-filter-remove-outline"
              :disabled="isLoading"
              @click="resetFilter"
              class="text-none"
            >
              Reset
            </VBtn>

            <VBtn
              color="primary"
              prepend-icon="mdi-filter-check-outline"
              :loading="isLoading"
              :disabled="!isFilterValid"
              @click="applyFilter"
              class="text-none"
            >
              Terapkan
            </VBtn>
          </div>
        </div>

        <VAlert
          v-if="!isFilterValid"
          type="warning"
          variant="tonal"
          density="compact"
          class="mt-4"
        >
          Lengkapi pilihan periode terlebih dahulu.
        </VAlert>
      </VCardText>
    </VCard>

    <VAlert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      closable
      class="mb-6"
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </VAlert>

    <!-- Initial Loading -->
    <VRow
      v-if="isLoading && !lastUpdatedAt"
      class="match-height mb-2"
    >
      <VCol
        v-for="index in 4"
        :key="index"
        cols="12"
        sm="6"
        xl="3"
      >
        <VCard class="dashboard-card">
          <VCardText>
            <VSkeletonLoader
              type="list-item-avatar-three-line"
            />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- PR vs PO Cards -->
    <VRow
      v-else
      class="match-height mb-2"
    >
      <VCol
        v-for="(card, index) in statisticCards"
        :key="card.title"
        cols="12"
        sm="6"
        xl="3"
      >
        <VCard
          class="statistic-card dashboard-card h-100 dashboard-enter"
          :style="{
            animationDelay: `${index * 90}ms`,
          }"
        >
          <VCardText class="pa-5">
            <div class="d-flex justify-space-between align-start gap-3">
              <div>
                <VChip
                  :color="card.color"
                  variant="tonal"
                  size="x-small"
                  class="mb-3"
                >
                  {{ card.shortTitle }}
                </VChip>

                <div class="text-body-2 text-medium-emphasis mb-2">
                  {{ card.title }}
                </div>

                <div
                  class="text-h4 font-weight-bold mb-2"
                  :title="card.fullValue ?? card.value"
                >
                  {{ card.value }}
                </div>

                <div class="text-caption text-medium-emphasis">
                  {{ card.subtitle }}
                </div>
              </div>

              <VAvatar
                :color="card.color"
                variant="tonal"
                rounded="lg"
                size="48"
              >
                <VIcon
                  :icon="card.icon"
                  size="27"
                />
              </VAvatar>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Operational Statistics -->
    <VCard class="dashboard-card mb-6">
      <VCardText>
        <VRow>
          <VCol
            v-for="item in operationalStatistics"
            :key="item.title"
            cols="12"
            sm="6"
            lg="3"
          >
            <div class="operational-statistic">
              <VAvatar
                :color="item.color"
                variant="tonal"
                rounded
                size="42"
              >
                <VIcon :icon="item.icon" />
              </VAvatar>

              <div>
                <div class="text-caption text-medium-emphasis">
                  {{ item.title }}
                </div>

                <div class="text-h6 font-weight-bold">
                  {{ item.value }}
                </div>
              </div>
            </div>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Management Insight -->
    <VAlert
      :type="managementInsight.type"
      :icon="managementInsight.icon"
      variant="tonal"
      class="mb-6 dashboard-enter"
    >
      <div class="font-weight-semibold mb-1">
        {{ managementInsight.title }}
      </div>

      <div class="text-body-2">
        {{ managementInsight.message }}
      </div>
    </VAlert>

    <!-- Comparison and Trend -->
    <VRow class="match-height mb-2">
      <VCol
        cols="12"
        lg="4"
      >
        <VCard class="dashboard-card chart-card h-100">
          <VCardItem class="chart-card-header">
            <VCardTitle>
              Realisasi PO terhadap PR
            </VCardTitle>

            <VCardSubtitle>
              Rasio nilai PO dibandingkan kebutuhan PR pada periode aktif
            </VCardSubtitle>
          </VCardItem>

          <VCardText>
            <VueApexCharts
              v-if="hasComparisonData"
              type="radialBar"
              :height="comparisonChartHeight"
              :options="comparisonChartOptions"
              :series="comparisonChartSeries"
            />

            <div
              v-if="hasComparisonData"
              class="comparison-summary-grid"
            >
              <div class="comparison-summary-item">
                <span>Nilai PR</span>
                <strong :title="formatCurrency(summary.total_pr_amount)">
                  {{ formatCompactCurrency(summary.total_pr_amount, 'short') }}
                </strong>
              </div>

              <div class="comparison-summary-item">
                <span>Nilai PO</span>
                <strong :title="formatCurrency(summary.total_po_amount)">
                  {{ formatCompactCurrency(summary.total_po_amount, 'short') }}
                </strong>
              </div>

              <div class="comparison-summary-item comparison-summary-wide">
                <span>Selisih belum terealisasi</span>
                <strong :title="formatCurrency(comparisonGapAmount)">
                  {{ formatCompactCurrency(comparisonGapAmount, 'short') }}
                </strong>
              </div>
            </div>

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="secondary"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-chart-bar"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Belum ada data PR dan PO
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        lg="8"
      >
        <VCard class="dashboard-card chart-card h-100">
          <VCardItem class="chart-card-header">
            <VCardTitle>
              Tren Nilai PR dan PO
            </VCardTitle>

            <VCardSubtitle>
              Pergerakan kebutuhan dan realisasi pada
              {{ executiveChartSubtitle }}
            </VCardSubtitle>
          </VCardItem>

          <VCardText>
            <VueApexCharts
              v-if="hasTrendData"
              type="line"
              :height="trendChartHeight"
              :options="trendChartOptions"
              :series="trendChartSeries"
            />

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="secondary"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-chart-line"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Belum ada data tren
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Value Comparison PR vs PO -->
    <!-- <VCard class="dashboard-card chart-card mb-6">
      <VCardItem class="chart-card-header">
        <template #prepend>
          <VAvatar
            color="success"
            variant="tonal"
            rounded
          >
            <VIcon icon="mdi-cash-sync" />
          </VAvatar>
        </template>

        <VCardTitle>
          Efisiensi dan Kenaikan Nilai PR vs PO
        </VCardTitle>

        <VCardSubtitle>
          Perbandingan final Grand Total PR dengan nilai PO untuk PR yang sudah completed pada
          {{ executiveChartSubtitle }}.
        </VCardSubtitle>
      </VCardItem>

      <VCardText>
        <template v-if="hasValueComparison">
          <div class="item-price-summary-grid mb-4">
            <div
              v-for="card in valueComparisonSummaryCards"
              :key="card.title"
              class="item-price-summary-card"
            >
              <VAvatar
                :color="card.color"
                variant="tonal"
                rounded
                size="38"
              >
                <VIcon
                  :icon="card.icon"
                  size="21"
                />
              </VAvatar>

              <div class="item-price-summary-content">
                <span>{{ card.title }}</span>
                <strong>{{ card.value }}</strong>
                <small>{{ card.subtitle }}</small>
              </div>
            </div>
          </div>

          <div class="item-price-comparison-list">
            <div
              v-for="item in topValueComparisonItems"
              :key="item.purchase_request_id ?? item.pr_number"
              class="item-price-comparison-item"
            >
              <div class="item-price-comparison-header">
                <div class="item-price-name-block">
                  <div class="item-price-name">
                    {{ item.pr_number }}
                  </div>

                  <div class="item-price-meta">
                    {{ formatDate(item.pr_date) }} · PO {{ item.po_numbers }}
                  </div>
                </div>

                <VChip
                  :color="valueComparisonColor(item.variance_type)"
                  variant="tonal"
                  size="small"
                  class="item-price-variance-chip"
                >
                  {{ valueComparisonLabel(item.variance_type) }}
                  {{ formatSignedPercent(item.difference_percent) }}
                </VChip>
              </div>

              <div class="item-price-value-grid">
                <div class="item-price-value-card">
                  <span>Grand Total PR</span>
                  <strong :title="formatCurrency(item.pr_amount)">
                    {{ formatCompactCurrency(item.pr_amount, 'short') }}
                  </strong>
                </div>

                <div class="item-price-value-card">
                  <span>Total PO</span>
                  <strong :title="formatCurrency(item.po_amount)">
                    {{ formatCompactCurrency(item.po_amount, 'short') }}
                  </strong>
                </div>

                <div class="item-price-value-card">
                  <span>{{ item.variance_label }}</span>
                  <strong
                    :class="`text-${valueComparisonColor(item.variance_type)}`"
                    :title="formatCurrency(item.difference_amount)"
                  >
                    {{ formatCompactCurrency(item.difference_amount, 'short') }}
                  </strong>
                </div>
              </div>

              <div class="item-price-bars">
                <div class="item-price-bar-row">
                  <span>PR</span>
                  <VProgressLinear
                    :model-value="getBreakdownPercent(item.pr_amount, Math.max(item.pr_amount, item.po_amount, 1))"
                    color="primary"
                    height="9"
                    rounded
                  />
                  <strong>{{ formatCompactCurrency(item.pr_amount, 'short') }}</strong>
                </div>

                <div class="item-price-bar-row">
                  <span>PO</span>
                  <VProgressLinear
                    :model-value="getBreakdownPercent(item.po_amount, Math.max(item.pr_amount, item.po_amount, 1))"
                    :color="valueComparisonColor(item.variance_type)"
                    height="9"
                    rounded
                  />
                  <strong>{{ formatCompactCurrency(item.po_amount, 'short') }}</strong>
                </div>
              </div>
            </div>
          </div>

          <div
            v-if="valueComparison.items.length > topValueComparisonItems.length"
            class="executive-breakdown-note mt-4"
          >
            Menampilkan {{ topValueComparisonItems.length }} PR dengan selisih nilai terbesar dari {{ valueComparison.items.length }} PR completed.
          </div>
        </template>

        <div
          v-else
          class="empty-state"
        >
          <VAvatar
            color="secondary"
            variant="tonal"
            size="60"
            class="mb-3"
          >
            <VIcon
              icon="mdi-cash-search"
              size="31"
            />
          </VAvatar>

          <div class="font-weight-medium">
            Belum ada data efisiensi atau kenaikan nilai
          </div>

          <div class="text-body-2 text-medium-emphasis mt-1">
            Data akan muncul setelah PR completed memiliki PO valid pada periode aktif.
          </div>
        </div>
      </VCardText>
    </VCard> -->

    <!-- Item Price Comparison -->
    <!-- <VCard class="dashboard-card chart-card mb-6">
      <VCardItem class="chart-card-header">
        <template #prepend>
          <VAvatar
            color="warning"
            variant="tonal"
            rounded
          >
            <VIcon icon="mdi-tag-arrow-up-outline" />
          </VAvatar>
        </template>

        <VCardTitle>
          Perbandingan Harga Item PR dan PO
        </VCardTitle>

        <VCardSubtitle>
          Monitoring perubahan harga satuan dari kebutuhan PR ke realisasi PO pada
          {{ executiveChartSubtitle }}.
        </VCardSubtitle>
      </VCardItem>

      <VCardText>
        <template v-if="hasItemPriceComparison">
          <div class="item-price-summary-grid mb-4">
            <div
              v-for="card in itemPriceComparisonSummaryCards"
              :key="card.title"
              class="item-price-summary-card"
            >
              <VAvatar
                :color="card.color"
                variant="tonal"
                rounded
                size="38"
              >
                <VIcon
                  :icon="card.icon"
                  size="21"
                />
              </VAvatar>

              <div class="item-price-summary-content">
                <span>{{ card.title }}</span>
                <strong>{{ card.value }}</strong>
                <small>{{ card.subtitle }}</small>
              </div>
            </div>
          </div>

          <div class="item-price-comparison-list">
            <div
              v-for="item in topItemPriceComparisonItems"
              :key="item.purchase_request_item_id ?? item.item_name"
              class="item-price-comparison-item"
            >
              <div class="item-price-comparison-header">
                <div class="item-price-name-block">
                  <div class="item-price-name">
                    {{ item.item_name }}
                  </div>

                  <div class="item-price-meta">
                    PR {{ item.pr_number }} · {{ item.po_count }} PO · Qty {{ formatNumber(item.po_qty) }}
                  </div>
                </div>

                <VChip
                  :color="itemPriceVarianceColor(item.variance_type)"
                  variant="tonal"
                  size="small"
                  class="item-price-variance-chip"
                >
                  {{ itemPriceVarianceLabel(item.variance_type) }}
                  {{ formatSignedPercent(item.price_difference_percent) }}
                </VChip>
              </div>

              <div class="item-price-value-grid">
                <div class="item-price-value-card">
                  <span>Harga PR</span>
                  <strong :title="formatCurrency(item.pr_unit_price)">
                    {{ formatCompactCurrency(item.pr_unit_price, 'short') }}
                  </strong>
                </div>

                <div class="item-price-value-card">
                  <span>Harga PO rata-rata</span>
                  <strong :title="formatCurrency(item.po_unit_price)">
                    {{ formatCompactCurrency(item.po_unit_price, 'short') }}
                  </strong>
                </div>

                <div class="item-price-value-card">
                  <span>Selisih harga</span>
                  <strong
                    :class="`text-${itemPriceVarianceColor(item.variance_type)}`"
                    :title="formatCurrency(item.price_difference)"
                  >
                    {{ formatSignedCurrency(item.price_difference) }}
                  </strong>
                </div>
              </div>

              <div class="item-price-bars">
                <div class="item-price-bar-row">
                  <span>PR</span>
                  <VProgressLinear
                    :model-value="getItemPriceBarPercent(item.pr_unit_price, item)"
                    color="primary"
                    height="9"
                    rounded
                  />
                  <strong>{{ formatCompactCurrency(item.pr_unit_price, 'short') }}</strong>
                </div>

                <div class="item-price-bar-row">
                  <span>PO</span>
                  <VProgressLinear
                    :model-value="getItemPriceBarPercent(item.po_unit_price, item)"
                    :color="itemPriceVarianceColor(item.variance_type)"
                    height="9"
                    rounded
                  />
                  <strong>{{ formatCompactCurrency(item.po_unit_price, 'short') }}</strong>
                </div>
              </div>
            </div>
          </div>

          <div
            v-if="itemPriceComparison.items.length > topItemPriceComparisonItems.length"
            class="executive-breakdown-note mt-4"
          >
            Menampilkan {{ topItemPriceComparisonItems.length }} item dengan perubahan harga terbesar dari {{ itemPriceComparison.items.length }} item.
          </div>
        </template>

        <div
          v-else
          class="empty-state"
        >
          <VAvatar
            color="secondary"
            variant="tonal"
            size="60"
            class="mb-3"
          >
            <VIcon
              icon="mdi-tag-search-outline"
              size="31"
            />
          </VAvatar>

          <div class="font-weight-medium">
            Belum ada data perbandingan harga item
          </div>

          <div class="text-body-2 text-medium-emphasis mt-1">
            Data akan muncul setelah item PR direalisasikan menjadi PO pada periode aktif.
          </div>
        </div>
      </VCardText>
    </VCard> -->

    <!-- Breakdown Cabang dan Departemen -->
    <VRow class="match-height mb-2">
      <VCol
        cols="12"
        lg="6"
      >
        <VCard class="dashboard-card chart-card h-100">
          <VCardItem class="chart-card-header chart-card-header-with-action">
            <VCardTitle>
              Analisis PR dan PO per Cabang
            </VCardTitle>

            <VCardSubtitle>
              Perbandingan kebutuhan dan realisasi
              berdasarkan cabang
            </VCardSubtitle>

            <template #append>
              <VSelect
                v-model="cabangBreakdownMetric"
                :items="breakdownMetricOptions"
                item-title="title"
                item-value="value"
                density="compact"
                variant="outlined"
                hide-details
                class="breakdown-metric-select"
              />
            </template>
          </VCardItem>

          <VCardText>
            <div
              v-if="executiveCabangBreakdownItems.length"
              class="executive-breakdown-list"
            >
              <div
                v-for="item in executiveCabangBreakdownItems"
                :key="`cabang-${item.id ?? item.name}`"
                class="executive-breakdown-item"
              >
                <div class="executive-breakdown-header">
                  <div class="executive-breakdown-name">
                    {{ item.name }}
                  </div>

                  <div class="executive-breakdown-total">
                    {{ formatBreakdownMetricValue(item.totalValue, cabangBreakdownMetric) }}
                  </div>
                </div>

                <div class="executive-breakdown-bars">
                  <div class="executive-breakdown-bar-row">
                    <span class="executive-breakdown-label">PR</span>
                    <VProgressLinear
                      :model-value="getBreakdownPercent(item.prValue, item.totalValue)"
                      color="primary"
                      height="8"
                      rounded
                    />
                    <span class="executive-breakdown-value">
                      {{ formatBreakdownMetricValue(item.prValue, cabangBreakdownMetric) }}
                    </span>
                  </div>

                  <div class="executive-breakdown-bar-row">
                    <span class="executive-breakdown-label">PO</span>
                    <VProgressLinear
                      :model-value="getBreakdownPercent(item.poValue, item.totalValue)"
                      color="success"
                      height="8"
                      rounded
                    />
                    <span class="executive-breakdown-value">
                      {{ formatBreakdownMetricValue(item.poValue, cabangBreakdownMetric) }}
                    </span>
                  </div>
                </div>
              </div>

              <div
                v-if="breakdownByCabang.length > executiveCabangBreakdownItems.length"
                class="executive-breakdown-note"
              >
                Menampilkan {{ executiveCabangBreakdownItems.length }} cabang teratas dari {{ breakdownByCabang.length }} cabang.
              </div>
            </div>

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="secondary"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-office-building-outline"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Belum ada data per cabang
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        lg="6"
      >
        <VCard class="dashboard-card chart-card h-100">
          <VCardItem class="chart-card-header chart-card-header-with-action">
            <VCardTitle>
              Analisis PR dan PO per Departemen
            </VCardTitle>

            <VCardSubtitle>
              Perbandingan kebutuhan dan realisasi
              berdasarkan departemen
            </VCardSubtitle>

            <template #append>
              <VSelect
                v-model="departmentBreakdownMetric"
                :items="breakdownMetricOptions"
                item-title="title"
                item-value="value"
                density="compact"
                variant="outlined"
                hide-details
                class="breakdown-metric-select"
              />
            </template>
          </VCardItem>

          <VCardText>
            <div
              v-if="executiveDepartmentBreakdownItems.length"
              class="executive-breakdown-list"
            >
              <div
                v-for="item in executiveDepartmentBreakdownItems"
                :key="`department-${item.id ?? item.name}`"
                class="executive-breakdown-item"
              >
                <div class="executive-breakdown-header">
                  <div class="executive-breakdown-name">
                    {{ item.name }}
                  </div>

                  <div class="executive-breakdown-total">
                    {{ formatBreakdownMetricValue(item.totalValue, departmentBreakdownMetric) }}
                  </div>
                </div>

                <div class="executive-breakdown-bars">
                  <div class="executive-breakdown-bar-row">
                    <span class="executive-breakdown-label">PR</span>
                    <VProgressLinear
                      :model-value="getBreakdownPercent(item.prValue, item.totalValue)"
                      color="primary"
                      height="8"
                      rounded
                    />
                    <span class="executive-breakdown-value">
                      {{ formatBreakdownMetricValue(item.prValue, departmentBreakdownMetric) }}
                    </span>
                  </div>

                  <div class="executive-breakdown-bar-row">
                    <span class="executive-breakdown-label">PO</span>
                    <VProgressLinear
                      :model-value="getBreakdownPercent(item.poValue, item.totalValue)"
                      color="success"
                      height="8"
                      rounded
                    />
                    <span class="executive-breakdown-value">
                      {{ formatBreakdownMetricValue(item.poValue, departmentBreakdownMetric) }}
                    </span>
                  </div>
                </div>
              </div>

              <div
                v-if="breakdownByDepartment.length > executiveDepartmentBreakdownItems.length"
                class="executive-breakdown-note"
              >
                Menampilkan {{ executiveDepartmentBreakdownItems.length }} departemen teratas dari {{ breakdownByDepartment.length }} departemen.
              </div>
            </div>

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="secondary"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-account-group-outline"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Belum ada data per departemen
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Status and Attention -->
    <VRow class="match-height">
      <VCol
        cols="12"
        lg="4"
      >
        <VCard class="dashboard-card h-100">
          <VCardItem>
            <VCardTitle>
              Status Purchase Order
            </VCardTitle>

            <VCardSubtitle>
              Kondisi PO pada periode terpilih
            </VCardSubtitle>
          </VCardItem>

          <VCardText>
            <div
              v-if="visibleStatuses.length"
              class="status-list"
            >
              <div
                v-for="status in visibleStatuses"
                :key="status.status"
                class="status-item"
              >
                <div class="d-flex justify-space-between mb-2">
                  <span class="text-body-2">
                    {{ status.label }}
                  </span>

                  <strong>
                    {{ formatNumber(status.total) }}
                  </strong>
                </div>

                <VProgressLinear
                  :model-value="
                    statusPercentage(status.total)
                  "
                  :color="statusColor(status.status)"
                  height="7"
                  rounded
                />
              </div>
            </div>

            <div
              v-else
              class="empty-state"
            >
              Belum ada data status.
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        lg="8"
      >
        <VCard class="dashboard-card h-100">
          <VCardItem>
            <template #prepend>
              <VAvatar
                color="warning"
                variant="tonal"
                rounded
              >
                <VIcon icon="mdi-alert-decagram-outline" />
              </VAvatar>
            </template>

            <VCardTitle>
              Purchase Order yang Perlu Perhatian
            </VCardTitle>

            <VCardSubtitle>
              Prioritas keputusan dan tindak lanjut management
            </VCardSubtitle>
          </VCardItem>

          <VCardText class="pa-0">
            <div
              v-if="visibleAttentionItems.length"
              class="table-wrapper"
            >
              <VTable hover>
                <thead>
                  <tr>
                    <th>Purchase Order</th>
                    <th>Cabang / Departemen</th>
                    <th>Vendor</th>
                    <th>Nilai</th>
                    <th>Umur</th>
                    <th>Keterangan</th>
                  </tr>
                </thead>

                <tbody>
                  <tr
                    v-for="item in visibleAttentionItems"
                    :key="item.public_id"
                  >
                    <td>
                      <div class="font-weight-semibold">
                        {{ item.po_number }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ formatDate(item.po_date) }}
                      </div>
                    </td>

                    <td>
                      <div>
                        {{ item.cabang_name ?? '-' }}
                      </div>

                      <div class="text-caption text-medium-emphasis">
                        {{ item.department_name ?? '-' }}
                      </div>
                    </td>

                    <td>
                      {{ item.vendor_name ?? '-' }}
                    </td>

                    <td class="text-no-wrap">
                      {{ formatCurrency(item.total_amount) }}
                    </td>

                    <td class="text-no-wrap">
                      {{ formatNumber(item.age_days) }} hari
                    </td>

                    <td>
                      <VChip
                        color="warning"
                        variant="tonal"
                        size="small"
                      >
                        {{ item.reason }}
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>

            <div
              v-else
              class="empty-state"
            >
              <VAvatar
                color="success"
                variant="tonal"
                size="60"
                class="mb-3"
              >
                <VIcon
                  icon="mdi-check-all"
                  size="31"
                />
              </VAvatar>

              <div class="font-weight-medium">
                Tidak ada PO yang perlu perhatian
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </section>
</template>

<style scoped>
.purchase-order-dashboard {
  min-block-size: 100%;
}

.dashboard-header {
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background:
    linear-gradient(
      135deg,
      rgba(var(--v-theme-success), 0.12) 0%,
      rgba(var(--v-theme-surface), 1) 58%,
      rgba(var(--v-theme-primary), 0.07) 100%
    );
}

.dashboard-header::after {
  position: absolute;
  border: 28px solid rgba(var(--v-theme-success), 0.05);
  border-radius: 50%;
  block-size: 180px;
  content: '';
  inline-size: 180px;
  inset-block-start: -85px;
  inset-inline-end: -45px;
  pointer-events: none;
}

.header-avatar {
  box-shadow: 0 8px 20px rgba(var(--v-theme-success), 0.22);
}

.dashboard-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.filter-card {
  overflow: visible;
}

.filter-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-block-end: 22px;
}

.filter-grid {
  display: grid;
  align-items: start;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 16px;
}

.filter-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.statistic-card {
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease;
}

.statistic-card:hover {
  box-shadow: 0 10px 26px rgba(var(--v-shadow-key-umbra-color), 0.12);
  transform: translateY(-4px);
}

.operational-statistic {
  display: flex;
  align-items: center;
  gap: 12px;
  padding-block: 6px;
}


.dashboard-card :deep(.v-card-title) {
  overflow: visible;
  text-overflow: unset;
  white-space: normal;
  line-height: 1.35;
}

.dashboard-card :deep(.v-card-subtitle) {
  overflow: visible;
  text-overflow: unset;
  white-space: normal;
  line-height: 1.45;
}

.dashboard-card :deep(.v-card-item) {
  align-items: flex-start;
  gap: 12px;
}

.dashboard-card :deep(.v-card-item__append) {
  align-self: flex-start;
  padding-inline-start: 12px;
}

.comparison-insight-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-block-start: 12px;
}

.comparison-insight-item {
  padding: 12px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  background: rgba(var(--v-theme-on-surface), 0.025);
}

.comparison-insight-item span {
  display: block;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.75rem;
  line-height: 1.35;
}

.comparison-insight-item strong {
  display: block;
  margin-block-start: 4px;
  color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity));
  font-size: 0.98rem;
  line-height: 1.35;
}

.status-list {
  display: flex;
  flex-direction: column;
  gap: 22px;
}

.status-item {
  border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  padding-block-end: 16px;
}

.status-item:last-child {
  border-block-end: none;
  padding-block-end: 0;
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-block-size: 280px;
  padding: 24px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  text-align: center;
}

.table-wrapper {
  overflow-x: auto;
}

.table-wrapper table {
  min-inline-size: 900px;
}

.table-wrapper th {
  background-color: rgba(var(--v-theme-on-surface), 0.025);
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
}

.dashboard-enter {
  animation: dashboard-enter 0.55s ease both;
}

@keyframes dashboard-enter {
  from {
    opacity: 0;
    transform: translateY(14px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@media (max-width: 1279px) {
  .filter-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 600px) {
  .header-avatar {
    display: none;
  }

  .filter-header,
  .filter-footer {
    align-items: stretch;
    flex-direction: column;
  }

  .filter-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .filter-footer > div:last-child {
    justify-content: flex-end;
  }

  .dashboard-card :deep(.v-card-item) {
    display: block;
  }

  .dashboard-card :deep(.v-card-item__append) {
    padding-block-start: 12px;
    padding-inline-start: 0;
  }

  .dashboard-card :deep(.v-select) {
    inline-size: 100%;
  }

  .comparison-insight-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (prefers-reduced-motion: reduce) {
  .dashboard-enter {
    animation: none;
  }

  .statistic-card {
    transition: none;
  }
}

.breakdown-chart-scroll {
  overflow-y: auto;
  max-block-size: 720px;
  padding-inline-end: 4px;
}

.breakdown-chart-scroll::-webkit-scrollbar {
  inline-size: 6px;
}

.breakdown-chart-scroll::-webkit-scrollbar-thumb {
  border-radius: 10px;
  background-color: rgba(
    var(--v-theme-on-surface),
    0.16
  );
}

.chart-card {
  overflow: hidden;
}

.chart-card-header {
  align-items: flex-start;
}

.chart-card-header :deep(.v-card-item__content) {
  overflow: visible;
  min-inline-size: 0;
}

.chart-card-header :deep(.v-card-title) {
  overflow: visible;
  white-space: normal;
  line-height: 1.3;
  word-break: normal;
}

.chart-card-header :deep(.v-card-subtitle) {
  overflow: visible;
  white-space: normal;
  line-height: 1.45;
}

.chart-card-header-with-action {
  display: flex;
  align-items: flex-start;
  gap: 16px;
}

.breakdown-metric-select {
  inline-size: 190px;
  min-inline-size: 190px;
}

.comparison-summary-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin-block-start: 8px;
}

.comparison-summary-item {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  background: rgba(var(--v-theme-on-surface), 0.025);
  padding: 12px;
  min-inline-size: 0;
}

.comparison-summary-item span {
  display: block;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.72rem;
  line-height: 1.35;
}

.comparison-summary-item strong {
  display: block;
  overflow-wrap: anywhere;
  margin-block-start: 4px;
  color: rgba(var(--v-theme-on-surface), 0.92);
  font-size: 0.95rem;
  line-height: 1.25;
}

.comparison-summary-wide {
  grid-column: 1 / -1;
}

.executive-breakdown-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.executive-breakdown-item {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 16px;
  background:
    linear-gradient(
      180deg,
      rgba(var(--v-theme-on-surface), 0.018),
      rgba(var(--v-theme-surface), 1)
    );
  padding: 14px;
}

.executive-breakdown-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  margin-block-end: 12px;
}

.executive-breakdown-name {
  min-inline-size: 0;
  color: rgba(var(--v-theme-on-surface), 0.9);
  font-size: 0.88rem;
  font-weight: 700;
  line-height: 1.35;
  white-space: normal;
  word-break: normal;
  overflow-wrap: anywhere;
}

.executive-breakdown-total {
  flex: 0 0 auto;
  border-radius: 999px;
  background: rgba(var(--v-theme-primary), 0.09);
  color: rgb(var(--v-theme-primary));
  font-size: 0.76rem;
  font-weight: 800;
  line-height: 1;
  padding-block: 7px;
  padding-inline: 10px;
  white-space: nowrap;
}

.executive-breakdown-bars {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.executive-breakdown-bar-row {
  display: grid;
  grid-template-columns: 32px minmax(0, 1fr) minmax(76px, auto);
  align-items: center;
  gap: 10px;
}

.executive-breakdown-label {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.72rem;
  font-weight: 800;
}

.executive-breakdown-value {
  color: rgba(var(--v-theme-on-surface), 0.74);
  font-size: 0.74rem;
  font-weight: 700;
  text-align: end;
  white-space: nowrap;
}

.executive-breakdown-note {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.78rem;
  text-align: center;
}


.item-price-summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.item-price-summary-card {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 16px;
  background: rgba(var(--v-theme-on-surface), 0.024);
  padding: 14px;
  min-inline-size: 0;
}

.item-price-summary-content {
  min-inline-size: 0;
}

.item-price-summary-content span,
.item-price-value-card span {
  display: block;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.74rem;
  line-height: 1.35;
}

.item-price-summary-content strong {
  display: block;
  margin-block-start: 2px;
  color: rgba(var(--v-theme-on-surface), 0.92);
  font-size: 1.18rem;
  font-weight: 800;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.item-price-summary-content small {
  display: block;
  margin-block-start: 2px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.7rem;
  line-height: 1.35;
}

.item-price-comparison-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.item-price-comparison-item {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 18px;
  background:
    linear-gradient(
      180deg,
      rgba(var(--v-theme-on-surface), 0.018),
      rgba(var(--v-theme-surface), 1)
    );
  padding: 16px;
}

.item-price-comparison-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  margin-block-end: 14px;
}

.item-price-name-block {
  min-inline-size: 0;
}

.item-price-name {
  color: rgba(var(--v-theme-on-surface), 0.92);
  font-size: 0.95rem;
  font-weight: 800;
  line-height: 1.35;
  white-space: normal;
  overflow-wrap: anywhere;
}

.item-price-meta {
  margin-block-start: 3px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.75rem;
  line-height: 1.4;
  white-space: normal;
  overflow-wrap: anywhere;
}

.item-price-variance-chip {
  flex: 0 0 auto;
  font-weight: 800;
}

.item-price-value-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-block-end: 14px;
}

.item-price-value-card {
  border-radius: 14px;
  background: rgba(var(--v-theme-on-surface), 0.024);
  padding: 11px 12px;
  min-inline-size: 0;
}

.item-price-value-card strong {
  display: block;
  overflow-wrap: anywhere;
  margin-block-start: 3px;
  color: rgba(var(--v-theme-on-surface), 0.92);
  font-size: 0.94rem;
  font-weight: 800;
  line-height: 1.25;
}

.item-price-bars {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.item-price-bar-row {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr) minmax(84px, auto);
  align-items: center;
  gap: 10px;
}

.item-price-bar-row span {
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 0.72rem;
  font-weight: 800;
}

.item-price-bar-row strong {
  color: rgba(var(--v-theme-on-surface), 0.74);
  font-size: 0.74rem;
  font-weight: 800;
  text-align: end;
  white-space: nowrap;
}

@media (max-width: 960px) {
  .chart-card-header-with-action {
    flex-direction: column;
  }

  .breakdown-metric-select {
    inline-size: 100%;
    min-inline-size: 0;
  }

  .item-price-summary-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 600px) {
  .comparison-summary-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .comparison-summary-wide {
    grid-column: auto;
  }

  .executive-breakdown-header {
    flex-direction: column;
    gap: 8px;
  }

  .executive-breakdown-total {
    align-self: flex-start;
  }

  .executive-breakdown-bar-row {
    grid-template-columns: 30px minmax(0, 1fr);
  }

  .executive-breakdown-value {
    grid-column: 2 / 3;
    text-align: start;
  }

  .item-price-summary-grid,
  .item-price-value-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .item-price-comparison-header {
    flex-direction: column;
    gap: 8px;
  }

  .item-price-variance-chip {
    align-self: flex-start;
  }

  .item-price-bar-row {
    grid-template-columns: 32px minmax(0, 1fr);
  }

  .item-price-bar-row strong {
    grid-column: 2 / 3;
    text-align: start;
  }
}

</style>