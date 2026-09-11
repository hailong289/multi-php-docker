export const PIN_STORAGE_KEY = 'manager-pinned-containers'
export const PIN_KINDS = ['nginx', 'infra', 'php', 'compose']

export function pinKey(kind, id) {
  return `${kind}:${id}`
}

function normalizeEntry(raw) {
  if (!raw || typeof raw !== 'object') return null
  const kind = String(raw.kind || '')
  const id = String(raw.id || '')
  if (!PIN_KINDS.includes(kind) || !id) return null
  if (kind === 'nginx' && id !== 'nginx') return null
  return { kind, id }
}

export function readPins() {
  try {
    const raw = localStorage.getItem(PIN_STORAGE_KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    const seen = new Set()
    const out = []
    for (const item of parsed) {
      const entry = normalizeEntry(item)
      if (!entry) continue
      const key = pinKey(entry.kind, entry.id)
      if (seen.has(key)) continue
      seen.add(key)
      out.push(entry)
    }
    return out
  } catch (_) {
    return []
  }
}

export function writePins(list) {
  const normalized = []
  const seen = new Set()
  for (const item of list || []) {
    const entry = normalizeEntry(item)
    if (!entry) continue
    const key = pinKey(entry.kind, entry.id)
    if (seen.has(key)) continue
    seen.add(key)
    normalized.push(entry)
  }
  try {
    localStorage.setItem(PIN_STORAGE_KEY, JSON.stringify(normalized))
  } catch (_) {}
  return normalized
}

export function isPinned(kind, id) {
  const key = pinKey(kind, id)
  return readPins().some((p) => pinKey(p.kind, p.id) === key)
}

export function pin(kind, id) {
  const list = readPins()
  if (list.some((p) => pinKey(p.kind, p.id) === pinKey(kind, id))) return list
  return writePins([...list, { kind, id }])
}

export function unpin(kind, id) {
  const key = pinKey(kind, id)
  return writePins(readPins().filter((p) => pinKey(p.kind, p.id) !== key))
}

export function togglePin(kind, id) {
  return isPinned(kind, id) ? unpin(kind, id) : pin(kind, id)
}

export function movePin(kind, id, toIndex) {
  const list = readPins()
  const key = pinKey(kind, id)
  const from = list.findIndex((p) => pinKey(p.kind, p.id) === key)
  if (from < 0) return list
  const next = list.slice()
  const [item] = next.splice(from, 1)
  const clamped = Math.max(0, Math.min(next.length, Number(toIndex) || 0))
  next.splice(clamped, 0, item)
  return writePins(next)
}

export function reorderPins(nextList) {
  return writePins(nextList)
}
