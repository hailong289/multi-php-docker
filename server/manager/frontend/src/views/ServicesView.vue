<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Tag from 'primevue/tag'
import ActionMenu from '../components/ActionMenu.vue'
import PinButton from '../components/PinButton.vue'
import { useManager } from '../composables/useManager'
import { usePinnedContainers } from '../composables/usePinnedContainers'
import {
  buildComposeMenuItems,
  buildInfraMenuItems,
  buildPinMenuItem,
} from '../lib/containerMenus'

const router = useRouter()
const { t } = useI18n()
const mgr = useManager()
const {
  loading,
  data,
  stateLabel,
  infraServiceState,
  showInfraCreateHint,
  composeFileState,
  showComposeCreateHint,
  loadBootstrap,
} = mgr
const { isPinned, togglePin } = usePinnedContainers()

const targets = computed(() => data.infra_services?.targets || {})

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}

const menuCtx = computed(() => ({ t, router, mgr }))

function pinKindId(row) {
  if (row.kind === 'compose') return { kind: 'compose', id: row.item.name }
  return { kind: 'infra', id: row.service }
}

function rowMenuItems(row) {
  const { kind, id } = pinKindId(row)
  const pinItem = buildPinMenuItem(kind, id, {
    t,
    pinned: isPinned(kind, id),
    toggle: togglePin,
  })
  const items =
    row.kind === 'infra'
      ? buildInfraMenuItems(row.service, menuCtx.value)
      : buildComposeMenuItems(row.item, menuCtx.value)
  return [...items, pinItem]
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
            <div class="row-actions">
              <PinButton
                :kind="pinKindId(row).kind"
                :id="pinKindId(row).id"
              />
              <ActionMenu :items="rowMenuItems(row)" />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>
  </section>
</template>
