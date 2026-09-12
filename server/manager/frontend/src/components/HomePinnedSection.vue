<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ActionMenu from './ActionMenu.vue'
import PinButton from './PinButton.vue'
import ServiceConnectionDialog from './ServiceConnectionDialog.vue'
import { apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { usePinnedContainers } from '../composables/usePinnedContainers'
import { pinKey } from '../lib/pinnedContainers'
import {
  buildComposeMenuItems,
  buildInfraMenuItems,
  buildNginxMenuItems,
  buildPhpMenuItems,
} from '../lib/containerMenus'

const { t } = useI18n()
const router = useRouter()
const mgr = useManager()
const {
  data,
  stateLabel,
  infraServiceState,
  phpServiceState,
  composeFileState,
  showToast,
  translateApiError,
} = mgr
const { pins, movePin, reorderPins } = usePinnedContainers()

const nginxPending = ref('')
const dragKey = ref('')
const connectionOpen = ref(false)
const connectionService = ref('')
const connectionLabel = ref('')

function openConnectionDetails(service) {
  const target = data.infra_services?.targets?.[service]
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

function stateSeverity(state) {
  if (state === 'running') return 'success'
  if (state === 'stopped') return 'secondary'
  if (state === 'error') return 'danger'
  if (state === 'busy') return 'warn'
  return 'contrast'
}

function nginxState() {
  return data.nginx_management?.state || 'not_created'
}

function nginxEnabled(action) {
  if (data.php_controller_daemon?.state !== 'running') return false
  const state = nginxState()
  if (nginxPending.value || state === 'busy') return false
  if (action === 'start') return state === 'stopped'
  return state === 'running'
}

async function nginxRun(action) {
  const paths = {
    start: '/api/nginx/actions/start',
    stop: '/api/nginx/actions/stop',
    restart: '/api/nginx/actions/restart',
    test: '/api/nginx/test',
    reload: '/api/nginx/reload',
  }
  const path = paths[action]
  if (!path) return
  nginxPending.value = action
  try {
    const result = await apiSend('POST', path, {})
    if (result.nginx_management) {
      data.nginx_management = {
        ...(data.nginx_management || {}),
        ...result.nginx_management,
      }
    }
    showToast('success', t(result.message_key || (action === 'reload' ? 'reload.waiting' : 'nginx.requested')))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    nginxPending.value = ''
  }
}

const rows = computed(() =>
  pins.value.map((pin) => {
    const key = pinKey(pin.kind, pin.id)
    if (pin.kind === 'nginx') {
      return {
        key,
        kind: 'nginx',
        id: 'nginx',
        available: true,
        label: t('nginx.name'),
        state: nginxState(),
      }
    }
    if (pin.kind === 'infra') {
      const target = data.infra_services?.targets?.[pin.id]
      return {
        key,
        kind: 'infra',
        id: pin.id,
        available: !!target,
        label: target?.label || pin.id,
        state: target ? infraServiceState(pin.id) : 'not_created',
      }
    }
    if (pin.kind === 'php') {
      const target = data.php_controllers?.targets?.[pin.id]
      return {
        key,
        kind: 'php',
        id: pin.id,
        available: !!target,
        label: target?.label || pin.id,
        state: target ? phpServiceState(pin.id) : 'not_created',
      }
    }
    const item = (data.infra_services?.compose_files || []).find((file) => file.name === pin.id)
    const svc = item?.compose_services?.[0]
    return {
      key,
      kind: 'compose',
      id: pin.id,
      available: !!item,
      label: svc?.name || pin.id.replace(/\.ya?ml$/i, ''),
      state: item ? composeFileState(item) : 'not_created',
      item,
    }
  }),
)

function menuItems(row) {
  if (row.kind === 'nginx') {
    return buildNginxMenuItems({
      t,
      nginx: {
        enabled: nginxEnabled,
        pending: (action) => nginxPending.value === action,
        run: nginxRun,
      },
    })
  }
  if (row.kind === 'infra') return buildInfraMenuItems(row.id, menuCtx.value)
  if (row.kind === 'php') return buildPhpMenuItems(row.id, menuCtx.value)
  return buildComposeMenuItems(row.item, menuCtx.value)
}

function onDragStart(row, event) {
  dragKey.value = row.key
  event.dataTransfer?.setData('text/plain', row.key)
  event.dataTransfer.effectAllowed = 'move'
}

function onDragEnd() {
  dragKey.value = ''
}

function onDrop(toIndex) {
  if (!dragKey.value) return
  const list = pins.value.slice()
  const from = list.findIndex((p) => pinKey(p.kind, p.id) === dragKey.value)
  dragKey.value = ''
  if (from < 0 || from === toIndex) return
  const [item] = list.splice(from, 1)
  list.splice(toIndex, 0, item)
  reorderPins(list)
}
</script>

<template>
  <section
    v-if="pins.length"
    class="panel home-pinned"
    data-tour="home-pinned"
  >
    <div class="panel-heading">
      <h2>{{ t('pin.section_title') }}</h2>
      <p class="status-line">{{ t('pin.section_hint') }}</p>
    </div>
    <div class="panel-body home-pinned-body">
      <ul class="home-pinned-list">
        <li
          v-for="(row, index) in rows"
          :key="row.key"
          class="home-pinned-row"
          :class="{
            'is-unavailable': !row.available,
            'is-dragging': dragKey === row.key,
          }"
          @dragover.prevent
          @drop.prevent="onDrop(index)"
        >
          <span
            class="home-pinned-handle"
            role="button"
            tabindex="0"
            :aria-label="t('pin.drag_handle')"
            draggable="true"
            @dragstart="onDragStart(row, $event)"
            @dragend="onDragEnd"
          >
            ⋮⋮
          </span>
          <div class="home-pinned-main">
            <Tag
              class="home-pinned-kind"
              :value="t(`pin.kind_${row.kind}`)"
              severity="secondary"
              rounded
            />
            <div class="home-pinned-meta">
              <strong :title="row.label">{{ row.label }}</strong>
              <Tag
                v-if="row.available"
                :value="stateLabel(row.state)"
                :severity="stateSeverity(row.state)"
                rounded
              />
              <Tag
                v-else
                :value="t('pin.unavailable')"
                severity="contrast"
                rounded
              />
            </div>
          </div>
          <div class="home-pinned-actions">
            <div class="home-pinned-reorder">
              <Button
                type="button"
                icon="pi pi-arrow-up"
                text
                rounded
                size="small"
                :disabled="index === 0"
                :aria-label="t('pin.move_up')"
                @click="movePin(row.kind, row.id, index - 1)"
              />
              <Button
                type="button"
                icon="pi pi-arrow-down"
                text
                rounded
                size="small"
                :disabled="index === rows.length - 1"
                :aria-label="t('pin.move_down')"
                @click="movePin(row.kind, row.id, index + 1)"
              />
            </div>
            <div class="home-pinned-menu">
              <ActionMenu v-if="row.available" :items="menuItems(row)" />
            </div>
            <PinButton :kind="row.kind" :id="row.id" />
          </div>
        </li>
      </ul>
    </div>

    <ServiceConnectionDialog
      v-model:visible="connectionOpen"
      :service="connectionService"
      :label="connectionLabel"
    />
  </section>
</template>
