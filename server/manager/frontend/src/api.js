import axios from 'axios'

let csrfToken = ''

const API_PREFIX = '/server-manage'
const API_TIMEOUT_MS = 30000

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

const http = axios.create({
  timeout: API_TIMEOUT_MS,
  withCredentials: true,
  headers: {
    Accept: 'application/json',
  },
})

function unwrapPayload(data) {
  return data && typeof data === 'object' ? data : {}
}

function normalizeError(error) {
  if (error.response) {
    const data = unwrapPayload(error.response.data)
    const err = new Error(data?.error?.key || `HTTP ${error.response.status}`)
    err.status = error.response.status
    err.payload = data
    throw err
  }
  throw error
}

export async function apiGet(path, options = {}) {
  try {
    const response = await http.get(withApiPrefix(path), {
      signal: options.signal,
    })
    return response.data
  } catch (error) {
    normalizeError(error)
  }
}

export async function apiSend(method, path, body, options = {}) {
  try {
    const response = await http.request({
      method,
      url: withApiPrefix(path),
      data: body,
      timeout: options.timeout ?? API_TIMEOUT_MS,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken,
      },
    })
    return response.data
  } catch (error) {
    normalizeError(error)
  }
}
