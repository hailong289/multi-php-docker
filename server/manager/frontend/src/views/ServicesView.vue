<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Tag from 'primevue/tag'
import ActionMenu from '../components/ActionMenu.vue'
import { useManager } from '../composables/useManager'

const router = useRouter()
const { t } = useI18n()
const {
  loading,
  data,
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

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}

function infraMenuItems(service) {
  return [
    {
      id: 'create',
      label: t('services.create'),
      primary: true,
      disabled: !infraActionEnabled(service, 'create'),
      loading: isPending('infra', { service, action: 'create' }),
      run: () => infraAction(service, 'create'),
    },
    {
      id: 'start',
      label: t('services.start'),
      disabled: !infraActionEnabled(service, 'start'),
      loading: isPending('infra', { service, action: 'start' }),
      run: () => infraAction(service, 'start'),
    },
    {
      id: 'stop',
      label: t('services.stop'),
      disabled: !infraActionEnabled(service, 'stop'),
      loading: isPending('infra', { service, action: 'stop' }),
      run: () => infraAction(service, 'stop'),
    },
    {
      id: 'restart',
      label: t('services.restart'),
      disabled: !infraActionEnabled(service, 'restart'),
      loading: isPending('infra', { service, action: 'restart' }),
      run: () => infraAction(service, 'restart'),
    },
    {
      id: 'logs',
      label: t('services.view_logs'),
      disabled: infraServiceState(service) === 'not_created',
      run: () => router.push({ name: 'service-logs', params: { service } }),
    },
    {
      id: 'delete',
      label: t('services.delete_container'),
      danger: true,
      disabled: !infraActionEnabled(service, 'delete'),
      loading: isPending('infra', { service, action: 'delete' }),
      run: () => infraAction(service, 'delete'),
    },
    {
      id: 'delete-image',
      label: t('services.delete_image'),
      danger: true,
      disabled: !infraActionEnabled(service, 'delete-image'),
      loading: isPending('infra', { service, action: 'delete-image' }),
      run: () => infraAction(service, 'delete-image'),
    },
  ]
}

function composeMenuItems(item) {
  return [
    {
      id: 'create',
      label: t('services.create'),
      primary: true,
      disabled: !composeYamlActionEnabled(item, 'create'),
      loading: isPending('compose-file', { name: item.name, action: 'create' }),
      run: () => composeYamlAction(item, 'create'),
    },
    {
      id: 'start',
      label: t('services.start'),
      disabled: !composeYamlActionEnabled(item, 'start'),
      loading: isPending('compose-file', { name: item.name, action: 'start' }),
      run: () => composeYamlAction(item, 'start'),
    },
    {
      id: 'stop',
      label: t('services.stop'),
      disabled: !composeYamlActionEnabled(item, 'stop'),
      loading: isPending('compose-file', { name: item.name, action: 'stop' }),
      run: () => composeYamlAction(item, 'stop'),
    },
    {
      id: 'restart',
      label: t('services.restart'),
      disabled: !composeYamlActionEnabled(item, 'restart'),
      loading: isPending('compose-file', { name: item.name, action: 'restart' }),
      run: () => composeYamlAction(item, 'restart'),
    },
    {
      id: 'logs',
      label: t('services.view_logs'),
      disabled: composeFileState(item) === 'not_created',
      run: () => router.push({ name: 'compose-file-logs', params: { name: item.name } }),
    },
    {
      id: 'delete',
      label: t('services.delete_container'),
      danger: true,
      disabled: !composeYamlActionEnabled(item, 'delete'),
      loading: isPending('compose-file', { name: item.name, action: 'delete' }),
      run: () => composeYamlAction(item, 'delete'),
    },
    {
      id: 'delete-image',
      label: t('services.delete_image'),
      danger: true,
      disabled: !composeYamlActionEnabled(item, 'delete-image'),
      loading: isPending('compose-file', { name: item.name, action: 'delete-image' }),
      run: () => composeYamlAction(item, 'delete-image'),
    },
  ]
}

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

function rowMenuItems(row) {
  if (row.kind === 'infra') return infraMenuItems(row.service)
  return composeMenuItems(row.item)
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
          <Button
            type="button"
            data-tour="services-compose-yaml"
            :label="t('services.manage_compose_yaml')"
            @click="openComposeYaml"
          />
        </div>
      </div>
    </div>

    <DataTable
      v-if="loading"
      :value="[{}, {}, {}, {}]"
      :loading="true"
    >
      <Column :header="t('services.service')" />
      <Column :header="t('services.container')" />
      <Column :header="t('services.profile')" />
      <Column :header="t('services.state')" />
      <Column :header="t('services.actions')" />
    </DataTable>
    <div v-else data-tour="services-table">
      <DataTable :value="serviceRows" data-key="key" striped-rows>
        <Column :header="t('services.service')">
          <template #body="{ data: row }">
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
          </template>
        </Column>
        <Column :header="t('services.container')">
          <template #body="{ data: row }">
            <code>{{ row.container }}</code>
          </template>
        </Column>
        <Column :header="t('services.profile')">
          <template #body="{ data: row }">
            <code>{{ row.profile }}</code>
          </template>
        </Column>
        <Column :header="t('services.state')">
          <template #body="{ data: row }">
            <Tag
              :value="stateLabel(rowState(row))"
              :severity="stateSeverity(rowState(row))"
              rounded
            />
            <div v-if="showCreateHint(row)" class="create-hint">
              {{ t('services.create_hint') }}
            </div>
          </template>
        </Column>
        <Column
          :header="t('services.actions')"
          header-style="width: 1%; white-space: nowrap"
          style="width: 1%; white-space: nowrap; vertical-align: middle"
        >
          <template #body="{ data: row }">
            <ActionMenu :items="rowMenuItems(row)" />
          </template>
        </Column>
      </DataTable>
    </div>
  </section>
</template>
