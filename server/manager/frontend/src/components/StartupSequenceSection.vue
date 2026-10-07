<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import { apiSend } from '../api'
import { useManager } from '../composables/useManager'
import { usePinnedContainers } from '../composables/usePinnedContainers'
import { readStartupSequence, sequenceKey, writeStartupSequence } from '../lib/startupSequence'

const { t } = useI18n()
const {
  data,
  phpServiceState,
  phpActionEnabled,
  phpAction,
  infraServiceState,
  infraActionEnabled,
  infraAction,
  composeFileState,
  composeYamlActionEnabled,
  composeYamlAction,
  showToast,
  translateApiError,
} = useManager()
const { pins } = usePinnedContainers()

const saved = ref(readStartupSequence())
const dialogOpen = ref(false)
const draftStart = ref([])
const draftStop = ref([])
const sequenceRunning = ref(false)
const sequenceMode = ref('')
const sequenceKeyActive = ref('')
const nginxPending = ref('')

function nginxState() {
  return data.nginx_management?.state || 'not_created'
}

function composeItem(name) {
  return (data.infra_services?.compose_files || []).find((file) => file.name === name) || null
}

function catalogEntry(kind, id) {
  if (kind === 'nginx') {
    return {
      key: sequenceKey('nginx', 'nginx'),
      kind: 'nginx',
      id: 'nginx',
      available: true,
      label: t('nginx.name'),
      state: nginxState(),
    }
  }
  if (kind === 'php') {
    const target = data.php_controllers?.targets?.[id]
    return {
      key: sequenceKey('php', id),
      kind: 'php',
      id,
      available: !!target,
      label: target?.label || id,
      state: target ? phpServiceState(id) : 'not_created',
    }
  }
  if (kind === 'infra') {
    const target = data.infra_services?.targets?.[id]
    return {
      key: sequenceKey('infra', id),
      kind: 'infra',
      id,
      available: !!target,
      label: target?.label || id,
      state: target ? infraServiceState(id) : 'not_created',
    }
  }
  const item = composeItem(id)
  const svc = item?.compose_services?.[0]
  return {
    key: sequenceKey('compose', id),
    kind: 'compose',
    id,
    available: !!item,
    label: svc?.name || id.replace(/\.ya?ml$/i, ''),
    state: item ? composeFileState(item) : 'not_created',
  }
}

const catalog = computed(() => pins.value.map((entry) => catalogEntry(entry.kind, entry.id)))

const startRows = computed(() => saved.value.start.map((entry) => catalogEntry(entry.kind, entry.id)))
const stopRows = computed(() => saved.value.stop.map((entry) => catalogEntry(entry.kind, entry.id)))

function rowsFor(mode) {
  return mode === 'stop' ? stopRows.value : startRows.value
}

function draftList(mode) {
  return mode === 'stop' ? draftStop.value : draftStart.value
}

const draftStartRows = computed(() => draftStart.value.map((entry) => catalogEntry(entry.kind, entry.id)))
const draftStopRows = computed(() => draftStop.value.map((entry) => catalogEntry(entry.kind, entry.id)))

function nginxEnabled(action) {
  if (data.php_controller_daemon?.state !== 'running') return false
  const state = nginxState()
  if (nginxPending.value || state === 'busy') return false
  if (action === 'start') return state === 'stopped'
  if (action === 'stop') return state === 'running'
  return false
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
      () => [data.nginx_management?.state, data.nginx_management?.logs?.action?.updated_at],
      check,
      { flush: 'post' },
    )
    const tick = setInterval(check, 300)
    check()
  })
}

async function nginxRun(action) {
  const path = action === 'start' ? '/api/nginx/actions/start' : '/api/nginx/actions/stop'
  nginxPending.value = action
  const previousActionAt = data.nginx_management?.logs?.action?.updated_at || ''
  try {
    const result = await apiSend('POST', path, {})
    if (result.nginx_management) {
      data.nginx_management = {
        ...(data.nginx_management || {}),
        ...result.nginx_management,
      }
    }
    const minSettleAt = Date.now() + 900
    await waitForNginx(() => {
      if (nginxState() === 'busy') return false
      if (Date.now() < minSettleAt) return false
      const actionAt = data.nginx_management?.logs?.action?.updated_at || ''
      return !previousActionAt || actionAt !== previousActionAt || Date.now() - minSettleAt > 2000
    }, 45000)
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    nginxPending.value = ''
  }
}

function canRun(entry, mode) {
  if (!entry?.available || entry.state === 'busy') return false
  if (mode === 'start' && entry.state === 'running') return false
  if (mode === 'stop' && entry.state !== 'running') return false
  if (entry.kind === 'nginx') return nginxEnabled(mode)
  if (entry.kind === 'php') return phpActionEnabled(entry.id, mode)
  if (entry.kind === 'infra') return infraActionEnabled(entry.id, mode)
  const item = composeItem(entry.id)
  return !!(item && composeYamlActionEnabled(item, mode))
}

const canStartSequence = computed(() => startRows.value.some((row) => canRun(row, 'start')))
const canStopSequence = computed(() => stopRows.value.some((row) => canRun(row, 'stop')))

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function rowByKey(key, mode) {
  return rowsFor(mode).find((row) => row.key === key) || null
}

async function waitUntilState(key, mode, wanted, timeoutMs = 90000) {
  const started = Date.now()
  while (Date.now() - started < timeoutMs) {
    const row = rowByKey(key, mode)
    if (!row) return false
    if (row.state === wanted) return true
    if (row.state === 'error') return false
    await sleep(400)
  }
  return rowByKey(key, mode)?.state === wanted
}

async function runOne(entry, mode) {
  if (entry.kind === 'nginx') {
    await nginxRun(mode)
    return
  }
  if (entry.kind === 'php') {
    await phpAction(entry.id, mode)
    return
  }
  if (entry.kind === 'infra') {
    await infraAction(entry.id, mode)
    return
  }
  const item = composeItem(entry.id)
  if (item) await composeYamlAction(item, mode)
}

async function runSequence(mode) {
  const enabled = mode === 'stop' ? canStopSequence.value : canStartSequence.value
  if (sequenceRunning.value || !enabled) return
  sequenceRunning.value = true
  sequenceMode.value = mode
  let changed = 0
  const wanted = mode === 'stop' ? 'stopped' : 'running'
  try {
    const order = rowsFor(mode).map((row) => row.key)
    for (const key of order) {
      const row = rowByKey(key, mode)
      if (!row || !canRun(row, mode)) continue
      sequenceKeyActive.value = key
      await runOne(row, mode)
      const ok = await waitUntilState(key, mode, wanted)
      if (!ok) {
        showToast('failure', t(mode === 'stop' ? 'sequence.stop_failed' : 'sequence.start_failed', { label: row.label }))
        return
      }
      changed += 1
    }
    const okKey = mode === 'stop' ? 'sequence.stop_ok' : 'sequence.start_ok'
    const alreadyKey = mode === 'stop' ? 'sequence.stop_already' : 'sequence.start_already'
    showToast('success', changed ? t(okKey, { count: changed }) : t(alreadyKey))
  } finally {
    sequenceRunning.value = false
    sequenceMode.value = ''
    sequenceKeyActive.value = ''
  }
}

function openSettings() {
  draftStart.value = saved.value.start.map((entry) => ({ ...entry }))
  draftStop.value = saved.value.stop.map((entry) => ({ ...entry }))
  dialogOpen.value = true
}

function addEntry(mode, entry) {
  const current = draftList(mode)
  if (current.some((item) => sequenceKey(item.kind, item.id) === entry.key)) return
  const next = [...current, { kind: entry.kind, id: entry.id }]
  if (mode === 'stop') draftStop.value = next
  else draftStart.value = next
}

function removeAt(mode, index) {
  const next = draftList(mode).filter((_, i) => i !== index)
  if (mode === 'stop') draftStop.value = next
  else draftStart.value = next
}

function moveDraft(mode, index, toIndex) {
  const current = draftList(mode)
  if (toIndex < 0 || toIndex >= current.length) return
  const next = current.slice()
  const [item] = next.splice(index, 1)
  next.splice(toIndex, 0, item)
  if (mode === 'stop') draftStop.value = next
  else draftStart.value = next
}

function saveDraft() {
  saved.value = writeStartupSequence({ start: draftStart.value, stop: draftStop.value })
  dialogOpen.value = false
}
</script>

<template>
  <div class="startup-sequence-actions" data-tour="home-startup-sequence">
    <Button
      type="button"
      icon="pi pi-cog"
      severity="secondary"
      outlined
      size="small"
      :label="t('sequence.settings')"
      :disabled="sequenceRunning"
      @click="openSettings"
    />
    <Button
      type="button"
      icon="pi pi-forward"
      size="small"
      :label="sequenceMode === 'start' ? t('sequence.start_working') : t('sequence.start')"
      :loading="sequenceMode === 'start'"
      :disabled="sequenceRunning || !canStartSequence"
      @click="runSequence('start')"
    />
    <Button
      type="button"
      icon="pi pi-power-off"
      size="small"
      severity="danger"
      outlined
      :label="sequenceMode === 'stop' ? t('sequence.stop_working') : t('sequence.stop')"
      :loading="sequenceMode === 'stop'"
      :disabled="sequenceRunning || !canStopSequence"
      @click="runSequence('stop')"
    />

    <Dialog
      :visible="dialogOpen"
      modal
      :header="t('sequence.dialog_title')"
      :style="{ width: 'min(980px, 100%)' }"
      dismissable-mask
      @update:visible="(open) => { dialogOpen = open }"
    >
      <div class="startup-sequence-dialog">
        <div>
          <h3>{{ t('sequence.available') }}</h3>
          <p v-if="catalog.length === 0" class="status-line">{{ t('sequence.available_empty') }}</p>
          <ul v-else class="startup-sequence-pick">
            <li v-for="entry in catalog.filter((item) => item.available)" :key="entry.key">
              <span>
                <Tag :value="t(`pin.kind_${entry.kind}`)" severity="secondary" rounded />
                {{ entry.label }}
              </span>
              <span class="startup-sequence-row-actions">
                <Button
                  type="button"
                  size="small"
                  icon="pi pi-plus"
                  :label="t('sequence.add_start')"
                  :disabled="draftStart.some((item) => sequenceKey(item.kind, item.id) === entry.key)"
                  @click="addEntry('start', entry)"
                />
                <Button
                  type="button"
                  size="small"
                  severity="danger"
                  outlined
                  icon="pi pi-plus"
                  :label="t('sequence.add_stop')"
                  :disabled="draftStop.some((item) => sequenceKey(item.kind, item.id) === entry.key)"
                  @click="addEntry('stop', entry)"
                />
              </span>
            </li>
          </ul>
        </div>
        <div>
          <h3>{{ t('sequence.start_list') }}</h3>
          <p v-if="draftStartRows.length === 0" class="status-line">{{ t('sequence.selected_empty') }}</p>
          <ol v-else class="startup-sequence-pick">
            <li v-for="(entry, index) in draftStartRows" :key="entry.key">
              <span>
                <Tag :value="t(`pin.kind_${entry.kind}`)" severity="secondary" rounded />
                {{ entry.label }}
              </span>
              <span class="startup-sequence-row-actions">
                <Button
                  type="button"
                  icon="pi pi-arrow-up"
                  text
                  rounded
                  size="small"
                  :disabled="index === 0"
                  :aria-label="t('pin.move_up')"
                  @click="moveDraft('start', index, index - 1)"
                />
                <Button
                  type="button"
                  icon="pi pi-arrow-down"
                  text
                  rounded
                  size="small"
                  :disabled="index === draftStartRows.length - 1"
                  :aria-label="t('pin.move_down')"
                  @click="moveDraft('start', index, index + 1)"
                />
                <Button
                  type="button"
                  size="small"
                  severity="danger"
                  text
                  :label="t('sequence.remove')"
                  @click="removeAt('start', index)"
                />
              </span>
            </li>
          </ol>
        </div>
        <div>
          <h3>{{ t('sequence.stop_list') }}</h3>
          <p v-if="draftStopRows.length === 0" class="status-line">{{ t('sequence.selected_empty') }}</p>
          <ol v-else class="startup-sequence-pick">
            <li v-for="(entry, index) in draftStopRows" :key="entry.key">
              <span>
                <Tag :value="t(`pin.kind_${entry.kind}`)" severity="secondary" rounded />
                {{ entry.label }}
              </span>
              <span class="startup-sequence-row-actions">
                <Button
                  type="button"
                  icon="pi pi-arrow-up"
                  text
                  rounded
                  size="small"
                  :disabled="index === 0"
                  :aria-label="t('pin.move_up')"
                  @click="moveDraft('stop', index, index - 1)"
                />
                <Button
                  type="button"
                  icon="pi pi-arrow-down"
                  text
                  rounded
                  size="small"
                  :disabled="index === draftStopRows.length - 1"
                  :aria-label="t('pin.move_down')"
                  @click="moveDraft('stop', index, index + 1)"
                />
                <Button
                  type="button"
                  size="small"
                  severity="danger"
                  text
                  :label="t('sequence.remove')"
                  @click="removeAt('stop', index)"
                />
              </span>
            </li>
          </ol>
        </div>
      </div>
      <template #footer>
        <Button
          type="button"
          severity="secondary"
          outlined
          :label="t('action.cancel')"
          @click="dialogOpen = false"
        />
        <Button type="button" :label="t('sequence.save')" @click="saveDraft" />
      </template>
    </Dialog>
  </div>
</template>
