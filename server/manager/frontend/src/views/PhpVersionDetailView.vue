<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

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
} = useManager()

const service = computed(() => String(route.params.service || ''))
const loading = ref(true)
const pending = ref('')
const tab = ref('extensions')
const details = ref(null)
const iniDraft = ref('')

const label = computed(() => details.value?.target?.label || service.value)
const state = computed(() => details.value?.status?.state || phpServiceState(service.value))
const target = computed(() => details.value?.target || data.php_controllers?.targets?.[service.value] || {})

function statusLabel(status) {
  return t(`php_controller.ext_status_${status}`)
}

async function load() {
  loading.value = true
  try {
    const result = await apiGet(`/api/php-controllers/${service.value}/details`)
    details.value = result.php_details
    iniDraft.value = result.php_details?.ini?.content || ''
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    loading.value = false
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
    await load()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function extAction(name, action) {
  pending.value = `${action}:${name}`
  try {
    const path = `/api/php-controllers/${service.value}/extensions/${name}/${action}`
    const result = await apiSend('POST', path, {})
    showToast(
      'success',
      t(result.message_key || 'php_controller.action_success', result.message_parameters || {}),
    )
    if (action === 'install') {
      await new Promise((r) => setTimeout(r, 2500))
    }
    await load()
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = ''
  }
}

async function runLifecycle(action) {
  await phpAction(service.value, action)
  await loadBootstrap()
  await load()
}

watch(service, () => {
  tab.value = 'extensions'
  load()
})

onMounted(async () => {
  await loadBootstrap()
  await load()
})
</script>

<template>
  <section class="panel">
    <div class="panel-heading nginx-heading">
      <div>
        <button type="button" @click="router.push({ name: 'php-versions' })">
          {{ t('php_controller.back') }}
        </button>
        <h2>{{ t('php_controller.details_title', { version: label }) }}</h2>
        <p>{{ t('php_controller.details_subtitle') }}</p>
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
        <div class="controller-actions">
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
        </div>
      </div>

      <div class="panel-body">
        <div class="controller-actions">
          <button
            type="button"
            :class="{ primary: tab === 'extensions' }"
            @click="tab = 'extensions'"
          >
            {{ t('php_controller.tab_extensions') }}
          </button>
          <button type="button" :class="{ primary: tab === 'ini' }" @click="tab = 'ini'">
            {{ t('php_controller.tab_ini') }}
          </button>
        </div>
      </div>

      <div v-if="tab === 'extensions'" class="panel-body">
        <p class="create-hint">{{ t('php_controller.extensions_banner') }}</p>
        <p v-if="state !== 'running'" class="create-hint">
          {{ t('php_controller.extensions_need_running') }}
        </p>

        <p v-if="details.modules?.modules?.length">
          <strong>{{ t('php_controller.loaded_modules') }}:</strong>
          {{ details.modules.modules.join(', ') }}
        </p>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>{{ t('php_controller.extension') }}</th>
                <th>{{ t('php_controller.state') }}</th>
                <th>{{ t('php_controller.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="ext in details.extensions" :key="ext.name">
                <td><code>{{ ext.name }}</code></td>
                <td>{{ statusLabel(ext.status) }}</td>
                <td>
                  <div class="controller-actions">
                    <button
                      v-if="ext.status === 'available_to_install'"
                      type="button"
                      class="primary"
                      :disabled="state !== 'running' || !!pending"
                      @click="extAction(ext.name, 'install')"
                    >
                      {{
                        pending === `install:${ext.name}`
                          ? t('action.working')
                          : t('php_controller.ext_install')
                      }}
                    </button>
                    <button
                      v-if="ext.status === 'disabled_in_ini'"
                      type="button"
                      :disabled="!!pending"
                      @click="extAction(ext.name, 'enable')"
                    >
                      {{
                        pending === `enable:${ext.name}`
                          ? t('action.working')
                          : t('php_controller.ext_enable')
                      }}
                    </button>
                    <button
                      v-if="ext.status === 'loaded'"
                      type="button"
                      :disabled="!!pending"
                      @click="extAction(ext.name, 'disable')"
                    >
                      {{
                        pending === `disable:${ext.name}`
                          ? t('action.working')
                          : t('php_controller.ext_disable')
                      }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-else class="panel-body">
        <p v-if="details.ini.relative_path">
          <code>{{ details.ini.relative_path }}</code>
        </p>
        <textarea
          v-model="iniDraft"
          rows="18"
          style="width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace"
          :disabled="!!pending || !details.ini.readable"
        ></textarea>
        <div class="controller-actions" style="margin-top: 0.75rem">
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
