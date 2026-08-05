<script setup>
import { onMounted } from 'vue'
import TableSkeleton from '../components/TableSkeleton.vue'
import { useManager } from '../composables/useManager'

const {
  loading,
  data,
  infraAction,
  stateClass,
  stateLabel,
  infraServiceState,
  infraActionEnabled,
  showInfraCreateHint,
  isPending,
  loadBootstrap,
} = useManager()

onMounted(() => {
  loadBootstrap()
})
</script>

<template>
  <section class="panel" data-tour="services-panel">
    <div class="panel-heading">
      <div class="controller-heading panel-heading-row">
        <div>
          <h2>{{ $t('services.title') }}</h2>
          <p>{{ $t('services.subtitle') }}</p>
        </div>
      </div>
    </div>

    <TableSkeleton
      v-if="loading"
      :columns="5"
      :rows="3"
      :headers="[
        $t('services.service'),
        $t('services.container'),
        $t('services.profile'),
        $t('services.state'),
        $t('services.actions'),
      ]"
    />
    <div v-else class="table-wrap" data-tour="services-table">
      <table>
        <thead>
          <tr>
            <th>{{ $t('services.service') }}</th>
            <th>{{ $t('services.container') }}</th>
            <th>{{ $t('services.profile') }}</th>
            <th>{{ $t('services.state') }}</th>
            <th>{{ $t('services.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(target, service) in data.infra_services.targets" :key="service">
            <td>
              <div>{{ target.label }}</div>
              <div class="create-hint">{{ $t('services.ports') }}: {{ target.ports }}</div>
            </td>
            <td><code>{{ target.container }}</code></td>
            <td><code>{{ target.profile }}</code></td>
            <td>
              <span class="state-badge" :class="stateClass(infraServiceState(service))">
                {{ stateLabel(infraServiceState(service)) }}
              </span>
              <div v-if="showInfraCreateHint(service, target)" class="create-hint">
                {{ $t('services.create_hint') }}
              </div>
            </td>
            <td>
              <div class="controller-actions">
                <button
                  v-if="showInfraCreateHint(service, target)"
                  type="button"
                  class="primary"
                  :class="{ 'is-loading': isPending('infra', { service, action: 'create' }) }"
                  :disabled="!infraActionEnabled(service, 'create')"
                  @click="infraAction(service, 'create')"
                >
                  <span
                    v-if="isPending('infra', { service, action: 'create' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service, action: 'create' })
                      ? $t('action.working')
                      : $t('services.create')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('infra', { service, action: 'start' }) }"
                  :disabled="!infraActionEnabled(service, 'start')"
                  @click="infraAction(service, 'start')"
                >
                  <span
                    v-if="isPending('infra', { service, action: 'start' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service, action: 'start' })
                      ? $t('action.working')
                      : $t('services.start')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('infra', { service, action: 'stop' }) }"
                  :disabled="!infraActionEnabled(service, 'stop')"
                  @click="infraAction(service, 'stop')"
                >
                  <span
                    v-if="isPending('infra', { service, action: 'stop' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service, action: 'stop' })
                      ? $t('action.working')
                      : $t('services.stop')
                  }}
                </button>
                <button
                  type="button"
                  :class="{ 'is-loading': isPending('infra', { service, action: 'restart' }) }"
                  :disabled="!infraActionEnabled(service, 'restart')"
                  @click="infraAction(service, 'restart')"
                >
                  <span
                    v-if="isPending('infra', { service, action: 'restart' })"
                    class="btn-spinner"
                    aria-hidden="true"
                  ></span>
                  {{
                    isPending('infra', { service, action: 'restart' })
                      ? $t('action.working')
                      : $t('services.restart')
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
