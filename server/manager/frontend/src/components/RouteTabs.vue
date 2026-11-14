<script setup>
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useManager } from '../composables/useManager'
import {
  addSplit,
  removeTab,
  resolveTab,
  routeTabs,
  splitTabs,
  tabIcon,
  tabLabel,
  upsertTab,
} from '../lib/routeTabs'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { data } = useManager()

function label(tab) {
  return tabLabel(router, t, data.servers, tab.fullPath)
}

function icon(tab) {
  return tabIcon(router, tab.fullPath)
}

async function close(tab) {
  const { list, neighbor } = removeTab(routeTabs.value, tab.path)
  // Leave the tab first so KeepAlive unmounts it as a normal view.
  if (tab.path === route.path && neighbor) await router.push(neighbor.fullPath)
  else if (tab.path === route.path) await router.push('/')
  routeTabs.value = list
}

/** Moves the tab into a side pane; when all panes are taken, the last one's screen goes back to the tab bar. */
async function openSplit(tab) {
  const { list, evicted } = addSplit(splitTabs.value, tab.fullPath)
  splitTabs.value = list
  await close(tab)
  if (evicted) {
    routeTabs.value = upsertTab(routeTabs.value, resolveTab(router, evicted.fullPath).path, evicted.fullPath)
  }
}
</script>

<template>
  <div class="workspace-tabs-bar" role="tablist" :aria-label="t('tabs.label')">
    <div class="workspace-tabs-list">
      <div
        v-for="tab in routeTabs"
        :key="tab.path"
        class="workspace-tab"
        :class="{ 'is-active': tab.path === route.path }"
        role="tab"
        :aria-selected="tab.path === route.path"
        @auxclick.middle.prevent="close(tab)"
      >
        <RouterLink
          :to="tab.fullPath"
          class="workspace-tab-link"
          :aria-current="tab.path === route.path ? 'page' : undefined"
        >
          <i :class="['pi', icon(tab), 'workspace-tab-icon']" aria-hidden="true" />
          <span class="workspace-tab-text" :title="label(tab)">{{ label(tab) }}</span>
        </RouterLink>
        <button
          type="button"
          class="workspace-tab-btn workspace-tab-split route-tab-close"
          :aria-label="t('tabs.open_split')"
          :title="t('tabs.open_split')"
          @click="openSplit(tab)"
        >
          <i class="pi pi-objects-column" aria-hidden="true" />
        </button>
        <button
          type="button"
          class="workspace-tab-btn workspace-tab-close route-tab-close"
          :aria-label="t('tabs.close')"
          :title="t('tabs.close')"
          @click="close(tab)"
        >
          <i class="pi pi-times" aria-hidden="true" />
        </button>
      </div>
    </div>
  </div>
</template>
