<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const { showToast, translateApiError, stateClass } = useManager()
const loading = ref(true)
const pending = ref('')
const nginx = ref({
  container: 'nginx_container',
  state: 'not_created',
  test_status: null,
  reload_status: null,
  logs: {},
})

const stateLabel = computed(() => t(`nginx.state_${nginx.value.state || 'not_created'}`))

function enabled(action) {
  if (pending.value || nginx.value.state === 'busy') return false
  if (action === 'start') return nginx.value.state === 'stopped'
  return nginx.value.state === 'running'
}

async function load() {
  loading.value = true
  try {
    const result = await apiGet('/api/nginx/management')
    nginx.value = result.nginx_management
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    loading.value = false
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

onMounted(load)
</script>

<template>
  <section class="panel">
    <div class="panel-heading nginx-heading">
      <div>
        <h2>{{ t('nginx.title') }}</h2>
        <p>{{ t('nginx.subtitle') }}</p>
      </div>
      <button type="button" :disabled="loading || !!pending" @click="load">
        {{ t('nginx.refresh') }}
      </button>
    </div>

    <div v-if="loading" class="panel-body">{{ t('loading') }}</div>
    <template v-else>
      <div class="panel-body nginx-overview">
        <div>
          <span class="state-badge" :class="stateClass(nginx.state)">{{ stateLabel }}</span>
          <code>{{ nginx.container }}</code>
        </div>
        <div class="controller-actions">
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

      <div class="panel-body nginx-log-grid">
        <article v-for="name in ['operation', 'error', 'access']" :key="name" class="nginx-log-card">
          <h3>{{ t(`nginx.log_${name}`) }}</h3>
          <pre>{{ nginx.logs?.[name]?.available ? nginx.logs[name].content : t('nginx.log_empty') }}</pre>
        </article>
      </div>
    </template>
  </section>
</template>
