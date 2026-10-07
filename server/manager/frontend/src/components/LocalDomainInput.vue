<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { CUSTOM_TLD, LOCAL_TLDS } from '../lib/localDomain'

const { t } = useI18n()

const name = defineModel('name', { type: String, default: '' })
const tld = defineModel('tld', { type: String, default: '.test' })
const custom = defineModel('custom', { type: String, default: '' })

const isCustom = computed(() => tld.value === CUSTOM_TLD)

const tldOptions = computed(() => [
  ...LOCAL_TLDS.map((item) => ({ label: item, value: item })),
  { label: t('form.domain_tld_custom'), value: CUSTOM_TLD },
])
</script>

<template>
  <div class="domain-input-row">
    <InputText
      v-model="name"
      :placeholder="$t('form.domain_placeholder')"
      required
      autocomplete="off"
      spellcheck="false"
      fluid
    />
    <Select
      v-model="tld"
      :options="tldOptions"
      option-label="label"
      option-value="value"
      :aria-label="$t('form.domain_tld')"
      class="domain-tld-select"
    />
    <InputText
      v-if="isCustom"
      v-model="custom"
      class="domain-custom-suffix"
      :placeholder="$t('form.domain_custom_placeholder')"
      required
      autocomplete="off"
      spellcheck="false"
      :aria-label="$t('form.domain_custom_placeholder')"
      fluid
    />
  </div>
</template>
