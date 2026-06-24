<script setup lang="ts">
import axios from '@axios'
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

const items = ref<any[]>([])
const loading = ref(false)

const fetchData = async () => {
  loading.value = true

  try {
    const res = await axios.get('/inventory/shipping-instruction')

    items.value = res.data
    // console.log(res)
  } catch (err) {
    console.error(err)
  } finally {
    loading.value = false
  }
}

const goDetail = (id: number) => {
  router.push(`/purchaseSupplier/shipping-instruction/${id}`)
}

const userRoles = ref<string[]>([])
const getProfile = async () => {
  try {
    const res = await axios.get('/auth/me')

    userRoles.value = res.data.role

    // console.log('ROLE:', res)
  } catch (err) {
    console.error(err)
  }
}
const canApprove = (item: any) => {
  if (userRoles.value.includes('Logistic Manager') && item.status === 0) return true
  if (userRoles.value.includes('CFO') && item.status === 1) return true
  if (userRoles.value.includes('CEO') && item.status === 2) return true
  return false
}

const getRowClass = (item: any) => {
  // Log role
  if (userRoles.value.includes('Logistic Manager') && item.status === 0 && item.nomor_si== null) {
    return 'bg-grey-100'
  }
  // CFO role
  if (userRoles.value.includes('CFO') && item.status === 1 && item.cfo_result === 0) {
    return 'bg-grey-100'
  }

  // CEO role
  if (userRoles.value.includes('CEO') &&item.status === 2 && item.ceo_result === 0) {
    return 'bg-grey-100'
  }

  return ''
}
const getStatusLabel = (item: any) => {
  if (item.status == 0) {
    return 'Request Procurement'
  } else if (item.status == 1) {
    return 'Verifikasi CFO'
  } else if (item.status == 2) {
    if (item.cfo_result == 1) {
      return 'Verifikasi CEO'
    } else {
      return `Ditolak CFO (${formatDate(item.cfo_tanggal)})`
    }
  } else if (item.status == 3) {
    if (item.ceo_result == 1) {
      return `Terverifikasi CEO (${formatDate(item.ceo_tanggal)})`
    } else {
      return `Ditolak CEO (${formatDate(item.ceo_tanggal)})`
    }
  }

  return '-'
}

const formatDate = (date: string) => {
  if (!date) return '-'

  return new Date(date).toLocaleString('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }) + ' WIB'
}

onMounted(() => {
  getProfile()
  fetchData()
})
</script>

<template>
  <section>

    <VCard>
      <VCardText class="d-flex flex-wrap gap-4 align-center">
        <h4> Shipping Instruction Request</h4>
        <VSpacer />

        <VChip v-if="loading" size="small" variant="tonal">
          Loading...
        </VChip>
      </VCardText>

      <VDivider />

      <VTable >
        <thead>
         <tr>
            <th>No</th>
            <th>No Shipping Req</th>
            <th>No PO Supplier</th>
            <th>Vendor/Terminal</th>
            <th>Tanggal</th>
            <th>Quantity</th>
            <th>Disposisi</th>
            <th >Aksi</th>
         </tr>
       </thead>
       <tbody>
        <tr v-for="(v, index) in items" :key="v.id_master" :class="getRowClass(v)">
            <td>
              {{ index + 1 }}
            </td>
            <td class="text-caption text-no-wrap">{{ v.nomor_req}}</td>
            <td class="text-caption text-no-wrap">{{ v.po_supplier.nomor_po }}</td>
             <td> 
              <div><strong>{{ v.po_supplier.vendor.nama_vendor }}</strong></div>
              <div class="text-caption text-grey">
                {{ v.po_supplier.terminal?.nama_terminal+' - '+ v.po_supplier.terminal?.lokasi_terminal|| '-' }}
              </div>
            </td>
            <td>{{ v.created_at }}</td>
            <td>{{ v.quantity }}</td>
           <td v-html="getStatusLabel(v)"></td>
            <td>
               <VBtn size="34" class="mr-1" variant="tonal" color="primary" @click="goDetail(v.id_master)">
                  <VIcon icon="ri-information-2-line"/>
                </VBtn>
            </td>
        </tr>
       </tbody>
     
      </VTable>

      <VDivider />
    </VCard>
  </section>
</template>
<style>
.row-pending {
  background-color: #f5f5f5 !important;
  opacity: 0.7;
}
</style>