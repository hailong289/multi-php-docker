<script setup>
import { computed } from 'vue'
import { CUSTOM_TLD, LOCAL_TLDS } from '../lib/localDomain'

const name = defineModel('name', { type: String, default: '' })
const tld = defineModel('tld', { type: String, default: '.test' })
const custom = defineModel('custom', { type: String, default: '' })

const isCustom = computed(() => tld.value === CUSTOM_TLD)
</script>

<template>
  <div class="domain-input-row">
    <input
      v-model="name"
      :placeholder="$t('form.domain_placeholder')"
      required
      autocomplete="off"
      spellcheck="false"
    />
    <select v-model="tld" :aria-label="$t('form.domain_tld')">
      <option v-for="item in LOCAL_TLDS" :key="item" :value="item">{{ item }}</option>
      <option :value="CUSTOM_TLD">{{ $t('form.domain_tld_custom') }}</option>
    </select>
    <input
      v-if="isCustom"
      v-model="custom"
      class="domain-custom-suffix"
      :placeholder="$t('form.domain_custom_placeholder')"
      required
      autocomplete="off"
      spellcheck="false"
      :aria-label="$t('form.domain_custom_placeholder')"
    />
  </div>
</template>
