<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const { showToast, translateApiError, stateClass } = useManager()
const tab = ref('control')
const loading = ref(true)
const pending = ref('')
const nginx = ref({
  container: 'nginx_container',
  state: 'not_created',
  test_status: null,
  reload_status: null,
  logs: {},
})

const templates = ref([])
const templatesLoading = ref(false)
const selectedName = ref('')
const draft = ref('')
const original = ref('')
const templateMeta = ref(null)
const templateLoading = ref(false)
let pollTimer = null

const stateLabel = computed(() => t(`nginx.state_${nginx.value.state || 'not_created'}`))
const dirty = computed(() => draft.value !== original.value)

function enabled(action) {
  if (pending.value || nginx.value.state === 'busy') return false
  if (action === 'start') return nginx.value.state === 'stopped'
  return nginx.value.state === 'running'
}

function formatSize(bytes) {
  if (!bytes && bytes !== 0) return '—'
  if (bytes < 1024) return `${bytes} B`
  return `${(bytes / 1024).toFixed(1)} KB`
}

function formatTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true
  try {
    const result = await apiGet('/api/nginx/management')
    nginx.value = result.nginx_management
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
  } finally {
    if (!silent) loading.value = false
  }
}

async function loadTemplates({ silent = false } = {}) {
  if (!silent) templatesLoading.value = true
  try {
    const result = await apiGet('/api/nginx/templates')
    templates.value = result.templates || []
    if (selectedName.value && !templates.value.some((item) => item.name === selectedName.value)) {
      selectedName.value = ''
      draft.value = ''
      original.value = ''
      templateMeta.value = null
    }
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
  } finally {
    if (!silent) templatesLoading.value = false
  }
}

async function openTemplate(name) {
  if (dirty.value && !confirm(t('nginx.template_discard_confirm'))) return
  selectedName.value = name
  templateLoading.value = true
  try {
    const result = await apiGet(`/api/nginx/templates/${encodeURIComponent(name)}`)
    const tpl = result.template
    draft.value = tpl.content || ''
    original.value = draft.value
    templateMeta.value = tpl
  } catch (error) {
    showToast('failure', translateApiError(error))
    selectedName.value = ''
  } finally {
    templateLoading.value = false
  }
}

async function saveTemplate() {
  if (!selectedName.value || !dirty.value) return
  pending.value = 'template-save'
  try {
    const result = await apiSend(
      'PUT',
      `/api/nginx/templates/${encodeURIComponent(selectedName.value)}`,
      { content: draft.value, soft_reload: true },
    )
    original.value = draft.value
    templateMeta.value = { ...(templateMeta.value || {}), ...result.template }
    showToast('success', t(result.message_key || 'nginx.template_saved_reloading'))
    await loadTemplates({ silent: true })
    setTimeout(() => load({ silent: true }), 1800)
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function run(action, path) {
  pending.value = action
  try {
    const result = await apiSend('POST', path, {})
    showToast('success', t(result.message_key || 'nginx.requested'))
    await new Promise((resolve) => setTimeout(resolve, 1400))
    await load()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

function statusText(status) {
  if (!status) return t('nginx.no_result')
  return status.message_key ? t(status.message_key) : status.message || t('nginx.no_result')
}

watch(tab, (next) => {
  if (next === 'templates') loadTemplates()
})

onMounted(() => {
  load()
  pollTimer = setInterval(() => {
    if (document.visibilityState === 'visible' && !pending.value) {
      load({ silent: true })
      if (tab.value === 'templates') loadTemplates({ silent: true })
    }
  }, 5000)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
  <section class="panel" data-tour="nginx-panel">
    <div class="panel-heading nginx-heading">
      <div>
        <h2>{{ t('nginx.title') }}</h2>
        <p>{{ t('nginx.subtitle') }}</p>
      </div>
      <button
        type="button"
        :disabled="loading || templatesLoading || !!pending"
        @click="tab === 'templates' ? loadTemplates() : load()"
      >
        {{ t('nginx.refresh') }}
      </button>
    </div>

    <div class="panel-body php-detail-tabs-wrap" data-tour="nginx-tabs">
      <div class="php-detail-tabs" role="tablist">
        <button
          type="button"
          role="tab"
          :aria-selected="tab === 'control'"
          :class="{ active: tab === 'control' }"
          @click="tab = 'control'"
        >
          {{ t('nginx.tab_control') }}
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="tab === 'templates'"
          :class="{ active: tab === 'templates' }"
          data-tour="nginx-templates-tab"
          @click="tab = 'templates'"
        >
          {{ t('nginx.tab_templates') }}
        </button>
      </div>
    </div>

    <div v-if="tab === 'control'">
      <div v-if="loading" class="panel-body">{{ t('loading') }}</div>
      <template v-else>
        <div class="panel-body nginx-overview">
          <div>
            <span class="state-badge" :class="stateClass(nginx.state)">{{ stateLabel }}</span>
            <code>{{ nginx.container }}</code>
          </div>
          <div class="controller-actions" data-tour="nginx-actions">
            <button :disabled="!enabled('start')" @click="run('start', '/api/nginx/actions/start')">
              {{ pending === 'start' ? t('action.working') : t('nginx.start') }}
            </button>
            <button :disabled="!enabled('stop')" @click="run('stop', '/api/nginx/actions/stop')">
              {{ pending === 'stop' ? t('action.working') : t('nginx.stop') }}
            </button>
            <button :disabled="!enabled('restart')" @click="run('restart', '/api/nginx/actions/restart')">
              {{ pending === 'restart' ? t('action.working') : t('nginx.restart') }}
            </button>
            <button :disabled="!enabled('test')" @click="run('test', '/api/nginx/test')">
              {{ pending === 'test' ? t('action.working') : t('nginx.test') }}
            </button>
            <button class="primary" :disabled="!enabled('reload')" @click="run('reload', '/api/nginx/reload')">
              {{ pending === 'reload' ? t('action.working') : t('nginx.apply_reload') }}
            </button>
          </div>
        </div>

        <div class="panel-body nginx-results">
          <p><strong>{{ t('nginx.test_result') }}:</strong> {{ statusText(nginx.test_status) }}</p>
          <p><strong>{{ t('nginx.reload_result') }}:</strong> {{ statusText(nginx.reload_status) }}</p>
        </div>

        <div class="panel-body nginx-log-grid" data-tour="nginx-logs">
          <article v-for="name in ['operation', 'error', 'access']" :key="name" class="nginx-log-card">
            <h3>{{ t(`nginx.log_${name}`) }}</h3>
            <pre>{{ nginx.logs?.[name]?.available ? nginx.logs[name].content : t('nginx.log_empty') }}</pre>
          </article>
        </div>
      </template>
    </div>

    <div v-else class="nginx-templates" data-tour="nginx-templates">
      <div class="panel-body">
        <p class="status-line warn">{{ t('nginx.templates_warn') }}</p>
      </div>

      <div v-if="templatesLoading && templates.length === 0" class="panel-body">{{ t('loading') }}</div>
      <div v-else-if="templates.length === 0" class="panel-body empty">{{ t('nginx.templates_empty') }}</div>
      <div v-else class="nginx-templates-layout">
        <div class="table-wrap nginx-templates-list">
          <table>
            <thead>
              <tr>
                <th>{{ t('nginx.template_name') }}</th>
                <th>{{ t('nginx.template_size') }}</th>
                <th>{{ t('nginx.template_updated') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in templates"
                :key="item.name"
                :class="{ 'is-selected': item.name === selectedName }"
                @click="openTemplate(item.name)"
              >
                <td><code>{{ item.name }}</code></td>
                <td>{{ formatSize(item.size) }}</td>
                <td>{{ formatTime(item.updated_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="panel-body nginx-template-editor">
          <div v-if="!selectedName" class="empty">{{ t('nginx.template_pick') }}</div>
          <template v-else>
            <div class="nginx-template-editor-head">
              <div>
                <strong><code>{{ selectedName }}</code></strong>
                <span v-if="dirty" class="status-pill status-off">{{ t('nginx.template_dirty') }}</span>
              </div>
              <button
                type="button"
                class="primary"
                :disabled="!dirty || !!pending || templateLoading"
                @click="saveTemplate"
              >
                {{ pending === 'template-save' ? t('action.working') : t('nginx.template_save') }}
              </button>
            </div>
            <p v-if="templateMeta" class="nginx-template-meta">
              {{ formatSize(templateMeta.size) }} · {{ formatTime(templateMeta.updated_at) }}
            </p>
            <textarea
              v-model="draft"
              class="php-ini-editor nginx-template-textarea"
              rows="20"
              spellcheck="false"
              :disabled="templateLoading || pending === 'template-save'"
            ></textarea>
          </template>
        </div>
      </div>
    </div>
  </section>
</template>
