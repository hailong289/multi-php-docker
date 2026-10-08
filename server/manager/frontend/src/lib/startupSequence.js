export const STARTUP_SEQUENCE_KEY = 'manager-startup-sequence'

const KINDS = new Set(['nginx', 'php', 'infra', 'compose'])

export function sequenceKey(kind, id) {
  return `${kind}:${kind === 'nginx' ? 'nginx' : id}`
}

function normalizeList(raw) {
  if (!Array.isArray(raw)) return []
  const seen = new Set()
  const list = []
  for (const entry of raw) {
    if (!entry || !KINDS.has(entry.kind)) continue
    const id = entry.kind === 'nginx' ? 'nginx' : String(entry.id || '')
    if (!id) continue
    const key = sequenceKey(entry.kind, id)
    if (seen.has(key)) continue
    seen.add(key)
    list.push({ kind: entry.kind, id })
  }
  return list
}

export function readStartupSequence() {
  try {
    const raw = JSON.parse(localStorage.getItem(STARTUP_SEQUENCE_KEY) || 'null')
    if (Array.isArray(raw)) {
      return { start: normalizeList(raw), stop: [] }
    }
    if (!raw || typeof raw !== 'object') return { start: [], stop: [] }
    return {
      start: normalizeList(raw.start),
      stop: normalizeList(raw.stop),
    }
  } catch {
    return { start: [], stop: [] }
  }
}

export function writeStartupSequence(lists) {
  const next = {
    start: normalizeList(lists?.start),
    stop: normalizeList(lists?.stop),
  }
  localStorage.setItem(STARTUP_SEQUENCE_KEY, JSON.stringify(next))
  return next
}
