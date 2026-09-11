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
import { buildPhpMenuItems, buildPinMenuItem } from '../lib/containerMenus'

const router = useRouter()
const { t } = useI18n()
const mgr = useManager()
const {
  loading,
  data,
  stateLabel,
  phpServiceState,
  showCreateHint,
  loadBootstrap,
  busy,
} = mgr
const { isPinned, togglePin } = usePinnedContainers()

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}

const menuCtx = computed(() => ({ t, router, mgr }))

function phpMenuItems(service) {
  const pinItem = buildPinMenuItem('php', service, {
    t,
    pinned: isPinned('php', service),
    toggle: togglePin,
  })
  return [...buildPhpMenuItems(service, menuCtx.value), pinItem]
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
            <div class="row-actions">
              <PinButton kind="php" :id="row.service" />
              <ActionMenu :items="phpMenuItems(row.service)" />
            </div>
          </template>
        </Column>
      </DataTable>
    </div>
  </section>
</template>
