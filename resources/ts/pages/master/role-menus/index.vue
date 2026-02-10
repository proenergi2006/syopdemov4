<script setup lang="ts">
import axios from '@axios';
import { computed, h, onMounted, ref, resolveComponent, watch } from 'vue';

type Role = { id: number; nama: string; kode?: string }
type MenuRow = { id: number; parent_id: number | null; name: string; icon?: string | null; order_no?: number | null }
type MenuNode = MenuRow & { children?: MenuNode[] }

// ✅ sesuaikan:
// const API_BASE = '/master/role-menus' // kalau axios baseURL sudah /api
const API_BASE = '/master/role-menus'

const roles = ref<Role[]>([])
const selectedRole = ref<number | null>(null)

const menusFlat = ref<MenuRow[]>([])
const treeItems = ref<MenuNode[]>([])

const checked = ref<number[]>([])
const opened = ref<number[]>([])

const loading = ref(false)
const saving = ref(false)

// snackbar
const snackbar = ref(false)
const snackText = ref('')
const snackColor = ref<'success' | 'error' | 'warning' | 'info'>('success')
const notify = (text: string, color: 'success' | 'error' | 'warning' | 'info' = 'success') => {
  snackText.value = text
  snackColor.value = color
  snackbar.value = true
}

const roleItems = computed(() => roles.value.map(r => ({ title: r.nama, value: Number(r.id) })))

const buildTree = (flat: MenuRow[]) => {
  const byId = new Map<number, MenuNode>()
  flat.forEach(m => byId.set(Number(m.id), { ...m, children: [] }))

  const roots: MenuNode[] = []
  byId.forEach(node => {
    const pid = node.parent_id ? Number(node.parent_id) : null
    if (pid && byId.has(pid)) byId.get(pid)!.children!.push(node)
    else roots.push(node)
  })

  const sortTree = (arr: MenuNode[]) => {
    arr.sort((a, b) => Number(a.order_no ?? 9999) - Number(b.order_no ?? 9999))
    arr.forEach(n => n.children?.length && sortTree(n.children!))
  }
  sortTree(roots)

  return roots
}

const parentMap = computed(() => {
  const m = new Map<number, number | null>()
  menusFlat.value.forEach(x => m.set(Number(x.id), x.parent_id ? Number(x.parent_id) : null))
  return m
})

const includeParents = (ids: number[]) => {
  const out = new Set<number>(ids.map(Number))
  ids.forEach(id => {
    let p = parentMap.value.get(Number(id))
    while (p) {
      out.add(Number(p))
      p = parentMap.value.get(Number(p)) ?? null
    }
  })
  return Array.from(out)
}

const expandParentsOfChecked = () => {
  const opens = new Set<number>()
  checked.value.forEach(id => {
    let p = parentMap.value.get(Number(id))
    while (p) {
      opens.add(Number(p))
      p = parentMap.value.get(Number(p)) ?? null
    }
  })
  opened.value = Array.from(opens)
}

// checkbox helpers
const isChecked = (id: number) => checked.value.includes(Number(id))
const isOpen = (id: number) => opened.value.includes(Number(id))

const toggleOpen = (id: number) => {
  const nid = Number(id)
  if (opened.value.includes(nid)) opened.value = opened.value.filter(x => x !== nid)
  else opened.value.push(nid)
}

const toggleCheck = (id: number, on: boolean) => {
  const nid = Number(id)
  if (on) {
    if (!checked.value.includes(nid)) checked.value.push(nid)
  } else {
    checked.value = checked.value.filter(x => Number(x) !== nid)
  }
}

const checkAllChildren = (node: MenuNode, on: boolean) => {
  toggleCheck(node.id, on)
  node.children?.forEach(ch => checkAllChildren(ch, on))
}

// API
const fetchInit = async () => {
  loading.value = true
  try {
    const { data } = await axios.get(API_BASE)

    roles.value = Array.isArray(data?.roles) ? data.roles : []
    menusFlat.value = Array.isArray(data?.menus) ? data.menus : []
    treeItems.value = buildTree(menusFlat.value)

    if (!selectedRole.value && roles.value.length) selectedRole.value = Number(roles.value[0].id)
  } catch (e: any) {
    console.error('[RoleMenus] init error:', e?.response?.status, e?.response?.data || e)
    notify('Failed to load roles/menus', 'error')
  } finally {
    loading.value = false
  }
}

const fetchChecked = async () => {
  if (!selectedRole.value) return
  loading.value = true
  try {
    const { data } = await axios.get(API_BASE, { params: { role_id: selectedRole.value } })
    checked.value = Array.isArray(data?.checked) ? data.checked.map((x: any) => Number(x)) : []
    expandParentsOfChecked()
  } catch (e: any) {
    console.error('[RoleMenus] checked error:', e?.response?.status, e?.response?.data || e)
    checked.value = []
    notify('Failed to load role menus', 'error')
  } finally {
    loading.value = false
  }
}

const save = async () => {
  if (!selectedRole.value) return notify('Please select role', 'warning')

  saving.value = true
  try {
    const menuIds = includeParents(checked.value)
    const { data } = await axios.post(API_BASE, { role_id: selectedRole.value, menu_ids: menuIds })
    notify(data?.message || 'Saved', 'success')
    await fetchChecked()
  } catch (e: any) {
    console.error('[RoleMenus] save error:', e?.response?.status, e?.response?.data || e)
    notify(e?.response?.data?.message || 'Save failed', 'error')
  } finally {
    saving.value = false
  }
}

// ✅ render tree pakai native checkbox (pasti muncul)
const VBtn = resolveComponent('VBtn') as any
const VIcon = resolveComponent('VIcon') as any

const renderTree = (items: MenuNode[], level = 0) => {
  return items.map(item => {
    const hasChildren = !!item.children?.length
    const open = isOpen(item.id)
    const indent = `${level * 22}px`

    return h('div', { key: item.id }, [
      h(
        'div',
        { class: 'rm-row d-flex align-center', style: { paddingLeft: indent } },
        [
          hasChildren
            ? h(
                VBtn,
                { icon: true, variant: 'text', size: 'small', class: 'me-1', onClick: () => toggleOpen(item.id) },
                () => h(VIcon, { icon: open ? 'mdi-chevron-down' : 'mdi-chevron-right' }),
              )
            : h('div', { class: 'rm-spacer me-1' }),

          // ✅ native checkbox
          h('input', {
            class: 'rm-checkbox me-2',
            type: 'checkbox',
            checked: isChecked(item.id),
            onChange: (e: Event) => {
              const on = (e.target as HTMLInputElement).checked
              toggleCheck(item.id, on)
              if (hasChildren) checkAllChildren(item, on)
              if (on) toggleOpen(item.id) // optional: auto open when checked
            },
          }),

          item.icon ? h(VIcon, { icon: item.icon, size: 18, class: 'me-2' }) : null,
          h('div', { class: 'text-body-2' }, item.name),
        ],
      ),

      hasChildren && open ? h('div', {}, renderTree(item.children!, level + 1)) : null,
    ])
  })
}

onMounted(async () => {
  await fetchInit()
  if (selectedRole.value) await fetchChecked()
})

watch(selectedRole, async () => {
  checked.value = []
  opened.value = []
  await fetchChecked()
})
</script>

<template>
  <section>
    <VCard>
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap gap-3">
          <div>
            <h3 class="text-h6 mb-1">Role Menu Management</h3>
            <div class="text-body-2 opacity-70">Select role and tick menus, then Save.</div>
          </div>

          <VBtn color="primary" :loading="saving" :disabled="loading || !selectedRole" @click="save">
            Save
          </VBtn>
        </div>

        <div class="mt-4">
          <VSelect
            v-model="selectedRole"
            :items="roleItems"
            label="Select Role"
            clearable
            clear-icon="mdi-close"
            :loading="loading"
          />
        </div>
      </VCardText>

      <VDivider />

      <VCardText>
        <VAlert v-if="loading" type="info" variant="tonal">
          Loading menus...
        </VAlert>

        <VAlert v-else-if="!treeItems.length" type="warning" variant="tonal">
          No menus found.
        </VAlert>

        <div v-else class="mt-2">
          <div class="d-flex flex-column gap-1">
            <component :is="{ render: () => renderTree(treeItems) }" />
          </div>
        </div>
      </VCardText>
    </VCard>

    <VSnackbar v-model="snackbar" :color="snackColor" location="top end" :timeout="2500">
      {{ snackText }}
      <template #actions>
        <VBtn variant="text" @click="snackbar = false">Close</VBtn>
      </template>
    </VSnackbar>
  </section>
</template>

<style scoped>
.rm-row {
  min-height: 34px;
}

.rm-spacer {
  width: 36px;
  height: 1px;
}

/* ✅ biar checkbox look & size enak (mirip Vuetify) */
.rm-checkbox {
  width: 18px;
  height: 18px;
  accent-color: rgb(var(--v-global-theme-primary));
  cursor: pointer;
}
</style>
