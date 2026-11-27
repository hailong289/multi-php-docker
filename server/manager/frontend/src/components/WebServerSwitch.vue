<script setup>
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'

// Nginx and Apache share one "Web server" menu entry; this switches between their pages.
const { t } = useI18n()
const route = useRoute()
const items = [
  { name: 'nginx', label: 'Nginx' },
  { name: 'apache', label: 'Apache' },
]
</script>

<template>
  <nav class="web-server-switch" :aria-label="t('nav.web_server')">
    <RouterLink
      v-for="item in items"
      :key="item.name"
      :to="{ name: item.name }"
      class="web-server-switch-item"
      :class="{ 'is-active': route.name === item.name }"
      :aria-current="route.name === item.name ? 'page' : undefined"
    >
      {{ item.label }}
    </RouterLink>
  </nav>
</template>
