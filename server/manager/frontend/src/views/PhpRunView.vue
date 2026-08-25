<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const MonacoEditor = defineAsyncComponent(() => import('../components/MonacoEditor.vue'))

const DEFAULT_CODE = "<?php\n\necho 'PHP ' . PHP_VERSION . PHP_EOL;\n"
const SAVE_DEBOUNCE_MS = 700
const LEGACY_KEY_PREFIX = 'manager.php-run.'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const {
  showToast,
  translateApiError,
  phpServiceState,
  loadBootstrap,
  data,
} = useManager()

const service = computed(() => String(route.params.service || ''))
const requestedSessionId = computed(() => String(route.query.session || ''))
const loading = ref(true)
const pending = ref(false)
const saving = ref(false)
const mutating = ref('')
const sessions = ref([])
const sessionId = ref('')
const sessionName = ref('')
const code = ref(DEFAULT_CODE)
const result = ref(null)
const updatedAt = ref('')
const lastSavedCode = ref('')
let saveTimer = null
let skipWatch = false

const target = computed(() => data.php_controllers?.targets?.[service.value] || {})
const label = computed(() => target.value.label || service.value)
const running = computed(() => phpServiceState(service.value) === 'running')
const title = computed(() => t('php_controller.run_title', { version: label.value }))
const canRun = computed(() => running.value && !pending.value && code.value.trim() !== '')
const dirty = computed(() => code.value !== lastSavedCode.value)
const exitClass = computed(() => {
  if (!result.value) return ''
  if (result.value.timed_out) return 'is-timeout'
  if ((result.value.exit_code ?? 0) !== 0) return 'is-error'
  return 'is-ok'
})
const savedLabel = computed(() => {
  if (saving.value) return t('php_controller.run_saving')
  if (!updatedAt.value) return dirty.value ? t('php_controller.run_unsaved') : ''
  const at = formatSavedAt(updatedAt.value)
  return dirty.value
    ? t('php_controller.run_unsaved')
    : t('php_controller.run_last_saved', { at })
})

function formatSavedAt(iso) {
  if (!iso) return ''
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  return date.toLocaleString()
}

function legacyKey() {
  return service.value ? LEGACY_KEY_PREFIX + service.value : ''
}

function readLegacy() {
  const key = legacyKey()
  if (!key) return ''
  try {
    const raw = localStorage.getItem(key)
    if (!raw) return ''
    try {
      const parsed = JSON.parse(raw)
      if (parsed && typeof parsed.code === 'string') return parsed.code
    } catch {
      /* raw php source from the old modal */
    }
    return raw
  } catch {
    return ''
  }
}

function writeLegacy(nextCode, nextResult) {
  const key = legacyKey()
  if (!key) return
  try {
    localStorage.setItem(
      key,
      JSON.stringify({
        session_id: sessionId.value,
        code: nextCode,
        result: nextResult,
        updated_at: updatedAt.value,
      }),
    )
  } catch {
    /* ignore quota */
  }
}

function syncSessionQuery(id) {
  if (!id || requestedSessionId.value === id) return
  router.replace({
    name: 'php-version-run',
    params: { service: service.value },
    query: { session: id },
  })
}

function applyPad(pad, preferredId = '') {
  const list = Array.isArray(pad?.sessions) ? pad.sessions : []
  sessions.value = list
  const wanted = preferredId || pad?.active_id || pad?.id || ''
  const current = list.find((item) => item.id === wanted) || list[0] || null
  applySession(current)
  if (current?.id) syncSessionQuery(current.id)
}

function applySession(session) {
  skipWatch = true
  sessionId.value = session?.id || ''
  sessionName.value = session?.name || ''
  code.value = session?.code || DEFAULT_CODE
  result.value = session?.result || null
  updatedAt.value = session?.updated_at || ''
  lastSavedCode.value = code.value
  skipWatch = false
  writeLegacy(code.value, result.value)
}

function scheduleSave() {
  if (skipWatch || !service.value || !sessionId.value) return
  if (saveTimer) clearTimeout(saveTimer)
  saveTimer = setTimeout(() => {
    saveTimer = null
    saveDraft()
  }, SAVE_DEBOUNCE_MS)
}

async function saveDraft({ silent = true, force = false } = {}) {
  if (!service.value || !sessionId.value || pending.value) return
  if (loading.value && !force) return
  if (!force && code.value === lastSavedCode.value) return
  saving.value = true
  const snapshot = code.value
  writeLegacy(snapshot, result.value)
  try {
    const payload = await apiSend(
      'PUT',
      `/api/php-controllers/${service.value}/scratch/${sessionId.value}`,
      { code: snapshot },
    )
    const scratch = payload.php_scratch
    lastSavedCode.value = snapshot
    updatedAt.value = scratch?.updated_at || updatedAt.value
    if (Array.isArray(scratch?.sessions)) sessions.value = scratch.sessions
    if (scratch?.name) sessionName.value = scratch.name
  } catch (error) {
    if (!silent) showToast('failure', translateApiError(error))
  } finally {
    saving.value = false
  }
}

async function flushDraft() {
  if (saveTimer) {
    clearTimeout(saveTimer)
    saveTimer = null
  }
  if (sessionId.value && code.value !== lastSavedCode.value) {
    await saveDraft({ silent: true, force: true })
  }
}

async function loadScratch() {
  loading.value = true
  try {
    await loadBootstrap()
    if (!data.php_controllers?.targets?.[service.value]) {
      showToast('failure', t('php_controller.invalid_service'))
      router.push({ name: 'php-versions' })
      return
    }
    const payload = await apiGet(`/api/php-controllers/${service.value}/scratch`)
    const scratch = payload.php_scratch || {}
    const serverCode = typeof scratch.code === 'string' ? scratch.code : ''
    const legacy = readLegacy()
    const serverIsDefault =
      (!scratch.updated_at || scratch.sessions?.length === 1) &&
      (!serverCode || serverCode === DEFAULT_CODE) &&
      !(scratch.sessions || []).some((item) => item.updated_at)
    if (serverIsDefault && legacy && legacy !== DEFAULT_CODE) {
      applyPad(scratch, requestedSessionId.value)
      skipWatch = true
      code.value = legacy
      lastSavedCode.value = ''
      skipWatch = false
      await saveDraft({ silent: true, force: true })
      return
    }
    applyPad(scratch, requestedSessionId.value)
  } catch (error) {
    showToast('failure', translateApiError(error))
    applyPad({
      sessions: [
        {
          id: '',
          name: t('php_controller.session_default_name', { n: 1 }),
          code: readLegacy() || DEFAULT_CODE,
          result: null,
          updated_at: '',
        },
      ],
    })
  } finally {
    loading.value = false
  }
}

async function createSession() {
  if (!service.value || mutating.value) return
  await flushDraft()
  mutating.value = 'create'
  try {
    const payload = await apiSend('POST', `/api/php-controllers/${service.value}/scratch`, {
      name: t('php_controller.session_default_name', { n: sessions.value.length + 1 }),
    })
    applyPad(payload.php_scratch)
    showToast('success', t(payload.message_key || 'php_controller.session_created'))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    mutating.value = ''
  }
}

async function selectSession(id) {
  if (!id || id === sessionId.value || mutating.value) return
  await flushDraft()
  mutating.value = 'switch'
  try {
    const payload = await apiSend(
      'PUT',
      `/api/php-controllers/${service.value}/scratch/${id}`,
      { activate: true },
    )
    applyPad(payload.php_scratch, id)
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    mutating.value = ''
  }
}

async function renameSession(item) {
  const current = item || { id: sessionId.value, name: sessionName.value }
  if (!current.id) return
  const next = window.prompt(t('php_controller.session_rename_prompt'), current.name)
  if (next === null) return
  const name = next.trim()
  if (!name || name === current.name) return
  mutating.value = 'rename'
  try {
    const payload = await apiSend(
      'PUT',
      `/api/php-controllers/${service.value}/scratch/${current.id}`,
      { name },
    )
    applyPad(payload.php_scratch, sessionId.value)
    showToast('success', t(payload.message_key || 'php_controller.session_renamed'))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    mutating.value = ''
  }
}

async function deleteSession(item) {
  const current = item || { id: sessionId.value, name: sessionName.value }
  if (!current.id) return
  if (!confirm(t('php_controller.session_delete_confirm', { name: current.name }))) return
  if (current.id === sessionId.value) {
    await flushDraft()
  }
  mutating.value = 'delete'
  try {
    const payload = await apiSend(
      'DELETE',
      `/api/php-controllers/${service.value}/scratch/${current.id}`,
    )
    applyPad(payload.php_scratch)
    showToast('success', t(payload.message_key || 'php_controller.session_deleted'))
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    mutating.value = ''
  }
}

async function runCode() {
  if (!canRun.value) return
  await flushDraft()
  pending.value = true
  try {
    const payload = await apiSend(
      'POST',
      `/api/php-controllers/${service.value}/run`,
      { code: code.value, session_id: sessionId.value || undefined },
      { timeout: 25000 },
    )
    result.value = payload.php_run || null
    if (payload.php_scratch) {
      applyPad(payload.php_scratch, sessionId.value)
    } else {
      lastSavedCode.value = code.value
    }
    writeLegacy(code.value, result.value)
    if (payload.php_run?.timed_out) {
      showToast('failure', t('php_controller.run_timed_out'))
    }
  } catch (error) {
    showToast('failure', translateApiError(error))
  } finally {
    pending.value = false
  }
}

function goBack() {
  router.push({ name: 'php-versions' })
}

function onKeydown(event) {
  if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
    event.preventDefault()
    runCode()
  }
}

watch(code, () => scheduleSave())
watch(service, async (next, prev) => {
  if (saveTimer) {
    clearTimeout(saveTimer)
    saveTimer = null
  }
  if (prev && prev !== next && sessionId.value && code.value !== lastSavedCode.value) {
    try {
      await apiSend('PUT', `/api/php-controllers/${prev}/scratch/${sessionId.value}`, {
        code: code.value,
      })
    } catch {
      /* keep local copy */
    }
  }
  await loadScratch()
})

onMounted(async () => {
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('beforeunload', onBeforeUnload)
  await loadScratch()
})

function onBeforeUnload() {
  if (saveTimer) {
    clearTimeout(saveTimer)
    saveTimer = null
  }
  writeLegacy(code.value, result.value)
}

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('beforeunload', onBeforeUnload)
  if (saveTimer) {
    clearTimeout(saveTimer)
    saveTimer = null
    saveDraft({ silent: true })
  }
})
</script>

<template>
  <section class="panel php-run-page" data-tour="php-run-panel">
    <div class="panel-heading nginx-heading">
      <div class="php-detail-heading">
        <button
          type="button"
          class="icon-back"
          :aria-label="t('php_controller.back')"
          :title="t('php_controller.back')"
          @click="goBack"
        >
          <svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false">
            <path
              d="M12.5 4.5 7 10l5.5 5.5"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </button>
        <div>
          <h2>{{ title }}</h2>
          <p>{{ t('php_controller.run_subtitle') }}</p>
        </div>
      </div>
    </div>

    <div class="panel-body nginx-domain-logs-toolbar">
      <p class="status-line">{{ t('php_controller.session_hint') }}</p>
      <button
        type="button"
        class="primary"
        :disabled="!!mutating || loading"
        @click="createSession"
      >
        {{ mutating === 'create' ? t('action.working') : t('php_controller.session_add') }}
      </button>
    </div>

    <p v-if="loading" class="panel-body">{{ t('loading') }}</p>
    <div v-else class="php-run-layout">
      <aside class="php-run-sessions" :aria-label="t('php_controller.session_name')">
        <div class="php-run-sessions-head">{{ t('php_controller.session_name') }}</div>
        <ul>
          <li
            v-for="item in sessions"
            :key="item.id"
            :class="{ 'is-selected': item.id === sessionId }"
            @click="selectSession(item.id)"
          >
            <div class="php-run-session-copy">
              <strong>{{ item.name }}</strong>
              <span v-if="item.updated_at" class="php-run-session-meta">{{
                formatSavedAt(item.updated_at)
              }}</span>
            </div>
            <div class="php-run-session-actions">
              <button type="button" :disabled="!!mutating" @click.stop="renameSession(item)">
                {{ t('php_controller.session_rename') }}
              </button>
              <button
                type="button"
                class="danger"
                :disabled="!!mutating"
                @click.stop="deleteSession(item)"
              >
                {{ t('action.delete') }}
              </button>
            </div>
          </li>
        </ul>
      </aside>

      <div class="panel-body php-run-editor-pane">
        <p v-if="!running" class="php-ext-warn">{{ t('php_controller.run_need_running') }}</p>
        <div class="nginx-template-editor-head">
          <div>
            <strong>{{ sessionName }}</strong>
            <span v-if="dirty" class="status-pill status-off">{{ t('php_controller.run_unsaved') }}</span>
          </div>
          <div class="actions">
            <button type="button" :disabled="!!mutating || !sessionId" @click="renameSession()">
              {{ t('php_controller.session_rename') }}
            </button>
            <button
              type="button"
              class="danger"
              :disabled="!!mutating || !sessionId"
              @click="deleteSession()"
            >
              {{ mutating === 'delete' ? t('action.working') : t('action.delete') }}
            </button>
          </div>
        </div>
        <div class="php-run-editor">
          <MonacoEditor
            v-model="code"
            language="php"
            min-height="360px"
            :read-only="pending"
            @ready="(editor) => editor?.focus()"
          />
        </div>
        <div class="form-actions php-run-actions">
          <button
            type="button"
            class="primary"
            :class="{ 'is-loading': pending }"
            :disabled="!canRun"
            @click="runCode"
          >
            <span v-if="pending" class="btn-spinner" aria-hidden="true"></span>
            {{ pending ? t('php_controller.run_running') : t('php_controller.run') }}
          </button>
          <span class="php-run-hint">{{ t('php_controller.run_shortcut') }}</span>
          <span class="php-run-save-status" :class="{ 'is-dirty': dirty }">{{ savedLabel }}</span>
        </div>
        <div v-if="result" class="php-run-output" :class="exitClass">
          <div class="php-run-output-meta">
            <span v-if="result.timed_out">{{ t('php_controller.run_timed_out') }}</span>
            <span v-else>
              {{
                t('php_controller.run_meta', {
                  code: result.exit_code,
                  ms: result.duration_ms,
                })
              }}
            </span>
            <span v-if="result.truncated">{{ t('php_controller.run_truncated') }}</span>
          </div>
          <pre v-if="result.stdout" class="php-run-stdout">{{ result.stdout }}</pre>
          <pre v-if="result.stderr" class="php-run-stderr">{{ result.stderr }}</pre>
          <p v-if="!result.stdout && !result.stderr" class="php-run-empty">
            {{ t('php_controller.run_no_output') }}
          </p>
        </div>
      </div>
    </div>
  </section>
</template>
