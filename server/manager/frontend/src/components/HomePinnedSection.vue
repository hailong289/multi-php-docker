<script setup>
import { computed, ref, watch } from 'vue'
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

function nginxActionLogExcerpt() {
  const content = data.nginx_management?.logs?.action?.content || ''
  if (!content) return ''
  const lines = content.split(/\r?\n/).map((l) => l.trim()).filter(Boolean)
  const emerg = [...lines].reverse().find((l) => /\[emerg\]|\[alert\]|\[crit\]|error:/i.test(l))
  return emerg || lines[lines.length - 1] || ''
}

function nginxStatusText(status) {
  if (!status) return t('nginx.no_result')
  return status.message_key ? t(status.message_key) : status.message || t('nginx.no_result')
}

function waitForNginx(predicate, timeoutMs = 45000) {
  return new Promise((resolve) => {
    const started = Date.now()
    let done = false
    const finish = (ok) => {
      if (done) return
      done = true
      stopWatch()
      clearInterval(tick)
      resolve(ok)
    }
    const check = () => {
      if (predicate()) finish(true)
      else if (Date.now() - started > timeoutMs) finish(false)
    }
    const stopWatch = watch(
      () => [
        data.nginx_management?.state,
        data.nginx_management?.reload_status?.updated_at,
        data.nginx_management?.test_status?.updated_at,
        data.nginx_management?.logs?.action?.updated_at,
      ],
      check,
      { flush: 'post' },
    )
    const tick = setInterval(check, 300)
    check()
  })
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
  const previousReloadAt = data.nginx_management?.reload_status?.updated_at || ''
  const previousTestAt = data.nginx_management?.test_status?.updated_at || ''
  const previousActionAt = data.nginx_management?.logs?.action?.updated_at || ''
  try {
    const result = await apiSend('POST', path, {})
    if (result.nginx_management) {
      data.nginx_management = {
        ...(data.nginx_management || {}),
        ...result.nginx_management,
        logs: result.nginx_management.logs
          ? {
              ...(data.nginx_management?.logs || {}),
              ...result.nginx_management.logs,
            }
          : data.nginx_management?.logs,
      }
    }

    if (action === 'reload' || action === 'test') {
      showToast('success', t(action === 'reload' ? 'reload.waiting' : 'nginx.test_requested'))
      const prev = action === 'reload' ? previousReloadAt : previousTestAt
      const okWait = await waitForNginx(() => {
        const rs =
          action === 'reload'
            ? data.nginx_management?.reload_status
            : data.nginx_management?.test_status
        return !!(rs && rs.updated_at && rs.updated_at !== prev)
      }, 30000)
      if (!okWait) {
        showToast('failure', t(action === 'reload' ? 'reload.timeout' : 'nginx.no_result'))
        return
      }
      const rs =
        action === 'reload'
          ? data.nginx_management?.reload_status
          : data.nginx_management?.test_status
      const ok = rs?.status === 'success'
      showToast(ok ? 'success' : 'failure', nginxStatusText(rs))
      return
    }

    if (action === 'start' || action === 'stop' || action === 'restart') {
      showToast('success', t('nginx.action_requested'))
      const minSettleAt = Date.now() + 900
      const settled = await waitForNginx(() => {
        if (nginxState() === 'busy') return false
        if (Date.now() < minSettleAt) return false
        const actionAt = data.nginx_management?.logs?.action?.updated_at || ''
        const logUpdated = !previousActionAt || actionAt !== previousActionAt
        return logUpdated || Date.now() - minSettleAt > 2000
      }, 45000)
      if (!settled && nginxState() === 'busy') {
        showToast('failure', t('nginx.action_timeout'))
        return
      }
      const state = nginxState()
      if (action === 'start' && state !== 'running') {
        const excerpt = nginxActionLogExcerpt()
        showToast(
          'failure',
          excerpt ? t('nginx.start_failed_detail', { detail: excerpt }) : t('nginx.start_failed'),
        )
      } else if (action === 'restart' && state !== 'running') {
        const excerpt = nginxActionLogExcerpt()
        showToast(
          'failure',
          excerpt
            ? t('nginx.restart_failed_detail', { detail: excerpt })
            : t('nginx.restart_failed'),
        )
      } else if (action === 'stop' && state === 'running') {
        showToast('failure', t('nginx.stop_failed'))
      } else {
        showToast('success', t(`nginx.${action}_ok`))
      }
      return
    }

    showToast('success', t(result.message_key || 'nginx.requested'))
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

const QUICK_ACTION_IDS = new Set(['start', 'stop', 'restart'])

function rowActions(row) {
  const items = menuItems(row).filter((item) => item && item.hidden !== true)
  const byId = Object.fromEntries(items.map((item) => [item.id, item]))
  return {
    start: byId.start || null,
    stop: byId.stop || null,
    restart: byId.restart || null,
    more: items.filter((item) => !QUICK_ACTION_IDS.has(item.id)),
  }
}

/** Show quick icon only when the action applies (or is in-flight). */
function showQuick(item) {
  return !!(item && (!item.disabled || item.loading))
}

function runQuick(item) {
  if (!item || item.disabled || item.loading) return
  item.run?.()
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
            <div class="home-pinned-menu" v-if="row.available">
              <div
                v-for="qa in [rowActions(row)]"
                :key="`${row.key}-qa`"
                class="home-pinned-quick"
              >
                <Button
                  v-if="showQuick(qa.start)"
                  type="button"
                  icon="pi pi-play"
                  class="home-pinned-act home-pinned-act-start"
                  rounded
                  size="small"
                  severity="success"
                  :aria-label="qa.start.label"
                  :title="qa.start.label"
                  :loading="!!qa.start.loading"
                  :disabled="!!qa.start.disabled"
                  @click="runQuick(qa.start)"
                />
                <Button
                  v-if="showQuick(qa.stop)"
                  type="button"
                  icon="pi pi-stop"
                  class="home-pinned-act home-pinned-act-stop"
                  rounded
                  size="small"
                  severity="danger"
                  :aria-label="qa.stop.label"
                  :title="qa.stop.label"
                  :loading="!!qa.stop.loading"
                  :disabled="!!qa.stop.disabled"
                  @click="runQuick(qa.stop)"
                />
                <Button
                  v-if="showQuick(qa.restart)"
                  type="button"
                  icon="pi pi-refresh"
                  class="home-pinned-act home-pinned-act-restart"
                  rounded
                  size="small"
                  severity="warn"
                  :aria-label="qa.restart.label"
                  :title="qa.restart.label"
                  :loading="!!qa.restart.loading"
                  :disabled="!!qa.restart.disabled"
                  @click="runQuick(qa.restart)"
                />
                <ActionMenu
                  v-if="qa.more.length"
                  class="home-pinned-act home-pinned-act-details"
                  :items="qa.more"
                  :label="t('pin.details')"
                />
              </div>
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
