<script setup>
import { computed } from 'vue'
import Select from 'primevue/select'
import { useManager } from '../composables/useManager'

const model = defineModel({ type: String, default: '' })
const { domainEntries } = useManager()

const options = computed(() => {
  const names = []
  const seen = new Set()
  for (const item of domainEntries.value) {
    const name = String(item.domain_name || '').trim().toLowerCase()
    if (!name || seen.has(name)) continue
    seen.add(name)
    names.push(name)
  }
  return names
})
</script>

<template>
  <Select
    v-model="model"
    :options="options"
    editable
    fluid
    :placeholder="$t('form.server_domain_placeholder')"
    :aria-label="$t('form.domain')"
    autocomplete="off"
    spellcheck="false"
  />
</template>
