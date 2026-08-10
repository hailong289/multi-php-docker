<script setup>
import { FitAddon } from '@xterm/addon-fit'
import { Terminal } from '@xterm/xterm'
import '@xterm/xterm/css/xterm.css'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const props = defineProps({
  serverKey: { type: String, required: true },
  title: { type: String, default: '' },
  page: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])
const { t } = useI18n()
const { showToast, translateApiError } = useManager()

const hostEl = ref(null)
const status = ref('connecting')
const sessionId = ref('')
const cwdLabel = ref('')
let term = null
let fitAddon = null
let offset = 0
let pollTimer = null
let closed = false

function bytesToBase64(bytes) {
  let binary = ''
  const chunk = 0x8000
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunk))
  }
  return btoa(binary)
}

function base64ToUint8(b64) {
  const binary = atob(b64 || '')
  const out = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i += 1) out[i] = binary.charCodeAt(i)
  return out
}

function stringToBase64(str) {
  return bytesToBase64(new TextEncoder().encode(str))
}

async function pollOnce() {
  if (!sessionId.value || closed) return
  try {
    const data = await apiGet(`/api/terminal/sessions/${sessionId.value}/output?since=${offset}`)
    if (typeof data.offset === 'number') offset = data.offset
    if (data.data) {
      const bytes = base64ToUint8(data.data)
      if (bytes.length && term) term.write(bytes)
    }
    if (data.closed) {
      status.value = 'disconnected'
      stopPoll()
    }
  } catch (_) {
    // keep trying briefly; hard failures end on next user action
  }
}

function startPoll() {
  stopPoll()
  pollTimer = setInterval(pollOnce, 80)
}

function stopPoll() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

async function sendInput(data) {
  if (!sessionId.value || closed || status.value !== 'ready') return
  try {
    await apiSend('POST', `/api/terminal/sessions/${sessionId.value}/input`, {
      data: stringToBase64(data),
    })
  } catch (_) {}
}

async function sendResize() {
  if (!sessionId.value || !fitAddon || closed) return
  try {
    fitAddon.fit()
    const dims = fitAddon.proposeDimensions?.() || null
    const cols = dims?.cols || term?.cols || 120
    const rows = dims?.rows || term?.rows || 32
    await apiSend('POST', `/api/terminal/sessions/${sessionId.value}/resize`, { cols, rows })
  } catch (_) {}
}

function onWinResize() {
  sendResize()
}

async function closeSession() {
  closed = true
  stopPoll()
  window.removeEventListener('resize', onWinResize)
  const id = sessionId.value
  sessionId.value = ''
  if (id) {
    try {
      await apiSend('DELETE', `/api/terminal/sessions/${id}`)
    } catch (_) {}
  }
  if (term) {
    term.dispose()
    term = null
  }
  emit('close')
}

onMounted(async () => {
  try {
    const created = await apiSend('POST', '/api/terminal/sessions', {
      server_key: props.serverKey,
      cols: 120,
      rows: 32,
    })
    sessionId.value = created.session_id
    cwdLabel.value = typeof created.cwd === 'string' ? created.cwd : ''
    status.value = 'ready'

    term = new Terminal({
      cursorBlink: true,
      fontSize: 13,
      fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
      theme: {
        background: '#0f1419',
        foreground: '#e7ecf3',
        cursor: '#e7ecf3',
      },
    })
    fitAddon = new FitAddon()
    term.loadAddon(fitAddon)
    term.open(hostEl.value)
    fitAddon.fit()
    term.focus()
    term.onData((data) => sendInput(data))
    window.addEventListener('resize', onWinResize)
    await sendResize()
    startPoll()
  } catch (err) {
    status.value = 'error'
    showToast('failure', translateApiError(err))
    emit('close')
  }
})

onBeforeUnmount(() => {
  closed = true
  stopPoll()
  window.removeEventListener('resize', onWinResize)
  if (sessionId.value) {
    apiSend('DELETE', `/api/terminal/sessions/${sessionId.value}`).catch(() => {})
    sessionId.value = ''
  }
  if (term) {
    term.dispose()
    term = null
  }
})
</script>

<template>
  <section
    class="terminal-panel"
    :class="{ 'terminal-panel-page': page }"
    data-tour="docker-terminal"
  >
    <div class="terminal-panel-header">
      <div>
        <h3>{{ title || $t('terminal.title') }}</h3>
        <p class="terminal-hint">
          {{ $t('terminal.hint') }}
          <template v-if="cwdLabel">
            <br />
            <code>{{ cwdLabel }}</code>
          </template>
        </p>
      </div>
      <div class="terminal-panel-actions">
        <span class="terminal-status">
          {{
            status === 'connecting'
              ? $t('terminal.connecting')
              : status === 'disconnected'
                ? $t('terminal.disconnected')
                : status === 'ready'
                  ? ''
                  : $t('terminal.unavailable')
          }}
        </span>
        <button type="button" @click="closeSession">
          {{ page ? $t('terminal.back') : $t('terminal.close') }}
        </button>
      </div>
    </div>
    <div ref="hostEl" class="terminal-host" />
  </section>
</template>
