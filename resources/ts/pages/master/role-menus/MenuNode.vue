<script setup lang="ts">
import { computed } from 'vue'

type TreeNode = {
  id: number
  name: string
  children?: TreeNode[]
}

const props = defineProps<{
  node: TreeNode
  level: number
  expanded: Record<number, boolean>
  isChecked: (id: number) => boolean
  someChildrenChecked: (node: TreeNode) => boolean
}>()

const emit = defineEmits<{
  (e: 'toggle-expand', id: number): void
  (e: 'toggle', node: TreeNode, value: boolean): void
}>()

const hasChildren = computed(() => props.node.children && props.node.children.length > 0)
const isOpen = computed(() => props.expanded[props.node.id])
</script>

<template>
  <div :style="{ paddingLeft: `${level * 18}px` }" class="d-flex align-center gap-2">
    <!-- Expand -->
    <VBtn
      v-if="hasChildren"
      icon
      variant="text"
      size="x-small"
      @click="emit('toggle-expand', node.id)"
    >
      <VIcon :icon="isOpen ? 'mdi-chevron-down' : 'mdi-chevron-right'" />
    </VBtn>
    <div v-else style="width:32px" />

    <!-- Checkbox -->
    <VCheckbox
      :model-value="isChecked(node.id)"
      :indeterminate="!isChecked(node.id) && someChildrenChecked(node)"
      :label="node.name"
      @update:model-value="val => emit('toggle', node, val)"
    />

    <!-- Children -->
    <div v-if="hasChildren && isOpen" class="w-100">
      <MenuNode
        v-for="c in node.children"
        :key="c.id"
        :node="c"
        :level="level + 1"
        :expanded="expanded"
        :is-checked="isChecked"
        :some-children-checked="someChildrenChecked"
        @toggle-expand="emit('toggle-expand', $event)"
        @toggle="emit('toggle', $event[0], $event[1])"
      />
    </div>
  </div>
</template>
