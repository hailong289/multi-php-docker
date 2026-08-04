<script setup>
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import TableSkeleton from '../components/TableSkeleton.vue'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const { t } = useI18n()
const {
  loading,
  data,
  phpAction,
  stateClass,
  stateLabel,
  phpServiceState,
  phpActionEnabled,
  showCreateHint,
  isPending,
  showToast,
  translateApiError,
  loadBootstrap,
  busy,
} = useManager()

const modalOpen = ref(false)
const versionsLoading = ref(false)
const installing = ref('')
const availableVersions = ref([])

async function openAddModal() {
  modalOpen.value = true
  versionsLoading.value = true
  availableVersions.value = []
  try {
    const result = await apiGet('/api/php-controllers/available-versions')
    availableVersions.value = result.versions || []
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    versionsLoading.value = false
  }
}

function closeAddModal() {
  if (installing.value) return
  modalOpen.value = false
}

async function installVersion(row) {
  if (row.installed || installing.value) return
  installing.value = row.version
  try {
    const result = await apiSend('POST', '/api/php-controllers/install-version', {
      version: row.version,
    })
    showToast(
      'success',
      t(result.message_key || 'php_controller.version_install_requested', result.message_parameters || {}),
    )
    if (result.php_controllers) data.php_controllers = result.php_controllers
    row.installed = true
    await loadBootstrap()
    modalOpen.value = false
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    installing.value = ''
  }
}

onMounted(() => {
  loadBootstrap()
})
</script>

<template>
  <section class="panel">
    <div class="panel-heading">
      <div class="controller-heading panel-heading-row">
        <div>
          <h2>{{ $t('php_controller.title') }}</h2>
          <p>{{ $t('php_controller.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <button type="button" class="primary" :disabled="busy || loading" @click="openAddModal">
            {{ $t('php_controller.add_version') }}
          </button>
        </div>
      </div>
    </div>

    <TableSkeleton
      v-if="loading"
      :columns="5"
      :rows="4"
      :headers="[
        $t('php_controller.version'),
        $t('php_controller.container'),
        $t('php_controller.profile'),
        $t('php_controller.state'),
        $t('php_controller.actions'),
      ]"
    />
    <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>{{ $t('php_controller.version') }}</th>
            <th>{{ $t('php_controller.container') }}</th>
            <th>{{ $t('php_controller.profile') }}</th>
            <th>{{ $t('php_controller.state') }}</th>
            <th>{{ $t('php_controller.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(target, service) in data.php_controllers.targets" :key="service">
            <td>{{ target.label }}</td>
            <td><code>{{ target.container }}</code></td>
            <td><code>{{ target.profile || $t('php_controller.default_profile') }}</code></td>
            <td>
              <span class="state-badge" :class="stateClass(phpServiceState(service))">
                {{ stateLabel(phpServiceState(service)) }}
              </span>
              <div v-if="showCreateHint(service, target)" class="create-hint">
                {{ $t('php_controller.create_hint') }}
              </div>
            </td>
            <td>
              <div class="controller-actions">
                <button
                  v-if="showCreateHint(service, target)"
                  type="button"
                  class="primary"
                  :class="{ 'is-loading': isPending('php', { service, action: 'create' }) }"
                  :disabled="!phpActionEnabled(service, 'create')"
                  @click="phpAction(service, 'create')"
                >
                  <span
                    v-if="isPending('php', { service, action: 'create' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('php', { service, action: 'create' })
                      ? $t('action.working')
                      : $t('php_controller.create')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('php', { service, action: 'start' }) }"
                  :disabled="!phpActionEnabled(service, 'start')"
                  @click="phpAction(service, 'start')"
                >
                  <span
                    v-if="isPending('php', { service, action: 'start' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('php', { service, action: 'start' })
                      ? $t('action.working')
                      : $t('php_controller.start')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('php', { service, action: 'stop' }) }"
                  :disabled="!phpActionEnabled(service, 'stop')"
                  @click="phpAction(service, 'stop')"
                >
                  <span
                    v-if="isPending('php', { service, action: 'stop' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('php', { service, action: 'stop' })
                      ? $t('action.working')
                      : $t('php_controller.stop')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('php', { service, action: 'restart' }) }"
                  :disabled="!phpActionEnabled(service, 'restart')"
                  @click="phpAction(service, 'restart')"
                >
                  <span
                    v-if="isPending('php', { service, action: 'restart' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('php', { service, action: 'restart' })
                      ? $t('action.working')
                      : $t('php_controller.restart')
                  }}
                </button>
                <button
                  type="button"
                  @click="$router.push({ name: 'php-version-detail', params: { service } })"
                >
                  {{ $t('php_controller.details') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <div v-if="modalOpen" class="modal-backdrop" @click.self="closeAddModal">
    <div
      class="modal-panel php-version-modal"
      role="dialog"
      aria-modal="true"
      :aria-label="$t('php_controller.add_version_title')"
    >
      <div class="modal-header">
        <h2>{{ $t('php_controller.add_version_title') }}</h2>
        <button type="button" class="modal-close" :disabled="!!installing" @click="closeAddModal">
          ×
        </button>
      </div>
      <div class="modal-body">
        <p class="create-hint">{{ $t('php_controller.add_version_subtitle') }}</p>
        <div v-if="versionsLoading" class="php-version-modal-loading">{{ $t('loading') }}</div>
        <div v-else-if="!availableVersions.length" class="php-version-modal-loading">
          {{ $t('php_controller.no_versions_available') }}
        </div>
        <div v-else class="table-wrap php-version-modal-table">
          <table>
            <thead>
              <tr>
                <th>{{ $t('php_controller.version') }}</th>
                <th>{{ $t('php_controller.hub_tag') }}</th>
                <th>{{ $t('php_controller.actions') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in availableVersions" :key="row.version">
                <td>{{ row.label }}</td>
                <td><code>{{ row.tag }}</code></td>
                <td>
                  <button
                    v-if="!row.installed"
                    type="button"
                    class="primary"
                    :disabled="!!installing"
                    @click="installVersion(row)"
                  >
                    <span
                      v-if="installing === row.version"
                      class="btn-spinner"
                      aria-hidden="true"
                    ></span>
                    {{
                      installing === row.version
                        ? $t('action.working')
                        : $t('php_controller.install_version')
                    }}
                  </button>
                  <span v-else class="php-version-installed">{{
                    $t('php_controller.already_installed')
                  }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
