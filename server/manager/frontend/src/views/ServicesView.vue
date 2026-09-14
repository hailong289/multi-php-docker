<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ActionMenu from '../components/ActionMenu.vue'
import PinButton from '../components/PinButton.vue'
import ServiceConnectionDialog from '../components/ServiceConnectionDialog.vue'
import { useManager } from '../composables/useManager'
import { usePinnedContainers } from '../composables/usePinnedContainers'
import {
  buildComposeMenuItems,
  buildInfraMenuItems,
  buildPinMenuItem,
} from '../lib/containerMenus'
import { getInfraWebUrl, openInfraWeb } from '../lib/infraConnectionDetails'

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

const connectionOpen = ref(false)
const connectionService = ref('')
const connectionLabel = ref('')

const targets = computed(() => data.infra_services?.targets || {})

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}

function openConnectionDetails(service) {
  const target = targets.value[service]
  connectionService.value = service
  connectionLabel.value = target?.label || service
  connectionOpen.value = true
}

const menuCtx = computed(() => ({
  t,
  router,
  mgr,
  onDetails: openConnectionDetails,
}))

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

function rowWebUrl(row) {
  if (row.kind !== 'infra') return null
  return getInfraWebUrl(row.service)
}

function canOpenWeb(row) {
  return !!rowWebUrl(row) && rowState(row) === 'running'
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

    <div class="panel-body">
      <div
        v-if="loading"
        class="resource-card-grid"
        aria-busy="true"
        aria-live="polite"
      >
        <div
          v-for="n in 6"
          :key="'svc-skel-' + n"
          class="resource-card resource-card-skeleton"
        >
          <div class="resource-card-head">
            <span class="skeleton-line skeleton-w2"></span>
            <span class="skeleton-line skeleton-tag"></span>
          </div>
          <div class="resource-card-meta">
            <span class="skeleton-line skeleton-w1"></span>
            <span class="skeleton-line skeleton-w0"></span>
          </div>
          <div class="resource-card-footer">
            <span class="skeleton-line skeleton-w0"></span>
          </div>
        </div>
      </div>

      <div
        v-else-if="serviceRows.length === 0"
        class="empty"
        data-tour="services-table"
      >
        {{ t('services.subtitle') }}
      </div>

      <div v-else class="resource-card-grid" data-tour="services-table">
        <article
          v-for="row in serviceRows"
          :key="row.key"
          class="resource-card"
          :data-state="rowState(row)"
        >
          <div class="resource-card-head">
            <div class="resource-card-title">
              <h3 :title="row.label">{{ row.label }}</h3>
              <Tag
                v-if="row.kind === 'compose'"
                :value="t('pin.kind_compose')"
                severity="secondary"
                rounded
              />
            </div>
            <Tag
              :value="stateLabel(rowState(row))"
              :severity="stateSeverity(rowState(row))"
              rounded
            />
          </div>

          <dl class="resource-card-meta">
            <div>
              <dt>{{ t('services.container') }}</dt>
              <dd><code>{{ row.container }}</code></dd>
            </div>
            <div>
              <dt>{{ t('services.profile') }}</dt>
              <dd><code>{{ row.profile }}</code></dd>
            </div>
            <div v-if="row.kind === 'infra'">
              <dt>{{ t('services.ports') }}</dt>
              <dd>{{ row.ports }}</dd>
            </div>
            <div v-else>
              <dt>{{ t('pin.kind_compose') }}</dt>
              <dd>
                <code>{{ row.ports }}</code>
                <span v-if="!row.item.included" class="status-line warn">
                  · {{ t('services.compose_not_included') }}
                </span>
              </dd>
            </div>
          </dl>

          <p v-if="showCreateHint(row)" class="create-hint">
            {{ t('services.create_hint') }}
          </p>

          <div class="resource-card-footer">
            <Button
              v-if="rowWebUrl(row)"
              type="button"
              size="small"
              outlined
              icon="pi pi-external-link"
              :label="t('services.open_web')"
              :disabled="!canOpenWeb(row)"
              :title="rowWebUrl(row)"
              data-tour="service-open-web"
              @click="openInfraWeb(row.service)"
            />
            <div class="row-actions">
              <PinButton
                :kind="pinKindId(row).kind"
                :id="pinKindId(row).id"
              />
              <ActionMenu :items="rowMenuItems(row)" />
            </div>
          </div>
        </article>
      </div>
    </div>

    <ServiceConnectionDialog
      v-model:visible="connectionOpen"
      :service="connectionService"
      :label="connectionLabel"
    />
  </section>
</template>
