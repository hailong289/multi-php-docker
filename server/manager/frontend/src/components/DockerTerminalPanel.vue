<script setup>
import { FitAddon } from '@xterm/addon-fit'
import { Terminal } from '@xterm/xterm'
import '@xterm/xterm/css/xterm.css'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { apiGet, apiSend } from '../api'
import { useManager } from '../composables/useManager'

const props = defineProps({
  serverKey: { type: String, required: true },
  title: { type: String, default: '' },
  page: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])
const { showToast, translateApiError } = useManager()

const hostEl = ref(null)
const status = ref('connecting')
const sessionId = ref('')
const cwdLabel = ref('')
let term = null
let fitAddon = null
let offset = 0
let closed = false
let resizeTimer = null
let hostObserver = null
let idleTimer = 0
let inputBuf = ''
let inputFlushTimer = 0
let inputInFlight = null

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

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

function helperTextarea(host) {
  return host?.querySelector?.('.xterm-helper-textarea') || host?.querySelector?.('textarea') || null
}

function onImeNoise(ev) {
  ev.preventDefault()
  ev.stopImmediatePropagation()
}

function onBeforeInput(ev) {
  if (ev.isComposing || ev.inputType === 'insertCompositionText' || ev.inputType === 'insertFromComposition') {
    ev.preventDefault()
    ev.stopImmediatePropagation()
  }
}

function applyEnglishImeAttrs(host) {
  const ta = helperTextarea(host)
  if (!ta) return
  ta.setAttribute('lang', 'en')
  ta.setAttribute('inputmode', 'text')
  ta.setAttribute('autocomplete', 'off')
  ta.setAttribute('autocapitalize', 'off')
  ta.setAttribute('autocorrect', 'off')
  ta.setAttribute('spellcheck', 'false')
  ta.style.setProperty('ime-mode', 'disabled')
}

function applyOutput(data) {
  if (!data || typeof data !== 'object') return false
  if (typeof data.offset === 'number') offset = data.offset
  if (data.data) {
    const bytes = base64ToUint8(data.data)
    if (bytes.length && term) term.write(bytes)
  }
  if (data.closed) {
    status.value = 'disconnected'
    stopIdle()
    return true
  }
  return false
}

function stopIdle() {
  if (idleTimer) {
    clearTimeout(idleTimer)
    idleTimer = 0
  }
}

function scheduleIdle() {
  stopIdle()
  if (closed || status.value !== 'ready') return
  idleTimer = window.setTimeout(() => {
    idleTimer = 0
    pullOutput().then((ended) => {
      if (!ended) scheduleIdle()
    })
  }, 2000)
}

async function pullOutput() {
  if (!sessionId.value || closed) return true
  try {
    const data = await apiGet(`/api/terminal/sessions/${sessionId.value}/output?since=${offset}`)
    return applyOutput(data)
  } catch (_) {
    return false
  }
}

async function postInput(data) {
  if (!sessionId.value || closed || status.value !== 'ready') return
  try {
    const result = await apiSend('POST', `/api/terminal/sessions/${sessionId.value}/input`, {
      data: stringToBase64(data),
      since: offset,
    })
    applyOutput(result)
  } catch (_) {}
}

function shouldFlushNow(data) {
  return /[\r\n\x03\x04\x1a\x1b]/.test(data)
}

const SHIFT_DIGIT = {
  Digit1: '!',
  Digit2: '@',
  Digit3: '#',
  Digit4: '$',
  Digit5: '%',
  Digit6: '^',
  Digit7: '&',
  Digit8: '*',
  Digit9: '(',
  Digit0: ')',
}

const PUNCT = {
  Minus: ['-', '_'],
  Equal: ['=', '+'],
  BracketLeft: ['[', '{'],
  BracketRight: [']', '}'],
  Backslash: ['\\', '|'],
  Semicolon: [';', ':'],
  Quote: ["'", '"'],
  Backquote: ['`', '~'],
  Comma: [',', '<'],
  Period: ['.', '>'],
  Slash: ['/', '?'],
}

function physicalKeyToPty(ev) {
  if (ev.ctrlKey || ev.altKey || ev.metaKey) return null
  const { code, shiftKey } = ev
  if (code === 'Space') return ' '
  if (code === 'Enter' || code === 'NumpadEnter') return '\r'
  if (code === 'Backspace') return '\x7f'
  if (code === 'Tab') return '\t'
  if (code === 'Escape') return '\x1b'
  if (code === 'ArrowUp') return '\x1b[A'
  if (code === 'ArrowDown') return '\x1b[B'
  if (code === 'ArrowRight') return '\x1b[C'
  if (code === 'ArrowLeft') return '\x1b[D'
  if (code === 'Home') return '\x1b[H'
  if (code === 'End') return '\x1b[F'
  if (code === 'Delete') return '\x1b[3~'
  if (code.startsWith('Key') && code.length === 4) {
    const letter = code.slice(3)
    return shiftKey ? letter : letter.toLowerCase()
  }
  if (code.startsWith('Digit')) {
    return shiftKey ? SHIFT_DIGIT[code] || code.slice(5) : code.slice(5)
  }
  if (code.startsWith('Numpad') && code.length === 7 && code[6] >= '0' && code[6] <= '9') {
    return code.slice(6)
  }
  const punct = PUNCT[code]
  if (punct) return shiftKey ? punct[1] : punct[0]
  return null
}

function onPtyData(data) {
  if (!data) return
  if (data.length > 1) {
    queueInput(data)
    return
  }
  const code = data.charCodeAt(0)
  if (code < 32 || code === 127) queueInput(data)
}

function onHostKeyDown(ev) {
  if (closed || status.value !== 'ready') return
  const ch = physicalKeyToPty(ev)
  if (ch === null) return
  ev.preventDefault()
  ev.stopImmediatePropagation()
  queueInput(ch)
}

async function drainAfterCommand() {
  for (let i = 0; i < 12; i += 1) {
    if (closed || status.value !== 'ready') return
    await sleep(40)
    const before = offset
    const ended = await pullOutput()
    if (ended) return
    if (offset === before && i >= 2) break
  }
}

async function flushInput() {
  if (inputFlushTimer) {
    clearTimeout(inputFlushTimer)
    inputFlushTimer = 0
  }
  if (inputInFlight) await inputInFlight
  if (!inputBuf) return
  const data = inputBuf
  inputBuf = ''
  const urgent = shouldFlushNow(data)
  stopIdle()
  inputInFlight = postInput(data).finally(() => {
    inputInFlight = null
  })
  await inputInFlight
  if (urgent) await drainAfterCommand()
  if (inputBuf) queueInput('')
  else scheduleIdle()
}

function queueInput(data) {
  if (data) inputBuf += data
  if (!inputBuf) return
  if (shouldFlushNow(inputBuf)) {
    flushInput()
    return
  }
  if (inputFlushTimer) return
  inputFlushTimer = window.setTimeout(() => {
    inputFlushTimer = 0
    flushInput()
  }, 12)
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
  if (resizeTimer) clearTimeout(resizeTimer)
  resizeTimer = setTimeout(() => {
    resizeTimer = null
    sendResize()
  }, 80)
}

function teardownIo() {
  closed = true
  stopIdle()
  if (resizeTimer) clearTimeout(resizeTimer)
  if (inputFlushTimer) clearTimeout(inputFlushTimer)
  hostObserver?.disconnect()
  hostObserver = null
  window.removeEventListener('resize', onWinResize)
  hostEl.value?.removeEventListener('keydown', onHostKeyDown, true)
  hostEl.value?.removeEventListener('compositionstart', onImeNoise, true)
  hostEl.value?.removeEventListener('compositionupdate', onImeNoise, true)
  hostEl.value?.removeEventListener('compositionend', onImeNoise, true)
  hostEl.value?.removeEventListener('beforeinput', onBeforeInput, true)
}

async function closeSession() {
  teardownIo()
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
      scrollback: 4000,
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
    applyEnglishImeAttrs(hostEl.value)
    term.element?.addEventListener('focusin', () => applyEnglishImeAttrs(hostEl.value))
    term.focus()
    term.onData(onPtyData)
    hostEl.value.addEventListener('keydown', onHostKeyDown, true)
    hostEl.value.addEventListener('compositionstart', onImeNoise, true)
    hostEl.value.addEventListener('compositionupdate', onImeNoise, true)
    hostEl.value.addEventListener('compositionend', onImeNoise, true)
    hostEl.value.addEventListener('beforeinput', onBeforeInput, true)
    hostEl.value.addEventListener('mousedown', () => term?.focus())
    window.addEventListener('resize', onWinResize)
    hostObserver = new ResizeObserver(() => onWinResize())
    hostObserver.observe(hostEl.value)
    await sendResize()
    await pullOutput()
    scheduleIdle()
  } catch (err) {
    status.value = 'error'
    showToast('failure', translateApiError(err))
    emit('close')
  }
})

onBeforeUnmount(() => {
  teardownIo()
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
    <div class="terminal-screen">
      <div ref="hostEl" class="terminal-host" lang="en" />
    </div>
  </section>
</template>
