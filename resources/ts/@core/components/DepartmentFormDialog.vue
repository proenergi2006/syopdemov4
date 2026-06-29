<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'

export type DepartmentRow = {
  id: number
  kode: string
  nama: string
  is_active: boolean
  created_at?: string | null
  updated_at?: string | null
}

export type DepartmentPayload = {
  kode: string
  nama: string
  is_active: boolean
}

type DialogMode = 'create' | 'edit'

type Props = {
  modelValue: boolean
  mode: DialogMode
  department?: DepartmentRow | null
  loading?: boolean
  errors?: Record<string, string>
}

const props = withDefaults(defineProps<Props>(), {
  department: null,
  loading: false,
  errors: () => ({}),
})

const emit = defineEmits<{
  (
    event: 'update:modelValue',
    value: boolean
  ): void

  (
    event: 'submit',
    value: DepartmentPayload
  ): void
}>()

const formRef = ref<any>(null)

const form = ref<DepartmentPayload>({
  kode: '',
  nama: '',
  is_active: true,
})

const dialogOpen = computed({
  get: () => props.modelValue,
  set: value => {
    if (!props.loading)
      emit('update:modelValue', value)
  },
})

const isEdit = computed(() => props.mode === 'edit')

const dialogTitle = computed(() => {
  return isEdit.value
    ? 'Edit Department'
    : 'Tambah Department'
})

const dialogSubtitle = computed(() => {
  return isEdit.value
    ? 'Perbarui informasi department yang dipilih.'
    : 'Tambahkan department baru ke dalam sistem.'
})

const requiredRule = (value: unknown) => {
  return String(value ?? '').trim().length > 0
    || 'Field ini wajib diisi.'
}

const maxLengthRule = (
  maximum: number,
  label: string
) => {
  return (value: unknown) => {
    return String(value ?? '').length <= maximum
      || `${label} maksimal ${maximum} karakter.`
  }
}

const resetForm = async () => {
  form.value = {
    kode: props.department?.kode ?? '',
    nama: props.department?.nama ?? '',
    is_active: props.department?.is_active ?? true,
  }

  await nextTick()
  formRef.value?.resetValidation()
}

watch(
  [
    () => props.modelValue,
    () => props.department,
    () => props.mode,
  ],
  async ([isOpen]) => {
    if (isOpen)
      await resetForm()
  },
  {
    immediate: true,
  },
)

const closeDialog = () => {
  if (props.loading)
    return

  emit('update:modelValue', false)
}

const submitForm = async () => {
  if (props.loading)
    return

  const validation = await formRef.value?.validate()

  if (validation && !validation.valid)
    return

  emit('submit', {
    kode: form.value.kode
      .trim()
      .toUpperCase(),

    nama: form.value.nama.trim(),

    is_active: Boolean(form.value.is_active),
  })
}
</script>

<template>
  <VDialog
    v-model="dialogOpen"
    max-width="560"
    :persistent="loading"
    :retain-focus="false"
  >
    <VCard>
      <VCardItem class="pa-6 pb-3">
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
            size="44"
            class="me-3"
          >
            <VIcon
              :icon="
                isEdit
                  ? 'mdi-office-building-edit-outline'
                  : 'mdi-office-building-plus-outline'
              "
              size="24"
            />
          </VAvatar>
        </template>

        <VCardTitle class="text-h5">
          {{ dialogTitle }}
        </VCardTitle>

        <VCardSubtitle class="mt-1">
          {{ dialogSubtitle }}
        </VCardSubtitle>
      </VCardItem>

      <VDivider />

      <VForm
        ref="formRef"
        @submit.prevent="submitForm"
      >
        <VCardText class="pa-6">
          <VRow>
            <VCol cols="12">
              <VTextField
                v-model="form.kode"
                label="Kode Department"
                placeholder="Contoh: IT"
                prepend-inner-icon="mdi-identifier"
                maxlength="20"
                counter="20"
                autocomplete="off"
                :disabled="loading"
                :rules="[
                  requiredRule,
                  maxLengthRule(20, 'Kode department'),
                ]"
                :error-messages="errors.kode"
                @update:model-value="
                  form.kode = String($event ?? '').toUpperCase()
                "
              />
            </VCol>

            <VCol cols="12">
              <VTextField
                v-model="form.nama"
                label="Nama Department"
                placeholder="Contoh: Information Technology"
                prepend-inner-icon="mdi-office-building-outline"
                maxlength="120"
                counter="120"
                autocomplete="off"
                :disabled="loading"
                :rules="[
                  requiredRule,
                  maxLengthRule(120, 'Nama department'),
                ]"
                :error-messages="errors.nama"
              />
            </VCol>

            <VCol cols="12">
              <div
                class="d-flex align-center justify-space-between border rounded pa-4"
              >
                <div>
                  <div class="text-body-1 font-weight-medium">
                    Status Department
                  </div>

                  <div class="text-body-2 text-medium-emphasis mt-1">
                    Department aktif dapat dipilih pada data user,
                    permission, dan transaksi.
                  </div>
                </div>

                <VSwitch
                  v-model="form.is_active"
                  color="success"
                  hide-details
                  inset
                  :disabled="loading"
                />
              </div>

              <VAlert
                class="mt-3"
                density="compact"
                variant="tonal"
                :color="form.is_active ? 'success' : 'warning'"
                :icon="
                  form.is_active
                    ? 'mdi-check-circle-outline'
                    : 'mdi-alert-circle-outline'
                "
              >
                {{
                  form.is_active
                    ? 'Department akan berstatus aktif.'
                    : 'Department akan berstatus nonaktif dan tidak muncul pada dropdown aktif.'
                }}
              </VAlert>

              <div
                v-if="errors.is_active"
                class="text-error text-caption mt-1"
              >
                {{ errors.is_active }}
              </div>
            </VCol>
          </VRow>
        </VCardText>

        <VDivider />

        <VCardActions class="pa-6 pt-4">
          <VSpacer />

          <VBtn
            variant="outlined"
            color="secondary"
            :disabled="loading"
            @click="closeDialog"
            class="text-none"
          >
            Batal
          </VBtn>

          <VBtn
            type="submit"
            color="primary"
            :loading="loading"
            :disabled="loading"
            prepend-icon="mdi-content-save-outline"
            class="text-none"
          >
            {{ isEdit ? 'Simpan Perubahan' : 'Tambah Department' }}
          </VBtn>
        </VCardActions>
      </VForm>
    </VCard>
  </VDialog>
</template>