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
  phpAction,
  stateLabel,
  phpServiceState,
  phpActionEnabled,
  showCreateHint,
  isPending,
  loadBootstrap,
  busy,
} = useManager()

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}

function phpMenuItems(service) {
  return [
    {
      id: 'create',
      label: t('php_controller.create'),
      primary: true,
      disabled: !phpActionEnabled(service, 'create'),
      loading: isPending('php', { service, action: 'create' }),
      run: () => phpAction(service, 'create'),
    },
    {
      id: 'start',
      label: t('php_controller.start'),
      disabled: !phpActionEnabled(service, 'start'),
      loading: isPending('php', { service, action: 'start' }),
      run: () => phpAction(service, 'start'),
    },
    {
      id: 'stop',
      label: t('php_controller.stop'),
      disabled: !phpActionEnabled(service, 'stop'),
      loading: isPending('php', { service, action: 'stop' }),
      run: () => phpAction(service, 'stop'),
    },
    {
      id: 'restart',
      label: t('php_controller.restart'),
      disabled: !phpActionEnabled(service, 'restart'),
      loading: isPending('php', { service, action: 'restart' }),
      run: () => phpAction(service, 'restart'),
    },
    {
      id: 'logs',
      label: t('php_controller.view_logs'),
      disabled: phpServiceState(service) === 'not_created',
      run: () => router.push({ name: 'php-version-logs', params: { service } }),
    },
    {
      id: 'run',
      label: t('php_controller.run'),
      run: () => router.push({ name: 'php-version-run', params: { service } }),
    },
    {
      id: 'details',
      label: t('php_controller.details'),
      run: () => router.push({ name: 'php-version-detail', params: { service } }),
    },
    {
      id: 'supervisor',
      label: t('php_controller.supervisor'),
      run: () => router.push({ name: 'php-version-supervisor', params: { service } }),
    },
    {
      id: 'delete',
      label: t('services.delete_container'),
      danger: true,
      disabled: !phpActionEnabled(service, 'delete'),
      loading: isPending('php', { service, action: 'delete' }),
      run: () => phpAction(service, 'delete'),
    },
    {
      id: 'delete-image',
      label: t('services.delete_image'),
      danger: true,
      disabled: !phpActionEnabled(service, 'delete-image'),
      loading: isPending('php', { service, action: 'delete-image' }),
      run: () => phpAction(service, 'delete-image'),
    },
  ]
}

const phpRows = computed(() =>
  Object.entries(data.php_controllers?.targets || {}).map(([service, target]) => ({
    service,
    target,
  })),
)

onMounted(() => {
  loadBootstrap()
})
</script>

<template>
  <section class="panel" data-tour="php-panel">
    <div class="panel-heading">
      <div class="controller-heading panel-heading-row">
        <div>
          <h2>{{ t('php_controller.title') }}</h2>
          <p>{{ t('php_controller.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <Button
            type="button"
            data-tour="php-compose-yaml"
            :label="t('php_controller.manage_yaml')"
            :disabled="busy || loading"
            @click="router.push({ name: 'php-compose-yaml' })"
          />
          <Button
            type="button"
            data-tour="php-add"
            :label="t('php_controller.add_version')"
            :disabled="busy || loading"
            @click="router.push({ name: 'php-version-catalog' })"
          />
        </div>
      </div>
    </div>

    <DataTable
      v-if="loading"
      :value="[{}, {}, {}, {}]"
      :loading="true"
    >
      <Column :header="t('php_controller.version')" />
      <Column :header="t('php_controller.container')" />
      <Column :header="t('php_controller.profile')" />
      <Column :header="t('php_controller.state')" />
      <Column :header="t('php_controller.actions')" />
    </DataTable>
    <div v-else data-tour="php-table">
      <DataTable :value="phpRows" data-key="service" striped-rows>
        <Column :header="t('php_controller.version')">
          <template #body="{ data: row }">
            {{ row.target.label }}
          </template>
        </Column>
        <Column :header="t('php_controller.container')">
          <template #body="{ data: row }">
            <code>{{ row.target.container }}</code>
          </template>
        </Column>
        <Column :header="t('php_controller.profile')">
          <template #body="{ data: row }">
            <code>{{ row.target.profile || t('php_controller.default_profile') }}</code>
          </template>
        </Column>
        <Column :header="t('php_controller.state')">
          <template #body="{ data: row }">
            <Tag
              :value="stateLabel(phpServiceState(row.service))"
              :severity="stateSeverity(phpServiceState(row.service))"
              rounded
            />
            <div v-if="showCreateHint(row.service, row.target)" class="create-hint">
              {{ t('php_controller.create_hint') }}
            </div>
          </template>
        </Column>
        <Column
          :header="t('php_controller.actions')"
          header-style="width: 1%; white-space: nowrap"
          style="width: 1%; white-space: nowrap; vertical-align: middle"
        >
          <template #body="{ data: row }">
            <ActionMenu :items="phpMenuItems(row.service)" />
          </template>
        </Column>
      </DataTable>
    </div>
  </section>
</template>
