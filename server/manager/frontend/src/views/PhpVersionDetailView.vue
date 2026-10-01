<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Tab from 'primevue/tab'
import TabList from 'primevue/tablist'
import TabPanel from 'primevue/tabpanel'
import TabPanels from 'primevue/tabpanels'
import Tabs from 'primevue/tabs'
import Tag from 'primevue/tag'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { confirmDialog } from '../lib/confirm'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const {
  showToast,
  translateApiError,
  phpAction,
  phpActionEnabled,
  phpServiceState,
  showCreateHint,
  isPending,
  loadBootstrap,
  data,
  dockerStatusBusy,
} = useManager()

const service = computed(() => String(route.params.service || ''))
const loading = ref(true)
const pending = ref('')
const tab = ref('extensions')
const details = ref(null)
const iniDraft = ref('')
const customExt = ref('')
let statusPollTimer = null

const label = computed(() => details.value?.target?.label || service.value)
const state = computed(() => details.value?.status?.state || phpServiceState(service.value))
const target = computed(() => details.value?.target || data.php_controllers?.targets?.[service.value] || {})
const availableExtensions = computed(() => details.value?.available?.extensions || [])
const datalistId = computed(() => `ext-suggestions-${service.value}`)
const loadedCount = computed(() => (details.value?.modules?.modules || []).length)
const loadedModulePreview = computed(() => {
  const mods = details.value?.modules?.modules || []
  if (mods.length <= 8) return mods.join(', ')
  return `${mods.slice(0, 8).join(', ')}…`
})

function statusLabel(status) {
  return t(`php_controller.ext_status_${status}`)
}

function stateSeverity(value) {
  if (value === 'running') return 'success'
  if (value === 'stopped') return 'secondary'
  if (value === 'error') return 'danger'
  if (value === 'busy') return 'warn'
  return 'contrast'
}

function extSeverity(status) {
  if (status === 'loaded' || status === 'enabled_in_ini') return 'success'
  if (status === 'available_to_install') return 'info'
  if (status === 'disabled_in_ini') return 'warn'
  return 'secondary'
}

function rowPending(name) {
  return pending.value === `install:${name}` || pending.value === `uninstall:${name}`
}

function normalizeExtName(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/\.so$/i, '')
}

async function installCustomExt() {
  const name = normalizeExtName(customExt.value)
  if (!/^[a-z][a-z0-9_]*$/.test(name)) {
    showToast('failure', t('php_controller.invalid_extension'))
    return
  }
  await extAction(name, 'install')
  customExt.value = ''
}

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true
  try {
    const result = await apiGet(`/api/php-controllers/${service.value}/details`)
    const next = result.php_details
    const previousIni = details.value?.ini?.content || ''
    details.value = next
    if (!silent || iniDraft.value === previousIni) {
      iniDraft.value = next?.ini?.content || ''
    }
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
  } finally {
    if (!silent) loading.value = false
  }
}

async function saveIni() {
  pending.value = 'ini'
  try {
    const result = await apiSend('PUT', `/api/php-controllers/${service.value}/ini`, {
      content: iniDraft.value,
    })
    showToast('success', t(result.message_key || 'php_controller.ini_saved'))
    details.value = result.php_details
    if (await confirmDialog(t('php_controller.ini_restart_confirm'), { acceptLabel: t('action.ok'), rejectLabel: t('action.cancel'), acceptSeverity: 'primary' })) {
      await phpAction(service.value, 'restart')
    }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function extAction(name, action) {
  if (action === 'uninstall') {
    if (!(await confirmDialog(t('php_controller.ext_uninstall_confirm', { extension: name }), { acceptLabel: t('action.delete'), rejectLabel: t('action.cancel'), acceptSeverity: 'danger' }))) {
      return
    }
  }
  pending.value = `${action}:${name}`
  try {
    const path = `/api/php-controllers/${service.value}/extensions/${name}/${action}`
    const result = await apiSend('POST', path, {})
    showToast(
      'success',
      t(result.message_key || 'php_controller.action_success', result.message_parameters || {}),
    )
    if (result.php_details) details.value = result.php_details
    else if (result.php_controllers) data.php_controllers = result.php_controllers
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function runLifecycle(action) {
  await phpAction(service.value, action)
}

function stopStatusPoll() {
  if (statusPollTimer) {
    clearInterval(statusPollTimer)
    statusPollTimer = null
  }
}

function startStatusPoll() {
  stopStatusPoll()
  const busy = state.value === 'busy' || dockerStatusBusy.value || !!pending.value
  const ms = busy ? 2000 : 5000
  statusPollTimer = setInterval(() => {
    if (document.visibilityState !== 'visible' || !service.value) return
    load({ silent: true })
  }, ms)
}

watch(service, () => {
  tab.value = 'extensions'
  load()
  startStatusPoll()
})

watch(
  () => data.php_controllers?.statuses?.[service.value],
  (status) => {
    if (!status || !details.value) return
    details.value = {
      ...details.value,
      status: { ...(details.value.status || {}), ...status },
    }
  },
)

watch(
  () => [state.value, dockerStatusBusy.value, pending.value],
  () => startStatusPoll(),
)

onMounted(async () => {
  await loadBootstrap()
  await load()
  startStatusPoll()
})

onUnmounted(() => {
  stopStatusPoll()
})
</script>

<template>
  <section class="panel" data-tour="php-detail-panel">
    <div class="panel-heading nginx-heading">
      <div class="php-detail-heading">
        <Button
          type="button"
          class="icon-back"
          icon="pi pi-arrow-left"
          severity="secondary"
          text
          rounded
          :aria-label="t('php_controller.back')"
          :title="t('php_controller.back')"
          @click="router.push({ name: 'php-versions' })"
        />
        <div>
          <h2>{{ t('php_controller.details_title', { version: label }) }}</h2>
          <p>{{ t('php_controller.details_subtitle') }}</p>
        </div>
      </div>
      <Button
        type="button"
        :label="t('php_controller.refresh')"
        :disabled="loading || !!pending"
        @click="load"
      />
    </div>

    <div v-if="loading && !details" class="panel-body">{{ t('loading') }}</div>
    <template v-else-if="details">
      <div class="panel-body nginx-overview">
        <div>
          <Tag
            :value="t(`php_controller.state_${state}`)"
            :severity="stateSeverity(state)"
            rounded
          />
          <code class="php-detail-container">{{ target.container }}</code>
        </div>
        <div class="controller-actions" data-tour="php-detail-actions">
          <Button
            v-if="showCreateHint(service, target)"
            type="button"
            size="small"
            :label="
              isPending('php', { service, action: 'create' })
                ? t('action.working')
                : t('php_controller.create')
            "
            :loading="isPending('php', { service, action: 'create' })"
            :disabled="!phpActionEnabled(service, 'create') || !!pending"
            @click="runLifecycle('create')"
          />
          <Button
            type="button"
            size="small"
            :label="
              isPending('php', { service, action: 'start' })
                ? t('action.working')
                : t('php_controller.start')
            "
            :loading="isPending('php', { service, action: 'start' })"
            :disabled="!phpActionEnabled(service, 'start') || !!pending"
            @click="runLifecycle('start')"
          />
          <Button
            type="button"
            size="small"
            severity="secondary"
            outlined
            :label="
              isPending('php', { service, action: 'stop' })
                ? t('action.working')
                : t('php_controller.stop')
            "
            :loading="isPending('php', { service, action: 'stop' })"
            :disabled="!phpActionEnabled(service, 'stop') || !!pending"
            @click="runLifecycle('stop')"
          />
          <Button
            type="button"
            size="small"
            severity="secondary"
            outlined
            :label="
              isPending('php', { service, action: 'restart' })
                ? t('action.working')
                : t('php_controller.restart')
            "
            :loading="isPending('php', { service, action: 'restart' })"
            :disabled="!phpActionEnabled(service, 'restart') || !!pending"
            @click="runLifecycle('restart')"
          />
          <Button
            type="button"
            size="small"
            severity="secondary"
            outlined
            data-tour="php-logs-btn"
            :label="t('php_controller.view_logs')"
            :disabled="phpServiceState(service) === 'not_created'"
            @click="router.push({ name: 'php-version-logs', params: { service: service } })"
          />
          <Button
            type="button"
            size="small"
            severity="secondary"
            outlined
            data-tour="php-run"
            :label="t('php_controller.run')"
            @click="router.push({ name: 'php-version-run', params: { service: service } })"
          />
          <Button
            type="button"
            size="small"
            severity="secondary"
            outlined
            :label="t('php_controller.supervisor')"
            :disabled="!!pending"
            @click="router.push({ name: 'php-version-supervisor', params: { service: service } })"
          />
        </div>
      </div>

      <div class="panel-body php-detail-tabs-wrap" data-tour="php-detail-tabs">
        <Tabs v-model:value="tab">
          <TabList>
            <Tab value="extensions">{{ t('php_controller.tab_extensions') }}</Tab>
            <Tab value="ini" data-tour="php-detail-ini-tab">{{ t('php_controller.tab_ini') }}</Tab>
          </TabList>
          <TabPanels>
            <TabPanel value="extensions">
              <div class="php-ext-panel">
                <p class="php-ext-banner">{{ t('php_controller.extensions_banner') }}</p>
                <Message
                  v-if="state !== 'running'"
                  severity="warn"
                  :closable="false"
                  class="php-ext-warn-msg"
                >
                  {{ t('php_controller.extensions_need_running') }}
                </Message>

                <div class="php-ext-add">
                  <div class="php-ext-add-copy">
                    <h3>{{ t('php_controller.ext_custom_label') }}</h3>
                    <p>{{ t('php_controller.ext_custom_hint') }}</p>
                  </div>
                  <form class="php-ext-add-form" @submit.prevent="installCustomExt">
                    <InputText
                      :id="datalistId + '-input'"
                      v-model="customExt"
                      type="text"
                      autocomplete="off"
                      spellcheck="false"
                      :list="datalistId"
                      :disabled="state !== 'running' || !!pending"
                      :placeholder="t('php_controller.ext_custom_placeholder')"
                      :aria-label="t('php_controller.ext_custom_label')"
                      fluid
                    />
                    <datalist :id="datalistId">
                      <option v-for="name in availableExtensions" :key="name" :value="name" />
                    </datalist>
                    <Button
                      type="submit"
                      :label="
                        pending.startsWith('install:')
                          ? t('action.working')
                          : t('php_controller.ext_custom_install')
                      "
                      :loading="pending.startsWith('install:')"
                      :disabled="state !== 'running' || !!pending || !normalizeExtName(customExt)"
                    />
                  </form>
                </div>

                <div v-if="loadedCount" class="php-ext-loaded">
                  <span class="php-ext-loaded-count">
                    {{ t('php_controller.loaded_count', { count: loadedCount }) }}
                  </span>
                  <span
                    class="php-ext-loaded-preview"
                    :title="(details.modules?.modules || []).join(', ')"
                  >
                    {{ loadedModulePreview }}
                  </span>
                </div>

                <DataTable
                  :value="details.extensions || []"
                  data-key="name"
                  striped-rows
                  :row-class="(ext) => (rowPending(ext.name) ? 'is-pending' : '')"
                >
                  <Column :header="t('php_controller.extension')">
                    <template #body="{ data: ext }">
                      <code class="php-ext-name">{{ ext.name }}</code>
                    </template>
                  </Column>
                  <Column :header="t('php_controller.state')">
                    <template #body="{ data: ext }">
                      <Tag
                        :value="statusLabel(ext.status)"
                        :severity="extSeverity(ext.status)"
                        rounded
                      />
                    </template>
                  </Column>
                  <Column :header="t('php_controller.actions')">
                    <template #body="{ data: ext }">
                      <div class="php-ext-row-actions">
                        <Button
                          v-if="ext.status === 'available_to_install'"
                          type="button"
                          size="small"
                          :label="
                            pending === `install:${ext.name}`
                              ? t('action.working')
                              : t('php_controller.ext_install')
                          "
                          :loading="pending === `install:${ext.name}`"
                          :disabled="state !== 'running' || !!pending"
                          @click="extAction(ext.name, 'install')"
                        />
                        <Button
                          v-if="
                            ext.status === 'loaded' ||
                            ext.status === 'enabled_in_ini' ||
                            ext.status === 'disabled_in_ini'
                          "
                          type="button"
                          size="small"
                          severity="danger"
                          outlined
                          :label="
                            pending === `uninstall:${ext.name}`
                              ? t('action.working')
                              : t('php_controller.ext_uninstall')
                          "
                          :loading="pending === `uninstall:${ext.name}`"
                          :disabled="state !== 'running' || !!pending"
                          @click="extAction(ext.name, 'uninstall')"
                        />
                      </div>
                    </template>
                  </Column>
                </DataTable>
              </div>
            </TabPanel>
            <TabPanel value="ini">
              <div class="php-ini-panel">
                <p v-if="details.ini.relative_path" class="php-ini-path">
                  <code>{{ details.ini.relative_path }}</code>
                </p>
                <MonacoEditor
                  v-model="iniDraft"
                  language="ini"
                  min-height="360px"
                  :read-only="!!pending || !details.ini.readable"
                />
                <div class="controller-actions php-ini-actions">
                  <Button
                    type="button"
                    :label="pending === 'ini' ? t('action.working') : t('php_controller.ini_save')"
                    :loading="pending === 'ini'"
                    :disabled="!!pending || !details.ini.readable"
                    @click="saveIni"
                  />
                </div>
              </div>
            </TabPanel>
          </TabPanels>
        </Tabs>
      </div>
    </template>
  </section>
</template>
