<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import TableSkeleton from '../components/TableSkeleton.vue'
import { useManager } from '../composables/useManager'

const router = useRouter()
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
                  data-tour="php-logs-btn"
                  :disabled="phpServiceState(service) === 'not_created'"
                  @click="$router.push({ name: 'php-version-logs', params: { service } })"
                >
                  {{ $t('php_controller.view_logs') }}
                </button>
                <button
                  type="button"
                  data-tour="php-run"
                  @click="$router.push({ name: 'php-version-run', params: { service } })"
                >
                  {{ $t('php_controller.run') }}
                </button>
                <button
                  type="button"
                  @click="$router.push({ name: 'php-version-detail', params: { service } })"
                >
                  {{ $t('php_controller.details') }}
                </button>
                <button
                  type="button"
                  data-tour="php-supervisor"
                  @click="$router.push({ name: 'php-version-supervisor', params: { service } })"
                >
                  {{ $t('php_controller.supervisor') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
