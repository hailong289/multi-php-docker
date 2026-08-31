<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import TableSkeleton from '../components/TableSkeleton.vue'
import { useManager } from '../composables/useManager'

const router = useRouter()
const { t } = useI18n()
const {
  loading,
  data,
  stateClass,
  stateLabel,
  infraServiceState,
  infraActionEnabled,
  infraAction,
  showInfraCreateHint,
  composeYamlAction,
  composeYamlActionEnabled,
  composeFileState,
  showComposeCreateHint,
  isPending,
  loadBootstrap,
} = useManager()

const targets = computed(() => data.infra_services?.targets || {})

const serviceRows = computed(() => {
  const rows = Object.entries(targets.value).map(([service, target]) => ({
    kind: 'infra',
    key: `infra:${service}`,
    service,
    target,
    label: target.label,
    container: target.container,
    profile: target.profile,
    ports: target.ports,
  }))

  for (const item of data.infra_services?.compose_files || []) {
    if (item.runtime !== 'compose') continue
    const svc = item.compose_services?.[0]
    rows.push({
      kind: 'compose',
      key: `compose:${item.name}`,
      item,
      label: svc?.name || item.name.replace(/\.ya?ml$/i, ''),
      container: svc?.container || '—',
      profile: svc?.profile || '—',
      ports: item.name,
    })
  }

  return rows
})

function openLogs(service) {
  router.push({ name: 'service-logs', params: { service } })
}

function openComposeLogs(name) {
  router.push({ name: 'compose-file-logs', params: { name } })
}

function openComposeYaml() {
  router.push({ name: 'compose-yaml' })
}

function rowState(row) {
  if (row.kind === 'infra') return infraServiceState(row.service)
  return composeFileState(row.item)
}

function showCreateHint(row) {
  if (row.kind === 'infra') return showInfraCreateHint(row.service, row.target)
  return showComposeCreateHint(row.item)
}

onMounted(() => {
  loadBootstrap()
})
</script>

<template>
  <section class="panel" data-tour="services-panel">
    <div class="panel-heading">
      <div class="controller-heading panel-heading-row">
        <div>
          <h2>{{ t('services.title') }}</h2>
          <p>{{ t('services.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <button
            type="button"
            data-tour="services-compose-yaml"
            @click="openComposeYaml"
          >
            {{ t('services.manage_compose_yaml') }}
          </button>
        </div>
      </div>
    </div>

    <TableSkeleton
      v-if="loading"
      :columns="5"
      :rows="4"
      :headers="[
        t('services.service'),
        t('services.container'),
        t('services.profile'),
        t('services.state'),
        t('services.actions'),
      ]"
    />
    <div v-else class="table-wrap" data-tour="services-table">
      <table>
        <thead>
          <tr>
            <th>{{ t('services.service') }}</th>
            <th>{{ t('services.container') }}</th>
            <th>{{ t('services.profile') }}</th>
            <th>{{ t('services.state') }}</th>
            <th>{{ t('services.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in serviceRows" :key="row.key">
            <td>
              <div>{{ row.label }}</div>
              <div v-if="row.kind === 'infra'" class="create-hint">
                {{ t('services.ports') }}: {{ row.ports }}
              </div>
              <div v-else class="create-hint">
                <code>{{ row.ports }}</code>
                <span v-if="!row.item.included" class="status-line warn">
                  · {{ t('services.compose_not_included') }}
                </span>
              </div>
            </td>
            <td><code>{{ row.container }}</code></td>
            <td><code>{{ row.profile }}</code></td>
            <td>
              <span class="state-badge" :class="stateClass(rowState(row))">
                {{ stateLabel(rowState(row)) }}
              </span>
              <div v-if="showCreateHint(row)" class="create-hint">
                {{ t('services.create_hint') }}
              </div>
            </td>
            <td>
              <div v-if="row.kind === 'infra'" class="controller-actions">
                <button
                  v-if="showInfraCreateHint(row.service, row.target)"
                  type="button"
                  class="primary"
                  :class="{ 'is-loading': isPending('infra', { service: row.service, action: 'create' }) }"
                  :disabled="!infraActionEnabled(row.service, 'create')"
                  @click="infraAction(row.service, 'create')"
                >
                  <span
                    v-if="isPending('infra', { service: row.service, action: 'create' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service: row.service, action: 'create' })
                      ? t('action.working')
                      : t('services.create')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('infra', { service: row.service, action: 'start' }) }"
                  :disabled="!infraActionEnabled(row.service, 'start')"
                  @click="infraAction(row.service, 'start')"
                >
                  <span
                    v-if="isPending('infra', { service: row.service, action: 'start' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service: row.service, action: 'start' })
                      ? t('action.working')
                      : t('services.start')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('infra', { service: row.service, action: 'stop' }) }"
                  :disabled="!infraActionEnabled(row.service, 'stop')"
                  @click="infraAction(row.service, 'stop')"
                >
                  <span
                    v-if="isPending('infra', { service: row.service, action: 'stop' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service: row.service, action: 'stop' })
                      ? t('action.working')
                      : t('services.stop')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('infra', { service: row.service, action: 'restart' }) }"
                  :disabled="!infraActionEnabled(row.service, 'restart')"
                  @click="infraAction(row.service, 'restart')"
                >
                  <span
                    v-if="isPending('infra', { service: row.service, action: 'restart' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service: row.service, action: 'restart' })
                      ? t('action.working')
                      : t('services.restart')
                  }}
                </button>
                <button
                  type="button"
                  data-tour="services-logs-btn"
                  :disabled="infraServiceState(row.service) === 'not_created'"
                  @click="openLogs(row.service)"
                >
                  {{ t('services.view_logs') }}
                </button>
                <button
                  type="button"
                  class="danger"
                  data-tour="services-delete-btn"
                  :class="{ 'is-loading': isPending('infra', { service: row.service, action: 'delete' }) }"
                  :disabled="!infraActionEnabled(row.service, 'delete')"
                  @click="infraAction(row.service, 'delete')"
                >
                  <span
                    v-if="isPending('infra', { service: row.service, action: 'delete' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service: row.service, action: 'delete' })
                      ? t('action.working')
                      : t('services.delete')
                  }}
                </button>
                <button
                  type="button"
                  class="danger"
                  data-tour="services-delete-image-btn"
                  :class="{ 'is-loading': isPending('infra', { service: row.service, action: 'delete-image' }) }"
                  :disabled="!infraActionEnabled(row.service, 'delete-image')"
                  @click="infraAction(row.service, 'delete-image')"
                >
                  <span
                    v-if="isPending('infra', { service: row.service, action: 'delete-image' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service: row.service, action: 'delete-image' })
                      ? t('action.working')
                      : t('services.delete_image')
                  }}
                </button>
              </div>
              <div v-else class="controller-actions">
                <button
                  v-if="showComposeCreateHint(row.item)"
                  type="button"
                  class="primary"
                  :class="{ 'is-loading': isPending('compose-file', { name: row.item.name, action: 'create' }) }"
                  :disabled="!composeYamlActionEnabled(row.item, 'create')"
                  @click="composeYamlAction(row.item, 'create')"
                >
                  <span
                    v-if="isPending('compose-file', { name: row.item.name, action: 'create' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('compose-file', { name: row.item.name, action: 'create' })
                      ? t('action.working')
                      : t('services.create')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('compose-file', { name: row.item.name, action: 'start' }) }"
                  :disabled="!composeYamlActionEnabled(row.item, 'start')"
                  @click="composeYamlAction(row.item, 'start')"
                >
                  <span
                    v-if="isPending('compose-file', { name: row.item.name, action: 'start' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('compose-file', { name: row.item.name, action: 'start' })
                      ? t('action.working')
                      : t('services.start')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('compose-file', { name: row.item.name, action: 'stop' }) }"
                  :disabled="!composeYamlActionEnabled(row.item, 'stop')"
                  @click="composeYamlAction(row.item, 'stop')"
                >
                  <span
                    v-if="isPending('compose-file', { name: row.item.name, action: 'stop' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('compose-file', { name: row.item.name, action: 'stop' })
                      ? t('action.working')
                      : t('services.stop')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('compose-file', { name: row.item.name, action: 'restart' }) }"
                  :disabled="!composeYamlActionEnabled(row.item, 'restart')"
                  @click="composeYamlAction(row.item, 'restart')"
                >
                  <span
                    v-if="isPending('compose-file', { name: row.item.name, action: 'restart' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('compose-file', { name: row.item.name, action: 'restart' })
                      ? t('action.working')
                      : t('services.restart')
                  }}
                </button>
                <button
                  type="button"
                  :disabled="composeFileState(row.item) === 'not_created'"
                  @click="openComposeLogs(row.item.name)"
                >
                  {{ t('services.view_logs') }}
                </button>
                <button
                  type="button"
                  class="danger"
                  :class="{ 'is-loading': isPending('compose-file', { name: row.item.name, action: 'delete' }) }"
                  :disabled="!composeYamlActionEnabled(row.item, 'delete')"
                  @click="composeYamlAction(row.item, 'delete')"
                >
                  <span
                    v-if="isPending('compose-file', { name: row.item.name, action: 'delete' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('compose-file', { name: row.item.name, action: 'delete' })
                      ? t('action.working')
                      : t('services.delete')
                  }}
                </button>
                <button
                  type="button"
                  class="danger"
                  :class="{ 'is-loading': isPending('compose-file', { name: row.item.name, action: 'delete-image' }) }"
                  :disabled="!composeYamlActionEnabled(row.item, 'delete-image')"
                  @click="composeYamlAction(row.item, 'delete-image')"
                >
                  <span
                    v-if="isPending('compose-file', { name: row.item.name, action: 'delete-image' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('compose-file', { name: row.item.name, action: 'delete-image' })
                      ? t('action.working')
                      : t('services.delete_image')
                  }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
