<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const {
  showToast,
  translateApiError,
  stateClass,
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

function extStatusClass(status) {
  return `ext-status ext-status--${status}`
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
    if (window.confirm(t('php_controller.ini_restart_confirm'))) {
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
    if (!window.confirm(t('php_controller.ext_uninstall_confirm', { extension: name }))) {
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
        <button
          type="button"
          class="icon-back"
          :aria-label="t('php_controller.back')"
          :title="t('php_controller.back')"
          @click="router.push({ name: 'php-versions' })"
        >
          <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false">
            <path
              d="M12.5 4.5 7 10l5.5 5.5"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </button>
        <div>
          <h2>{{ t('php_controller.details_title', { version: label }) }}</h2>
          <p>{{ t('php_controller.details_subtitle') }}</p>
        </div>
      </div>
      <button type="button" :disabled="loading || !!pending" @click="load">
        {{ t('php_controller.refresh') }}
      </button>
    </div>

    <div v-if="loading && !details" class="panel-body">{{ t('loading') }}</div>
    <template v-else-if="details">
      <div class="panel-body nginx-overview">
        <div>
          <span class="state-badge" :class="stateClass(state)">
            {{ t(`php_controller.state_${state}`) }}
          </span>
          <code>{{ target.container }}</code>
        </div>
        <div class="controller-actions" data-tour="php-detail-actions">
          <button
            v-if="showCreateHint(service, target)"
            type="button"
            class="primary"
            :disabled="!phpActionEnabled(service, 'create') || !!pending"
            @click="runLifecycle('create')"
          >
            {{ isPending('php', { service, action: 'create' }) ? t('action.working') : t('php_controller.create') }}
          </button>
          <button
            type="button"
            :disabled="!phpActionEnabled(service, 'start') || !!pending"
            @click="runLifecycle('start')"
          >
            {{ isPending('php', { service, action: 'start' }) ? t('action.working') : t('php_controller.start') }}
          </button>
          <button
            type="button"
            :disabled="!phpActionEnabled(service, 'stop') || !!pending"
            @click="runLifecycle('stop')"
          >
            {{ isPending('php', { service, action: 'stop' }) ? t('action.working') : t('php_controller.stop') }}
          </button>
          <button
            type="button"
            :disabled="!phpActionEnabled(service, 'restart') || !!pending"
            @click="runLifecycle('restart')"
          >
            {{ isPending('php', { service, action: 'restart' }) ? t('action.working') : t('php_controller.restart') }}
          </button>
          <button
            type="button"
            data-tour="php-run"
            @click="router.push({ name: 'php-version-run', params: { service: service } })"
          >
            {{ t('php_controller.run') }}
          </button>
          <button
            type="button"
            :disabled="!!pending"
            @click="router.push({ name: 'php-version-supervisor', params: { service: service } })"
          >
            {{ t('php_controller.supervisor') }}
          </button>
        </div>
      </div>

      <div class="panel-body php-detail-tabs-wrap" data-tour="php-detail-tabs">
        <div class="php-detail-tabs" role="tablist">
          <button
            type="button"
            role="tab"
            :aria-selected="tab === 'extensions'"
            :class="{ active: tab === 'extensions' }"
            @click="tab = 'extensions'"
          >
            {{ t('php_controller.tab_extensions') }}
          </button>
          <button
            type="button"
            role="tab"
            :aria-selected="tab === 'ini'"
            :class="{ active: tab === 'ini' }"
            data-tour="php-detail-ini-tab"
            @click="tab = 'ini'"
          >
            {{ t('php_controller.tab_ini') }}
          </button>
        </div>
      </div>

      <div v-if="tab === 'extensions'" class="panel-body php-ext-panel">
        <p class="php-ext-banner">{{ t('php_controller.extensions_banner') }}</p>
        <p v-if="state !== 'running'" class="php-ext-warn">
          {{ t('php_controller.extensions_need_running') }}
        </p>

        <div class="php-ext-add">
          <div class="php-ext-add-copy">
            <h3>{{ t('php_controller.ext_custom_label') }}</h3>
            <p>{{ t('php_controller.ext_custom_hint') }}</p>
          </div>
          <form class="php-ext-add-form" @submit.prevent="installCustomExt">
            <input
              :id="datalistId + '-input'"
              v-model="customExt"
              type="text"
              autocomplete="off"
              spellcheck="false"
              :list="datalistId"
              :disabled="state !== 'running' || !!pending"
              :placeholder="t('php_controller.ext_custom_placeholder')"
              :aria-label="t('php_controller.ext_custom_label')"
            />
            <datalist :id="datalistId">
              <option v-for="name in availableExtensions" :key="name" :value="name" />
            </datalist>
            <button
              type="submit"
              class="primary"
              :disabled="state !== 'running' || !!pending || !normalizeExtName(customExt)"
            >
              <span
                v-if="pending.startsWith('install:')"
                class="btn-spinner"
                aria-hidden="true"
              ></span>
              {{
                pending.startsWith('install:')
                  ? t('action.working')
                  : t('php_controller.ext_custom_install')
              }}
            </button>
          </form>
        </div>

        <div v-if="loadedCount" class="php-ext-loaded">
          <span class="php-ext-loaded-count">
            {{ t('php_controller.loaded_count', { count: loadedCount }) }}
          </span>
          <span class="php-ext-loaded-preview" :title="(details.modules?.modules || []).join(', ')">
            {{ loadedModulePreview }}
          </span>
        </div>

        <div class="table-wrap php-ext-table-wrap">
          <table class="php-ext-table">
            <thead>
              <tr>
                <th>{{ t('php_controller.extension') }}</th>
                <th>{{ t('php_controller.state') }}</th>
                <th class="php-ext-actions-col">{{ t('php_controller.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="ext in details.extensions"
                :key="ext.name"
                :class="{ 'is-pending': rowPending(ext.name) }"
              >
                <td>
                  <code class="php-ext-name">{{ ext.name }}</code>
                </td>
                <td>
                  <span class="state-badge" :class="extStatusClass(ext.status)">
                    {{ statusLabel(ext.status) }}
                  </span>
                </td>
                <td class="php-ext-actions-col">
                  <div class="php-ext-row-actions">
                    <button
                      v-if="ext.status === 'available_to_install'"
                      type="button"
                      class="primary"
                      :disabled="state !== 'running' || !!pending"
                      @click="extAction(ext.name, 'install')"
                    >
                      <span
                        v-if="pending === `install:${ext.name}`"
                        class="btn-spinner"
                        aria-hidden="true"
                      ></span>
                      {{
                        pending === `install:${ext.name}`
                          ? t('action.working')
                          : t('php_controller.ext_install')
                      }}
                    </button>
                    <button
                      v-if="
                        ext.status === 'loaded' ||
                        ext.status === 'enabled_in_ini' ||
                        ext.status === 'disabled_in_ini'
                      "
                      type="button"
                      class="danger"
                      :disabled="state !== 'running' || !!pending"
                      @click="extAction(ext.name, 'uninstall')"
                    >
                      <span
                        v-if="pending === `uninstall:${ext.name}`"
                        class="btn-spinner"
                        aria-hidden="true"
                      ></span>
                      {{
                        pending === `uninstall:${ext.name}`
                          ? t('action.working')
                          : t('php_controller.ext_uninstall')
                      }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-else class="panel-body php-ini-panel">
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
          <button
            type="button"
            class="primary"
            :disabled="!!pending || !details.ini.readable"
            @click="saveIni"
          >
            {{ pending === 'ini' ? t('action.working') : t('php_controller.ini_save') }}
          </button>
        </div>
      </div>
    </template>
  </section>
</template>
