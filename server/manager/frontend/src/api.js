let csrfToken = ''

const API_PREFIX = '/server-manage'

export function getCsrfToken() {
  return csrfToken
}

export function setCsrfToken(token) {
  csrfToken = token || ''
}

function withApiPrefix(path) {
  const p = path.startsWith('/') ? path : `/${path}`
  if (p.startsWith('/server-manage/') || p === '/server-manage') return p
  return `${API_PREFIX}${p}`
}

async function parseJson(response) {
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    const error = new Error(data?.error?.key || `HTTP ${response.status}`)
    error.status = response.status
    error.payload = data
    throw error
  }
  return data
}

export async function apiGet(path) {
  const response = await fetch(withApiPrefix(path), {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  })
  return parseJson(response)
}

export async function apiSend(method, path, body) {
  const response = await fetch(withApiPrefix(path), {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  return parseJson(response)
}
