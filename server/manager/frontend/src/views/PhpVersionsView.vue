<script setup>
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import ActionMenu from '../components/ActionMenu.vue'
import TableSkeleton from '../components/TableSkeleton.vue'
import { useManager } from '../composables/useManager'

const router = useRouter()
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
  loadBootstrap,
  busy,
} = useManager()

function phpMenuItems(service, target) {
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
          <h2>{{ $t('php_controller.title') }}</h2>
          <p>{{ $t('php_controller.subtitle') }}</p>
        </div>
        <div class="panel-heading-actions">
          <button
            type="button"
            data-tour="php-compose-yaml"
            :disabled="busy || loading"
            @click="router.push({ name: 'php-compose-yaml' })"
          >
            {{ $t('php_controller.manage_yaml') }}
          </button>
          <button
            type="button"
            class="primary"
            data-tour="php-add"
            :disabled="busy || loading"
            @click="router.push({ name: 'php-version-catalog' })"
          >
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
    <div v-else class="table-wrap" data-tour="php-table">
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
          <tr v-for="row in phpRows" :key="row.service">
            <td>{{ row.target.label }}</td>
            <td><code>{{ row.target.container }}</code></td>
            <td><code>{{ row.target.profile || $t('php_controller.default_profile') }}</code></td>
            <td>
              <span class="state-badge" :class="stateClass(phpServiceState(row.service))">
                {{ stateLabel(phpServiceState(row.service)) }}
              </span>
              <div v-if="showCreateHint(row.service, row.target)" class="create-hint">
                {{ $t('php_controller.create_hint') }}
              </div>
            </td>
            <td>
              <ActionMenu :items="phpMenuItems(row.service, row.target)" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
