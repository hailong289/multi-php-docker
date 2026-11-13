<script setup>
import { computed, provide, watch } from 'vue'
import { routerKey, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import { useManager } from '../composables/useManager'
import { provideRoute, resolveTab, splitTabs, tabLabel } from '../lib/routeTabs'

const props = defineProps({ paneId: { type: String, required: true } })

const { t } = useI18n()
const router = useRouter()
const { data, loadForRoute } = useManager()

const fullPath = computed(() => splitTabs.value.find((pane) => pane.id === props.paneId)?.fullPath || '/')
const location = computed(() => resolveTab(router, fullPath.value))
const view = computed(() => location.value.matched[0]?.components?.default)
const title = computed(() => tabLabel(router, t, data.servers, location.value.fullPath))

// Views keep calling useRoute()/useRouter(); inside the pane both target the pane, not the URL.
provideRoute(location)
const paneRouter = Object.create(router)
paneRouter.currentRoute = location
paneRouter.push = paneRouter.replace = async (to) => {
  const next = resolveTab(router, to).fullPath
  splitTabs.value = splitTabs.value.map((pane) => (pane.id === props.paneId ? { ...pane, fullPath: next } : pane))
}
provide(routerKey, paneRouter)

watch(
  () => location.value.name,
  (name) => {
    if (name) loadForRoute(name)
  },
  { immediate: true },
)

function closePane() {
  splitTabs.value = splitTabs.value.filter((pane) => pane.id !== props.paneId)
}

function moveToMain() {
  const target = fullPath.value
  closePane()
  router.push(target)
}
</script>

<template>
  <section class="split-pane" :aria-label="title">
    <header class="split-pane-bar">
      <span class="split-pane-title">{{ title }}</span>
      <Button
        icon="pi pi-window-maximize"
        text
        rounded
        size="small"
        severity="secondary"
        :aria-label="t('tabs.move_to_main')"
        :title="t('tabs.move_to_main')"
        @click="moveToMain"
      />
      <Button
        icon="pi pi-times"
        text
        rounded
        size="small"
        severity="secondary"
        :aria-label="t('tabs.close_split')"
        :title="t('tabs.close_split')"
        @click="closePane"
      />
    </header>
    <component :is="view" v-if="view" :key="location.path" />
  </section>
</template>
