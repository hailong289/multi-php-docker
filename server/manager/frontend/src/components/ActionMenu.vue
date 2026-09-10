<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Menu from 'primevue/menu'

const props = defineProps({
  items: {
    type: Array,
    default: () => [],
  },
  label: {
    type: String,
    default: '',
  },
})

const { t } = useI18n()
const menu = ref(null)

const visibleItems = computed(() =>
  (props.items || []).filter((item) => item && item.hidden !== true),
)

const anyLoading = computed(() => visibleItems.value.some((item) => item.loading))

const triggerLabel = computed(() => props.label || t('action.menu'))

const model = computed(() => {
  const safe = []
  const danger = []
  for (const item of visibleItems.value) {
    const entry = {
      label: item.loading ? t('action.working') : item.label,
      disabled: !!(item.disabled || item.loading),
      class: [
        item.danger ? 'action-menu-item-danger' : '',
        item.primary ? 'action-menu-item-accent' : '',
      ]
        .filter(Boolean)
        .join(' '),
      command: () => {
        if (item.disabled || item.loading) return
        item.run?.()
      },
    }
    if (item.danger) danger.push(entry)
    else safe.push(entry)
  }
  if (!safe.length && !danger.length) {
    return [{ label: t('action.menu_empty'), disabled: true }]
  }
  if (safe.length && danger.length) {
    return [...safe, { separator: true }, ...danger]
  }
  return [...safe, ...danger]
})

function toggle(event) {
  menu.value?.toggle(event)
}
</script>

<template>
  <div class="action-menu">
    <Button
      type="button"
      size="small"
      severity="secondary"
      outlined
      :label="triggerLabel"
      :loading="anyLoading"
      aria-haspopup="true"
      @click="toggle"
    />
    <Menu ref="menu" :model="model" popup />
  </div>
</template>
